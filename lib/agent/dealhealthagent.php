<?php declare(strict_types=1);

namespace Shef\ToolsAi\Agent;

use Bitrix\Crm\DealTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Shef\Options\Main\TempFile\Pid;
use Shef\Options\TraitList\Security\FixUser;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Deal\DealFacts;
use Shef\ToolsAi\Deal\Model\DealCheckTable;
use Shef\ToolsAi\Deal\Model\DealProfileTable;
use Shef\ToolsAi\Deal\Profile;
use Shef\ToolsAi\Deal\ProfileMigration;
use Shef\ToolsAi\Deal\ProfilePicker;
use Shef\ToolsAi\Deal\RiskScale;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Quota\Ledger;
use Shef\ToolsAi\Quota\Status;

// Модуль shef.options подключается ДО объявления класса: `use FixUser` —
// композиция при разборе файла, и к моменту выполнения run() трейт уже
// должен быть найден (навык shef-new-agent, п. 3).
if(!\Bitrix\Main\Loader::includeModule('shef.options'))
{
	return;
}

/**
 * Периодический обход сделок: пора ли звать старшего.
 *
 * ВАЖНО ПРО РАСХОД: агент — главный потребитель квоты. Сотня открытых сделок
 * при ежедневном прогоне даёт ~3000 запросов в месяц. Поэтому три
 * ограничителя, все в настройках:
 *   - дешёвый фильтр DealFacts::isWorthAnalyzing() ДО обращения к модели
 *     (IDLE_DAYS профиля);
 *   - не больше DEAL_maxperrun анализов за прогон;
 *   - повторный анализ одной сделки не чаще раза в REANALYZE_DAYS профиля;
 *   - сделка без подходящего профиля модель не зовёт вовсе.
 *
 * Что анализировать и как действовать — профили (Deal\Profile, страница
 * «ИИ: профили анализа сделок»): направление, тип клиента, промпт, шкала.
 *
 * Анализ — тоже расход: каждый запрос к модели пишется в журнал (категория
 * deal) и проверяется по квоте, как запросы Копилота.
 *
 * Агент регистрирует «Проверить и включить» (Main\Setup::ensureAgent) —
 * не установщик; работает — только при включённом анализе и хотя бы одном
 * включённом профиле.
 */
final class DealHealthAgent
{
	use FixUser;

	public const CATEGORY = 'deal';

	/** Во сколько раз больше кандидатов просматривать, чем анализировать. */
	private const SCAN_FACTOR = 5;

	protected static function getInitedUserId(): int
	{
		return \Shef\Options\Main\Constants::getSystemUserId();
	}

	/** Ошибок провайдера подряд, после которых прогон останавливается. */
	public const PROVIDER_ERRORS_MAX = 3;

	/**
	 * Коды ProviderException, при которых виноват провайдер, а не сделка.
	 * Остальные (не JSON, 4xx на запрос) повторялись бы на той же сделке.
	 */
	public const PROVIDER_DOWN_CODES = ['provider_unavailable', 'provider_rate_limit', 'provider_auth'];

	/** Точка входа агента. Возвращает свой вызов для следующего запуска. */
	public static function run(): string
	{
		$self = '\\'.static::class.'::run();';

		if(!Container::getConfig()->isDealHealthEnabled() || !Loader::includeModule('crm'))
		{
			return $self;
		}

		try
		{
			static::runLocked(static fn(): array => static::process());
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}

		return $self;
	}

	/**
	 * Выполнить под блокировкой группы и от служебного пользователя. Этим
	 * пользуются и агент, и cli/deal-health.php: ручной прогон рядом с
	 * агентом платил бы за те же сделки дважды.
	 *
	 * @param callable(): array $work
	 * @return array|null null — прогон уже идёт в другом процессе
	 */
	public static function runLocked(callable $work): ?array
	{
		$lock = new Pid(Constants::LOCK_GROUP_DEAL_HEALTH);
		if(!$lock->add())
		{
			return null;
		}

		try
		{
			// После add() в каталоге группы только живые блокировки: больше
			// одной — прогон уже идёт в другом процессе.
			if(count(glob(Pid::getBasePath(Constants::LOCK_GROUP_DEAL_HEALTH).'/*.lock') ?: []) > 1)
			{
				return null;
			}

			static::initUser();
			try
			{
				return $work();
			}
			finally
			{
				static::closeUser();
			}
		}
		finally
		{
			$lock->remove();
		}
	}

	/**
	 * Один прогон. Звать через runLocked().
	 *
	 * @param int[]|null $onlyDeals только эти сделки, без выборки кандидатов
	 * @return array<int, array{risk: int, needSenior: bool, skipped: bool, escalated: string[], error: string, profile: int}>
	 */
	public static function process(?array $onlyDeals = null): array
	{
		$config = Container::getConfig();

		// Обновление без установщика: таблицы и перенос старых настроек.
		ProfileMigration::run($config);
		$profiles = DealProfileTable::getProfiles(true);
		$pick = static fn(DealFacts $facts): ?Profile => ProfilePicker::pick($profiles, $facts->categoryId, $facts->clientType);

		$analyzer = Container::getHealthAnalyzer();
		$escalation = Container::getEscalation();
		$meter = Container::getMeter();
		$ledger = new Ledger();
		$logger = Container::getLogger(static::class);

		$report = [];
		$analyzed = 0;
		$providerErrors = 0;

		foreach($onlyDeals ?? static::getCandidates() as $dealId)
		{
			if($analyzed >= $config->getMaxPerRun())
			{
				break;
			}

			// Провайдер лежит — дальше каждая сделка ждёт таймаут и ничего не
			// даёт. Остановиться и оставить сделки непроверенными.
			if($providerErrors >= static::PROVIDER_ERRORS_MAX)
			{
				$logger?->warning('Анализ сделок остановлен: провайдер не отвечает '.$providerErrors.' раза подряд');
				break;
			}

			// Оценки у анализа нет — проверяем, что квота не исчерпана.
			if($meter->getMonthly()->isExceeded())
			{
				$logger?->warning('Анализ сделок остановлен: месячная квота исчерпана');
				break;
			}

			$row = ['risk' => 0, 'needSenior' => false, 'skipped' => false, 'escalated' => [], 'error' => '', 'profile' => 0];

			try
			{
				$analysis = $analyzer->analyze($dealId, $pick);
			}
			catch(ProviderException $exception)
			{
				$analyzed++;
				$logger?->error($exception, ['itemId' => $dealId]);

				// Ответ оплачен, но негоден (не JSON, не по схеме) — трата
				// всё равно в журнал и в квоту.
				if($exception->spentMicro > 0 || $exception->spentUnits > 0)
				{
					try
					{
						$id = $ledger->start(Constants::getEngineCode(static::CATEGORY), static::CATEGORY, $analyzer->getLlmCode(), null);
						if($id !== null)
						{
							$ledger->finish($id, Status::ERROR, $exception->spentUnits, $exception->spentMicro, mb_substr($exception->getMessage(), 0, 500));
						}
					}
					catch(\Throwable $throwable)
					{
						$logger?->error($throwable, ['itemId' => $dealId, 'costMicro' => $exception->spentMicro]);
					}
				}
				$row['error'] = $exception->getMessage();
				$report[$dealId] = $row;

				// Провайдер лежит (нет связи, лимит частоты, ключ) — это не
				// свойство сделки: CHECKED_AT не пишем, в следующий прогон
				// сделка снова кандидат.
				if(in_array($exception->errorCode, static::PROVIDER_DOWN_CODES, true))
				{
					$providerErrors++;
					continue;
				}

				// Сделка, на которой модель падает сама (не JSON, слишком
				// длинный контекст), падала бы так каждый прогон — и каждый
				// раз за деньги. Отметить проверенной с ошибкой.
				static::saveCheck($dealId, ['CHECKED_AT' => new DateTime(), 'SKIPPED' => 'Y', 'WHY' => mb_substr('Ошибка модели: '.$row['error'], 0, 500)], $logger);
				continue;
			}
			catch(\Throwable $throwable)
			{
				// Сделки нет или CRM не отдала данные — отметить, иначе она
				// будет первой кандидаткой каждый прогон.
				$logger?->error($throwable, ['itemId' => $dealId]);
				$row['error'] = $throwable->getMessage();
				$report[$dealId] = $row;
				static::saveCheck($dealId, ['CHECKED_AT' => new DateTime(), 'SKIPPED' => 'Y', 'WHY' => mb_substr('Ошибка: '.$row['error'], 0, 500)], $logger);
				continue;
			}

			$providerErrors = 0;
			$verdict = $analysis->verdict;

			if($analysis->llm !== null)
			{
				$analyzed++;
				try
				{
					$id = $ledger->start(Constants::getEngineCode(static::CATEGORY), static::CATEGORY, $analyzer->getLlmCode(), null);
					if($id !== null)
					{
						$ledger->finish($id, Status::SUCCESS, $analysis->llm->getTokens(), $analysis->llm->costMicro);
					}
				}
				catch(\Throwable $throwable)
				{
					$logger?->error($throwable, ['itemId' => $dealId, 'costMicro' => $analysis->llm->costMicro]);
				}
			}

			// Шкала профиля пишет в CRM клиента — её сбой не должен ни обрывать
			// прогон, ни оставлять сделку без отметки о проверке (иначе за
			// неё платили бы каждый прогон).
			$escalated = [];
			$profile = $analysis->profile;
			$facts = $analysis->facts;
			if($profile !== null && $facts !== null && !$verdict->skipped)
			{
				try
				{
					$check = DealCheckTable::getByDeal($dealId);
					$border = time() - $profile->reanalyzeDays * 86400;
					$isRecent = static fn(mixed $at): bool => $at instanceof DateTime && $at->getTimestamp() > $border;

					$decision = RiskScale::decide(
						$verdict,
						$profile,
						$facts->assignedById,
						$facts->openActivities,
						$isRecent($check['MANAGER_TODO_AT'] ?? null),
						$isRecent($check['ESCALATED_AT'] ?? null),
					);
					$escalated = $escalation->apply($dealId, $verdict, $profile, $decision, $facts->assignedById);
				}
				catch(\Throwable $throwable)
				{
					$logger?->error($throwable, ['itemId' => $dealId]);
					$row['error'] = 'Эскалация не удалась: '.$throwable->getMessage();
				}
			}

			$fields = [
				'CHECKED_AT' => new DateTime(),
				'RISK' => $verdict->risk,
				'NEED_SENIOR' => $verdict->needSenior ? 'Y' : 'N',
				'SKIPPED' => $verdict->skipped ? 'Y' : 'N',
				'WHY' => $verdict->skipped ? $verdict->skipReason : $verdict->why,
				'NEXT_STEP' => $verdict->nextStep,
				'PROFILE_ID' => $profile?->id ?? 0,
			];
			if(in_array('comment', $escalated, true) || in_array('todo', $escalated, true))
			{
				$fields['ESCALATED_AT'] = new DateTime();
			}
			if(in_array('manager_todo', $escalated, true))
			{
				$fields['MANAGER_TODO_AT'] = new DateTime();
			}
			static::saveCheck($dealId, $fields, $logger);

			$report[$dealId] = [
				'risk' => $verdict->risk,
				'needSenior' => $verdict->needSenior,
				'skipped' => $verdict->skipped,
				'escalated' => $escalated,
				'error' => $row['error'],
				'profile' => $profile?->id ?? 0,
			];
		}

		return $report;
	}

	private static function saveCheck(int $dealId, array $fields, ?\Psr\Log\LoggerInterface $logger): void
	{
		try
		{
			DealCheckTable::save($dealId, $fields);
		}
		catch(\Throwable $throwable)
		{
			$logger?->error($throwable, ['itemId' => $dealId]);
		}
	}

	/**
	 * Открытые сделки направлений с включёнными профилями, которые давно не
	 * проверялись: сначала непроверенные, потом самые давние. Срок повтора —
	 * самый короткий REANALYZE_DAYS среди профилей направления.
	 *
	 * @return int[]
	 */
	public static function getCandidates(): array
	{
		$config = Container::getConfig();
		ProfileMigration::run($config);

		// Нет включённых профилей — не работаем: «все направления» по ошибке
		// — это счёт за всю базу.
		$days = ProfilePicker::getCategoryReanalyzeDays(DealProfileTable::getProfiles(true));
		if($days === [])
		{
			return [];
		}

		$byCategory = ['LOGIC' => 'OR'];
		foreach($days as $categoryId => $reanalyzeDays)
		{
			$byCategory[] = [
				'=CATEGORY_ID' => $categoryId,
				[
					'LOGIC' => 'OR',
					['=CHECK.ID' => null],
					['<CHECK.CHECKED_AT' => DateTime::createFromTimestamp(time() - $reanalyzeDays * 86400)],
				],
			];
		}

		$rows = DealTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=CLOSED' => 'N',
				$byCategory,
			],
			'runtime' => [
				new Reference(
					'CHECK',
					DealCheckTable::class,
					Join::on('this.ID', 'ref.DEAL_ID'),
					['join_type' => 'LEFT']
				),
			],
			'order' => ['CHECK.CHECKED_AT' => 'ASC', 'ID' => 'ASC'],
			'limit' => $config->getMaxPerRun() * self::SCAN_FACTOR,
		])->fetchAll();

		return array_map('intval', array_column($rows, 'ID'));
	}
}

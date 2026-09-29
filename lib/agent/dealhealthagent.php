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
use Shef\ToolsAi\Deal\Model\DealCheckTable;
use Shef\ToolsAi\Main\Constants;
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
 *   - дешёвый фильтр DealFacts::isWorthAnalyzing() ДО обращения к модели;
 *   - не больше DEAL_maxperrun анализов за прогон;
 *   - повторный анализ одной сделки не чаще раза в DEAL_reanalyzedays дней.
 *
 * Анализ — тоже расход: каждый запрос к модели пишется в журнал (категория
 * deal) и проверяется по квоте, как запросы Копилота.
 *
 * Агент регистрируется всегда (Main\Setup::ensureAgent), работает — только
 * при включённом анализе и заданных направлениях.
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

	/** Точка входа агента. Возвращает свой вызов для следующего запуска. */
	public static function run(): string
	{
		$self = '\\'.static::class.'::run();';

		$config = Container::getConfig();
		if(!$config->isDealHealthEnabled() || !Loader::includeModule('crm'))
		{
			return $self;
		}

		$lock = new Pid(Constants::LOCK_GROUP_DEAL_HEALTH);
		if(!$lock->add())
		{
			return $self;
		}

		static::initUser();
		try
		{
			// После add() в каталоге группы только живые блокировки: больше
			// одной — прогон уже идёт в другом процессе.
			if(count(glob(Pid::getBasePath(Constants::LOCK_GROUP_DEAL_HEALTH).'/*.lock') ?: []) > 1)
			{
				return $self;
			}

			static::process();
		}
		catch(\Throwable $throwable)
		{
			Container::getLogger(static::class)?->error($throwable);
		}
		finally
		{
			static::closeUser();
			$lock->remove();
		}

		return $self;
	}

	/**
	 * Один прогон. Отдельно от run() — чтобы звать руками (cli/deal-health.php).
	 *
	 * @param int[]|null $onlyDeals только эти сделки, без выборки кандидатов
	 * @return array<int, array{risk: int, needSenior: bool, skipped: bool, escalated: string[], error: string}>
	 */
	public static function process(?array $onlyDeals = null): array
	{
		$config = Container::getConfig();
		$analyzer = Container::getHealthAnalyzer();
		$escalation = Container::getEscalation();
		$meter = Container::getMeter();
		$ledger = new Ledger();
		$logger = Container::getLogger(static::class);

		$report = [];
		$analyzed = 0;

		foreach($onlyDeals ?? static::getCandidates() as $dealId)
		{
			if($analyzed >= $config->getMaxPerRun())
			{
				break;
			}

			// Оценки у анализа нет — проверяем, что квота не исчерпана.
			if($meter->getMonthly()->isExceeded())
			{
				$logger?->warning('Анализ сделок остановлен: месячная квота исчерпана');
				break;
			}

			$row = ['risk' => 0, 'needSenior' => false, 'skipped' => false, 'escalated' => [], 'error' => ''];

			try
			{
				$analysis = $analyzer->analyze($dealId, $config->getIdleDays());
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $dealId]);
				$row['error'] = $throwable->getMessage();
				$report[$dealId] = $row;
				DealCheckTable::save($dealId, ['CHECKED_AT' => new DateTime(), 'SKIPPED' => 'Y', 'WHY' => mb_substr('Ошибка: '.$row['error'], 0, 500)]);
				continue;
			}

			$verdict = $analysis->verdict;

			if($analysis->llm !== null)
			{
				$analyzed++;
				$id = $ledger->start(Constants::getEngineCode(static::CATEGORY), static::CATEGORY, $analyzer->getLlmCode(), null);
				if($id !== null)
				{
					$ledger->finish($id, Status::SUCCESS, $analysis->llm->getTokens(), $analysis->llm->costMicro);
				}
			}

			$check = DealCheckTable::getByDeal($dealId);
			$lastEscalated = $check['ESCALATED_AT'] ?? null;
			$recentlyEscalated = $lastEscalated instanceof DateTime
				&& $lastEscalated->getTimestamp() > time() - $config->getReanalyzeDays() * 86400;

			$escalated = $recentlyEscalated ? [] : $escalation->escalate($dealId, $verdict);

			$fields = [
				'CHECKED_AT' => new DateTime(),
				'RISK' => $verdict->risk,
				'NEED_SENIOR' => $verdict->needSenior ? 'Y' : 'N',
				'SKIPPED' => $verdict->skipped ? 'Y' : 'N',
				'WHY' => $verdict->skipped ? $verdict->skipReason : $verdict->why,
				'NEXT_STEP' => $verdict->nextStep,
			];
			if($escalated !== [])
			{
				$fields['ESCALATED_AT'] = new DateTime();
			}
			DealCheckTable::save($dealId, $fields);

			$report[$dealId] = [
				'risk' => $verdict->risk,
				'needSenior' => $verdict->needSenior,
				'skipped' => $verdict->skipped,
				'escalated' => $escalated,
				'error' => '',
			];
		}

		return $report;
	}

	/**
	 * Открытые сделки выбранных направлений, которые давно не проверялись:
	 * сначала непроверенные, потом самые давние.
	 *
	 * @return int[]
	 */
	public static function getCandidates(): array
	{
		$config = Container::getConfig();
		$categories = $config->getDealCategories();

		// null — настройка испорчена; [] — не выбрано. В обоих случаях не
		// работаем: «все направления» по ошибке — это счёт за всю базу.
		if($categories === null || $categories === [])
		{
			return [];
		}

		$border = DateTime::createFromTimestamp(time() - $config->getReanalyzeDays() * 86400);

		$rows = DealTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=CLOSED' => 'N',
				'@CATEGORY_ID' => $categories,
				[
					'LOGIC' => 'OR',
					['=CHECK.ID' => null],
					['<CHECK.CHECKED_AT' => $border],
				],
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

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Agent;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Shef\Options\Main\TempFile\Pid;
use Shef\Options\TraitList\Security\FixUser;
use Shef\ToolsAi\Chat\ChatScore;
use Shef\ToolsAi\Chat\ChatScorer;
use Shef\ToolsAi\Chat\DialogSource;
use Shef\ToolsAi\Chat\Model\ChatAssessmentTable;
use Shef\ToolsAi\Chat\Transcript;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Quota\Ledger;
use Shef\ToolsAi\Quota\Status;

// Модуль shef.options подключается ДО объявления класса: `use FixUser` —
// композиция при разборе файла (навык shef-new-agent, п. 3).
if(!\Bitrix\Main\Loader::includeModule('shef.options'))
{
	return;
}

/**
 * Оценка переписки открытых линий по скрипту (1.6.0, bx-shef/toolsai#34).
 *
 * Оценки по скрипту для чатов в CRM нет (ScoreCall — только звонки), поэтому
 * своя: закрытые за последние CHAT_days дней диалоги, привязанные к сделке
 * или лиду, без оценки модуля -> текст переписки (Chat\DialogSource,
 * Chat\Transcript) -> скрипт речевой аналитики (DialogSource::pickScript())
 * -> модель текстовой точки доступа (Chat\ChatScorer) -> комментарий в
 * таймлайн сделки или лида и строка в shef_toolsai_chat_assessment.
 *
 * Расход — в журнал (категория chat) и под месячной квотой: запись «в
 * работе» с оценкой — до модели, итог — после, как у запросов Копилота.
 * Ограничители: CHAT_maxperrun оценок за прогон, без реплик менеджера или
 * клиента и без скрипта — без модели, разобранный чат второй раз не
 * оценивается (строка в таблице).
 *
 * Агент регистрирует «Проверить и включить» (Main\Setup::ensureAgent()),
 * интервал — как у анализа сделок (DEAL_interval); работает только при
 * CHAT_enabled = Y. Ручной прогон — cli/chat-assessment.php.
 */
final class ChatAssessmentAgent
{
	use FixUser;

	public const CATEGORY = 'chat';

	/** Во сколько раз больше кандидатов брать, чем оценивать: часть уйдёт в пропуск без модели. */
	private const SCAN_FACTOR = 3;

	/** Ошибок провайдера подряд, после которых прогон останавливается. */
	public const PROVIDER_ERRORS_MAX = 3;

	protected static function getInitedUserId(): int
	{
		return \Shef\Options\Main\Constants::getSystemUserId();
	}

	/** Точка входа агента. Возвращает свой вызов для следующего запуска. */
	public static function run(): string
	{
		$self = '\\'.static::class.'::run();';

		if(!Container::getConfig()->isChatAssessmentEnabled() || !static::includeModules())
		{
			return $self;
		}

		// До CHAT_maxperrun запросов к модели по несколько секунд: на хитах
		// агент упёрся бы в max_execution_time посреди оценки.
		if(function_exists('set_time_limit'))
		{
			@set_time_limit(0);
		}
		if(function_exists('ignore_user_abort'))
		{
			@ignore_user_abort(true);
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

	public static function includeModules(): bool
	{
		return Loader::includeModule('crm') && Loader::includeModule('im') && Loader::includeModule('imopenlines');
	}

	/**
	 * Выполнить под своей блокировкой и от служебного пользователя: ручной
	 * прогон рядом с агентом платил бы за те же чаты дважды.
	 *
	 * @param callable(): array $work
	 * @return array|null null — прогон уже идёт в другом процессе
	 */
	public static function runLocked(callable $work): ?array
	{
		$lock = new Pid(Constants::LOCK_GROUP_CHAT_ASSESSMENT);
		if(!$lock->add())
		{
			return null;
		}

		try
		{
			if(count(glob(Pid::getBasePath(Constants::LOCK_GROUP_CHAT_ASSESSMENT).'/*.lock') ?: []) > 1)
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
	 * @param int[]|null $onlyActivities только эти дела открытых линий (cli),
	 *                   без окна по дням и без отсева уже оценённых
	 * @return array<int, array{status: string, score: ?int, reason: string, owner: string, script: int}>
	 */
	public static function process(?array $onlyActivities = null): array
	{
		$config = Container::getConfig();
		ChatAssessmentTable::init();

		$scorer = new ChatScorer(Container::getLlm(Config::DIRECTION_TEXT));
		$meter = Container::getMeter();
		$ledger = new Ledger();
		$logger = Container::getLogger(static::class);

		$report = [];
		$assessed = 0;
		$providerErrors = 0;
		$max = $config->getChatMaxPerRun();

		foreach(DialogSource::getCandidates($config->getChatDays(), $max * self::SCAN_FACTOR, $onlyActivities) as $dialog)
		{
			if($assessed >= $max)
			{
				break;
			}
			if($providerErrors >= static::PROVIDER_ERRORS_MAX)
			{
				$logger?->warning('Оценка чатов остановлена: провайдер не отвечает '.$providerErrors.' раза подряд');
				break;
			}
			if($meter->getMonthly()->isExceeded())
			{
				$logger?->warning('Оценка чатов остановлена: месячная квота исчерпана');
				break;
			}

			$activityId = $dialog['ACTIVITY_ID'];
			$row = [
				'SESSION_ID' => $dialog['SESSION_ID'],
				'CHAT_ID' => $dialog['CHAT_ID'],
				'RESPONSIBLE_ID' => $dialog['RESPONSIBLE_ID'],
				'CREATED_AT' => new DateTime(),
			];

			// Без модели: чего-то не хватает — отметить, чтобы чат не
			// занимал выборку каждый прогон.
			try
			{
				$owner = DialogSource::getOwner($activityId);
				$transcript = Transcript::build(DialogSource::getMessages($dialog['SESSION_ID']));
				$script = DialogSource::pickScript($activityId, $dialog['DIRECTION'], $config->getChatScriptId());
				$skip = match(true)
				{
					$owner === null => 'нет сделки или лида',
					$transcript->getSkipReason() !== null => $transcript->getSkipReason(),
					$script === null => $config->getChatScriptId() > 0
						? 'скрипт #'.$config->getChatScriptId().' из настройки не найден'
						: 'скрипт не подобран: задайте «Скрипт для чатов» в настройках модуля',
					$script['criteria'] === [] => 'у скрипта «'.$script['title'].'» нет критериев: CRM ещё не выделила суть скрипта',
					default => null,
				};
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $activityId]);
				$skip = mb_substr('Ошибка: '.$throwable->getMessage(), 0, 500);
				$owner = null;
				$script = null;
				$transcript = null;
			}

			if($owner !== null)
			{
				[$row['OWNER_TYPE_ID'], $row['OWNER_ID']] = $owner;
			}
			if($script !== null)
			{
				$row['ASSESSMENT_ID'] = $script['id'];
			}

			if($skip !== null || $transcript === null || $script === null || $owner === null)
			{
				static::save($activityId, $row + ['STATUS' => ChatAssessmentTable::STATUS_SKIPPED, 'REASON' => $skip ?? 'нечего оценивать'], $logger);
				$report[$activityId] = static::reportRow(ChatAssessmentTable::STATUS_SKIPPED, null, (string)$skip, $owner, $script);
				continue;
			}

			// Журнал — до модели: сбой записи — не платим вслепую, прогон стоп.
			try
			{
				$ledgerId = $ledger->start(Constants::getEngineCode(static::CATEGORY), static::CATEGORY, $scorer->getLlmCode(), null, $scorer->estimateCostMicro($transcript->text, $script['criteria']));
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $activityId]);
				break;
			}

			$assessed++;
			try
			{
				$answer = $scorer->score($transcript->text, $script['criteria'], ['manager_name' => static::getUserName($dialog['RESPONSIBLE_ID'])]);
			}
			catch(ProviderException $exception)
			{
				$logger?->error($exception, ['itemId' => $activityId]);
				static::finish($ledger, $ledgerId, Status::ERROR, $exception->spentUnits, $exception->spentMicro, $exception->getMessage(), $logger);

				// Провайдер лежит — не свойство чата: строку не пишем, чат
				// снова кандидат в следующий прогон.
				if(in_array($exception->errorCode, DealHealthAgent::PROVIDER_DOWN_CODES, true))
				{
					$providerErrors++;
					$report[$activityId] = static::reportRow('', null, $exception->getMessage(), $owner, $script);
					continue;
				}

				// Модель падает на самом чате — каждый прогон за деньги. Отметить.
				$reason = mb_substr('Ошибка модели: '.$exception->getMessage(), 0, 500);
				static::save($activityId, $row + ['STATUS' => ChatAssessmentTable::STATUS_ERROR, 'REASON' => $reason], $logger);
				$report[$activityId] = static::reportRow(ChatAssessmentTable::STATUS_ERROR, null, $reason, $owner, $script);
				continue;
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $activityId]);
				static::finish($ledger, $ledgerId, Status::ERROR, 0, 0, $throwable->getMessage(), $logger);
				$reason = mb_substr('Ошибка: '.$throwable->getMessage(), 0, 500);
				static::save($activityId, $row + ['STATUS' => ChatAssessmentTable::STATUS_ERROR, 'REASON' => $reason], $logger);
				$report[$activityId] = static::reportRow(ChatAssessmentTable::STATUS_ERROR, null, $reason, $owner, $script);
				continue;
			}

			$providerErrors = 0;
			static::finish($ledger, $ledgerId, Status::SUCCESS, $answer['result']->getTokens(), $answer['result']->costMicro, null, $logger);

			$scoring = $answer['scoring'];
			$score = ChatScore::percent($scoring['call_review']['criteria']);
			$reason = '';
			try
			{
				if(!static::addComment($owner[0], $owner[1], ChatScore::formatComment($script['title'], $scoring)))
				{
					$reason = 'комментарий в таймлайн не записан';
				}
			}
			catch(\Throwable $throwable)
			{
				$logger?->error($throwable, ['itemId' => $activityId]);
				$reason = mb_substr('комментарий в таймлайн не записан: '.$throwable->getMessage(), 0, 500);
			}

			static::save($activityId, $row + [
				'STATUS' => ChatAssessmentTable::STATUS_DONE,
				'SCORE' => $score,
				'CRITERIA' => ChatScore::toStored($scoring),
				'SUMMARY' => $scoring['overall_summary'],
				'REASON' => $reason !== '' ? $reason : null,
			], $logger);
			$report[$activityId] = static::reportRow(ChatAssessmentTable::STATUS_DONE, $score, $reason, $owner, $script);
		}

		return $report;
	}

	private static function reportRow(string $status, ?int $score, string $reason, ?array $owner, ?array $script): array
	{
		return [
			'status' => $status,
			'score' => $score,
			'reason' => $reason,
			'owner' => $owner !== null ? ($owner[0] === DialogSource::OWNER_DEAL ? 'сделка ' : 'лид ').$owner[1] : '',
			'script' => (int)($script['id'] ?? 0),
		];
	}

	private static function save(int $activityId, array $fields, ?\Psr\Log\LoggerInterface $logger): void
	{
		try
		{
			ChatAssessmentTable::save($activityId, $fields);
		}
		catch(\Throwable $throwable)
		{
			$logger?->error($throwable, ['itemId' => $activityId]);
		}
	}

	private static function finish(Ledger $ledger, ?int $id, string $status, int $units, int $costMicro, ?string $error, ?\Psr\Log\LoggerInterface $logger): void
	{
		if($id === null)
		{
			return;
		}

		try
		{
			$ledger->finish($id, $status, $units, $costMicro, $error !== null ? mb_substr($error, 0, 500) : null);
		}
		catch(\Throwable $throwable)
		{
			$logger?->error($throwable, ['costMicro' => $costMicro]);
		}
	}

	/** Комментарий в таймлайн сделки или лида — как у эскалации (Deal\Escalation). */
	private static function addComment(int $ownerTypeId, int $ownerId, string $text): bool
	{
		if(!class_exists(\Bitrix\Crm\Timeline\CommentEntry::class))
		{
			return false;
		}

		$id = \Bitrix\Crm\Timeline\CommentEntry::create([
			'TEXT' => $text,
			'AUTHOR_ID' => Container::getAgentUserId() ?: 1,
			'BINDINGS' => [
				['ENTITY_TYPE_ID' => $ownerTypeId, 'ENTITY_ID' => $ownerId],
			],
		]);

		return (int)$id > 0;
	}

	/** Имя менеджера для справки модели: «Имя Фамилия», нет — пусто. */
	private static function getUserName(int $userId): string
	{
		if($userId <= 0)
		{
			return '';
		}

		$row = \Bitrix\Main\UserTable::getList([
			'select' => ['NAME', 'LAST_NAME'],
			'filter' => ['=ID' => $userId],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? trim($row['NAME'].' '.$row['LAST_NAME']) : '';
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Agent;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Shef\Options\Main\TempFile\Pid;
use Shef\Options\TraitList\Security\FixUser;
use Shef\ToolsAi\Chat\ChatScore;
use Shef\ToolsAi\Chat\DialogSource;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Deal\Model\DealProfileTable;
use Shef\ToolsAi\Deal\ProfilePicker;
use Shef\ToolsAi\Email\EmailAnalyzer;
use Shef\ToolsAi\Email\EmailComment;
use Shef\ToolsAi\Email\EmailPrompt;
use Shef\ToolsAi\Email\EmailSource;
use Shef\ToolsAi\Email\EmailText;
use Shef\ToolsAi\Email\EmailWriter;
use Shef\ToolsAi\Email\Model\EmailTable;
use Shef\ToolsAi\Email\ReplyClock;
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
 * Письма в CRM (1.7.0, bx-shef/toolsai#34, раздел «Письма»).
 *
 * Штатного ИИ для писем в CRM нет, поэтому свой — по образцу оценки чатов
 * (ChatAssessmentAgent). Четыре функции, у каждой свой флажок (вкладка
 * «Письма»):
 *
 * 1. резюме входящего письма клиента — комментарий в ленту сделки (лида);
 * 2. дела менеджеру по входящему письму — до 3, со сроком; спам, рассылки,
 *    автоответы, уведомления — без дел. 1 и 2 — один запрос к модели;
 * 3. оценка исходящего письма менеджера по критериям (с учётом предыдущего
 *    входящего в той же сделке) — комментарий в ленту, один запрос;
 * 4. контроль скорости ответа — без модели: клиент ждёт ответа дольше
 *    EMAIL_replyhours — дело менеджеру, дольше EMAIL_escalatehours — дело
 *    старшему; один раз (отметки в shef_toolsai_email).
 *
 * Расход — журнал (категория email) до запроса и после, под месячной
 * квотой. EMAIL_maxperrun — запросов к модели за прогон, входящие первыми.
 * Интервал — как у анализа сделок (DEAL_interval). Агента регистрирует
 * «Проверить и включить»; работает, если включена хоть одна функция. Ручной
 * прогон — cli/emails.php.
 */
final class EmailAgent
{
	use FixUser;

	public const CATEGORY = 'email';

	/** Во сколько раз больше кандидатов брать, чем разбирать: часть уйдёт в пропуск без модели. */
	private const SCAN_FACTOR = 3;

	/** Ошибок провайдера подряд, после которых прогон останавливается. */
	public const PROVIDER_ERRORS_MAX = 3;

	/** Писем под контролем скорости ответа за прогон, не больше: SQL дешёвый, модели нет. */
	public const WATCH_LIMIT = 500;

	/** Исходящее короче — оценивать нечего («Спасибо, получили»). */
	public const MIN_REVIEW_LENGTH = 30;

	/** Сроки дел о скорости ответа, часов: менеджеру и старшему. */
	public const REPLY_TODO_HOURS = 2;
	public const SENIOR_TODO_HOURS = 24;

	protected static function getInitedUserId(): int
	{
		return \Shef\Options\Main\Constants::getSystemUserId();
	}

	/** Точка входа агента. Возвращает свой вызов для следующего запуска. */
	public static function run(): string
	{
		$self = '\\'.static::class.'::run();';

		if(!Container::getConfig()->isEmailEnabled() || !static::includeModules())
		{
			return $self;
		}

		// До EMAIL_maxperrun запросов к модели по несколько секунд.
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
		return Loader::includeModule('crm');
	}

	/**
	 * Выполнить под своей блокировкой и от служебного пользователя: ручной
	 * прогон рядом с агентом платил бы за те же письма дважды и ставил бы
	 * дела дважды.
	 *
	 * @param callable(): array $work
	 * @return array|null null — прогон уже идёт в другом процессе
	 */
	public static function runLocked(callable $work): ?array
	{
		$lock = new Pid(Constants::LOCK_GROUP_EMAIL);
		if(!$lock->add())
		{
			return null;
		}

		try
		{
			if(count(glob(Pid::getBasePath(Constants::LOCK_GROUP_EMAIL).'/*.lock') ?: []) > 1)
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
	 * Один прогон. Звать через runLocked(). Делает только включённые функции.
	 *
	 * @param int[]|null $onlyActivities только эти письма (cli): без окна по
	 *                   дням и без отсева разобранных; контроль скорости — нет
	 * @return list<array{activity: int, kind: string, status: string, owner: string, info: string}>
	 */
	public static function process(?array $onlyActivities = null): array
	{
		$config = Container::getConfig();
		EmailTable::init();

		$run = [
			'config' => $config,
			'analyzer' => new EmailAnalyzer(Container::getLlm(Config::DIRECTION_TEXT)),
			'meter' => Container::getMeter(),
			'ledger' => new Ledger(),
			'logger' => Container::getLogger(static::class),
			'asked' => 0,
			'providerErrors' => 0,
			'stop' => false,
			'report' => [],
		];
		$max = $config->getEmailMaxPerRun();

		if($config->isEmailSummaryEnabled() || $config->isEmailTodosEnabled())
		{
			foreach(EmailSource::getCandidates(ReplyClock::DIRECTION_INCOMING, $config->getEmailDays(), $max * self::SCAN_FACTOR, $onlyActivities) as $activityId)
			{
				if(!static::canAsk($run, $max))
				{
					break;
				}
				static::incoming($activityId, $run);
			}
		}

		if($config->isEmailReviewEnabled())
		{
			foreach(EmailSource::getCandidates(ReplyClock::DIRECTION_OUTGOING, $config->getEmailDays(), $max * self::SCAN_FACTOR, $onlyActivities) as $activityId)
			{
				if(!static::canAsk($run, $max))
				{
					break;
				}
				static::review($activityId, $run);
			}
		}

		if($config->isEmailReplyControlEnabled() && $onlyActivities === null)
		{
			try
			{
				static::watch($run);
			}
			catch(\Throwable $throwable)
			{
				$run['logger']?->error($throwable);
				$run['report'][] = static::reportRow(0, 'reply', 'ERROR', null, 'контроль скорости ответа: '.$throwable->getMessage());
			}
		}

		return $run['report'];
	}

	/** Можно ли звать модель дальше: лимит прогона, провайдер, квота. */
	private static function canAsk(array &$run, int $max): bool
	{
		if($run['stop'] || $run['asked'] >= $max)
		{
			return false;
		}
		if($run['providerErrors'] >= static::PROVIDER_ERRORS_MAX)
		{
			$run['logger']?->warning('Разбор писем остановлен: провайдер не отвечает '.$run['providerErrors'].' раза подряд');
			$run['stop'] = true;

			return false;
		}
		if($run['meter']->getMonthly()->isExceeded())
		{
			$run['logger']?->warning('Разбор писем остановлен: месячная квота исчерпана');
			$run['stop'] = true;

			return false;
		}

		return true;
	}

	// region Входящее: резюме и дела ////
	private static function incoming(int $activityId, array &$run): void
	{
		/** @var Config $config */
		$config = $run['config'];
		$now = time();

		try
		{
			$mail = EmailSource::load($activityId);
			$owner = $mail !== null ? DialogSource::getOwner($activityId) : null;
			$text = $mail !== null ? EmailText::prepare($mail['body'], $mail['bodyType']) : '';
			$service = $mail !== null ? EmailText::serviceReason($mail['from'], $mail['subject']) : null;
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
			$run['report'][] = static::reportRow($activityId, 'incoming', 'ERROR', null, 'Ошибка: '.$throwable->getMessage());

			return;
		}

		if($mail === null || $mail['direction'] !== ReplyClock::DIRECTION_INCOMING)
		{
			$run['report'][] = static::reportRow($activityId, 'incoming', 'SKIPPED', $owner, $mail === null ? 'дела нет' : 'не входящее письмо');

			return;
		}

		$base = static::baseFields($mail, $owner, EmailTable::MODE_SUMMARY);
		$skip = match(true)
		{
			$owner === null => 'нет сделки или лида',
			$service !== null => $service,
			mb_strlen($text) < 3 => 'пустое письмо',
			default => null,
		};
		if($skip !== null)
		{
			static::save($activityId, [
				'MODE' => EmailTable::MODE_SUMMARY,
				'STATUS' => EmailTable::STATUS_SKIPPED,
				'REASON' => $skip,
				'ANALYZED_AT' => new DateTime(),
			] + ($service !== null ? ['IS_CLIENT' => 'N'] : []), $base, $run);
			$run['report'][] = static::reportRow($activityId, 'incoming', 'SKIPPED', $owner, $skip);

			return;
		}

		$messages = EmailPrompt::incomingMessages($mail['subject'], $text, [
			'manager_name' => EmailSource::getUserName($mail['responsibleId']),
			'mail_date' => date('d.m.Y H:i', $mail['mailAt']),
			'now' => date('Y-m-d\TH:i:s', $now),
		]);
		$answer = static::ask($activityId, 'incoming', $owner, $base, $messages, $run, static fn(EmailAnalyzer $analyzer): array => $analyzer->analyzeIncoming($messages, $now));
		if($answer === null)
		{
			return;
		}
		$answer = $answer['answer'];

		$reasons = [];
		$titles = [];
		if($answer['is_client'] === true)
		{
			$responsibleId = $mail['responsibleId'];
			if($config->isEmailTodosEnabled() && $answer['todos'] !== [])
			{
				try
				{
					$responsibleId = $responsibleId > 0 ? $responsibleId : (int)(EmailSource::getOwnerInfo($owner[0], $owner[1])['assignedById'] ?? 0);
					foreach($answer['todos'] as $todo)
					{
						if(EmailWriter::addTodo($owner[0], $owner[1], $responsibleId, $todo['title'], $todo['description'], $todo['deadline']))
						{
							$titles[] = $todo['title'];
						}
						else
						{
							$reasons[] = 'дело «'.$todo['title'].'» не поставлено';
						}
					}
				}
				catch(\Throwable $throwable)
				{
					$run['logger']?->error($throwable, ['itemId' => $activityId]);
					$reasons[] = 'дела не поставлены: '.$throwable->getMessage();
				}
			}

			if($config->isEmailSummaryEnabled())
			{
				try
				{
					if(!EmailWriter::addComment($owner[0], $owner[1], EmailComment::incoming($mail['subject'], $mail['mailAt'], $answer, $titles)))
					{
						$reasons[] = 'комментарий в ленту не записан';
					}
				}
				catch(\Throwable $throwable)
				{
					$run['logger']?->error($throwable, ['itemId' => $activityId]);
					$reasons[] = 'комментарий в ленту не записан: '.$throwable->getMessage();
				}
			}
		}

		$reason = $reasons !== [] ? mb_substr(implode('; ', $reasons), 0, 500) : null;
		static::save($activityId, [
			'MODE' => EmailTable::MODE_SUMMARY,
			'STATUS' => EmailTable::STATUS_DONE,
			'IS_CLIENT' => $answer['is_client'] ? 'Y' : 'N',
			'SUMMARY' => $answer['summary'],
			'RESULT' => (string)json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			'TODO_COUNT' => count($titles),
			'REASON' => $reason,
			'ANALYZED_AT' => new DateTime(),
		], $base, $run);
		$run['report'][] = static::reportRow(
			$activityId,
			'incoming',
			EmailTable::STATUS_DONE,
			$owner,
			($answer['is_client'] ? 'клиент, дел: '.count($titles) : 'не клиент ('.$answer['kind'].')').($reason !== null ? '; '.$reason : '')
		);
	}
	// endregion ////

	// region Исходящее: оценка ////
	private static function review(int $activityId, array &$run): void
	{
		/** @var Config $config */
		$config = $run['config'];

		try
		{
			$mail = EmailSource::load($activityId);
			$owner = $mail !== null ? DialogSource::getOwner($activityId) : null;
			$text = $mail !== null ? EmailText::prepare($mail['body'], $mail['bodyType']) : '';
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
			$run['report'][] = static::reportRow($activityId, 'review', 'ERROR', null, 'Ошибка: '.$throwable->getMessage());

			return;
		}

		if($mail === null || $mail['direction'] !== ReplyClock::DIRECTION_OUTGOING)
		{
			$run['report'][] = static::reportRow($activityId, 'review', 'SKIPPED', $owner, $mail === null ? 'дела нет' : 'не исходящее письмо');

			return;
		}

		$base = static::baseFields($mail, $owner, EmailTable::MODE_REVIEW);
		$skip = match(true)
		{
			$owner === null => 'нет сделки или лида',
			mb_strlen($text) < static::MIN_REVIEW_LENGTH => 'короткое письмо — оценивать нечего',
			default => null,
		};
		if($skip !== null)
		{
			static::save($activityId, ['MODE' => EmailTable::MODE_REVIEW, 'STATUS' => EmailTable::STATUS_SKIPPED, 'REASON' => $skip, 'ANALYZED_AT' => new DateTime()], $base, $run);
			$run['report'][] = static::reportRow($activityId, 'review', 'SKIPPED', $owner, $skip);

			return;
		}

		// Предыдущее письмо клиента в той же сделке: ответил ли менеджер на вопрос.
		$previous = '';
		try
		{
			$previousId = EmailSource::getPreviousIncoming($owner[0], $owner[1], $mail['at'], $activityId);
			$previousMail = $previousId !== null ? EmailSource::load($previousId) : null;
			if($previousMail !== null)
			{
				$previous = EmailText::prepare($previousMail['body'], $previousMail['bodyType'], 3000);
			}
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
		}

		$criteria = $config->getEmailCriteria();
		$messages = EmailPrompt::reviewMessages($mail['subject'], $text, $previous, $criteria, [
			'manager_name' => EmailSource::getUserName($mail['responsibleId']),
		]);
		$answer = static::ask($activityId, 'review', $owner, $base, $messages, $run, static fn(EmailAnalyzer $analyzer): array => $analyzer->review($messages, $criteria));
		if($answer === null)
		{
			return;
		}

		$scoring = $answer['scoring'];
		$score = ChatScore::percent($scoring['call_review']['criteria']);
		$reason = null;
		try
		{
			if(!EmailWriter::addComment($owner[0], $owner[1], EmailComment::review($mail['subject'], $scoring)))
			{
				$reason = 'комментарий в ленту не записан';
			}
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
			$reason = mb_substr('комментарий в ленту не записан: '.$throwable->getMessage(), 0, 500);
		}

		static::save($activityId, [
			'MODE' => EmailTable::MODE_REVIEW,
			'STATUS' => EmailTable::STATUS_DONE,
			'SCORE' => $score,
			'SUMMARY' => $scoring['overall_summary'],
			'RESULT' => EmailComment::toStoredReview($scoring),
			'REASON' => $reason,
			'ANALYZED_AT' => new DateTime(),
		], $base, $run);
		$run['report'][] = static::reportRow($activityId, 'review', EmailTable::STATUS_DONE, $owner, 'оценка '.($score !== null ? $score.'%' : 'без процента').($reason !== null ? '; '.$reason : ''));
	}
	// endregion ////

	// region Контроль скорости ответа ////
	/**
	 * Неотвеченные входящие письма — по сделкам (лидам): ответ нашёлся —
	 * отметить, сколько ждал клиент; нет — дело менеджеру и старшему по
	 * порогам, один раз на сделку (ReplyClock::decide()).
	 */
	private static function watch(array &$run): void
	{
		/** @var Config $config */
		$config = $run['config'];
		$now = time();
		$border = $now - max($config->getEmailDays() * 86400, ($config->getEmailEscalateHours() + 24) * 3600);

		$items = EmailSource::getWatchCandidates($border, static::WATCH_LIMIT);

		// Служебные письма (робот, автоответ) моделью могли не разбираться —
		// отсеять по адресу и теме, без модели.
		$senders = static::getSenders(array_map(
			static fn(array $item): int => $item['id'],
			array_filter($items, static fn(array $item): bool => $item['isClient'] === null)
		));

		$groups = [];
		foreach($items as $item)
		{
			$owner = DialogSource::getOwner($item['id']);
			if($owner === null)
			{
				continue;
			}
			if($item['isClient'] === null && EmailText::serviceReason($senders[$item['id']] ?? '', $item['subject']) !== null)
			{
				static::save($item['id'], ['IS_CLIENT' => 'N'], static::watchBase($item, $owner), $run);
				continue;
			}
			$groups[$owner[0].':'.$owner[1]]['owner'] = $owner;
			$groups[$owner[0].':'.$owner[1]]['items'][] = $item;
		}

		$profiles = null;
		foreach($groups as $group)
		{
			$owner = $group['owner'];
			$since = min(array_map(static fn(array $item): int => $item['at'], $group['items']));
			$replies = EmailSource::getReplyTimes($owner[0], $owner[1], $since);

			$waiting = [];
			foreach($group['items'] as $item)
			{
				$reply = ReplyClock::firstReplyAfter($item['at'], $replies);
				if($reply !== null)
				{
					static::save($item['id'], [
						'REPLIED_AT' => DateTime::createFromTimestamp($reply),
						'REPLY_SECONDS' => $reply - $item['at'],
					], static::watchBase($item, $owner), $run);
					continue;
				}
				$waiting[] = $item;
			}

			$decision = ReplyClock::decide($waiting, $now, $config->getEmailReplyHours(), $config->getEmailEscalateHours());
			if($decision === null || (!$decision['manager'] && !$decision['senior']))
			{
				continue;
			}

			$oldest = $waiting[0];
			foreach($waiting as $item)
			{
				if($item['at'] < $oldest['at'])
				{
					$oldest = $item;
				}
			}

			$info = EmailSource::getOwnerInfo($owner[0], $owner[1]);
			$managerId = $oldest['responsibleId'] > 0 ? $oldest['responsibleId'] : (int)($info['assignedById'] ?? 0);
			$mark = [];
			$done = [];

			if($decision['manager'])
			{
				$todo = EmailComment::managerTodo($oldest['subject'], $oldest['at'], $now);
				if(EmailWriter::addTodo($owner[0], $owner[1], $managerId, $todo['subject'], $todo['description'], $now + static::REPLY_TODO_HOURS * 3600))
				{
					$mark['REPLY_TODO_AT'] = new DateTime();
					$done[] = 'дело менеджеру';
				}
				else
				{
					$done[] = 'дело менеджеру не поставлено';
				}
			}

			if($decision['senior'])
			{
				$seniorId = static::pickSenior($owner, $info, $config, $profiles);
				if($seniorId <= 0)
				{
					$done[] = 'старший не задан — дела нет';
				}
				else
				{
					$todo = EmailComment::seniorTodo(EmailSource::getUserName($managerId), $oldest['subject'], $oldest['at'], $now, (string)($info['title'] ?? ''));
					if(EmailWriter::addTodo($owner[0], $owner[1], $seniorId, $todo['subject'], $todo['description'], $now + static::SENIOR_TODO_HOURS * 3600))
					{
						$mark['SENIOR_TODO_AT'] = new DateTime();
						$done[] = 'дело старшему';
					}
					else
					{
						$done[] = 'дело старшему не поставлено';
					}
				}
			}

			// Отметка — на все ждущие письма сделки: следующее письмо клиента
			// до ответа дело не повторит.
			if($mark !== [])
			{
				foreach($waiting as $item)
				{
					static::save($item['id'], $mark, static::watchBase($item, $owner), $run);
				}
			}

			$run['report'][] = static::reportRow($oldest['id'], 'reply', 'DONE', $owner, 'ждёт ответа '.$decision['hours'].' ч: '.implode(', ', $done));
		}
	}

	/**
	 * Старший для сделки: из профиля анализа сделок по направлению и типу
	 * клиента (ProfilePicker), иначе — настройка EMAIL_senior. Для лида —
	 * сразу настройка.
	 *
	 * @param \Shef\ToolsAi\Deal\Profile[]|null $profiles кеш профилей на прогон
	 */
	private static function pickSenior(array $owner, ?array $info, Config $config, ?array &$profiles): int
	{
		if($owner[0] === DialogSource::OWNER_DEAL && $info !== null)
		{
			try
			{
				$profiles ??= DealProfileTable::getProfiles(true);
			}
			catch(\Throwable)
			{
				$profiles = [];
			}
			$profile = ProfilePicker::pick($profiles, $info['categoryId'], $info['clientType']);
			if($profile !== null && $profile->seniorId > 0)
			{
				return $profile->seniorId;
			}
		}

		return $config->getEmailSeniorId();
	}

	/**
	 * Отправители писем: SETTINGS.EMAIL_META.from.
	 *
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	private static function getSenders(array $ids): array
	{
		if($ids === [])
		{
			return [];
		}

		$result = [];
		foreach(\Bitrix\Crm\ActivityTable::getList([
			'select' => ['ID', 'SETTINGS'],
			'filter' => ['@ID' => array_values($ids)],
		])->fetchAll() as $row)
		{
			$from = is_array($row['SETTINGS'] ?? null) ? ($row['SETTINGS']['EMAIL_META']['from'] ?? '') : '';
			$result[(int)$row['ID']] = is_scalar($from) ? (string)$from : '';
		}

		return $result;
	}

	private static function watchBase(array $item, array $owner): array
	{
		return [
			'OWNER_TYPE_ID' => $owner[0],
			'OWNER_ID' => $owner[1],
			'DIRECTION' => ReplyClock::DIRECTION_INCOMING,
			'RESPONSIBLE_ID' => $item['responsibleId'],
			'MAIL_AT' => DateTime::createFromTimestamp($item['at']),
			'MODE' => EmailTable::MODE_WATCH,
		];
	}
	// endregion ////

	/**
	 * Запрос к модели с журналом расхода: запись «в работе» с оценкой — до,
	 * итог — после. Сбой провайдера — строку не пишем (письмо снова
	 * кандидат), негодный ответ — ERROR в таблицу (оплачено).
	 *
	 * @param callable(EmailAnalyzer): array $call
	 */
	private static function ask(int $activityId, string $kind, array $owner, array $base, array $messages, array &$run, callable $call): ?array
	{
		/** @var EmailAnalyzer $analyzer */
		$analyzer = $run['analyzer'];
		/** @var Ledger $ledger */
		$ledger = $run['ledger'];
		$mode = $kind === 'review' ? EmailTable::MODE_REVIEW : EmailTable::MODE_SUMMARY;

		// Журнал — до модели: сбой записи — не платим вслепую, прогон стоп.
		try
		{
			$ledgerId = $ledger->start(Constants::getEngineCode(static::CATEGORY), static::CATEGORY, $analyzer->getLlmCode(), null, $analyzer->estimateCostMicro($messages));
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
			$run['stop'] = true;

			return null;
		}

		$run['asked']++;
		try
		{
			$answer = $call($analyzer);
		}
		catch(ProviderException $exception)
		{
			$run['logger']?->error($exception, ['itemId' => $activityId]);
			static::finish($ledgerId, Status::ERROR, $exception->spentUnits, $exception->spentMicro, $exception->getMessage(), $run);

			if(in_array($exception->errorCode, DealHealthAgent::PROVIDER_DOWN_CODES, true))
			{
				$run['providerErrors']++;
				$run['report'][] = static::reportRow($activityId, $kind, '', $owner, $exception->getMessage());

				return null;
			}

			$reason = mb_substr('Ошибка модели: '.$exception->getMessage(), 0, 500);
			static::save($activityId, ['MODE' => $mode, 'STATUS' => EmailTable::STATUS_ERROR, 'REASON' => $reason, 'ANALYZED_AT' => new DateTime()], $base, $run);
			$run['report'][] = static::reportRow($activityId, $kind, EmailTable::STATUS_ERROR, $owner, $reason);

			return null;
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
			static::finish($ledgerId, Status::ERROR, 0, 0, $throwable->getMessage(), $run);
			$reason = mb_substr('Ошибка: '.$throwable->getMessage(), 0, 500);
			static::save($activityId, ['MODE' => $mode, 'STATUS' => EmailTable::STATUS_ERROR, 'REASON' => $reason, 'ANALYZED_AT' => new DateTime()], $base, $run);
			$run['report'][] = static::reportRow($activityId, $kind, EmailTable::STATUS_ERROR, $owner, $reason);

			return null;
		}

		$run['providerErrors'] = 0;
		static::finish($ledgerId, Status::SUCCESS, $answer['result']->getTokens(), $answer['result']->costMicro, null, $run);

		return $answer;
	}

	private static function baseFields(array $mail, ?array $owner, string $mode): array
	{
		return [
			'OWNER_TYPE_ID' => $owner[0] ?? 0,
			'OWNER_ID' => $owner[1] ?? 0,
			'DIRECTION' => $mail['direction'],
			'RESPONSIBLE_ID' => $mail['responsibleId'],
			'MAIL_AT' => DateTime::createFromTimestamp($mail['at']),
			'MODE' => $mode,
		];
	}

	private static function reportRow(int $activityId, string $kind, string $status, ?array $owner, string $info): array
	{
		return [
			'activity' => $activityId,
			'kind' => $kind,
			'status' => $status,
			'owner' => $owner !== null ? ($owner[0] === DialogSource::OWNER_DEAL ? 'сделка ' : 'лид ').$owner[1] : '',
			'info' => $info,
		];
	}

	private static function save(int $activityId, array $fields, array $base, array &$run): void
	{
		try
		{
			EmailTable::save($activityId, $fields, $base);
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['itemId' => $activityId]);
		}
	}

	private static function finish(?int $id, string $status, int $units, int $costMicro, ?string $error, array &$run): void
	{
		if($id === null)
		{
			return;
		}

		try
		{
			$run['ledger']->finish($id, $status, $units, $costMicro, $error !== null ? mb_substr($error, 0, 500) : null);
		}
		catch(\Throwable $throwable)
		{
			$run['logger']?->error($throwable, ['costMicro' => $costMicro]);
		}
	}
}

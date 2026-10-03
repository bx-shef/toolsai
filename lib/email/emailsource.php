<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Shef\ToolsAi\Chat\DialogSource;
use Shef\ToolsAi\Email\Model\EmailTable;

/**
 * Письма CRM из базы портала: кандидаты, тело, соседние письма и звонки
 * сделки. Только чтение.
 *
 * Как CRM хранит письмо (crm 26.800, Activity\Provider\Email,
 * classes/general/crm_email.php:1458-1497):
 *
 * * дело b_crm_act: TYPE_ID = 4 (\CCrmActivityType::Email), PROVIDER_ID =
 *   CRM_EMAIL, DIRECTION 1 — входящее, 2 — исходящее; SUBJECT — тема;
 *   RESPONSIBLE_ID — ответственный; START_TIME — дата письма, CREATED —
 *   когда появилось в CRM; SETTINGS.EMAIL_META.from — отправитель;
 *   привязки к сделке/лиду/контакту — b_crm_act_bind;
 * * тело — HTML (DESCRIPTION_TYPE = 3). С PROVIDER_TYPE_ID =
 *   EMAIL_COMPRESSED (все новые письма: CCrmActivity::Add() зовёт
 *   Email::compressActivityDescription()) в DESCRIPTION — только начало
 *   текста, до 200 символов, без разметки, а целиком тело лежит в
 *   b_crm_act_mail_body (MailBodyTable, BODY сжат, ORM разжимает) по
 *   привязке b_crm_act_mail_body_bind (OWNER_TYPE_ID = \CCrmOwnerType::
 *   Activity, OWNER_ID = ID дела), а пока идёт перенос старых писем
 *   (crm::compress_mail_act_stepper_in_progress = Y) — по ID в
 *   ASSOCIATED_ENTITY_ID.
 *
 * Штатный Email::uncompressActivityDescription() не зовём: тела нет — он
 * перекачивает письмо с почтового сервера (Helper\Message::reSyncBody()) и
 * пишет в дело. Агенту это ни к чему: тела нет — берём начало текста из
 * DESCRIPTION.
 */
final class EmailSource
{
	public const PROVIDER_ID = 'CRM_EMAIL';
	public const PROVIDER_TYPE_COMPRESSED = 'EMAIL_COMPRESSED';
	/** \CCrmOwnerType::Activity — литералом: так его пишет и привязка тела. */
	public const OWNER_ACTIVITY = 6;
	/** crm CompressMailStepper::COMPRESS_IN_PROGRESS_OPTION_NAME. */
	private const COMPRESS_IN_PROGRESS = 'compress_mail_act_stepper_in_progress';

	/**
	 * Письма направления, привязанные к сделке или лиду, за последние $days
	 * дней (и по CREATED, и по дате письма: при подключении ящика CRM
	 * заводит старые письма с CREATED «сейчас»), моделью ещё не разобранные.
	 * Сначала давние: окно сдвигается.
	 *
	 * @param int[]|null $only только эти дела (cli), без окна и отсева
	 * @return list<int> ID дел
	 */
	public static function getCandidates(int $direction, int $days, int $limit, ?array $only = null): array
	{
		$connection = Application::getConnection();
		$border = static::toDb(time() - $days * 86400);

		$where = $only !== null
			? 'A.ID IN ('.implode(',', array_map('intval', $only ?: [0])).')'
			: 'A.CREATED >= '.$border.' AND A.START_TIME >= '.$border.' AND (E.ID IS NULL OR E.STATUS = \'\')';

		$rows = $connection->query(sprintf(
			'SELECT A.ID
			FROM %s A
			LEFT JOIN %s E ON E.ACTIVITY_ID = A.ID
			WHERE A.TYPE_ID = %d AND A.PROVIDER_ID = \'%s\' AND A.DIRECTION = %d
				AND %s
				AND EXISTS (SELECT 1 FROM %s B WHERE B.ACTIVITY_ID = A.ID AND B.OWNER_TYPE_ID IN (%d, %d))
			ORDER BY A.CREATED ASC, A.ID ASC
			LIMIT %d',
			ActivityTable::getTableName(),
			EmailTable::getTableName(),
			ReplyClock::TYPE_EMAIL,
			self::PROVIDER_ID,
			$direction,
			$where,
			ActivityBindingTable::getTableName(),
			DialogSource::OWNER_DEAL,
			DialogSource::OWNER_LEAD,
			max(1, $limit)
		))->fetchAll();

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Входящие письма под контролем скорости ответа: за окно, привязаны к
	 * сделке или лиду, ответа ещё не нашли, не помечены «не клиент».
	 * Отметки о делах — чтобы не повторять.
	 *
	 * @return list<array{id: int, at: int, responsibleId: int, subject: string, hasRow: bool, isClient: ?string, managerNotified: bool, seniorNotified: bool}>
	 */
	public static function getWatchCandidates(int $borderTs, int $limit): array
	{
		$connection = Application::getConnection();
		$border = static::toDb($borderTs);

		$rows = $connection->query(sprintf(
			'SELECT A.ID, A.CREATED, A.RESPONSIBLE_ID, A.SUBJECT, E.ID AS ROW_ID, E.IS_CLIENT, E.REPLY_TODO_AT, E.SENIOR_TODO_AT
			FROM %s A
			LEFT JOIN %s E ON E.ACTIVITY_ID = A.ID
			WHERE A.TYPE_ID = %d AND A.PROVIDER_ID = \'%s\' AND A.DIRECTION = %d
				AND A.CREATED >= %s AND A.START_TIME >= %s
				AND (E.ID IS NULL OR (E.REPLIED_AT IS NULL AND (E.IS_CLIENT IS NULL OR E.IS_CLIENT <> \'N\')))
				AND EXISTS (SELECT 1 FROM %s B WHERE B.ACTIVITY_ID = A.ID AND B.OWNER_TYPE_ID IN (%d, %d))
			ORDER BY A.CREATED ASC, A.ID ASC
			LIMIT %d',
			ActivityTable::getTableName(),
			EmailTable::getTableName(),
			ReplyClock::TYPE_EMAIL,
			self::PROVIDER_ID,
			ReplyClock::DIRECTION_INCOMING,
			$border,
			$border,
			ActivityBindingTable::getTableName(),
			DialogSource::OWNER_DEAL,
			DialogSource::OWNER_LEAD,
			max(1, $limit)
		))->fetchAll();

		return array_map(static fn(array $row): array => [
			'id' => (int)$row['ID'],
			'at' => static::toTs($row['CREATED']) ?? 0,
			'responsibleId' => (int)$row['RESPONSIBLE_ID'],
			'subject' => (string)$row['SUBJECT'],
			'hasRow' => $row['ROW_ID'] !== null,
			'isClient' => $row['IS_CLIENT'] !== null ? (string)$row['IS_CLIENT'] : null,
			'managerNotified' => static::toTs($row['REPLY_TODO_AT']) !== null,
			'seniorNotified' => static::toTs($row['SENIOR_TODO_AT']) !== null,
		], $rows);
	}

	/**
	 * Письмо целиком: поля дела и тело (разжатое, если сжато).
	 *
	 * @return array{id: int, subject: string, body: string, bodyType: int, from: string, at: int, mailAt: int, responsibleId: int, authorId: int, direction: int}|null
	 */
	public static function load(int $activityId): ?array
	{
		$row = ActivityTable::getList([
			'select' => ['ID', 'SUBJECT', 'DESCRIPTION', 'DESCRIPTION_TYPE', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SETTINGS', 'CREATED', 'START_TIME', 'RESPONSIBLE_ID', 'AUTHOR_ID', 'DIRECTION'],
			'filter' => ['=ID' => $activityId],
			'limit' => 1,
		])->fetch();
		if(!is_array($row))
		{
			return null;
		}

		[$body, $bodyType] = static::getBody($row);
		$settings = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
		$from = $settings['EMAIL_META']['from'] ?? '';

		return [
			'id' => (int)$row['ID'],
			'subject' => (string)$row['SUBJECT'],
			'body' => $body,
			'bodyType' => $bodyType,
			'from' => is_scalar($from) ? (string)$from : '',
			'at' => static::toTs($row['CREATED']) ?? time(),
			'mailAt' => static::toTs($row['START_TIME']) ?? static::toTs($row['CREATED']) ?? time(),
			'responsibleId' => (int)$row['RESPONSIBLE_ID'],
			'authorId' => (int)$row['AUTHOR_ID'],
			'direction' => (int)$row['DIRECTION'],
		];
	}

	/**
	 * Тело письма и его тип (EmailText::TYPE_*). Сжатое — из
	 * b_crm_act_mail_body; не нашлось — начало текста из DESCRIPTION.
	 *
	 * @return array{0: string, 1: int}
	 */
	public static function getBody(array $activity): array
	{
		$description = (string)($activity['DESCRIPTION'] ?? '');
		$type = (int)($activity['DESCRIPTION_TYPE'] ?? EmailText::TYPE_PLAIN);
		if(($activity['PROVIDER_TYPE_ID'] ?? '') !== self::PROVIDER_TYPE_COMPRESSED)
		{
			return [$description, $type];
		}

		$bodyId = 0;
		if((int)($activity['ASSOCIATED_ENTITY_ID'] ?? 0) > 0 && Option::get('crm', self::COMPRESS_IN_PROGRESS, 'N') === 'Y')
		{
			$bodyId = (int)$activity['ASSOCIATED_ENTITY_ID'];
		}
		else
		{
			$bind = \Bitrix\Crm\Activity\Entity\ActMailBodyBindTable::getList([
				'select' => ['BODY_ID'],
				'filter' => ['=OWNER_TYPE_ID' => self::OWNER_ACTIVITY, '=OWNER_ID' => (int)$activity['ID']],
				'limit' => 1,
			])->fetch();
			$bodyId = is_array($bind) ? (int)$bind['BODY_ID'] : 0;
		}

		if($bodyId > 0)
		{
			$body = \Bitrix\Crm\Activity\MailBodyTable::getById($bodyId)->fetch();
			if(is_array($body) && is_string($body['BODY'] ?? null) && trim($body['BODY']) !== '')
			{
				// Тело в b_crm_act_mail_body — всегда HTML (MailActivityDescriptionFactory).
				return [$body['BODY'], EmailText::TYPE_HTML];
			}
		}

		// Превью сжатого письма — текст без разметки (makeDescriptionPreview()).
		return [$description, EmailText::TYPE_PLAIN];
	}

	/** Последнее входящее письмо той же сделки (лида) до $beforeTs; нет — null. */
	public static function getPreviousIncoming(int $ownerTypeId, int $ownerId, int $beforeTs, int $excludeId): ?int
	{
		$row = Application::getConnection()->query(sprintf(
			'SELECT A.ID FROM %s A
			INNER JOIN %s B ON B.ACTIVITY_ID = A.ID
			WHERE B.OWNER_TYPE_ID = %d AND B.OWNER_ID = %d
				AND A.TYPE_ID = %d AND A.PROVIDER_ID = \'%s\' AND A.DIRECTION = %d
				AND A.CREATED <= %s AND A.ID <> %d
			ORDER BY A.CREATED DESC, A.ID DESC
			LIMIT 1',
			ActivityTable::getTableName(),
			ActivityBindingTable::getTableName(),
			$ownerTypeId,
			$ownerId,
			ReplyClock::TYPE_EMAIL,
			self::PROVIDER_ID,
			ReplyClock::DIRECTION_INCOMING,
			static::toDb($beforeTs),
			$excludeId
		))->fetch();

		return is_array($row) ? (int)$row['ID'] : null;
	}

	/**
	 * Время ответов по сделке (лиду) с $sinceTs: исходящие письма и исходящие
	 * звонки, CREATED.
	 *
	 * @return list<int>
	 */
	public static function getReplyTimes(int $ownerTypeId, int $ownerId, int $sinceTs): array
	{
		$rows = Application::getConnection()->query(sprintf(
			'SELECT A.CREATED FROM %s A
			INNER JOIN %s B ON B.ACTIVITY_ID = A.ID
			WHERE B.OWNER_TYPE_ID = %d AND B.OWNER_ID = %d
				AND A.DIRECTION = %d AND A.TYPE_ID IN (%d, %d)
				AND A.CREATED >= %s',
			ActivityTable::getTableName(),
			ActivityBindingTable::getTableName(),
			$ownerTypeId,
			$ownerId,
			ReplyClock::DIRECTION_OUTGOING,
			ReplyClock::TYPE_EMAIL,
			ReplyClock::TYPE_CALL,
			static::toDb($sinceTs)
		))->fetchAll();

		return array_values(array_filter(array_map(static fn(array $row): ?int => static::toTs($row['CREATED']), $rows), static fn(?int $time): bool => $time !== null));
	}

	/**
	 * Сделка или лид: ответственный, направление, название, тип клиента.
	 *
	 * @return array{assignedById: int, categoryId: int, title: string, clientType: ?int}|null
	 */
	public static function getOwnerInfo(int $ownerTypeId, int $ownerId): ?array
	{
		$item = \Bitrix\Crm\Service\Container::getInstance()->getFactory($ownerTypeId)?->getItem($ownerId);
		if($item === null)
		{
			return null;
		}

		$isDeal = $ownerTypeId === DialogSource::OWNER_DEAL;

		return [
			'assignedById' => (int)$item->getAssignedById(),
			'categoryId' => $isDeal ? (int)$item->getCategoryId() : 0,
			'title' => ($isDeal ? 'сделка' : 'лид').' «'.$item->getTitle().'»',
			'clientType' => $isDeal ? \Shef\ToolsAi\Deal\ContextBuilder::getClientType($item) : null,
		];
	}

	/** Имя сотрудника: «Имя Фамилия», нет — логин, нет — пусто. */
	public static function getUserName(int $userId): string
	{
		if($userId <= 0)
		{
			return '';
		}

		$row = UserTable::getList([
			'select' => ['NAME', 'LAST_NAME', 'LOGIN'],
			'filter' => ['=ID' => $userId],
			'limit' => 1,
		])->fetch();
		if(!is_array($row))
		{
			return '';
		}
		$name = trim($row['NAME'].' '.$row['LAST_NAME']);

		return $name !== '' ? $name : (string)$row['LOGIN'];
	}

	/**
	 * Резюме последних входящих писем сделки, которые разобрал модуль, — для
	 * сводки анализа сделки. Таблицы нет — пусто.
	 *
	 * @param int[] $activityIds письма сделки, от свежего к старому
	 * @return list<string> EmailComment::dealNote()
	 */
	public static function getDealNotes(array $activityIds, int $limit): array
	{
		$connection = Application::getConnection();
		if($activityIds === [] || !$connection->isTableExists(EmailTable::getTableName()))
		{
			return [];
		}

		$rows = $connection->query(sprintf(
			'SELECT ACTIVITY_ID, MAIL_AT, SUMMARY FROM %s
			WHERE ACTIVITY_ID IN (%s) AND MODE = \'%s\' AND STATUS = \'%s\' AND IS_CLIENT = \'Y\' AND SUMMARY IS NOT NULL AND SUMMARY <> \'\'
			ORDER BY MAIL_AT DESC, ACTIVITY_ID DESC
			LIMIT %d',
			EmailTable::getTableName(),
			implode(',', array_map('intval', $activityIds)),
			EmailTable::MODE_SUMMARY,
			EmailTable::STATUS_DONE,
			max(1, $limit)
		))->fetchAll();

		return array_map(static fn(array $row): string => EmailComment::dealNote(static::toTs($row['MAIL_AT']) ?? time(), (string)$row['SUMMARY']), $rows);
	}

	/**
	 * Письма из списка, которые модуль признал не клиентскими (спам,
	 * автоответ, уведомление). Таблицы нет — пусто.
	 *
	 * @param int[] $activityIds
	 * @return list<int>
	 */
	public static function getNotClientIds(array $activityIds): array
	{
		$connection = Application::getConnection();
		if($activityIds === [] || !$connection->isTableExists(EmailTable::getTableName()))
		{
			return [];
		}

		return array_map(static fn(array $row): int => (int)$row['ACTIVITY_ID'], $connection->query(sprintf(
			'SELECT ACTIVITY_ID FROM %s WHERE ACTIVITY_ID IN (%s) AND IS_CLIENT = \'N\'',
			EmailTable::getTableName(),
			implode(',', array_map('intval', $activityIds))
		))->fetchAll());
	}

	public static function toTs(mixed $value): ?int
	{
		if($value instanceof \Bitrix\Main\Type\Date)
		{
			return $value->getTimestamp();
		}
		if(is_string($value) && $value !== '')
		{
			$time = strtotime($value);

			return $time !== false ? $time : null;
		}

		return null;
	}

	private static function toDb(int $timestamp): string
	{
		return Application::getConnection()->getSqlHelper()->convertToDbDateTime(DateTime::createFromTimestamp($timestamp));
	}
}

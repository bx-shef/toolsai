<?php declare(strict_types=1);

namespace Shef\ToolsAi\Chat;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Shef\ToolsAi\Chat\Model\ChatAssessmentTable;

/**
 * Диалоги открытых линий из базы портала: кандидаты на оценку, текст
 * переписки, скрипт оценки.
 *
 * Связь дела CRM с диалогом — imopenlines/lib/crm/activity.php: дело
 * PROVIDER_ID = IMOPENLINES_SESSION, ASSOCIATED_ENTITY_ID = ID сессии,
 * ORIGIN_ID = «IMOL_<ID сессии>». Сообщения сессии — b_im_message чата
 * сессии от START_ID до END_ID (END_ID = 0 — сессия не закрыта), как их
 * выбирает CRM (Integration\OpenLineManager::getSessionMessages()). Готовый
 * метод CRM не берём: он соединяет параметры FILE_ID и ATTACH через LEFT
 * JOIN, и сообщение с двумя файлами приходит дважды, а дат и признака
 * автоответа в нём нет.
 *
 * Системные сообщения (AUTHOR_ID = 0, NOTIFY_EVENT = private_system) — прочь.
 * Чистая часть (pickOwner()) — без ядра.
 */
final class DialogSource
{
	public const PROVIDER_ID = 'IMOPENLINES_SESSION';

	/** CCrmOwnerType::Lead и ::Deal — литералами, чтобы pickOwner() жил без ядра. */
	public const OWNER_LEAD = 1;
	public const OWNER_DEAL = 2;

	/** Потолок сообщений одного диалога: дальше текст всё равно режется. */
	public const MAX_MESSAGES = 400;

	/**
	 * Закрытые за последние $days дней диалоги, привязанные к сделке или
	 * лиду, без оценки модуля. Сначала давние: окно сдвигается, и чат не
	 * должен выпасть из него неоценённым. Спам (SPAM = Y) — нет.
	 *
	 * @return list<array{ACTIVITY_ID: int, SESSION_ID: int, CHAT_ID: int, RESPONSIBLE_ID: int, DIRECTION: int}>
	 */
	public static function getCandidates(int $days, int $limit, ?array $onlyActivities = null): array
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$where = $onlyActivities !== null
			? 'A.ID IN ('.implode(',', array_map('intval', $onlyActivities ?: [0])).')'
			: 'S.CLOSED = \'Y\' AND S.DATE_CLOSE >= '.$helper->convertToDbDateTime(DateTime::createFromTimestamp(time() - $days * 86400)).'
				AND (S.SPAM IS NULL OR S.SPAM <> \'Y\')
				AND C.ID IS NULL';

		$rows = $connection->query(sprintf(
			'SELECT A.ID AS ACTIVITY_ID, S.ID AS SESSION_ID, S.CHAT_ID, A.RESPONSIBLE_ID, A.DIRECTION
			FROM %s A
			INNER JOIN %s S ON S.ID = A.ASSOCIATED_ENTITY_ID
			LEFT JOIN %s C ON C.ACTIVITY_ID = A.ID
			WHERE A.PROVIDER_ID = \'%s\'
				AND %s
				AND EXISTS (SELECT 1 FROM %s B WHERE B.ACTIVITY_ID = A.ID AND B.OWNER_TYPE_ID IN (%d, %d))
			ORDER BY S.DATE_CLOSE ASC, A.ID ASC
			LIMIT %d',
			ActivityTable::getTableName(),
			\Bitrix\ImOpenLines\Model\SessionTable::getTableName(),
			ChatAssessmentTable::getTableName(),
			self::PROVIDER_ID,
			$where,
			ActivityBindingTable::getTableName(),
			self::OWNER_DEAL,
			self::OWNER_LEAD,
			max(1, $limit)
		))->fetchAll();

		return array_map(static fn(array $row): array => [
			'ACTIVITY_ID' => (int)$row['ACTIVITY_ID'],
			'SESSION_ID' => (int)$row['SESSION_ID'],
			'CHAT_ID' => (int)$row['CHAT_ID'],
			'RESPONSIBLE_ID' => (int)$row['RESPONSIBLE_ID'],
			'DIRECTION' => (int)$row['DIRECTION'],
		], $rows);
	}

	/**
	 * Сделка или лид дела: сделка важнее лида, из нескольких — самая новая
	 * (больший ID). Чистая функция.
	 *
	 * @param list<array{OWNER_TYPE_ID: int|string, OWNER_ID: int|string}> $bindings
	 * @return array{0: int, 1: int}|null [тип, ID]
	 */
	public static function pickOwner(array $bindings): ?array
	{
		$best = null;
		foreach($bindings as $binding)
		{
			$type = (int)$binding['OWNER_TYPE_ID'];
			$id = (int)$binding['OWNER_ID'];
			if($id <= 0 || !in_array($type, [self::OWNER_DEAL, self::OWNER_LEAD], true))
			{
				continue;
			}
			$rank = [$type === self::OWNER_DEAL ? 1 : 0, $id];
			if($best === null || $rank > $best[0])
			{
				$best = [$rank, [$type, $id]];
			}
		}

		return $best[1] ?? null;
	}

	/** @return array{0: int, 1: int}|null */
	public static function getOwner(int $activityId): ?array
	{
		return static::pickOwner(ActivityBindingTable::getList([
			'select' => ['OWNER_TYPE_ID', 'OWNER_ID'],
			'filter' => ['=ACTIVITY_ID' => $activityId],
		])->fetchAll());
	}

	/**
	 * Сообщения сессии для Transcript::build(): роль, имя, время, текст,
	 * файлы.
	 *
	 * @return list<array{role: string, author: string, time: string, text: string, files: int, attach: bool}>
	 */
	public static function getMessages(int $sessionId): array
	{
		$connection = Application::getConnection();
		$rows = $connection->query(sprintf(
			'SELECT M.ID, M.AUTHOR_ID, M.DATE_CREATE, M.MESSAGE
			FROM b_im_message M
			INNER JOIN %s S ON S.CHAT_ID = M.CHAT_ID
			WHERE S.ID = %d
				AND M.ID >= S.START_ID AND (S.END_ID = 0 OR M.ID <= S.END_ID)
				AND M.AUTHOR_ID > 0
				AND (M.NOTIFY_EVENT IS NULL OR M.NOTIFY_EVENT <> \'private_system\')
			ORDER BY M.ID ASC
			LIMIT %d',
			\Bitrix\ImOpenLines\Model\SessionTable::getTableName(),
			$sessionId,
			self::MAX_MESSAGES
		))->fetchAll();
		if($rows === [])
		{
			return [];
		}

		// Параметры: файлы, карточки-вложения, класс автоответа.
		$params = [];
		$ids = array_map(static fn(array $row): int => (int)$row['ID'], $rows);
		foreach($connection->query(
			'SELECT MESSAGE_ID, PARAM_NAME, PARAM_VALUE FROM b_im_message_param
			WHERE MESSAGE_ID IN ('.implode(',', $ids).') AND PARAM_NAME IN (\'FILE_ID\', \'ATTACH\', \'CLASS\')'
		)->fetchAll() as $param)
		{
			$id = (int)$param['MESSAGE_ID'];
			$name = (string)$param['PARAM_NAME'];
			if($name === 'FILE_ID')
			{
				$params[$id]['files'] = ($params[$id]['files'] ?? 0) + 1;
			}
			elseif($name === 'ATTACH')
			{
				$params[$id]['attach'] = true;
			}
			else
			{
				$params[$id]['class'] = ($params[$id]['class'] ?? '').' '.$param['PARAM_VALUE'];
			}
		}

		$users = [];
		foreach(UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN', 'EXTERNAL_AUTH_ID'],
			'filter' => ['@ID' => array_values(array_unique(array_map(static fn(array $row): int => (int)$row['AUTHOR_ID'], $rows)))],
		])->fetchAll() as $user)
		{
			$name = trim($user['NAME'].' '.$user['LAST_NAME']);
			$users[(int)$user['ID']] = [
				'name' => $name !== '' ? $name : (string)$user['LOGIN'],
				'auth' => (string)$user['EXTERNAL_AUTH_ID'],
			];
		}

		$messages = [];
		foreach($rows as $row)
		{
			$id = (int)$row['ID'];
			$user = $users[(int)$row['AUTHOR_ID']] ?? ['name' => '', 'auth' => ''];
			$date = $row['DATE_CREATE'];
			$messages[] = [
				'role' => Transcript::role($user['auth'], trim($params[$id]['class'] ?? '')),
				'author' => $user['name'],
				'time' => $date instanceof \Bitrix\Main\Type\DateTime ? $date->format('d.m H:i') : '',
				'text' => (string)$row['MESSAGE'],
				'files' => (int)($params[$id]['files'] ?? 0),
				'attach' => (bool)($params[$id]['attach'] ?? false),
			];
		}

		return $messages;
	}

	/**
	 * Скрипт речевой аналитики для чата.
	 *
	 * Своего подбора для чатов у CRM нет: ItemFactory::getByActivityId()
	 * отвечает null на любое дело не-звонок (TYPE_ID !== Call,
	 * crm/lib/Copilot/CallAssessment/ItemFactory.php). Поэтому:
	 *
	 * 1. задан «Скрипт для чатов» (CHAT_script) — он, даже выключенный: так
	 *    скрипт для чатов можно держать выключенным, чтобы CRM не подобрала
	 *    его звонкам;
	 * 2. иначе — как CRM подбирает звонку (ItemFactory::
	 *    getAssessmentByClientAndCallType()): тип клиента по делу
	 *    (AssessmentClientTypeResolver), CALL_TYPE «все» или по направлению
	 *    дела, включённый, доступный сейчас, самый свежий по UPDATED_AT.
	 *
	 * @return array{id: int, title: string, criteria: list<string>, source: string}|null
	 */
	public static function pickScript(int $activityId, int $direction, int $configuredId): ?array
	{
		$controller = \Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController::getInstance();

		if($configuredId > 0)
		{
			$entity = $controller->getById($configuredId);

			return $entity !== null ? static::toScript($entity, 'настройка модуля') : null;
		}

		$clientType = (new \Bitrix\Crm\Copilot\CallAssessment\AssessmentClientTypeResolver())->resolveByActivityId($activityId);
		if($clientType === null)
		{
			return null;
		}

		$filter = [
			'=CALL_TYPE' => [
				CallType::ALL->value,
				$direction === (int)\CCrmActivityDirection::Incoming ? CallType::INCOMING->value : CallType::OUTGOING->value,
			],
			'=CLIENT_TYPES.CLIENT_TYPE_ID' => [$clientType->value],
			'=IS_ENABLED' => true,
		];
		$available = $controller->getCurrentAvailableAssessmentFilter();
		if($available)
		{
			$filter[] = $available;
		}

		$entity = $controller->getList([
			'filter' => $filter,
			'limit' => 1,
			'order' => ['UPDATED_AT' => 'DESC'],
		])->current();

		return $entity ? static::toScript($entity, 'подбор как у звонка') : null;
	}

	private static function toScript(object $entity, string $source): array
	{
		$item = \Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem::createFromEntity($entity);

		return [
			'id' => (int)$item->getId(),
			'title' => $item->getTitle(),
			'criteria' => ChatScore::criteriaFromGist($item->getGist()),
			'source' => $source,
		];
	}
}

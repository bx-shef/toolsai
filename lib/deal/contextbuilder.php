<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\History\Entity\DealStageHistoryTable;
use Bitrix\Crm\Service\Container as CrmContainer;
use Bitrix\Main\Loader;

/**
 * Сбор фактов о сделке для промпта — из CRM.
 *
 * Данные разбросаны по таблицам:
 *
 *  - карточка              -> фабрика сделок, Service\Container
 *  - дела и звонки         -> ActivityBindingTable + ActivityTable
 *  - откаты по стадиям     -> DealStageHistoryTable, порядок — сортировка стадий
 *
 * Резюме разговоров из Копилота здесь нет сознательно: где ядро их хранит,
 * зависит от версии crm, и опора на это — ещё одна точка хрупкости при
 * обновлениях. Вместо них — описания последних дел: то, что менеджер
 * написал сам, и транскрипт, если распознавание его туда положило.
 */
final class ContextBuilder implements FactsSourceInterface
{
	/** Сколько последних дел класть в промпт. */
	private const NOTES_LIMIT = 5;
	private const NOTE_LENGTH = 300;
	/** Сколько последних дел разбирать вообще. */
	private const ACTIVITIES_LIMIT = 200;
	/** Сколько последних звонков описывать резюме и оценкой. */
	private const CALLS_LIMIT = 3;

	/**
	 * @param int $ignoreAuthorId дела этого автора не считаются активностью —
	 *     служебный пользователь, от которого агент сам ставит дела. Иначе
	 *     дело менеджеру «оживляло» бы мёртвую сделку на ACTIVE_DAYS.
	 *     В счёт запланированных/просроченных они идут как обычно.
	 */
	public function __construct(private readonly int $ignoreAuthorId = 0)
	{
	}

	public function build(int $dealId): DealFacts
	{
		if(!Loader::includeModule('crm'))
		{
			throw new \RuntimeException('Модуль crm не установлен');
		}

		$factory = CrmContainer::getInstance()->getFactory(\CCrmOwnerType::Deal);
		$item = $factory?->getItem($dealId);

		if($item === null)
		{
			throw new \RuntimeException('Сделка '.$dealId.' не найдена');
		}

		$now = time();
		$created = $item->getCreatedTime();
		$activities = $this->getActivities($dealId);

		$lastActivity = 0;
		$callsTotal = 0;
		$callsIncoming = 0;
		$outgoingWithoutAnswer = 0;
		$seenIncoming = false;
		$openActivities = 0;
		$overdueActivities = 0;
		$oldestOverdueAt = null;
		$notes = [];
		$calls = [];

		// От свежего к старому.
		foreach($activities as $activity)
		{
			$time = $activity['CREATED'] instanceof \Bitrix\Main\Type\DateTime ? $activity['CREATED']->getTimestamp() : 0;
			if($this->ignoreAuthorId <= 0 || (int)($activity['AUTHOR_ID'] ?? 0) !== $this->ignoreAuthorId)
			{
				$lastActivity = max($lastActivity, $time);
			}
			$deadline = $activity['DEADLINE'] ?? null;
			$deadline = $deadline instanceof \Bitrix\Main\Type\DateTime ? $deadline->getTimestamp() : null;
			switch(DealFacts::activityState(($activity['COMPLETED'] ?? 'Y') !== 'N', $deadline, $now))
			{
				case DealFacts::ACTIVITY_PLANNED:
					$openActivities++;
					break;
				case DealFacts::ACTIVITY_OVERDUE:
					$overdueActivities++;
					$oldestOverdueAt = min($oldestOverdueAt ?? PHP_INT_MAX, (int)$deadline);
					break;
			}

			if((int)$activity['TYPE_ID'] === \CCrmActivityType::Call)
			{
				$callsTotal++;
				if(count($calls) < self::CALLS_LIMIT)
				{
					$calls[(int)$activity['ID']] = [$time, (int)$activity['DIRECTION'] === \CCrmActivityDirection::Incoming ? 'входящий' : 'исходящий'];
				}
				if((int)$activity['DIRECTION'] === \CCrmActivityDirection::Incoming)
				{
					$callsIncoming++;
					$seenIncoming = true;
				}
				elseif(!$seenIncoming)
				{
					$outgoingWithoutAnswer++;
				}
			}

			$note = trim(strip_tags((string)($activity['DESCRIPTION'] ?? '')));
			if($note !== '' && count($notes) < self::NOTES_LIMIT)
			{
				$notes[] = sprintf(
					'%s, %s: %s',
					$time > 0 ? date('d.m.Y', $time) : '?',
					(string)$activity['SUBJECT'],
					mb_substr(preg_replace('/\s+/u', ' ', $note), 0, self::NOTE_LENGTH)
				);
			}
		}

		$createdAt = $created instanceof \Bitrix\Main\Type\DateTime ? $created->getTimestamp() : $now;

		return new DealFacts(
			dealId: $dealId,
			title: (string)$item->getTitle(),
			stage: $this->getStageName($factory, (string)$item->getStageId(), (int)$item->getCategoryId()),
			opportunity: (float)$item->getOpportunity(),
			currency: (string)$item->getCurrencyId(),
			daysSinceCreated: intdiv(max(0, $now - $createdAt), 86400),
			daysSinceLastActivity: intdiv(max(0, $now - ($lastActivity ?: $createdAt)), 86400),
			stageRollbacks: $this->countRollbacks($factory, $dealId, (int)$item->getCategoryId()),
			callsTotal: $callsTotal,
			callsIncoming: $callsIncoming,
			outgoingWithoutAnswer: $outgoingWithoutAnswer,
			recentNotes: $notes,
			categoryId: (int)$item->getCategoryId(),
			assignedById: (int)$item->getAssignedById(),
			openActivities: $openActivities,
			clientType: $this->getClientType($item),
			overdueActivities: $overdueActivities,
			oldestOverdueAt: $oldestOverdueAt,
			callNotes: $this->getCallNotes($calls),
		);
	}

	/**
	 * Резюме, оценка и «не клиент» по последним звонкам — из заданий
	 * Копилота (b_crm_ai_queue) и оценок (b_crm_ai_quality_assessment).
	 * Разбор и форма — CallInsights. Сбой — без звонков, анализ не рвём.
	 *
	 * @param array<int, array{0: int, 1: string}> $calls ID дела => [время, направление]
	 * @return string[]
	 */
	private function getCallNotes(array $calls): array
	{
		if($calls === [])
		{
			return [];
		}

		$ids = implode(',', array_map('intval', array_keys($calls)));
		$summary = $failed = $notClient = $score = [];
		try
		{
			$connection = \Bitrix\Main\Application::getConnection();
			// От старых к новым: последнее успешное задание перекрывает.
			$result = $connection->query('
				SELECT ENTITY_ID, TYPE_ID, RESULT
				FROM b_crm_ai_queue
				WHERE ENTITY_TYPE_ID = '.\CCrmOwnerType::Activity.' AND ENTITY_ID IN ('.$ids.')
					AND TYPE_ID IN (2, 4, 9) AND EXECUTION_STATUS = \'SUCCESS\'
				ORDER BY ID ASC
			');
			while($row = $result->fetch())
			{
				$id = (int)$row['ENTITY_ID'];
				match((int)$row['TYPE_ID'])
				{
					2 => $summary[$id] = CallInsights::parseSummary($row['RESULT']),
					4 => $failed[$id] = CallInsights::parseFailed($row['RESULT']),
					9 => $notClient[$id] = CallInsights::parseNotClient($row['RESULT']),
					default => null,
				};
			}
			$result = $connection->query('
				SELECT ACTIVITY_ID, ASSESSMENT
				FROM b_crm_ai_quality_assessment
				WHERE ACTIVITY_ID IN ('.$ids.')
				ORDER BY ID ASC
			');
			while($row = $result->fetch())
			{
				$score[(int)$row['ACTIVITY_ID']] = (int)$row['ASSESSMENT'];
			}
		}
		catch(\Throwable)
		{
			return [];
		}

		$notes = [];
		foreach($calls as $id => [$time, $direction])
		{
			$note = CallInsights::formatCall($time, $direction, $summary[$id] ?? '', $score[$id] ?? null, $failed[$id] ?? [], $notClient[$id] ?? null);
			if($note !== '')
			{
				$notes[] = $note;
			}
		}

		return $notes;
	}

	/**
	 * @return array[] от свежего к старому
	 */
	private function getActivities(int $dealId): array
	{
		$ids = array_column(ActivityBindingTable::getList([
			'select' => ['ACTIVITY_ID'],
			'filter' => [
				'=OWNER_TYPE_ID' => \CCrmOwnerType::Deal,
				'=OWNER_ID' => $dealId,
			],
			'order' => ['ACTIVITY_ID' => 'DESC'],
			'limit' => self::ACTIVITIES_LIMIT,
		])->fetchAll(), 'ACTIVITY_ID');

		if($ids === [])
		{
			return [];
		}

		return ActivityTable::getList([
			'select' => ['ID', 'CREATED', 'TYPE_ID', 'DIRECTION', 'SUBJECT', 'DESCRIPTION', 'COMPLETED', 'DEADLINE', 'AUTHOR_ID'],
			'filter' => ['@ID' => $ids],
			'order' => ['CREATED' => 'DESC', 'ID' => 'DESC'],
		])->fetchAll();
	}

	/**
	 * Тип клиента сделки — штатно: ClientTypeResolver ядра по компании, нет
	 * компании — по контакту (решение владельца, 1.2.0; выбор —
	 * ClientType::pickClient()). Перевод в коды — как у речевой аналитики
	 * (AssessmentClientTypeResolver::resolveByIdentifier()), см. ClientType.
	 * Сбой или нет клиента — null: подойдёт только профиль «любой».
	 */
	private function getClientType(\Bitrix\Crm\Item $item): ?int
	{
		if(!class_exists(\Bitrix\Crm\Client\ClientTypeResolver::class))
		{
			return null;
		}

		$client = ClientType::pickClient((int)$item->getCompanyId(), (int)$item->getContactId());
		$identifier = match($client[0] ?? null)
		{
			ClientType::CLIENT_COMPANY => new \Bitrix\Crm\ItemIdentifier(\CCrmOwnerType::Company, $client[1]),
			ClientType::CLIENT_CONTACT => new \Bitrix\Crm\ItemIdentifier(\CCrmOwnerType::Contact, $client[1]),
			default => null,
		};
		if($identifier === null)
		{
			return null;
		}

		try
		{
			$type = (new \Bitrix\Crm\Client\ClientTypeResolver())->getType($identifier);
		}
		catch(\Throwable)
		{
			return null;
		}

		return ClientType::fromCoreName($type->name);
	}

	private function getStageName(\Bitrix\Crm\Service\Factory $factory, string $stageId, int $categoryId): string
	{
		foreach($factory->getStages($categoryId) as $stage)
		{
			if($stage->getStatusId() === $stageId)
			{
				return (string)$stage->getName();
			}
		}

		return $stageId;
	}

	/**
	 * Сколько раз сделка шла по стадиям назад: переход в стадию с меньшей
	 * сортировкой, чем у предыдущей.
	 */
	private function countRollbacks(\Bitrix\Crm\Service\Factory $factory, int $dealId, int $categoryId): int
	{
		$sort = [];
		foreach($factory->getStages($categoryId) as $stage)
		{
			$sort[$stage->getStatusId()] = (int)$stage->getSort();
		}

		$history = DealStageHistoryTable::getList([
			'select' => ['STAGE_ID'],
			'filter' => ['=OWNER_ID' => $dealId],
			'order' => ['CREATED_TIME' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		// Стадия не из текущего направления (сделку переносили) сравнивать
		// не с чем — она пропускается, а не считается сортировкой 0.
		return static::countBackward(array_values(array_filter(
			array_map(static fn(array $row): ?int => $sort[$row['STAGE_ID']] ?? null, $history),
			static fn(?int $value): bool => $value !== null
		)));
	}

	/**
	 * @param int[] $sorts сортировки стадий в порядке переходов
	 */
	public static function countBackward(array $sorts): int
	{
		$count = 0;
		$previous = null;
		foreach($sorts as $sort)
		{
			if($previous !== null && $sort < $previous)
			{
				$count++;
			}
			$previous = $sort;
		}

		return $count;
	}
}

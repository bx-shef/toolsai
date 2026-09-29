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
		$notes = [];

		// От свежего к старому.
		foreach($activities as $activity)
		{
			$time = $activity['CREATED'] instanceof \Bitrix\Main\Type\DateTime ? $activity['CREATED']->getTimestamp() : 0;
			$lastActivity = max($lastActivity, $time);

			if((int)$activity['TYPE_ID'] === \CCrmActivityType::Call)
			{
				$callsTotal++;
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
		);
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
			'select' => ['ID', 'CREATED', 'TYPE_ID', 'DIRECTION', 'SUBJECT', 'DESCRIPTION'],
			'filter' => ['@ID' => $ids],
			'order' => ['CREATED' => 'DESC', 'ID' => 'DESC'],
		])->fetchAll();
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

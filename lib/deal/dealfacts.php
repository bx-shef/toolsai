<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Сводка фактов о сделке.
 *
 * Специально НЕ сырой транскрипт: в промпт уходит компактная выжимка.
 * Дешевле по токенам и стабильнее по качеству — модель не тонет в тексте
 * пяти разговоров, а видит динамику (docs/02-deal-health.md).
 */
final class DealFacts
{
	public function __construct(
		public readonly int $dealId,
		public readonly string $title,
		public readonly string $stage,
		public readonly float $opportunity,
		public readonly string $currency,
		public readonly int $daysSinceCreated,
		public readonly int $daysSinceLastActivity,
		public readonly int $stageRollbacks,
		public readonly int $callsTotal,
		public readonly int $callsIncoming,
		/** Исходящих звонков подряд после последнего входящего. */
		public readonly int $outgoingWithoutAnswer,
		/** @var string[] краткие записи по последним делам, от свежего к старому */
		public readonly array $recentNotes,
		/** Направление сделки — по нему подбирается профиль. */
		public readonly int $categoryId = 0,
		/** Ответственный за сделку (менеджер). */
		public readonly int $assignedById = 0,
		/**
		 * Запланированных дел: незавершённых (COMPLETED = 'N') со сроком в
		 * будущем или без срока (activityState()). Просроченные сюда не входят
		 * с версии 1.4.0 — раньше считались все незавершённые.
		 */
		public readonly int $openActivities = 0,
		/** Тип клиента сделки, Deal\ClientType::*; null — не определился. */
		public readonly ?int $clientType = null,
		/** Просроченных дел: незавершённых со сроком в прошлом. С 1.4.0. */
		public readonly int $overdueActivities = 0,
		/** Срок самого старого просроченного дела (unix); null — просроченных нет. */
		public readonly ?int $oldestOverdueAt = null,
		/** @var string[] содержание последних звонков (CallInsights::formatCall()), от свежего к старому. С 1.4.0. */
		public readonly array $callNotes = [],
	)
	{
	}

	public const ACTIVITY_DONE = 'done';
	public const ACTIVITY_PLANNED = 'planned';
	public const ACTIVITY_OVERDUE = 'overdue';

	/**
	 * Состояние дела по его полям в b_crm_act (crm ActivityTable):
	 * COMPLETED и DEADLINE. Чистая функция.
	 *
	 *   завершено                         — done;
	 *   не завершено, срока нет           — planned;
	 *   не завершено, срок в будущем      — planned (ровно «сейчас» — тоже);
	 *   не завершено, срок прошёл         — overdue.
	 *
	 * «Без срока» ядро хранит не NULL, а максимальной датой базы — 9999 год
	 * (CCrmDateTimeHelper::GetMaxDatabaseDate(), IsMaxDatabaseDate() смотрит
	 * на год 9999). Такая дата всегда в будущем — planned без отдельной
	 * ветки; NULL (старые дела) — тоже planned.
	 *
	 * @param int|null $deadline срок (unix); null — не задан
	 */
	public static function activityState(bool $completed, ?int $deadline, int $now): string
	{
		if($completed)
		{
			return static::ACTIVITY_DONE;
		}

		return ($deadline === null || $deadline <= 0 || $deadline >= $now) ? static::ACTIVITY_PLANNED : static::ACTIVITY_OVERDUE;
	}

	/**
	 * Живая ли сделка: последняя активность не старше $activeDays дней
	 * (поле профиля ACTIVE_DAYS). 0 — без ограничения. Мёртвую сделку
	 * анализировать незачем: модель скажет «мертва, старший не нужен», а
	 * запрос оплачен (боевой прогон 1.3.0: 575-780 дней без активности).
	 */
	public function isAlive(int $activeDays): bool
	{
		return $activeDays <= 0 || $this->daysSinceLastActivity <= $activeDays;
	}

	/**
	 * Стоит ли тратить запрос.
	 *
	 * Дешёвый фильтр без ИИ: свежая и живая сделка в анализе не нуждается.
	 * При сотне открытых сделок и ежедневном прогоне это разница между ~3000
	 * и ~300 запросами в месяц.
	 */
	public function isWorthAnalyzing(int $idleDays): bool
	{
		if($this->daysSinceLastActivity < $idleDays)
		{
			return false;   // работа идёт
		}

		if($this->callsTotal === 0 && $this->recentNotes === [] && $this->stageRollbacks === 0 && $this->callNotes === [])
		{
			return false;   // не о чем рассуждать
		}

		return true;
	}

	public function toPromptText(): string
	{
		$lines = [
			sprintf('Сделка #%d: %s', $this->dealId, $this->title),
			'Стадия: '.$this->stage,
			sprintf('Сумма: %s %s', number_format($this->opportunity, 2, '.', ' '), $this->currency),
			'Дней с создания: '.$this->daysSinceCreated,
			'Дней без активности: '.$this->daysSinceLastActivity,
			'Откатов по стадиям назад: '.$this->stageRollbacks,
			sprintf('Звонков всего: %d (входящих: %d)', $this->callsTotal, $this->callsIncoming),
			'Исходящих подряд после последнего входящего: '.$this->outgoingWithoutAnswer,
			'Запланированных дел (не завершены, срок не прошёл): '.$this->openActivities,
			'Просроченных дел (не завершены, срок прошёл): '.$this->overdueActivities
				.($this->oldestOverdueAt !== null ? ', самое старое — с '.date('d.m.Y', $this->oldestOverdueAt) : ''),
		];

		if($this->recentNotes !== [])
		{
			$lines[] = '';
			$lines[] = 'Последние дела (от свежего к старому):';
			foreach(array_values($this->recentNotes) as $i => $note)
			{
				$lines[] = sprintf('%d) %s', $i + 1, $note);
			}
		}

		if($this->callNotes !== [])
		{
			$lines[] = '';
			$lines[] = 'Последние звонки — резюме и оценка Копилота (от свежего к старому):';
			foreach(array_values($this->callNotes) as $i => $note)
			{
				$lines[] = sprintf('%d) %s', $i + 1, $note);
			}
		}

		return implode("\n", $lines);
	}
}

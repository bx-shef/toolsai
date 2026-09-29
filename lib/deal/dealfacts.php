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
	)
	{
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

		if($this->callsTotal === 0 && $this->recentNotes === [] && $this->stageRollbacks === 0)
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

		return implode("\n", $lines);
	}
}

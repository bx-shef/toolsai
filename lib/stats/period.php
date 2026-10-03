<?php declare(strict_types=1);

namespace Shef\ToolsAi\Stats;

/**
 * Период страницы «ИИ: статистика»: «с» и «по» включительно, по дням.
 *
 * GET-параметры разбираются строго: только Y-m-d и только настоящая дата
 * (2026-02-30 — мусор, а не 2 марта). Мусор или пусто — значение по
 * умолчанию: с первого числа текущего месяца по сегодня. «С» позже «по» —
 * меняем местами, а не показываем пустую страницу.
 *
 * Чистая логика, без ядра: время отдаёт вызывающий.
 */
final class Period
{
	private function __construct(
		/** Начало первого дня, 00:00:00. */
		public readonly \DateTimeImmutable $from,
		/** Начало дня ПОСЛЕ последнего: в SQL — «< till», а не «<= 23:59:59». */
		public readonly \DateTimeImmutable $till,
	)
	{
	}

	/**
	 * Строгий разбор одной даты. Не строка, не Y-m-d, несуществующий день —
	 * null.
	 */
	public static function parseDate(mixed $value): ?\DateTimeImmutable
	{
		if(!is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
		{
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if($date === false || $date->format('Y-m-d') !== $value)
		{
			return null;
		}

		return $date;
	}

	public static function fromRequest(mixed $from, mixed $to, \DateTimeImmutable $now): self
	{
		$today = $now->setTime(0, 0);
		$fromDate = static::parseDate($from) ?? $today->modify('first day of this month');
		$toDate = static::parseDate($to) ?? $today;

		if($fromDate > $toDate)
		{
			[$fromDate, $toDate] = [$toDate, $fromDate];
		}

		return new self($fromDate, $toDate->modify('+1 day'));
	}

	/** Для поля «с»: Y-m-d. */
	public function getFromValue(): string
	{
		return $this->from->format('Y-m-d');
	}

	/** Для поля «по»: последний день включительно, Y-m-d. */
	public function getToValue(): string
	{
		return $this->till->modify('-1 day')->format('Y-m-d');
	}
}

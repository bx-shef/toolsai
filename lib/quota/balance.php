<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

/**
 * Остаток = месячная квота − израсходовано.
 *
 * Ответ на вопрос «сколько осталось», которого у Битрикса для своего движка
 * нет в принципе: ни страницы, ни метода, ни числа в ошибке
 * (docs/00-research.md, раздел 4).
 *
 * Чистая арифметика, без базы: суммы приносит Quota\Meter.
 */
final class Balance
{
	public function __construct(
		/** Квота, микро-единицы. 0 и меньше — без ограничения. */
		public readonly int $limitMicro,
		/** Израсходовано за период, микро-единицы. */
		public readonly int $spentMicro,
		public readonly \DateTimeInterface $periodFrom,
	)
	{
	}

	public function isUnlimited(): bool
	{
		return $this->limitMicro <= 0;
	}

	/** Остаток; при безлимите — PHP_INT_MAX. Перерасход остатком не становится. */
	public function getLeftMicro(): int
	{
		if($this->isUnlimited())
		{
			return PHP_INT_MAX;
		}

		return max(0, $this->limitMicro - $this->spentMicro);
	}

	/** Доля израсходованного, %; может быть больше 100 при перерасходе. */
	public function getPercent(): float
	{
		if($this->isUnlimited())
		{
			return 0.0;
		}

		return round($this->spentMicro / $this->limitMicro * 100, 1);
	}

	/**
	 * Хватит ли на запрос.
	 *
	 * Равенство — хватает: квота 100, потрачено 90, запрос 10 — последний
	 * разрешённый запрос месяца.
	 */
	public function canSpend(int $costMicro): bool
	{
		return $this->isUnlimited() || $this->getLeftMicro() >= max(0, $costMicro);
	}

	/**
	 * Израсходовано больше квоты.
	 *
	 * Этим проверяет диспетчер — ПОСЛЕ того, как запрос записан в журнал со
	 * своей оценкой: его стоимость уже в сумме, и сравнивать остаток с
	 * оценкой значило бы посчитать её дважды.
	 */
	public function isExceeded(): bool
	{
		return !$this->isUnlimited() && $this->spentMicro > $this->limitMicro;
	}

	public static function getMonthStart(\DateTimeImmutable $now): \DateTimeImmutable
	{
		return $now->setDate((int)$now->format('Y'), (int)$now->format('n'), 1)->setTime(0, 0);
	}
}

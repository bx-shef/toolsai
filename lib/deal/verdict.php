<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Вердикт по здоровью сделки.
 *
 * Ответ модели разбирается терпимо, но границы держатся строго: риск за
 * пределами 0-100 подрезается, длинный текст обрезается, needSenior — только
 * настоящий true, а не строка «false», которая при (bool) стала бы true.
 */
final class Verdict
{
	private function __construct(
		public readonly int $risk,
		public readonly bool $needSenior,
		public readonly string $why,
		public readonly string $nextStep,
		public readonly bool $skipped = false,
		public readonly string $skipReason = '',
	)
	{
	}

	public static function fromArray(array $raw): self
	{
		$risk = $raw['risk'] ?? 0;

		return new self(
			risk: is_numeric($risk) ? max(0, min(100, (int)round((float)$risk))) : 0,
			needSenior: ($raw['needSenior'] ?? false) === true,
			why: mb_substr(is_string($raw['why'] ?? null) ? trim($raw['why']) : '', 0, 500),
			nextStep: mb_substr(is_string($raw['nextStep'] ?? null) ? trim($raw['nextStep']) : '', 0, 300),
		);
	}

	public static function skipped(string $reason): self
	{
		return new self(0, false, '', '', true, $reason);
	}

	/** Звать ли старшего при таком пороге. */
	public function shouldEscalate(int $threshold): bool
	{
		return !$this->skipped && $this->needSenior && $this->risk >= $threshold;
	}
}

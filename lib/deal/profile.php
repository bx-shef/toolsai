<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Профиль анализа сделок: для какого направления и типа клиента, каким
 * промптом и по какой шкале действовать.
 *
 * Образец — скрипты речевой аналитики CRM (crm/lib/Copilot/CallAssessment,
 * CopilotCallAssessmentTable: TITLE, IS_ENABLED, PROMPT, LOW_BORDER /
 * HIGH_BORDER, тип клиента). Хранится в Model\DealProfileTable.
 *
 * Класс чистый — без ядра: строку таблицы разбирает fromRow(), форму —
 * fromInput(). Границы держатся строго, как у Verdict: риск-границы в 0-100,
 * HIGH не ниже LOW, типы клиента — только известные.
 */
final class Profile
{
	public const DEFAULT_IDLE_DAYS = 3;
	public const DEFAULT_REANALYZE_DAYS = 7;
	public const DEFAULT_LOW_BORDER = 50;
	public const DEFAULT_HIGH_BORDER = 70;
	public const DEFAULT_SORT = 100;

	/** Промпт длиннее — обрезается: это системный промпт, а не регламент. */
	public const PROMPT_MAX = 8000;

	/**
	 * @param int[] $clientTypes ClientType::*; пусто — любой тип клиента
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly bool $enabled,
		public readonly int $sort,
		public readonly int $categoryId,
		public readonly array $clientTypes,
		public readonly string $prompt,
		public readonly int $idleDays,
		public readonly int $reanalyzeDays,
		public readonly int $lowBorder,
		public readonly int $highBorder,
		public readonly int $seniorId,
	)
	{
	}

	/** Строка таблицы (или то, что пришло из формы) -> профиль. */
	public static function fromRow(array $row): self
	{
		$low = static::int($row['LOW_BORDER'] ?? null, static::DEFAULT_LOW_BORDER, 0, 100);

		return new self(
			id: static::int($row['ID'] ?? null, 0, 0),
			title: mb_substr(trim(is_string($row['TITLE'] ?? null) ? $row['TITLE'] : ''), 0, 255),
			enabled: ($row['IS_ENABLED'] ?? 'N') === 'Y' || ($row['IS_ENABLED'] ?? null) === true,
			sort: static::int($row['SORT'] ?? null, static::DEFAULT_SORT, 0, 1_000_000),
			categoryId: static::int($row['CATEGORY_ID'] ?? null, 0, 0),
			clientTypes: ClientType::parseList($row['CLIENT_TYPES'] ?? ''),
			prompt: mb_substr(trim(is_string($row['PROMPT'] ?? null) ? $row['PROMPT'] : ''), 0, static::PROMPT_MAX),
			idleDays: static::int($row['IDLE_DAYS'] ?? null, static::DEFAULT_IDLE_DAYS, 0, 365),
			reanalyzeDays: static::int($row['REANALYZE_DAYS'] ?? null, static::DEFAULT_REANALYZE_DAYS, 1, 365),
			lowBorder: $low,
			// HIGH ниже LOW дал бы дело старшему при риске, при котором
			// менеджеру ещё ничего не положено. Подтягиваем к LOW.
			highBorder: max($low, static::int($row['HIGH_BORDER'] ?? null, static::DEFAULT_HIGH_BORDER, 0, 100)),
			seniorId: static::int($row['SENIOR_ID'] ?? null, 0, 0),
		);
	}

	/**
	 * Поля формы -> поля таблицы и ошибки. Ошибка — значение, которое молча
	 * превратилось бы в другое: «70%» в границе, HIGH ниже LOW, пустое название.
	 *
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	public static function fromInput(array $input): array
	{
		$errors = [];
		$number = static function(string $key, int $default, int $min, int $max) use ($input, &$errors): int
		{
			$raw = trim((string)($input[$key] ?? ''));
			if($raw === '')
			{
				return $default;
			}
			if(1 !== preg_match('/^\d+$/', $raw) || (int)$raw < $min || (int)$raw > $max)
			{
				$errors[] = $key;

				return $default;
			}

			return (int)$raw;
		};

		$title = trim((string)($input['TITLE'] ?? ''));
		if($title === '')
		{
			$errors[] = 'TITLE';
		}

		$types = $input['CLIENT_TYPES'] ?? [];
		$types = is_array($types) ? $types : [$types];

		$fields = [
			'TITLE' => mb_substr($title, 0, 255),
			'IS_ENABLED' => ($input['IS_ENABLED'] ?? 'N') === 'Y' ? 'Y' : 'N',
			'SORT' => $number('SORT', static::DEFAULT_SORT, 0, 1_000_000),
			'CATEGORY_ID' => $number('CATEGORY_ID', 0, 0, PHP_INT_MAX),
			'CLIENT_TYPES' => ClientType::toList(ClientType::parseList(implode(',', array_map('strval', $types)))),
			'PROMPT' => mb_substr(trim((string)($input['PROMPT'] ?? '')), 0, static::PROMPT_MAX),
			'IDLE_DAYS' => $number('IDLE_DAYS', static::DEFAULT_IDLE_DAYS, 0, 365),
			'REANALYZE_DAYS' => $number('REANALYZE_DAYS', static::DEFAULT_REANALYZE_DAYS, 1, 365),
			'LOW_BORDER' => $number('LOW_BORDER', static::DEFAULT_LOW_BORDER, 0, 100),
			'HIGH_BORDER' => $number('HIGH_BORDER', static::DEFAULT_HIGH_BORDER, 0, 100),
			'SENIOR_ID' => $number('SENIOR_ID', 0, 0, PHP_INT_MAX),
		];

		if($fields['HIGH_BORDER'] < $fields['LOW_BORDER'])
		{
			$errors[] = 'HIGH_BORDER';
		}

		return [$fields, array_values(array_unique($errors))];
	}

	/** Профиль подходит любому типу клиента. */
	public function isAnyClient(): bool
	{
		return $this->clientTypes === [];
	}

	private static function int(mixed $value, int $default, int $min, int $max = PHP_INT_MAX): int
	{
		if(is_int($value))
		{
			return max($min, min($max, $value));
		}
		if(is_string($value) && 1 === preg_match('/^\s*\d+\s*$/', $value))
		{
			return max($min, min($max, (int)$value));
		}

		return $default;
	}
}

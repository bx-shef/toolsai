<?php declare(strict_types=1);

namespace Shef\ToolsAi\Stats;

/**
 * «Что не делают менеджеры»: сколько раз критерий оценки звонка провален
 * (status = false) — по всем и по каждому менеджеру.
 *
 * * Оценённый звонок — тот, где есть хотя бы один оценённый критерий; доля
 *   считается от оценённых звонков (всех или этого менеджера).
 * * Один критерий в одном звонке считается один раз, даже если модель
 *   повторила его в списке.
 * * Порядок топа: чаще проваленный выше, при равенстве — по названию.
 *
 * Чистая логика, без ядра.
 */
final class FailureCounter
{
	private int $assessed = 0;
	/** @var array<int, int> менеджер -> оценённых звонков */
	private array $assessedByUser = [];
	/** @var array<string, int> */
	private array $fails = [];
	/** @var array<int, array<string, int>> */
	private array $failsByUser = [];

	/**
	 * @param list<array{criterion: string, status: bool}> $criteria — из ScoreResult::parseCriteria()
	 */
	public function add(int $userId, array $criteria): void
	{
		if($criteria === [])
		{
			return;
		}

		$this->assessed++;
		$this->assessedByUser[$userId] = ($this->assessedByUser[$userId] ?? 0) + 1;

		$failed = [];
		foreach($criteria as $item)
		{
			if($item['status'] === false)
			{
				$failed[$item['criterion']] = true;
			}
		}

		foreach(array_keys($failed) as $name)
		{
			$name = (string)$name;
			$this->fails[$name] = ($this->fails[$name] ?? 0) + 1;
			$this->failsByUser[$userId][$name] = ($this->failsByUser[$userId][$name] ?? 0) + 1;
		}
	}

	public function getAssessed(): int
	{
		return $this->assessed;
	}

	/** @return int[] менеджеры с оценёнными звонками, по возрастанию ID */
	public function getUserIds(): array
	{
		$ids = array_keys($this->assessedByUser);
		sort($ids);

		return $ids;
	}

	public function getAssessedByUser(int $userId): int
	{
		return $this->assessedByUser[$userId] ?? 0;
	}

	/**
	 * @return list<array{criterion: string, count: int, percent: int}>
	 */
	public function getTop(int $limit): array
	{
		return static::top($this->fails, $this->assessed, $limit);
	}

	/**
	 * @return list<array{criterion: string, count: int, percent: int}>
	 */
	public function getTopByUser(int $userId, int $limit): array
	{
		return static::top($this->failsByUser[$userId] ?? [], $this->getAssessedByUser($userId), $limit);
	}

	/**
	 * @param array<string, int> $fails
	 * @return list<array{criterion: string, count: int, percent: int}>
	 */
	private static function top(array $fails, int $total, int $limit): array
	{
		$rows = [];
		foreach($fails as $name => $count)
		{
			$rows[] = [
				'criterion' => (string)$name,
				'count' => $count,
				'percent' => $total > 0 ? (int)round($count * 100 / $total) : 0,
			];
		}

		usort($rows, static fn(array $a, array $b): int => [$b['count'], $a['criterion']] <=> [$a['count'], $b['criterion']]);

		return array_slice($rows, 0, max(0, $limit));
	}

	/**
	 * Строка топа для страницы: «Критерий — 3× (50%)».
	 *
	 * Склейка, не интерполяция: в "$n×" PHP считает «×» (байты >= 0x80)
	 * частью имени переменной, и число пропадает — так было в прототипе.
	 *
	 * @param array{criterion: string, count: int, percent: int} $row
	 */
	public static function formatRow(array $row): string
	{
		return $row['criterion'].' — '.$row['count'].'× ('.$row['percent'].'%)';
	}
}

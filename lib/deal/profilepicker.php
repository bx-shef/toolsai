<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Подбор профиля к сделке. Чистая функция — без ядра.
 *
 * Правила (решение владельца, bx-shef/toolsai#29):
 *   1) только включённые профили направления сделки;
 *   2) профиль с типом клиента сделки главнее профиля «любой»;
 *      тип не определился — подходит только «любой»;
 *   3) дальше — по SORT, потом по ID (меньше — раньше).
 *
 * Не подошёл ни один — null: сделку не анализируем и модель не зовём.
 */
final class ProfilePicker
{
	/**
	 * @param Profile[] $profiles
	 */
	public static function pick(array $profiles, int $categoryId, ?int $clientType): ?Profile
	{
		$matched = [];
		foreach($profiles as $profile)
		{
			if(!$profile->enabled || $profile->categoryId !== $categoryId)
			{
				continue;
			}

			if($profile->isAnyClient())
			{
				$matched[] = [1, $profile];
			}
			elseif($clientType !== null && in_array($clientType, $profile->clientTypes, true))
			{
				$matched[] = [0, $profile];
			}
		}

		if($matched === [])
		{
			return null;
		}

		usort($matched, static fn(array $a, array $b): int => [$a[0], $a[1]->sort, $a[1]->id] <=> [$b[0], $b[1]->sort, $b[1]->id]);

		return $matched[0][1];
	}

	/**
	 * Направления, по которым есть хоть один включённый профиль, и самый
	 * короткий срок повторного анализа по каждому — для выборки кандидатов.
	 *
	 * @param Profile[] $profiles
	 * @return array<int, int> ID направления => дней
	 */
	public static function getCategoryReanalyzeDays(array $profiles): array
	{
		$result = [];
		foreach($profiles as $profile)
		{
			if(!$profile->enabled)
			{
				continue;
			}

			$result[$profile->categoryId] = min($result[$profile->categoryId] ?? PHP_INT_MAX, $profile->reanalyzeDays);
		}
		ksort($result);

		return $result;
	}
}

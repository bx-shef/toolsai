<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email;

/**
 * Скорость ответа на письмо клиента. Чистая логика, без ядра, без модели.
 *
 * Ответ на входящее письмо — любое исходящее письмо или исходящий звонок по
 * той же сделке (лиду), созданные не раньше входящего. Время — момент, когда
 * письмо появилось в CRM (CREATED дела): раньше менеджер его не видел.
 *
 * Часы — простые, календарные: ночь и выходные считаются. Рабочего графика
 * модуль не знает (решение владельца, bx-shef/toolsai#34): письмо пятницы
 * 18:00 к понедельнику 9:00 «ждёт» 63 часа.
 *
 * Неотвеченные письма одной сделки — одна группа: дело менеджеру и дело
 * старшему ставятся на группу один раз (отметка у любого письма группы
 * гасит повтор), срок — от самого старого неотвеченного.
 */
final class ReplyClock
{
	public const DIRECTION_INCOMING = 1;
	public const DIRECTION_OUTGOING = 2;
	/** \CCrmActivityType::Call и ::Email — литералами, чтобы жить без ядра. */
	public const TYPE_CALL = 2;
	public const TYPE_EMAIL = 4;

	/**
	 * Первый ответ не раньше входящего: unix-время или null.
	 *
	 * @param list<int> $replyTimes исходящие письма и звонки, unix
	 */
	public static function firstReplyAfter(int $incomingAt, array $replyTimes): ?int
	{
		$first = null;
		foreach($replyTimes as $time)
		{
			if($time >= $incomingAt && ($first === null || $time < $first))
			{
				$first = $time;
			}
		}

		return $first;
	}

	/** Полных часов ожидания. */
	public static function hours(int $since, int $now): int
	{
		return intdiv(max(0, $now - $since), 3600);
	}

	/**
	 * Что сделать с неотвеченными письмами одной сделки.
	 *
	 * @param list<array{at: int, managerNotified: bool, seniorNotified: bool}> $waiting
	 * @return array{oldestAt: int, hours: int, manager: bool, senior: bool}|null null — ждущих нет
	 */
	public static function decide(array $waiting, int $now, int $replyHours, int $escalateHours): ?array
	{
		if($waiting === [])
		{
			return null;
		}

		$oldest = PHP_INT_MAX;
		$managerDone = false;
		$seniorDone = false;
		foreach($waiting as $item)
		{
			$oldest = min($oldest, (int)$item['at']);
			$managerDone = $managerDone || (bool)$item['managerNotified'];
			$seniorDone = $seniorDone || (bool)$item['seniorNotified'];
		}

		$seconds = max(0, $now - $oldest);

		return [
			'oldestAt' => $oldest,
			'hours' => intdiv($seconds, 3600),
			'manager' => !$managerDone && $seconds >= $replyHours * 3600,
			'senior' => !$seniorDone && $seconds >= $escalateHours * 3600,
		];
	}

	/**
	 * С какого момента клиент ждёт ответа: самое старое входящее письмо после
	 * последнего ответа (исходящего письма или звонка). Для сводки анализа
	 * сделки. Ждёт ли — null, если ответ был после последнего входящего.
	 *
	 * @param list<array{type: int, direction: int, at: int}> $activities дела сделки в любом порядке
	 */
	public static function waitingSince(array $activities): ?int
	{
		$lastReply = null;
		foreach($activities as $activity)
		{
			if(static::isReply((int)$activity['type'], (int)$activity['direction']))
			{
				$lastReply = max($lastReply ?? PHP_INT_MIN, (int)$activity['at']);
			}
		}

		$oldest = null;
		foreach($activities as $activity)
		{
			if((int)$activity['type'] !== self::TYPE_EMAIL || (int)$activity['direction'] !== self::DIRECTION_INCOMING)
			{
				continue;
			}
			$at = (int)$activity['at'];
			if($lastReply !== null && $lastReply >= $at)
			{
				continue;
			}
			$oldest = min($oldest ?? PHP_INT_MAX, $at);
		}

		return $oldest;
	}

	/** Ответ ли это: исходящее письмо или исходящий звонок. */
	public static function isReply(int $type, int $direction): bool
	{
		return $direction === self::DIRECTION_OUTGOING && in_array($type, [self::TYPE_EMAIL, self::TYPE_CALL], true);
	}

	/** «2 ч 05 мин», «45 мин», «3 дн 4 ч». */
	public static function formatDuration(int $seconds): string
	{
		$seconds = max(0, $seconds);
		$minutes = intdiv($seconds, 60);
		if($minutes < 60)
		{
			return $minutes.' мин';
		}

		$hours = intdiv($minutes, 60);
		if($hours < 48)
		{
			return $hours.' ч '.sprintf('%02d', $minutes % 60).' мин';
		}

		return intdiv($hours, 24).' дн '.($hours % 24).' ч';
	}
}

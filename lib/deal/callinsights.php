<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Shef\ToolsAi\Stats\ScoreResult;

/**
 * Содержание звонков сделки для сводки модели (1.4.0): резюме, оценка по
 * скрипту, «не клиент». Чистая логика — разбор RESULT заданий Копилота и
 * короткое форматирование, без ядра. Данные достаёт ContextBuilder.
 *
 * Откуда (crm/lib/integration/ai, b_crm_ai_queue, ENTITY_TYPE_ID = 6 —
 * дело-звонок; RESULT — Json::encode(payload), abstractoperation.php):
 *   - TYPE_ID = 2 (SummarizeCallTranscription): {"summary":"…"};
 *   - TYPE_ID = 4 (ScoreCall): {"criteria":[{criterion,status,…}],…} —
 *     разбор Stats\ScoreResult; балл — b_crm_ai_quality_assessment.ASSESSMENT;
 *   - TYPE_ID = 9 (AnalyzeCommunication): {"isClient":false,
 *     "reasonIfIsClientFalse":"…","actions":[…]}.
 */
final class CallInsights
{
	public const SUMMARY_LENGTH = 400;
	public const REASON_LENGTH = 150;
	public const CRITERIA_LIMIT = 5;
	public const CRITERION_LENGTH = 80;

	/** Резюме звонка из RESULT задания TYPE_ID = 2; нет — ''. */
	public static function parseSummary(mixed $json): string
	{
		$data = static::decode($json);
		$summary = is_string($data['summary'] ?? null) ? $data['summary'] : '';

		return static::cut($summary, static::SUMMARY_LENGTH);
	}

	/**
	 * Причина «звонок не от клиента» из RESULT задания TYPE_ID = 9.
	 * null — клиент или признака нет; строка (возможно пустая) — не клиент.
	 */
	public static function parseNotClient(mixed $json): ?string
	{
		$data = static::decode($json);
		if(($data['isClient'] ?? null) !== false)
		{
			return null;
		}

		return static::cut(is_string($data['reasonIfIsClientFalse'] ?? null) ? $data['reasonIfIsClientFalse'] : '', static::REASON_LENGTH);
	}

	/**
	 * Невыполненные пункты скрипта (status = false) из RESULT TYPE_ID = 4.
	 *
	 * @return string[]
	 */
	public static function parseFailed(mixed $json): array
	{
		$failed = [];
		foreach(ScoreResult::parseCriteria($json) as $item)
		{
			if($item['status'] === false)
			{
				$failed[] = static::cut($item['criterion'], static::CRITERION_LENGTH);
			}
		}

		return $failed;
	}

	/**
	 * Строка сводки об одном звонке. Нечего сказать — ''.
	 *
	 * @param string[] $failed невыполненные пункты оценки
	 */
	public static function formatCall(int $time, string $direction, string $summary, ?int $assessment, array $failed, ?string $notClient): string
	{
		$parts = [];
		if($notClient !== null)
		{
			$parts[] = 'не клиент'.($notClient !== '' ? ' ('.$notClient.')' : '');
		}
		if($summary !== '')
		{
			$parts[] = 'резюме: '.$summary;
		}
		if($assessment !== null)
		{
			$score = 'оценка по скрипту: '.max(0, min(100, $assessment)).'%';
			$failed = array_slice(array_values(array_filter($failed, static fn(string $s): bool => $s !== '')), 0, static::CRITERIA_LIMIT);
			if($failed !== [])
			{
				$score .= ', не выполнено: '.implode('; ', $failed);
			}
			$parts[] = $score;
		}
		if($parts === [])
		{
			return '';
		}

		return sprintf('%s, %s: %s', $time > 0 ? date('d.m.Y', $time) : '?', $direction, implode('. ', $parts));
	}

	private static function decode(mixed $json): array
	{
		if(!is_string($json) || $json === '')
		{
			return [];
		}
		try
		{
			$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		}
		catch(\JsonException)
		{
			return [];
		}

		return is_array($data) ? $data : [];
	}

	private static function cut(string $text, int $length): string
	{
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

		return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
	}
}

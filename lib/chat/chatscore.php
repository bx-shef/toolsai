<?php declare(strict_types=1);

namespace Shef\ToolsAi\Chat;

/**
 * Оценка переписки по скрипту: критерии скрипта, процент, комментарий в
 * таймлайн, запись для таблицы. Чистая логика, без ядра.
 *
 * Процент — как у звонка в CRM (ScoreCall::getAssessmentsValue(),
 * crm/lib/integration/ai/operation/scorecall.php): выполненные делить на
 * оценённые (status — bool), критерии со status = null не считаются. Ни
 * одного оценённого — процента нет (у ядра в этом месте деление на ноль).
 */
final class ChatScore
{
	/** Потолок комментария в таймлайне, символов. */
	public const MAX_COMMENT_LENGTH = 3000;

	/** Пояснение заглушки: провайдер текста — echo, денег не тратим. */
	public const STUB_EXPLANATION = '[заглушка] Модель не вызывалась: провайдер текста — заглушка.';

	/**
	 * Критерии из «сути» скрипта (CopilotCallAssessment.GIST): ядро пишет
	 * их через PHP_EOL (extractscoringcriteria.php, implode(PHP_EOL, …)).
	 * Пустые строки и повторы — прочь.
	 *
	 * @return list<string>
	 */
	public static function criteriaFromGist(mixed $gist): array
	{
		$lines = preg_split('/\R/u', is_scalar($gist) ? (string)$gist : '') ?: [];
		$result = [];
		foreach($lines as $line)
		{
			$line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
			if($line !== '' && !in_array($line, $result, true))
			{
				$result[] = $line;
			}
		}

		return $result;
	}

	/**
	 * @param list<array{criterion: string, status: ?bool}> $criteria
	 * @return int|null 0..100; null — ни один критерий не оценён
	 */
	public static function percent(array $criteria): ?int
	{
		$assessed = 0;
		$done = 0;
		foreach($criteria as $item)
		{
			if(!is_bool($item['status'] ?? null))
			{
				continue;
			}
			$assessed++;
			$done += $item['status'] ? 1 : 0;
		}

		return $assessed > 0 ? (int)round($done * 100 / $assessed) : null;
	}

	/**
	 * Невыполненные критерии (status = false), без повторов, по порядку.
	 *
	 * @param list<array{criterion: string, status: ?bool}> $criteria
	 * @return list<string>
	 */
	public static function failed(array $criteria): array
	{
		$result = [];
		foreach($criteria as $item)
		{
			if(($item['status'] ?? null) === false && !in_array($item['criterion'], $result, true))
			{
				$result[] = $item['criterion'];
			}
		}

		return $result;
	}

	/**
	 * Комментарий в таймлайн сделки или лида:
	 * «Оценка переписки по скрипту «Название»: 75%. Не выполнено: …».
	 *
	 * @param array{call_review: array{criteria: list<array{criterion: string, status: ?bool, explanation: string}>}, overall_summary: string, recommendations: string} $scoring
	 *        — из CopilotPrompt::normalizeScoring()
	 */
	public static function formatComment(string $scriptTitle, array $scoring): string
	{
		$criteria = $scoring['call_review']['criteria'];
		$percent = static::percent($criteria);
		$title = trim($scriptTitle) !== '' ? '«'.trim($scriptTitle).'»' : 'без названия';

		$lines = [
			'Оценка переписки по скрипту '.$title.': '.($percent !== null ? $percent.'%' : 'не оценена — ни один пункт нельзя проверить по переписке').'.',
		];

		$failed = static::failed($criteria);
		if($percent !== null)
		{
			$lines[] = $failed === [] ? 'Все оценённые пункты выполнены.' : 'Не выполнено: '.implode('; ', $failed).'.';
		}

		$summary = trim((string)$scoring['overall_summary']);
		if($summary !== '')
		{
			$lines[] = 'Итог: '.$summary;
		}
		$recommendations = trim((string)$scoring['recommendations']);
		if($recommendations !== '')
		{
			$lines[] = 'Что сделать иначе: '.$recommendations;
		}

		$text = implode("\n", $lines);

		return mb_strlen($text) > static::MAX_COMMENT_LENGTH ? mb_substr($text, 0, static::MAX_COMMENT_LENGTH - 1).'…' : $text;
	}

	/**
	 * Критерии для таблицы результатов: {"criteria": [...]} — так их же
	 * разбирает Stats\ScoreResult::parseCriteria() (criteria в корне), и
	 * страница статистики считает провалы чатов тем же FailureCounter.
	 */
	public static function toStored(array $scoring): string
	{
		return (string)json_encode(
			['criteria' => $scoring['call_review']['criteria']],
			JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	/**
	 * Ответ «модели» для заглушки (провайдер текста echo): все критерии
	 * выполнены, пояснение — что это заглушка. Чтобы путь «чат -> оценка ->
	 * комментарий» проходился на стенде без денег, как анализ сделок.
	 *
	 * @param list<string> $criteria
	 */
	public static function stubAnswer(array $criteria): array
	{
		return [
			'criteria' => array_map(
				static fn(string $criterion): array => ['criterion' => $criterion, 'status' => true, 'explanation' => self::STUB_EXPLANATION],
				$criteria
			),
			'overall_summary' => self::STUB_EXPLANATION,
			'recommendations' => '',
		];
	}
}

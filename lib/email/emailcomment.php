<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email;

use Shef\ToolsAi\Chat\ChatScore;

/**
 * Тексты в CRM по письмам: комментарии в ленту сделки, дела менеджеру и
 * старшему, строки сводки анализа сделки. Чистая логика, без ядра.
 *
 * Время — unix, выводится в часовом поясе PHP (как у дел и комментариев,
 * которые пишет агент).
 */
final class EmailComment
{
	/** Потолок комментария в ленте, символов — как у оценки чатов. */
	public const MAX_LENGTH = ChatScore::MAX_COMMENT_LENGTH;

	/**
	 * Резюме входящего письма:
	 * «ИИ: письмо клиента от 03.10.2026 12:30 «Тема». Что пишет: … Чего
	 * хочет: … Сроки: … Дела менеджеру: …».
	 *
	 * @param array{summary: string, wants: string, terms: string} $answer — EmailPrompt::normalizeIncoming()
	 * @param list<string> $todoTitles поставленные дела
	 */
	public static function incoming(string $subject, int $mailAt, array $answer, array $todoTitles = []): string
	{
		$lines = ['ИИ: письмо клиента от '.date('d.m.Y H:i', $mailAt).static::subject($subject).'.'];
		foreach(['summary' => 'Что пишет', 'wants' => 'Чего хочет', 'terms' => 'Сроки'] as $key => $label)
		{
			$value = trim((string)($answer[$key] ?? ''));
			if($value !== '')
			{
				$lines[] = $label.': '.$value;
			}
		}
		if($todoTitles !== [])
		{
			$lines[] = 'Дела менеджеру: '.implode('; ', array_map(static fn(string $title): string => '«'.$title.'»', $todoTitles)).'.';
		}

		return static::cut(implode("\n", $lines));
	}

	/**
	 * Оценка исходящего письма:
	 * «ИИ-оценка письма менеджера «Тема»: 75%. Не выполнено: … Итог: … Что
	 * сделать иначе: …». Процент — как у звонка и чата (ChatScore::percent()).
	 *
	 * @param array{call_review: array{criteria: list<array{criterion: string, status: ?bool, explanation: string}>}, overall_summary: string, recommendations: string} $scoring
	 */
	public static function review(string $subject, array $scoring): string
	{
		$criteria = $scoring['call_review']['criteria'];
		$percent = ChatScore::percent($criteria);

		$lines = ['ИИ-оценка письма менеджера'.static::subject($subject).': '.($percent !== null ? $percent.'%' : 'не оценено — ни один пункт нельзя проверить по письму').'.'];
		if($percent !== null)
		{
			$failed = ChatScore::failed($criteria);
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

		return static::cut(implode("\n", $lines));
	}

	/**
	 * Дело менеджеру: ответить клиенту.
	 *
	 * @return array{subject: string, description: string}
	 */
	public static function managerTodo(string $mailSubject, int $mailAt, int $now): array
	{
		return [
			'subject' => 'Ответить клиенту на письмо от '.date('d.m.Y H:i', $mailAt),
			'description' => sprintf(
				"Клиент ждёт ответа %s%s. Ответьте письмом или позвоните.\nДело поставил ИИ-контроль скорости ответа: исходящего письма или звонка по сделке после письма клиента нет.",
				ReplyClock::formatDuration($now - $mailAt),
				static::subject($mailSubject, ' на письмо ')
			),
		];
	}

	/**
	 * Дело старшему: клиент без ответа дольше порога.
	 *
	 * @return array{subject: string, description: string}
	 */
	public static function seniorTodo(string $managerName, string $mailSubject, int $mailAt, int $now, string $ownerTitle): array
	{
		return [
			'subject' => 'Клиент без ответа '.ReplyClock::formatDuration($now - $mailAt).': письмо от '.date('d.m.Y H:i', $mailAt),
			'description' => sprintf(
				"Менеджер %s не ответил на письмо клиента от %s%s%s. Проконтролировать ответ.",
				$managerName !== '' ? $managerName : '—',
				date('d.m.Y H:i', $mailAt),
				static::subject($mailSubject),
				$ownerTitle !== '' ? ' ('.$ownerTitle.')' : ''
			),
		];
	}

	/** Строка сводки анализа сделки: «03.10.2026, письмо клиента: …». */
	public static function dealNote(int $mailAt, string $summary): string
	{
		return date('d.m.Y', $mailAt).', письмо клиента: '.mb_substr(trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary), 0, 300);
	}

	/**
	 * Критерии и итог для таблицы: {"criteria": [...], "summary", "recommendations"}
	 * — criteria в корне читает Stats\ScoreResult, страница статистики
	 * считает провалы тем же FailureCounter.
	 */
	public static function toStoredReview(array $scoring): string
	{
		return (string)json_encode(
			[
				'criteria' => $scoring['call_review']['criteria'],
				'summary' => $scoring['overall_summary'],
				'recommendations' => $scoring['recommendations'],
			],
			JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	private static function subject(string $subject, string $prefix = ' '): string
	{
		$subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');

		return $subject !== '' ? $prefix.'«'.mb_substr($subject, 0, 150).'»' : '';
	}

	private static function cut(string $text): string
	{
		return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH - 1).'…' : $text;
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Completion;

/**
 * Свои промпты для текстовой цепочки Копилота CRM.
 *
 * Промпты ядра на коробке обфусцированы (b_ai_prompt, токены <1568-…>):
 * сторонний движок получает данные, но не инструкции. Поэтому модуль сам
 * пишет инструкцию по коду промпта (payload_raw) и маркерам
 * (payload_markers) — ai/lib/Engine/ThirdParty.php:226-237 — и отвечает в
 * формате, который разбирает CRM.
 *
 * * summarize_transcript — резюме звонка. CRM берёт ответ как текст
 *   (SummarizeCallTranscription::extractPayloadFromAIResult) и кладёт в
 *   карточку; резюме же уходит дальше, в заполнение полей.
 * * extract_form_fields — заполнение полей. CRM ищет JSON между первой «{»
 *   и последней «}» (AbstractOperation::extractPayloadPrettifiedData),
 *   сопоставляет ключи с именами полей из маркера fields, «comment» —
 *   то, что в поля не легло. Ни одного совпадения и пустой comment —
 *   PAYLOAD_IS_EMPTY (боевая проверка, bx-shef/toolsai#11).
 *
 * Остальные коды — как раньше, текст промпта ядра как есть.
 */
final class CopilotPrompt
{
	public const SUMMARIZE = 'summarize_transcript';
	public const EXTRACT_FIELDS = 'extract_form_fields';

	/** Код промпта, для которого у модуля своя инструкция; null — нет такой. */
	public static function getCode(Request $request): ?string
	{
		if($request->payloadProvider !== 'prompt' || !is_string($request->rawData))
		{
			return null;
		}

		$code = $request->rawData;
		if(!in_array($code, [self::SUMMARIZE, self::EXTRACT_FIELDS], true))
		{
			return null;
		}

		// Без текста звонка писать не о чем — отдаём старому пути, он
		// хотя бы перешлёт то, что прислало ядро.
		return static::getText($request->markers['original_message'] ?? null) !== '' ? $code : null;
	}

	/**
	 * Сообщения chat completions по коду промпта.
	 *
	 * @return list<array{role: string, content: string}>
	 */
	public static function getMessages(Request $request): array
	{
		return match(static::getCode($request))
		{
			self::SUMMARIZE => static::summarize($request->markers),
			self::EXTRACT_FIELDS => static::extractFields($request->markers),
			default => [],
		};
	}

	/** @return list<array{role: string, content: string}> */
	private static function summarize(array $markers): array
	{
		$context = array_filter([
			'Компания (наша сторона): '.static::getText($markers['company_name'] ?? null),
			'Менеджер: '.static::getText($markers['manager_name'] ?? null),
		], static fn(string $line): bool => !str_ends_with($line, ': '));

		$system = implode("\n", [
			'Ты помощник отдела продаж. Тебе дают расшифровку телефонного разговора менеджера с клиентом.',
			'Напиши краткое деловое резюме разговора для карточки CRM.',
			'',
			'Правила:',
			'- пиши на языке: '.static::getLanguage($markers).';',
			'- только факты из разговора, ничего не додумывай;',
			'- 3–7 коротких пунктов, каждый с новой строки и с «- »: кто клиент и чего хочет, что обсудили, цены, сроки и количества, договорённости, следующий шаг и кто его делает;',
			'- чего в разговоре нет, не упоминай;',
			'- если разговора по сути не было (автоответчик, ошибся номером, тишина) — одной строкой так и напиши;',
			'- без заголовков, вступлений и выводов, без Markdown-разметки кроме «- ».',
		]);
		if($context !== [])
		{
			$system .= "\n\n".implode("\n", $context);
		}

		return [
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => static::getText($markers['original_message'] ?? null)],
		];
	}

	/** @return list<array{role: string, content: string}> */
	private static function extractFields(array $markers): array
	{
		$fields = is_array($markers['fields'] ?? null) ? $markers['fields'] : [];
		// comment — «нераспределённое»: ядро кладёт его в fields само, но
		// без него ответ без единого поля CRM считает пустым.
		$fields += ['comment' => 'list[string]'];
		$enums = is_array($markers['enum_fields_values'] ?? null) ? $markers['enum_fields_values'] : [];
		$today = implode('.', array_map(
			static fn(mixed $part): string => static::getText($part),
			[$markers['current_day'] ?? '', $markers['current_month'] ?? '', $markers['current_year'] ?? '']
		));

		$system = implode("\n", [
			'Ты помощник отдела продаж. Тебе дают резюме телефонного разговора менеджера с клиентом.',
			'Извлеки из него значения полей карточки CRM.',
			'',
			'Ответь одним JSON-объектом, без пояснений и без Markdown. Ключи — ровно имена полей из списка ниже, символ в символ; значение — по указанному типу или null, если в разговоре этого нет.',
			'',
			'Правила:',
			'- только то, что прямо сказано в разговоре; не угадывай и не подставляй умолчания;',
			'- даты — строкой ДД.ММ.ГГГГ'.($today !== '..' ? '; сегодня '.$today.', «завтра», «в пятницу» считай от этой даты' : '').';',
			'- числа и деньги — числом, без валюты и пробелов;',
			'- для полей со списком значений — только значение из этого списка, как написано;',
			'- list[...] — массив, даже из одного значения;',
			'- «comment» — массив коротких строк: важное из разговора, что не легло в поля (договорённости, следующий шаг, возражения). Если важного нет — пустой массив;',
			'- пиши на языке: '.static::getLanguage($markers).'.',
			'',
			'Поля (имя: тип):',
			(string)json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
			$enums !== [] ? "\nДопустимые значения полей со списком:\n".(string)json_encode($enums, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '',
		]);

		return [
			['role' => 'system', 'content' => rtrim($system)],
			['role' => 'user', 'content' => static::getText($markers['original_message'] ?? null)],
		];
	}

	private static function getLanguage(array $markers): string
	{
		$language = static::getText($markers['language'] ?? null);

		return match($language)
		{
			'', 'ru' => 'русский',
			'en' => 'английский',
			'de' => 'немецкий',
			'ua' => 'украинский',
			'kz' => 'казахский',
			'by' => 'белорусский',
			default => $language,
		};
	}

	private static function getText(mixed $value): string
	{
		return is_scalar($value) ? trim((string)$value) : '';
	}
}

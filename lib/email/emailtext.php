<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email;

/**
 * Тело письма CRM -> текст для модели. Чистая логика, без ядра.
 *
 * Как CRM хранит письмо (crm 26.800): дело TYPE_ID = 4, PROVIDER_ID =
 * CRM_EMAIL; тело — HTML (DESCRIPTION_TYPE = 3, \CCrmContentType::Html;
 * CCrmEMail кладёт BODY_HTML письма, а если его нет — текст, экранированный
 * и с <br>, MailActivityDescriptionFactory). Бывают и 1 (текст) и 2 (BB-код)
 * — у писем, созданных руками или старым кодом.
 *
 * Что режем:
 *
 * * разметку: head/style/script, комментарии, теги; блочные теги — перевод
 *   строки, ячейки — пробел; сущности раскрыты, неразрывный пробел — пробел;
 * * цитату переписки: всё внутри <blockquote> (так цитирует и сам Битрикс —
 *   mail Message::wrapTheMessageWithAQuote()), всё после блока цитаты
 *   Gmail/Yahoo/Thunderbird/Outlook (gmail_quote, yahoo_quoted,
 *   moz-cite-prefix, divRplyFwdMsg); строки с «>» в начале; всё после
 *   заголовка цитаты: «-----Original Message-----», «-----Исходное
 *   сообщение-----», «On … wrote:», «… пишет:» / «написал(а):», шапка
 *   «От кого:/От:/From:» с «Кому:/Отправлено:/Sent:/Date:» следом;
 * * подпись: строка «-- », «Отправлено с iPhone» / «Sent from my …»; «С
 *   уважением» / «Best regards» — только если перед ней уже есть текст;
 * * длину: потолок символов, дальше — «…».
 *
 * Цитаты режутся по признакам, а не наверняка: письмо без разметки цитаты и
 * без её заголовка уйдёт модели целиком (в потолке длины).
 */
final class EmailText
{
	public const TYPE_PLAIN = 1;
	public const TYPE_BBCODE = 2;
	public const TYPE_HTML = 3;

	/** Потолок текста одного письма для модели, символов. */
	public const MAX_LENGTH = 6000;

	private const QUOTE_MARK = "\n\u{1F}QUOTE\u{1F}\n";

	/**
	 * Готовый текст письма: разметка снята, цитата и подпись отрезаны,
	 * длина в потолке.
	 */
	public static function prepare(string $body, int $type, int $max = self::MAX_LENGTH): string
	{
		$text = match($type)
		{
			self::TYPE_PLAIN => static::normalize($body),
			self::TYPE_BBCODE => static::normalize(static::fromBbCode($body)),
			default => static::fromHtml($body),
		};

		return static::limit(static::cutSignature(static::cutQuote($text)), $max);
	}

	/** HTML -> текст: без цитат в разметке (blockquote, блоки цитат почтовиков). */
	public static function fromHtml(string $html): string
	{
		$html = static::replace('/<!--.*?-->/su', '', $html);
		$html = static::replace('/<(head|style|script|title)\b[^>]*>.*?<\/\1\s*>/siu', '', $html);

		// Блок цитаты почтовика: дальше — переписка, режем по метке.
		$html = static::replace(
			'/<(?:div|blockquote|span)\b[^>]*(?:class|id)\s*=\s*["\']?[^"\'>]*(?:gmail_quote|gmail_attr|yahoo_quoted|moz-cite-prefix|divRplyFwdMsg|appendonsend|OLK_SRC_BODY_SECTION|zmail_extra)[^>]*>/iu',
			self::QUOTE_MARK,
			$html
		);
		$html = static::replace('/<hr\b[^>]*id\s*=\s*["\']?stopSpelling[^>]*>/iu', self::QUOTE_MARK, $html);

		// Вложенные blockquote: снимаем самые внутренние, пока есть.
		for($i = 0; $i < 20 && preg_match('/<blockquote\b/iu', $html) === 1; $i++)
		{
			$next = static::replace('/<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?<\/blockquote\s*>/siu', "\n", $html);
			if($next === $html)
			{
				// Незакрытый blockquote — всё после него цитата.
				$next = static::replace('/<blockquote\b.*$/siu', '', $html);
			}
			$html = $next;
		}

		// «</br>» — так пишет шапку цитаты сам Битрикс (mail, lang MAIL_QUOTE_MESSAGE_HEADER).
		$html = static::replace('/<\/?br\s*\/?>/iu', "\n", $html);
		$html = static::replace('/<\/?(?:p|div|tr|li|ul|ol|table|h[1-6]|pre|section|article|header|footer)\b[^>]*>/iu', "\n", $html);
		$html = static::replace('/<\/?(?:td|th)\b[^>]*>/iu', ' ', $html);
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$position = mb_strpos($text, trim(self::QUOTE_MARK));
		if($position !== false)
		{
			$text = mb_substr($text, 0, $position);
		}

		// Абзацы и блоки в HTML дают пустые строки на каждом шаге вложенности —
		// модели они ничего не говорят.
		return static::replace('/\n{2,}/u', "\n", static::normalize($text));
	}

	/** BB-код -> текст: теги в квадратных скобках прочь, текст внутри остаётся. */
	public static function fromBbCode(string $text): string
	{
		$text = static::replace('/\[quote\b[^\]]*\].*?\[\/quote\]/siu', "\n", $text);
		$text = static::replace('/\[br\]/iu', "\n", $text);

		return static::replace('/\[\/?[a-z*]+(?:=[^\]]*)?\]/iu', '', $text);
	}

	/**
	 * Отрезать цитату предыдущей переписки в тексте: строки «>», всё после
	 * заголовка цитаты. Заголовок в первой строке — не режем: письмо, которое
	 * целиком цитата, лучше отдать как есть, чем пустым.
	 */
	public static function cutQuote(string $text): string
	{
		$lines = explode("\n", $text);
		$result = [];
		$count = count($lines);
		for($i = 0; $i < $count; $i++)
		{
			$line = trim($lines[$i]);
			if(str_starts_with($line, '>'))
			{
				continue;
			}

			if($result !== [] && static::isQuoteHeader($line, array_slice($lines, $i + 1, 4)))
			{
				break;
			}

			$result[] = $lines[$i];
		}

		return static::normalize(implode("\n", $result));
	}

	/**
	 * Начинается ли здесь цитата: «-----Original Message-----», «On … wrote:»,
	 * «… пишет:», шапка «От:/From:» с адресатом или датой в следующих строках.
	 *
	 * @param list<string> $next следующие строки
	 */
	public static function isQuoteHeader(string $line, array $next = []): bool
	{
		if($line === '')
		{
			return false;
		}

		if(preg_match('/^-{2,}\s*(?:original message|исходное сообщение|пересылаемое сообщение|forwarded message|переадресованное сообщение)\s*-{2,}/iu', $line) === 1)
		{
			return true;
		}

		// «On Mon, 3 Oct 2026 at 12:00, Иван <a@b.by> wrote:», «3 окт. 2026 г., в 12:00, Иван <a@b.by> пишет:»,
		// «03.10.2026 12:00, Иван написал(а):».
		if(mb_strlen($line) <= 300 && preg_match('/(?:wrote|пишет|написал|написала|написал\(а\)|писал|писала):\s*$/iu', $line) === 1
			&& preg_match('/\d|@|^on\s/iu', $line) === 1)
		{
			return true;
		}

		// Яндекс и Mail.ru: «03.10.2026, 12:00, "Иван" <ivan@x.by>:».
		if(mb_strlen($line) <= 300 && preg_match('/\d.*<[^<>\s]+@[^<>\s]+>\s*:\s*$/u', $line) === 1)
		{
			return true;
		}

		// Шапка Outlook / Битрикса: «От кого: …», «От: …», «From: …» и дальше «Кому:»/«Отправлено:»/«Sent:»/«Date:»/«Дата:».
		if(preg_match('/^(?:от кого|от|from)\s*:/iu', $line) === 1)
		{
			foreach($next as $nextLine)
			{
				if(preg_match('/^(?:кому|to|отправлено|sent|date|дата|тема|subject|копия|cc)\s*:/iu', trim($nextLine)) === 1)
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Отрезать подпись: «-- », «Отправлено с iPhone», «Sent from my …»; «С
	 * уважением» / «Best regards» — если до неё уже есть текст письма.
	 */
	public static function cutSignature(string $text): string
	{
		$lines = explode("\n", $text);
		$result = [];
		foreach($lines as $line)
		{
			$trimmed = trim($line);
			if($result !== [] && (
				$line === '-- ' || $trimmed === '--'
				|| preg_match('/^(?:отправлено (?:с|из)|sent from my|get outlook for|получено с помощью)(?=[\s,.!:]|$)/iu', $trimmed) === 1
				|| preg_match('/^(?:с уважением|с наилучшими пожеланиями|всего (?:доброго|наилучшего)|best regards|kind regards|regards|sincerely)(?=[\s,.!:]|$)/iu', $trimmed) === 1
			))
			{
				break;
			}
			$result[] = $line;
		}

		return static::normalize(implode("\n", $result));
	}

	/** Пробелы в строке схлопнуты, пустых строк подряд не больше одной. */
	public static function normalize(string $text): string
	{
		$text = str_replace(["\r\n", "\r", "\u{A0}", "\u{200B}", "\u{FEFF}"], ["\n", "\n", ' ', '', ''], $text);
		$lines = array_map(static fn(string $line): string => trim(static::replace('/[ \t\f\v]+/u', ' ', $line)), explode("\n", $text));
		$text = implode("\n", $lines);
		$text = static::replace('/\n{3,}/u', "\n\n", $text);

		return trim($text);
	}

	/** Потолок длины: дальше «…». */
	public static function limit(string $text, int $max): string
	{
		$max = max(100, $max);

		return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
	}

	/**
	 * Служебное письмо — по адресу отправителя и теме, без модели: робот
	 * почты, автоответ, недоставка, рассылка уведомлений. Причина или null.
	 */
	public static function serviceReason(string $from, string $subject): ?string
	{
		$address = mb_strtolower(preg_match('/<([^>]+)>/u', $from, $match) === 1 ? $match[1] : $from);
		if(preg_match('/(?:^|[\s<"])(?:mailer-daemon|postmaster|no-?reply|do-?not-?reply|noreply-[\w-]+|bounce[\w.-]*)@/u', ' '.$address) === 1)
		{
			return 'служебный адрес отправителя';
		}

		$subject = trim($subject);
		if(preg_match('/^(?:auto(?:matic)?[\s_-]*reply|autoreply|auto:|автоответ|автоматический ответ|out of (?:the )?office|нет на месте|в отпуске|undeliver|undelivered|delivery status notification|delivery (?:has )?failed|mail delivery failed|returned mail|не доставлено|недоставлено|сообщение не доставлено|уведомление о доставке|read:|прочитано:)/iu', $subject) === 1)
		{
			return 'автоответ или уведомление почты';
		}

		return null;
	}

	private static function replace(string $pattern, string $replacement, string $subject): string
	{
		return preg_replace($pattern, $replacement, $subject) ?? $subject;
	}
}

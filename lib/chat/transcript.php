<?php declare(strict_types=1);

namespace Shef\ToolsAi\Chat;

/**
 * Текст переписки открытой линии для модели: кто есть кто, без разметки.
 *
 * На входе — сообщения сессии в порядке отправки (Chat\DialogSource):
 * роль автора, имя, время, сырой текст из b_im_message.MESSAGE (BB-коды
 * мессенджера), число файлов и признак вложения-карточки. На выходе — строки
 * «[12:05] Клиент: текст», «[12:06] Менеджер (Иван Петров): текст».
 *
 * Роли:
 *
 * * client — пользователь коннектора (EXTERNAL_AUTH_ID = imconnector,
 *   Im\User::isConnector());
 * * bot — чат-бот (EXTERNAL_AUTH_ID = bot, Im\Bot::EXTERNAL_AUTH_ID);
 * * auto — автоответ открытой линии: параметр сообщения CLASS
 *   «bx-messenger-content-item-ol-…» (output — автоответы, end — «диалог
 *   закрыт», start, attention). Их подписывают и оператором: текст при
 *   закрытии диалога уходит с FROM_USER_ID = OPERATOR_ID
 *   (imopenlines/lib/session.php:1279-1290, 1350-1362), поэтому по автору
 *   их не отличить;
 * * hidden — CLASS «bx-messenger-content-item-system»: скрытое сообщение
 *   оператора (тихий режим, COMPONENT_ID = HiddenMessage,
 *   imopenlines/lib/connector.php:1489) и служебные данные коннектора.
 *   Клиент их не видел — в текст не идут;
 * * employee — все остальные: сотрудник портала, оператор.
 *
 * Системные сообщения (AUTHOR_ID = 0) отсекает выборка, сюда они не
 * доходят. Чистая логика, без ядра.
 */
final class Transcript
{
	public const ROLE_CLIENT = 'client';
	public const ROLE_EMPLOYEE = 'employee';
	public const ROLE_BOT = 'bot';
	public const ROLE_AUTO = 'auto';
	public const ROLE_HIDDEN = 'hidden';

	/** Классы сообщений открытой линии (параметр CLASS): автоответы и скрытые. */
	public const AUTO_CLASS = 'bx-messenger-content-item-ol-';
	public const HIDDEN_CLASS = 'bx-messenger-content-item-system';

	/** Потолок текста для модели, символов: длинный чат режется по середине. */
	public const DEFAULT_MAX_CHARS = 30000;

	private const LABELS = [
		self::ROLE_CLIENT => 'Клиент',
		self::ROLE_EMPLOYEE => 'Менеджер',
		self::ROLE_BOT => 'Бот',
		self::ROLE_AUTO => 'Автоответ',
	];

	/**
	 * BB-коды мессенджера, которые снимаем целиком (оставляя текст внутри).
	 * Неизвестный тег в квадратных скобках остаётся как есть: «[Заказ 15]»
	 * в тексте клиента — не разметка.
	 */
	private const BB_TAGS = 'b|i|u|s|url|size|color|quote|code|user|chat|icon|send|put|call|pch|context|br|disk|dialog|timestamp|img|left|right|center|justify|list|font|attach|\*';

	private function __construct(
		public readonly string $text,
		public readonly int $clientCount,
		public readonly int $employeeCount,
		/** Реплики бота и автоответы. */
		public readonly int $botCount,
		/** Сколько реплик выпало из середины, чтобы уложиться в потолок. */
		public readonly int $skipped,
	)
	{
	}

	/**
	 * Роль автора сообщения.
	 *
	 * @param string $externalAuthId b_user.EXTERNAL_AUTH_ID автора
	 * @param string $styleClass параметр CLASS сообщения ('' — нет)
	 */
	public static function role(string $externalAuthId, string $styleClass = ''): string
	{
		if($styleClass !== '' && str_contains($styleClass, static::HIDDEN_CLASS))
		{
			return static::ROLE_HIDDEN;
		}
		if($styleClass !== '' && str_contains($styleClass, static::AUTO_CLASS))
		{
			return static::ROLE_AUTO;
		}

		return match(mb_strtolower(trim($externalAuthId)))
		{
			'imconnector' => static::ROLE_CLIENT,
			'bot' => static::ROLE_BOT,
			default => static::ROLE_EMPLOYEE,
		};
	}

	/**
	 * Текст сообщения без разметки: BB-коды мессенджера сняты (упоминание
	 * [USER=5]Иван[/USER] — «Иван», ссылка [URL=…]текст[/URL] — «текст»),
	 * HTML-сущности раскрыты, теги убраны, пробелы и переводы строк — один
	 * пробел.
	 */
	public static function cleanText(string $text): string
	{
		$text = preg_replace('/\[br\]/iu', ' ', $text) ?? $text;
		$text = preg_replace('/\[(?:'.self::BB_TAGS.')(?:=[^\]]*)?\]/iu', '', $text) ?? $text;
		$text = preg_replace('/\[\/(?:'.self::BB_TAGS.')\]/iu', '', $text) ?? $text;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	/**
	 * @param list<array{role: string, author?: string, time?: string, text?: string, files?: int, attach?: bool}> $messages
	 */
	public static function build(array $messages, int $maxChars = self::DEFAULT_MAX_CHARS): self
	{
		$lines = [];
		$counts = [static::ROLE_CLIENT => 0, static::ROLE_EMPLOYEE => 0, 'other' => 0];
		foreach($messages as $message)
		{
			if(($message['role'] ?? '') === static::ROLE_HIDDEN)
			{
				continue;
			}
			$role = isset(self::LABELS[$message['role'] ?? '']) ? $message['role'] : static::ROLE_EMPLOYEE;
			$text = static::cleanText((string)($message['text'] ?? ''));
			$files = max(0, (int)($message['files'] ?? 0));
			$extra = [];
			if($files > 0)
			{
				$extra[] = $files === 1 ? '[файл]' : '[файлов: '.$files.']';
			}
			if(!empty($message['attach']))
			{
				$extra[] = '[вложение]';
			}
			if($text === '' && $extra === [])
			{
				continue;
			}

			$author = trim((string)($message['author'] ?? ''));
			$label = self::LABELS[$role];
			// Имя клиента модели ни к чему; у сотрудников и ботов — чтобы
			// отличать двух операторов в одном диалоге.
			if($author !== '' && $role !== static::ROLE_CLIENT && $role !== static::ROLE_AUTO)
			{
				$label .= ' ('.$author.')';
			}
			$time = trim((string)($message['time'] ?? ''));

			$lines[] = ($time !== '' ? '['.$time.'] ' : '').$label.': '.trim($text.' '.implode(' ', $extra));
			$counts[match($role)
			{
				static::ROLE_CLIENT => static::ROLE_CLIENT,
				static::ROLE_EMPLOYEE => static::ROLE_EMPLOYEE,
				default => 'other',
			}]++;
		}

		[$lines, $skipped] = static::fit($lines, max(1000, $maxChars));

		return new self(implode("\n", $lines), $counts[static::ROLE_CLIENT], $counts[static::ROLE_EMPLOYEE], $counts['other'], $skipped);
	}

	/**
	 * Есть что оценивать: хотя бы одна реплика клиента и одна — менеджера.
	 * Иначе причина пропуска (без запроса к модели), null — можно.
	 */
	public function getSkipReason(): ?string
	{
		if($this->employeeCount === 0)
		{
			return 'нет реплик менеджера';
		}
		if($this->clientCount === 0)
		{
			return 'нет реплик клиента';
		}

		return null;
	}

	/**
	 * Уложить строки в потолок: начало и конец диалога важнее середины
	 * (приветствие, выявление потребности — и договорённость о следующем
	 * шаге). Берём строки с начала и с конца по половине потолка, между
	 * ними — отметка, сколько пропущено.
	 *
	 * @param list<string> $lines
	 * @return array{0: list<string>, 1: int}
	 */
	private static function fit(array $lines, int $maxChars): array
	{
		$total = 0;
		foreach($lines as $line)
		{
			$total += mb_strlen($line) + 1;
		}
		if($total <= $maxChars)
		{
			return [$lines, 0];
		}

		$half = intdiv($maxChars, 2);
		$head = [];
		$used = 0;
		foreach($lines as $line)
		{
			$length = mb_strlen($line) + 1;
			if($used + $length > $half)
			{
				break;
			}
			$head[] = $line;
			$used += $length;
		}

		$tail = [];
		$used = 0;
		for($i = count($lines) - 1; $i >= count($head); $i--)
		{
			$length = mb_strlen($lines[$i]) + 1;
			if($used + $length > $half)
			{
				break;
			}
			array_unshift($tail, $lines[$i]);
			$used += $length;
		}

		// Одна гигантская реплика в начале — хотя бы её начало.
		if($head === [] && $lines !== [])
		{
			$head[] = mb_substr($lines[0], 0, $half).'…';
		}

		$skipped = count($lines) - count($head) - count($tail);

		return [[...$head, '… пропущено реплик: '.$skipped.' …', ...$tail], $skipped];
	}
}

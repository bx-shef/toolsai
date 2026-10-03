<?php declare(strict_types=1);

namespace Shef\ToolsAi\Completion;

/**
 * Входящий запрос от Битрикса на completions_url.
 *
 * Формат собирается в ThirdParty::completions() (ai/lib/Engine/ThirdParty.php:226-248).
 * Полное описание контракта — docs/01-engine-contract.md.
 *
 * НАМЕРЕННО ТЕРПИМ К ФОРМАТУ: отсутствующий ключ даёт пустое значение, а не
 * исключение. Битрикс добавляет поля между версиями, падать из-за нового
 * необязательного ключа незачем. Сверку формата делает cli/core-api-guard.php.
 *
 * Объект сериализуется в массив и обратно (toArray/fromArray): так запрос
 * переживает переход из эндпоинта в фоновую задачу.
 */
final class Request
{
	/** Роли сообщений, которые понимает chat completions. */
	private const CHAT_ROLES = ['system', 'user', 'assistant'];

	private function __construct(
		/** audio | text | image | call | vision | classify */
		public readonly string $category,
		/** strtolower(короткое имя класса Payload): audio | prompt | classify | styledpicture */
		public readonly string $payloadProvider,
		/** payload->getData(); для audio — ['file' => url, 'fields' => [], 'fileExtension' => 'mp3'] */
		public readonly mixed $prompt,
		/** payload->getRawData(): сырьё до упаковки. */
		public readonly mixed $rawData,
		/** Текст промпта; заполнен только при payloadProvider = 'prompt'. */
		public readonly ?string $promptText,
		/** Инструкция роли, может быть null. */
		public readonly ?string $role,
		/** [['role' => 'system', 'content' => '...'], ...] */
		public readonly array $context,
		/** Маркеры payload + ['language' => 'ru']; для audio есть ещё 'type' — MIME записи. */
		public readonly array $markers,
		/** Время жизни задания, сек. */
		public readonly int $ttl,
		public readonly string $callbackUrl,
		public readonly string $errorCallbackUrl,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			category: is_scalar($data['category'] ?? null) ? (string)$data['category'] : '',
			payloadProvider: is_scalar($data['payload_provider'] ?? null) ? (string)$data['payload_provider'] : '',
			prompt: $data['prompt'] ?? null,
			rawData: $data['payload_raw'] ?? null,
			promptText: is_scalar($data['payload_prompt_text'] ?? null) ? (string)$data['payload_prompt_text'] : null,
			role: is_scalar($data['payload_role'] ?? null) ? (string)$data['payload_role'] : null,
			context: is_array($data['context'] ?? null) ? $data['context'] : [],
			markers: is_array($data['payload_markers'] ?? null) ? $data['payload_markers'] : [],
			ttl: is_numeric($data['ttl'] ?? null) ? (int)$data['ttl'] : 300,
			callbackUrl: is_string($data['callbackUrl'] ?? null) ? $data['callbackUrl'] : '',
			errorCallbackUrl: is_string($data['errorCallbackUrl'] ?? null) ? $data['errorCallbackUrl'] : '',
		);
	}

	public function toArray(): array
	{
		return [
			'category' => $this->category,
			'payload_provider' => $this->payloadProvider,
			'prompt' => $this->prompt,
			'payload_raw' => $this->rawData,
			'payload_prompt_text' => $this->promptText,
			'payload_role' => $this->role,
			'context' => $this->context,
			'payload_markers' => $this->markers,
			'ttl' => $this->ttl,
			'callbackUrl' => $this->callbackUrl,
			'errorCallbackUrl' => $this->errorCallbackUrl,
		];
	}

	/** URL аудиозаписи — только для category = 'audio'. */
	public function getAudioUrl(): ?string
	{
		$file = is_array($this->prompt) ? ($this->prompt['file'] ?? null) : null;

		return is_string($file) && $file !== '' ? $file : null;
	}

	/**
	 * Расширение файла записи.
	 *
	 * Битрикс кладёт сюда расширение из имени файла, уже проверенное по
	 * Timeline\Config::ALLOWED_AUDIO_EXTENSIONS — mp3|mp4|m4a|vp6|aac|wav
	 * (transcribecallrecording.php:108-118).
	 */
	public function getAudioExtension(): string
	{
		$extension = is_array($this->prompt) ? ($this->prompt['fileExtension'] ?? null) : null;
		if(!is_string($extension) || 1 !== preg_match('/^[a-z0-9]{1,8}$/i', $extension))
		{
			return 'mp3';
		}

		return mb_strtolower($extension);
	}

	/**
	 * MIME-тип записи. Ему доверять больше, чем расширению: ставится из
	 * CFile::GetFileArray()['CONTENT_TYPE'] (transcribecallrecording.php:123).
	 */
	public function getAudioMimeType(): ?string
	{
		$type = $this->markers['type'] ?? null;

		return is_string($type) && 1 === preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $type) ? $type : null;
	}

	public function getLanguage(): string
	{
		$language = $this->markers['language'] ?? null;

		return is_string($language) && 1 === preg_match('/^[a-z]{2}$/', $language) ? $language : 'ru';
	}

	/**
	 * Хэш задания QueueJob — он же ключ идемпотентности.
	 *
	 * Лежит в query обоих колбэков. Нужен, чтобы не списать квоту дважды,
	 * если запрос придёт повторно.
	 */
	public function getJobHash(): ?string
	{
		$query = parse_url($this->callbackUrl, PHP_URL_QUERY);
		if(!is_string($query) || $query === '')
		{
			return null;
		}

		parse_str($query, $parsed);

		$hash = $parsed['hash'] ?? null;

		return is_string($hash) && 1 === preg_match('/^[A-Za-z0-9_-]{1,64}$/', $hash) ? $hash : null;
	}

	/** Минимальная пригодность: без колбэка результат некуда отдать. */
	public function isValid(): bool
	{
		return $this->category !== ''
			&& $this->callbackUrl !== ''
			&& $this->errorCallbackUrl !== '';
	}

	/**
	 * Сообщения для chat completions.
	 *
	 * Порядок: роль (system) -> контекст ядра -> текст промпта (user). Текст
	 * промпта не повторяем, если ядро уже положило его последним сообщением
	 * контекста. Ничего нет — берём сырые данные payload строкой: лучше
	 * ответить по ним, чем прислать пустоту, которая молча оборвёт цепочку.
	 *
	 * @return array<int, array{role: string, content: string}>
	 */
	public function getChatMessages(): array
	{
		$messages = [];

		if($this->role !== null && trim($this->role) !== '')
		{
			$messages[] = ['role' => 'system', 'content' => $this->role];
		}

		foreach($this->context as $item)
		{
			if(!is_array($item))
			{
				continue;
			}

			$role = $item['role'] ?? null;
			$content = $item['content'] ?? null;
			if(!in_array($role, self::CHAT_ROLES, true) || !is_string($content) || trim($content) === '')
			{
				continue;
			}

			$messages[] = ['role' => $role, 'content' => $content];
		}

		// Сначала готовый текст: prompt — это payload->getData(), шаблон уже
		// прогнан ядром через Formatter с маркерами (ai/lib/Payload/Prompt.php,
		// getData()). payload_prompt_text — сырой шаблон того же промпта
		// (Prompt\Manager::getByCode()->getPrompt()) с {маркерами} и @switch;
		// отданный модели, он даёт ответ «пришлите настоящий текст», а FillFields
		// получает пустой payload. Шаблон — только когда готового текста нет.
		$text = is_string($this->prompt) ? $this->prompt : null;
		if($text === null || trim($text) === '')
		{
			$text = $this->promptText;
		}

		if($text !== null && trim($text) !== '')
		{
			$last = end($messages);
			if(!is_array($last) || $last['content'] !== $text)
			{
				$messages[] = ['role' => 'user', 'content' => $text];
			}
		}

		$hasUser = array_filter($messages, static fn(array $message): bool => $message['role'] === 'user') !== [];
		if(!$hasUser)
		{
			$raw = is_string($this->rawData) ? $this->rawData : json_encode($this->rawData, JSON_UNESCAPED_UNICODE);
			if(is_string($raw) && trim($raw) !== '' && $raw !== 'null')
			{
				$messages[] = ['role' => 'user', 'content' => $raw];
			}
		}

		return $messages;
	}
}

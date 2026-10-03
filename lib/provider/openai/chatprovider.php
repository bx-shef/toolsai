<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Completion\CopilotPrompt;
use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Provider\Result;

/**
 * Категория text: резюме звонка, заполнение полей, оценка разговора.
 *
 * Промпт ядра — Request::getChatMessages(). Резюме звонка и заполнение
 * полей — свои промпты (CopilotPrompt), если они включены настройкой.
 */
final class ChatProvider implements ProviderInterface
{
	/** Запас на ответ при оценке: резюме и заполнение полей укладываются. */
	private const ESTIMATE_OUT_TOKENS = 2000;

	public function __construct(
		private readonly Config $config,
		private readonly Llm $llm,
	)
	{
	}

	public function getCode(): string
	{
		return Constants::PROVIDER_OPENAI;
	}

	/**
	 * Токен — не больше 2 символов для русского текста; оценка — с запасом.
	 */
	public function estimateCostMicro(Request $request): int
	{
		$chars = 0;
		foreach($this->getMessages($request) as $message)
		{
			$chars += mb_strlen($message['content']);
		}

		return $this->llm->getCostMicro(intdiv($chars, 2) + 1, self::ESTIMATE_OUT_TOKENS);
	}

	public function run(Request $request): Result
	{
		// Поля CRM разбирает как JSON: отдаём ровно объект, без обёрток, и
		// только ключи, которые CRM знает (полей из маркера и comment): лишнее
		// ей не нужно, а модель могли уговорить в разговоре.
		if($this->getOwnCode($request) === CopilotPrompt::EXTRACT_FIELDS)
		{
			$result = $this->llm->completeJsonObject($this->getMessages($request));
			$json = (array)$result->json;
			$names = CopilotPrompt::getFieldNames($request);
			if($names !== [])
			{
				$json = array_intersect_key($json, array_flip($names));
			}

			return new Result(
				$json === [] ? '{}' : (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
				$result->getTokens(),
				$result->costMicro
			);
		}

		$result = $this->llm->complete($this->getMessages($request));

		return new Result($result->text, $result->getTokens(), $result->costMicro);
	}

	/** Код своего промпта, если свои промпты включены и код наш. */
	private function getOwnCode(Request $request): ?string
	{
		return $this->config->isOwnPromptsEnabled() ? CopilotPrompt::getCode($request) : null;
	}

	/** @return list<array{role: string, content: string}> */
	private function getMessages(Request $request): array
	{
		return ($this->getOwnCode($request) !== null ? CopilotPrompt::getMessages($request) : []) ?: $request->getChatMessages();
	}
}

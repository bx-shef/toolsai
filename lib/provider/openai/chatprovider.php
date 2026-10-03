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
 * Резюме звонка и заполнение полей — свои промпты (CopilotPrompt): промпты
 * ядра на коробке обфусцированы. Остальное — промпт ядра как есть,
 * Request::getChatMessages().
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
		// Поля CRM разбирает как JSON: отдаём ровно объект, без обёрток.
		if(CopilotPrompt::getCode($request) === CopilotPrompt::EXTRACT_FIELDS)
		{
			$result = $this->llm->completeJsonObject($this->getMessages($request));

			return new Result($result->json === [] ? '{}' : (string)json_encode($result->json, JSON_UNESCAPED_UNICODE), $result->getTokens(), $result->costMicro);
		}

		$result = $this->llm->complete($this->getMessages($request));

		return new Result($result->text, $result->getTokens(), $result->costMicro);
	}

	/** @return list<array{role: string, content: string}> */
	private function getMessages(Request $request): array
	{
		return CopilotPrompt::getMessages($request) ?: $request->getChatMessages();
	}
}

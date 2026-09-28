<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Provider\Result;

/**
 * Категория text: резюме звонка, заполнение полей, оценка разговора.
 *
 * Промпт собирает ядро (сценарии crm/lib/Copilot/Pipeline/Scenario/*),
 * здесь он только переводится в сообщения chat completions —
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
		foreach($request->getChatMessages() as $message)
		{
			$chars += mb_strlen($message['content']);
		}

		return $this->llm->getCostMicro(intdiv($chars, 2) + 1, self::ESTIMATE_OUT_TOKENS);
	}

	public function run(Request $request): Result
	{
		$result = $this->llm->complete($request->getChatMessages());

		return new Result($result->text, $result->getTokens(), $result->costMicro);
	}
}

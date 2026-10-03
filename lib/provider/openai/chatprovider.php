<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Completion\CopilotPrompt;
use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderException;
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

	/**
	 * Лимит ответа для оценки по скрипту: критериев много, у каждого
	 * пояснение. 8192 — потолок выхода deepseek-chat; больше потолка модели
	 * провайдер отвечает 400, и оценка не прошла бы ни на одном звонке.
	 * Замер на портале (bx-shef/toolsai#24): 26 критериев — 2955 токенов
	 * с рассуждениями. Свой потолок — max_tokens в «Доп. параметрах модели».
	 */
	private const SCORING_MAX_TOKENS = 8192;

	/** Оценка ответа до вызова для оценки звонка — он длиннее резюме. */
	private const SCORING_ESTIMATE_OUT_TOKENS = 4000;

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

		$out = $this->getOwnCode($request) === CopilotPrompt::CALL_SCORING ? self::SCORING_ESTIMATE_OUT_TOKENS : self::ESTIMATE_OUT_TOKENS;

		return $this->llm->getCostMicro(intdiv($chars, 2) + 1, $out);
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

		// Оценка по скрипту: CRM разбирает JSON строгой формы — приводим к ней.
		if($this->getOwnCode($request) === CopilotPrompt::CALL_SCORING)
		{
			// Ответ длинный: пояснение на каждый критерий (на портале их 26) плюс
			// рассуждения модели — умолчание провайдера (у DeepSeek ~4K токенов)
			// обрезает JSON посередине (bx-shef/toolsai#11).
			$result = $this->llm->completeJsonObject($this->getMessages($request), self::SCORING_MAX_TOKENS);
			$scoring = CopilotPrompt::normalizeScoring((array)$result->json);
			// Ни одного критерия — оценки нет: CRM получила бы пустую, а журнал
			// записал бы SUCCESS. Оплаченный негодный ответ — ошибкой с ценой,
			// ядру — колбэк ошибки (как PAYLOAD_IS_EMPTY у полей, #11).
			if($scoring['call_review']['criteria'] === [])
			{
				throw new ProviderException('Модель не оценила ни одного критерия', 'provider_bad_response', null, $result->getTokens(), $result->costMicro);
			}

			return new Result(
				(string)json_encode($scoring, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
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

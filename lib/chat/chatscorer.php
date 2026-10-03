<?php declare(strict_types=1);

namespace Shef\ToolsAi\Chat;

use Shef\ToolsAi\Completion\CopilotPrompt;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;
use Shef\ToolsAi\Provider\Llm\LlmResult;
use Shef\ToolsAi\Provider\OpenAi\Llm;
use Shef\ToolsAi\Provider\ProviderException;

/**
 * Запрос к модели: оценка переписки по критериям скрипта.
 *
 * Промпт — тот же, что у своей оценки звонка (CopilotPrompt::
 * scoringMessages(), в режиме переписки), ответ — плоский JSON, его
 * разбирает и приводит к форме CopilotPrompt::normalizeScoring(). Модель —
 * текстовая точка доступа модуля (Container::getLlm('text')).
 *
 * * OpenAi\Llm — completeJsonObject() с потолком ответа, как у оценки
 *   звонка (ChatProvider::SCORING_MAX_TOKENS): критериев много, у каждого
 *   пояснение;
 * * заглушка (echo) — ответ без модели и без денег, ChatScore::stubAnswer();
 * * другой LlmProviderInterface — complete() и объект от «{» до «}».
 *
 * Ни одного критерия в ответе — ProviderException provider_bad_response
 * с расходом: ответ оплачен, но оценки нет.
 */
final class ChatScorer
{
	/** Потолок ответа, токенов: как у оценки звонка (ChatProvider). */
	public const MAX_TOKENS = 8192;

	/** Запас на ответ при оценке цены до запроса, токенов. */
	private const ESTIMATE_OUT_TOKENS = 4000;

	public function __construct(
		private readonly LlmProviderInterface $llm,
	)
	{
	}

	public function getLlmCode(): string
	{
		return $this->llm->getCode();
	}

	/**
	 * Оценка цены до запроса — для записи «в работе» в журнале и квоты:
	 * токен — не больше 2 символов русского текста, ответ — с запасом, как
	 * у оценки звонка (ChatProvider::SCORING_ESTIMATE_OUT_TOKENS). Цены
	 * знает только OpenAi\Llm; заглушка и чужой провайдер — 0.
	 *
	 * @param list<string> $criteria
	 */
	public function estimateCostMicro(string $transcript, array $criteria): int
	{
		if(!$this->llm instanceof Llm)
		{
			return 0;
		}

		$chars = 0;
		foreach(CopilotPrompt::scoringMessages(['transcript' => $transcript, 'criteria' => $criteria], true) as $message)
		{
			$chars += mb_strlen($message['content']);
		}

		return $this->llm->getCostMicro(intdiv($chars, 2) + 1, self::ESTIMATE_OUT_TOKENS);
	}

	/**
	 * @param list<string> $criteria критерии скрипта
	 * @param array{manager_name?: string, client_type?: string, language?: string} $context
	 * @return array{scoring: array, result: LlmResult}
	 * @throws ProviderException
	 */
	public function score(string $transcript, array $criteria, array $context = []): array
	{
		$messages = CopilotPrompt::scoringMessages(['transcript' => $transcript, 'criteria' => $criteria] + $context, true);

		if($this->llm->getCode() === Constants::PROVIDER_ECHO)
		{
			$json = ChatScore::stubAnswer($criteria);
			$result = new LlmResult((string)json_encode($json, JSON_UNESCAPED_UNICODE), $json);
		}
		elseif($this->llm instanceof Llm)
		{
			$result = $this->llm->completeJsonObject($messages, self::MAX_TOKENS);
		}
		else
		{
			$result = $this->llm->complete($messages);
			$json = static::extractObject($result->text);
			if($json === null)
			{
				throw new ProviderException('Модель ответила не JSON-объектом', 'provider_bad_response', null, $result->getTokens(), $result->costMicro);
			}
			$result = new LlmResult($result->text, $json, $result->tokensIn, $result->tokensOut, $result->costMicro, $result->finishReason);
		}

		$scoring = CopilotPrompt::normalizeScoring((array)$result->json);
		if($scoring['call_review']['criteria'] === [])
		{
			throw new ProviderException('Модель не оценила ни одного критерия', 'provider_bad_response', null, $result->getTokens(), $result->costMicro);
		}

		return ['scoring' => $scoring, 'result' => $result];
	}

	/** Объект от первой «{» до последней «}», как ищет его CRM. Им же пользуются письма (Email\EmailAnalyzer). */
	public static function extractObject(string $text): ?array
	{
		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		if($start === false || $end === false || $end < $start)
		{
			return null;
		}

		$json = json_decode(substr($text, $start, $end - $start + 1), true);

		return is_array($json) && !array_is_list($json) ? $json : null;
	}
}

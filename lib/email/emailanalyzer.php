<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email;

use Shef\ToolsAi\Chat\ChatScorer;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;
use Shef\ToolsAi\Provider\Llm\LlmResult;
use Shef\ToolsAi\Provider\OpenAi\Llm;
use Shef\ToolsAi\Provider\ProviderException;

/**
 * Запросы к модели по письмам — один на входящее (резюме и дела вместе),
 * один на исходящее (оценка). Модель — текстовая точка доступа модуля
 * (Container::getLlm('text')), как у оценки чатов:
 *
 * * OpenAi\Llm — completeJsonObject() с потолком ответа;
 * * заглушка (echo) — ответ без модели и без денег (EmailPrompt::stub*());
 * * другой LlmProviderInterface — complete() и объект от «{» до «}».
 *
 * Негодный ответ (у входящего нет is_client, у оценки ни одного критерия) —
 * ProviderException provider_bad_response с расходом: ответ оплачен.
 */
final class EmailAnalyzer
{
	/** Потолок ответа, токенов. */
	public const MAX_TOKENS_INCOMING = 2048;
	public const MAX_TOKENS_REVIEW = 4096;

	/** Запас на ответ при оценке цены до запроса, токенов. */
	private const ESTIMATE_OUT_TOKENS = 1500;

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
	 * Оценка цены до запроса — для записи «в работе» в журнале: токен — не
	 * больше 2 символов русского текста. Цены знает только OpenAi\Llm.
	 *
	 * @param list<array{role: string, content: string}> $messages
	 */
	public function estimateCostMicro(array $messages): int
	{
		if(!$this->llm instanceof Llm)
		{
			return 0;
		}

		$chars = 0;
		foreach($messages as $message)
		{
			$chars += mb_strlen($message['content']);
		}

		return $this->llm->getCostMicro(intdiv($chars, 2) + 1, self::ESTIMATE_OUT_TOKENS);
	}

	/**
	 * Входящее письмо: is_client, резюме, дела.
	 *
	 * @param list<array{role: string, content: string}> $messages — EmailPrompt::incomingMessages()
	 * @return array{answer: array, result: LlmResult}
	 * @throws ProviderException
	 */
	public function analyzeIncoming(array $messages, int $now): array
	{
		$result = $this->ask($messages, self::MAX_TOKENS_INCOMING, static fn(): array => EmailPrompt::stubIncoming());
		$answer = EmailPrompt::normalizeIncoming((array)$result->json, $now);
		if($answer['is_client'] === null)
		{
			throw new ProviderException('Модель не ответила, от клиента ли письмо (is_client)', 'provider_bad_response', null, $result->getTokens(), $result->costMicro);
		}

		return ['answer' => $answer, 'result' => $result];
	}

	/**
	 * Исходящее письмо: оценка по критериям.
	 *
	 * @param list<array{role: string, content: string}> $messages — EmailPrompt::reviewMessages()
	 * @param list<string> $criteria
	 * @return array{scoring: array, result: LlmResult}
	 * @throws ProviderException
	 */
	public function review(array $messages, array $criteria): array
	{
		$result = $this->ask($messages, self::MAX_TOKENS_REVIEW, static fn(): array => EmailPrompt::stubReview($criteria));
		$scoring = EmailPrompt::normalizeReview((array)$result->json);
		if($scoring['call_review']['criteria'] === [])
		{
			throw new ProviderException('Модель не оценила ни одного критерия', 'provider_bad_response', null, $result->getTokens(), $result->costMicro);
		}

		return ['scoring' => $scoring, 'result' => $result];
	}

	/**
	 * @param callable(): array $stub ответ заглушки
	 * @throws ProviderException
	 */
	private function ask(array $messages, int $maxTokens, callable $stub): LlmResult
	{
		if($this->llm->getCode() === Constants::PROVIDER_ECHO)
		{
			$json = $stub();

			return new LlmResult((string)json_encode($json, JSON_UNESCAPED_UNICODE), $json);
		}

		if($this->llm instanceof Llm)
		{
			return $this->llm->completeJsonObject($messages, $maxTokens);
		}

		$result = $this->llm->complete($messages);
		$json = ChatScorer::extractObject($result->text);
		if($json === null)
		{
			throw new ProviderException('Модель ответила не JSON-объектом', 'provider_bad_response', null, $result->getTokens(), $result->costMicro);
		}

		return new LlmResult($result->text, $json, $result->tokensIn, $result->tokensOut, $result->costMicro, $result->finishReason);
	}
}

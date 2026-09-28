<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;
use Shef\ToolsAi\Provider\Llm\LlmResult;
use Shef\ToolsAi\Provider\ProviderException;

/**
 * LLM через /chat/completions.
 */
final class Llm implements LlmProviderInterface
{
	public function __construct(
		private readonly Config $config,
		private readonly Client $client,
	)
	{
	}

	public function getCode(): string
	{
		return Constants::PROVIDER_OPENAI;
	}

	public function complete(array $messages): LlmResult
	{
		if($messages === [])
		{
			throw new ProviderException('Пустой промпт: нечего отправлять модели', 'empty_prompt');
		}

		return $this->request([
			'model' => $this->config->getLlmModel(),
			'messages' => $messages,
		]);
	}

	/**
	 * JSON Schema — через response_format: ответ либо валиден, либо провайдер
	 * честно падает. Свободный текст пришлось бы разбирать регулярками.
	 */
	public function completeJson(string $system, string $user, array $schema): LlmResult
	{
		$result = $this->request([
			'model' => $this->config->getLlmModel(),
			'messages' => [
				['role' => 'system', 'content' => $system],
				['role' => 'user', 'content' => $user],
			],
			'response_format' => [
				'type' => 'json_schema',
				'json_schema' => [
					'name' => 'answer',
					'strict' => true,
					'schema' => $schema,
				],
			],
		]);

		$json = static::extractJson($result->text);
		if($json === null)
		{
			throw new ProviderException('Модель вернула не JSON', 'provider_bad_response');
		}

		return new LlmResult($result->text, $json, $result->tokensIn, $result->tokensOut, $result->costMicro);
	}

	/**
	 * JSON из ответа модели. Часть совместимых серверов response_format
	 * игнорирует и заворачивает ответ в ```json ... ``` — снимаем обёртку.
	 */
	public static function extractJson(string $text): ?array
	{
		$text = trim($text);
		if(preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $match))
		{
			$text = $match[1];
		}

		$data = json_decode($text, true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Стоимость: цены в настройках — за миллион токенов, деньги в
	 * микро-единицах, то есть токен × цена за миллион даёт микро-единицы
	 * ровно. Округление вверх — только на дробной цене.
	 */
	public function getCostMicro(int $tokensIn, int $tokensOut): int
	{
		return intdiv($tokensIn * $this->config->getLlmPriceInMicro() + 999_999, 1_000_000)
			+ intdiv($tokensOut * $this->config->getLlmPriceOutMicro() + 999_999, 1_000_000);
	}

	private function request(array $payload): LlmResult
	{
		$data = $this->client->postJson('chat/completions', $payload);

		$content = $data['choices'][0]['message']['content'] ?? null;
		if(!is_string($content))
		{
			throw new ProviderException('В ответе модели нет текста', 'provider_bad_response');
		}

		$tokensIn = is_int($data['usage']['prompt_tokens'] ?? null) ? $data['usage']['prompt_tokens'] : 0;
		$tokensOut = is_int($data['usage']['completion_tokens'] ?? null) ? $data['usage']['completion_tokens'] : 0;

		return new LlmResult($content, null, $tokensIn, $tokensOut, $this->getCostMicro($tokensIn, $tokensOut));
	}
}

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
	/** Провайдер отверг response_format json_schema — дальше сразу json_object. */
	private bool $schemaRejected = false;

	/** Провайдер отверг и json_object — дальше без response_format. */
	private bool $jsonObjectRejected = false;

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
	 * Ответ по схеме.
	 *
	 * Сначала response_format json_schema (strict): ответ либо валиден, либо
	 * провайдер честно падает. Не все OpenAI-совместимые его знают: DeepSeek
	 * понимает только json_object и на схему отвечает 4xx (bx-shef/toolsai#12).
	 * Тогда — один повтор с json_object, схема — в системном сообщении, и
	 * этот провайдер дальше спрашивается сразу так. Ответ в обоих режимах
	 * сверяется со схемой у нас (validate()): провайдеру на слово не верим.
	 */
	public function completeJson(string $system, string $user, array $schema): LlmResult
	{
		$result = null;
		if(!$this->schemaRejected)
		{
			try
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
			}
			catch(ProviderException $exception)
			{
				// provider_error — 4xx, кроме ключа (401/403) и лимита (429):
				// на них повтор с другим форматом не поможет.
				if($exception->errorCode !== 'provider_error')
				{
					throw $exception;
				}
				$this->schemaRejected = true;
			}
		}

		$result ??= $this->request([
			'model' => $this->config->getLlmModel(),
			'messages' => [
				[
					'role' => 'system',
					'content' => $system."\n\nОтветь одним JSON-объектом строго по этой JSON Schema, без пояснений:\n"
						.json_encode($schema, JSON_UNESCAPED_UNICODE),
				],
				['role' => 'user', 'content' => $user],
			],
			'response_format' => ['type' => 'json_object'],
		]);

		// Ответ ниже уже оплачен: негоден — расход едет с исключением, чтобы
		// попасть в журнал и квоту.
		$json = static::extractJson($result->text);
		if($json === null)
		{
			throw new ProviderException('Модель вернула не JSON', 'provider_bad_response', null, $result->tokensIn + $result->tokensOut, $result->costMicro);
		}

		$problem = static::validate($json, $schema);
		if($problem !== null)
		{
			throw new ProviderException('Ответ модели не по схеме: '.$problem, 'provider_bad_response', null, $result->tokensIn + $result->tokensOut, $result->costMicro);
		}

		return new LlmResult($result->text, $json, $result->tokensIn, $result->tokensOut, $result->costMicro);
	}

	/**
	 * Ответ — JSON-объект с ключами, которых модуль заранее не знает
	 * (заполнение полей CRM: имена полей приходят от ядра). Схемы нет,
	 * поэтому json_object; провайдер его не знает (4xx) — один повтор без
	 * response_format, инструкция про JSON и так в сообщениях. Ответ —
	 * объект, иначе provider_bad_response с расходом.
	 *
	 * @param list<array{role: string, content: string}> $messages
	 */
	public function completeJsonObject(array $messages): LlmResult
	{
		if($messages === [])
		{
			throw new ProviderException('Пустой промпт: нечего отправлять модели', 'empty_prompt');
		}

		$payload = ['model' => $this->config->getLlmModel(), 'messages' => $messages];
		$result = null;
		if(!$this->jsonObjectRejected)
		{
			try
			{
				$result = $this->request($payload + ['response_format' => ['type' => 'json_object']]);
			}
			catch(ProviderException $exception)
			{
				if($exception->errorCode !== 'provider_error')
				{
					throw $exception;
				}
				$this->jsonObjectRejected = true;
			}
		}
		$result ??= $this->request($payload);

		// Без response_format модель может обернуть объект текстом — берём, как
		// CRM: от первой «{» до последней «}» (extractPayloadPrettifiedData).
		$json = static::extractJson($result->text);
		if($json === null && ($start = strpos($result->text, '{')) !== false && ($end = strrpos($result->text, '}')) > $start)
		{
			$json = static::extractJson(substr($result->text, $start, $end - $start + 1));
		}
		if($json === null || ($json !== [] && array_is_list($json)))
		{
			throw new ProviderException('Модель вернула не JSON-объект', 'provider_bad_response', null, $result->tokensIn + $result->tokensOut, $result->costMicro);
		}

		return new LlmResult($result->text, $json, $result->tokensIn, $result->tokensOut, $result->costMicro);
	}

	/**
	 * Проверка ответа по схеме — то, что используют схемы модуля: объект,
	 * обязательные ключи, типы свойств (вложенные объекты — рекурсивно).
	 * Лишние ключи не ошибка.
	 *
	 * @return string|null что не так; null — всё сходится
	 */
	public static function validate(mixed $value, array $schema, string $path = 'ответ'): ?string
	{
		$type = $schema['type'] ?? null;
		$ok = match($type)
		{
			'object' => is_array($value) && ($value === [] || !array_is_list($value)),
			'array' => is_array($value) && array_is_list($value),
			'string' => is_string($value),
			// 80.0 — тоже целое (JSON Schema так и считает); в режиме
			// json_object модели так отвечают.
			'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
			'number' => is_int($value) || is_float($value),
			'boolean' => is_bool($value),
			'null' => $value === null,
			default => true,
		};
		if(!$ok)
		{
			return $path.': ждали '.$type.', пришло '.get_debug_type($value);
		}

		if($type === 'object')
		{
			foreach((array)($schema['required'] ?? []) as $key)
			{
				if(!array_key_exists($key, $value))
				{
					return $path.': нет ключа «'.$key.'»';
				}
			}
			foreach((array)($schema['properties'] ?? []) as $key => $property)
			{
				if(array_key_exists($key, $value) && is_array($property))
				{
					$problem = static::validate($value[$key], $property, $path.'.'.$key);
					if($problem !== null)
					{
						return $problem;
					}
				}
			}
		}

		return null;
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
		// Дополнительные параметры провайдера (выключить рассуждения и т. п.)
		// — поверх, но без модели, сообщений и формата ответа.
		$data = $this->client->postJson('chat/completions', $payload + $this->config->getLlmExtra());

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

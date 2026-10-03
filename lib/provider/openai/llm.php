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

	/** completeJsonObject(): попыток на негодный ответ (пустой, не объект). */
	private const JSON_OBJECT_ATTEMPTS = 2;

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
	public function completeJsonObject(array $messages, ?int $maxTokens = null): LlmResult
	{
		if($messages === [])
		{
			throw new ProviderException('Пустой промпт: нечего отправлять модели', 'empty_prompt');
		}

		$payload = ['model' => $this->config->getLlmModel(), 'messages' => $messages];
		// max_tokens из «Доп. параметров модели» сильнее умолчания модуля:
		// потолок выхода у моделей разный, превышение — 400 на каждом запросе.
		$extraMax = $this->config->getLlmExtra()['max_tokens'] ?? null;
		if(is_int($extraMax) && $extraMax > 0)
		{
			$maxTokens = $extraMax;
		}
		if($maxTokens !== null && $maxTokens > 0)
		{
			$payload['max_tokens'] = $maxTokens;
		}

		// Негодный ответ (пустой, обрезанный, не объект) — один повтор: у
		// DeepSeek в режиме json_object пустой content бывает, и документация
		// провайдера советует повторить. Обе попытки оплачены — в расход идут
		// обе (bx-shef/toolsai#11: оценка звонка упала с «не JSON» и 0 в журнале).
		$tokensIn = 0;
		$tokensOut = 0;
		$costMicro = 0;
		$result = null;
		$jsonError = null;
		for($attempt = 1; $attempt <= self::JSON_OBJECT_ATTEMPTS; $attempt++)
		{
			try
			{
				$result = $this->requestJsonObject($payload);
			}
			catch(ProviderException $exception)
			{
				if($tokensIn + $tokensOut === 0 && $costMicro === 0)
				{
					throw $exception;
				}

				throw new ProviderException($exception->getMessage(), $exception->errorCode, $exception, $tokensIn + $tokensOut + $exception->spentUnits, $costMicro + $exception->spentMicro);
			}
			$tokensIn += $result->tokensIn;
			$tokensOut += $result->tokensOut;
			$costMicro += $result->costMicro;

			// Без response_format модель может обернуть объект текстом — берём, как
			// CRM: от первой «{» до последней «}» (extractPayloadPrettifiedData).
			$json = static::extractJson($result->text);
			if($json === null && ($start = strpos($result->text, '{')) !== false && ($end = strrpos($result->text, '}')) > $start)
			{
				$object = substr($result->text, $start, $end - $start + 1);
				$json = static::extractJson($object);
				// Частый огрех модели — прямые кавычки цитаты и переносы строк
				// внутри значений (боевой портал, #11: 6484 симв., stop, не JSON).
				if($json === null)
				{
					$jsonError = json_last_error_msg();
					$json = static::extractJson(static::repairJson($object));
				}
			}
			if($json !== null && ($json === [] || !array_is_list($json)))
			{
				return new LlmResult($result->text, $json, $tokensIn, $tokensOut, $costMicro, $result->finishReason);
			}
		}

		// Текст ответа в сообщение не кладём — в нём персональные данные
		// разговора; форма ответа достаточна, чтобы понять причину.
		throw new ProviderException(
			sprintf(
				'Модель вернула не JSON-объект (попыток %d; последний ответ: %d симв., finish_reason %s; разбор: %s)',
				self::JSON_OBJECT_ATTEMPTS,
				mb_strlen((string)$result?->text),
				($result?->finishReason ?? '') !== '' ? $result->finishReason : '—',
				$jsonError ?? 'не объект'
			),
			'provider_bad_response',
			null,
			$tokensIn + $tokensOut,
			$costMicro
		);
	}

	/** Запрос с json_object; провайдер его отверг — дальше без response_format. */
	private function requestJsonObject(array $payload): LlmResult
	{
		if(!$this->jsonObjectRejected)
		{
			try
			{
				return $this->request($payload + ['response_format' => ['type' => 'json_object']]);
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

		return $this->request($payload);
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
	/**
	 * Починка JSON-объекта от типичных огрехов модели внутри строк: прямая
	 * кавычка цитаты («сказал "добрый день"») и сырые управляющие символы
	 * (перенос строки, таб). Кавычка внутри строки считается закрывающей,
	 * только если за ней (через пробелы) идёт , : } ] или конец текста;
	 * иначе — экранируется. Структуру не угадываем: что не починилось,
	 * json_decode отвергнет.
	 */
	public static function repairJson(string $text): string
	{
		$out = '';
		$inString = false;
		$length = strlen($text);
		for($i = 0; $i < $length; $i++)
		{
			$char = $text[$i];
			if(!$inString)
			{
				$out .= $char;
				if($char === '"')
				{
					$inString = true;
				}
				continue;
			}

			if($char === '\\')
			{
				$out .= $char.($text[$i + 1] ?? '');
				$i++;
				continue;
			}

			if($char === '"')
			{
				$next = ltrim(substr($text, $i + 1));
				// После запятой должно начинаться новое значение или ключ —
				// иначе это запятая внутри цитаты («сказал "да", потом…»).
				$afterComma = $next !== '' && $next[0] === ',' ? ltrim(substr($next, 1)) : '';
				if(
					$next === ''
					|| str_contains(':}]', $next[0])
					|| ($next[0] === ',' && $afterComma !== '' && str_contains('"{[-0123456789tfn', $afterComma[0]))
				)
				{
					$inString = false;
					$out .= $char;
				}
				else
				{
					$out .= '\\"';
				}
				continue;
			}

			$out .= match($char)
			{
				"\n" => '\\n',
				"\r" => '\\r',
				"\t" => '\\t',
				default => ord($char) < 0x20 ? sprintf('\\u%04x', ord($char)) : $char,
			};
		}

		return $out;
	}

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

		$finishReason = $data['choices'][0]['finish_reason'] ?? '';

		return new LlmResult($content, null, $tokensIn, $tokensOut, $this->getCostMicro($tokensIn, $tokensOut), is_scalar($finishReason) ? (string)$finishReason : '');
	}
}

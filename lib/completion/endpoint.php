<?php declare(strict_types=1);

namespace Shef\ToolsAi\Completion;

use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Security\CallbackGuard;
use Shef\ToolsAi\Security\Token;

/**
 * Решение эндпоинта: какой статус отдать и что увести в фон.
 *
 * Сам файл эндпоинта (endpoint/completions.php) только читает запрос и
 * пишет ответ; всё, что можно проверить без портала, — здесь.
 *
 * ДВА РАЗНЫХ ОЖИДАЕМЫХ СТАТУСА НА ОДНОМ URL:
 *
 *   GET  -> 200  проверка при регистрации движка
 *                (ThirdPartyRegisterService::validateCompletionsUrl)
 *   POST -> 202  приём задания (ThirdParty::HTTP_STATUS_OK = 202,
 *                ai/lib/Engine/ThirdParty.php:23)
 *
 * Именно 202, не 200: сравнение строгое (ThirdParty.php:254), и 200 на POST
 * ядро сочтёт сбоем — при том что запрос ушёл и выглядел успешным.
 */
final class Endpoint
{
	/** Больше — не запрос ядра. Транскрипт часового звонка — сотни КБ. */
	public const MAX_BODY_BYTES = 5 * 1024 * 1024;

	public function __construct(
		private readonly string $token,
		private readonly CallbackGuard $callbackGuard,
	)
	{
	}

	public function handle(string $method, array $query, string $rawBody): EndpointResponse
	{
		$method = mb_strtoupper($method);

		// Проверка при регистрации движка: ровно 200. Ничего не раскрывает,
		// поэтому без токена — удобно проверять curl'ом снаружи.
		if($method === 'GET' || $method === 'HEAD')
		{
			return new EndpointResponse(200, ['status' => 'ok']);
		}

		if($method !== 'POST')
		{
			return new EndpointResponse(405, ['error' => 'method_not_allowed']);
		}

		if(!Token::check($this->token, $query[Constants::TOKEN_PARAM] ?? null))
		{
			return new EndpointResponse(403, ['error' => 'forbidden']);
		}

		if(strlen($rawBody) > static::MAX_BODY_BYTES)
		{
			return new EndpointResponse(413, ['error' => 'too_large']);
		}

		try
		{
			$data = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
		}
		catch(\JsonException)
		{
			return new EndpointResponse(400, ['error' => 'bad_json']);
		}

		if(!is_array($data))
		{
			return new EndpointResponse(400, ['error' => 'bad_json']);
		}

		$request = Request::fromArray($data);

		if(!$request->isValid())
		{
			return new EndpointResponse(400, ['error' => 'bad_request']);
		}

		// Хэш задания ядро кладёт в колбэк всегда. Без него нет ни защиты от
		// повторов, ни идемпотентности расхода: каждый такой POST — новый
		// платный запрос.
		if($request->getJobHash() === null)
		{
			return new EndpointResponse(400, ['error' => 'no_hash']);
		}

		if(!in_array($request->category, Constants::getCategoryList(), true))
		{
			return new EndpointResponse(400, ['error' => 'unsupported_category']);
		}

		if(
			!$this->callbackGuard->isAllowed($request->callbackUrl)
			|| !$this->callbackGuard->isAllowed($request->errorCallbackUrl)
		)
		{
			return new EndpointResponse(400, ['error' => 'foreign_callback']);
		}

		return new EndpointResponse(202, ['accepted' => true], $request);
	}
}

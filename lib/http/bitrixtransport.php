<?php declare(strict_types=1);

namespace Shef\ToolsAi\Http;

use Bitrix\Main\Web\HttpClient;

/**
 * Транспорт на HttpClient ядра.
 *
 * setPrivateIp(true): портал и сервис распознавания часто живут в одной
 * приватной сети, а колбэк уходит на сам портал. Ядро по умолчанию
 * приватные адреса запрещает (защита от SSRF), и колбэк на 10.x молча не
 * ушёл бы. Куда именно разрешено слать колбэк, решает Security\CallbackGuard.
 */
final class BitrixTransport implements TransportInterface
{
	public function post(string $url, string $body, array $headers, int $timeout): Response
	{
		$http = $this->create($timeout);
		foreach($headers as $name => $value)
		{
			$http->setHeader($name, $value);
		}

		$result = $http->post($url, $body);

		return new Response(
			(int)$http->getStatus(),
			is_string($result) ? $result : '',
			$this->getError($http),
		);
	}

	public function get(string $url, array $headers, int $timeout, int $maxBytes): Response
	{
		$http = $this->create($timeout);
		foreach($headers as $name => $value)
		{
			$http->setHeader($name, $value);
		}

		// Сверх лимита HttpClient обрывает чтение и отдаёт ошибку.
		$http->setBodyLengthMax($maxBytes);

		$result = $http->get($url);

		return new Response(
			(int)$http->getStatus(),
			is_string($result) ? $result : '',
			$this->getError($http),
		);
	}

	private function create(int $timeout): HttpClient
	{
		$http = new HttpClient([
			'socketTimeout' => min(30, $timeout),
			'streamTimeout' => $timeout,
			'redirect' => true,
			'redirectMax' => 3,
		]);
		$http->setPrivateIp(true);

		return $http;
	}

	private function getError(HttpClient $http): string
	{
		$errors = $http->getError();

		return is_array($errors) ? implode('; ', $errors) : '';
	}
}

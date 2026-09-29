<?php declare(strict_types=1);

namespace Shef\ToolsAi\Http;

use Bitrix\Main\Web\HttpClient;

/**
 * Транспорт на HttpClient ядра.
 *
 * Редиректы выключены: см. TransportInterface. Приватные адреса — только там,
 * где адрес проверен или задан администратором: POST (колбэк на портал,
 * провайдер в своей сети) и GET с $allowPrivate. Ядро по умолчанию приватные
 * адреса запрещает (защита от SSRF), и это умолчание остаётся для всего
 * остального.
 */
final class BitrixTransport implements TransportInterface
{
	public function post(string $url, string $body, array $headers, int $timeout): Response
	{
		$http = $this->create($timeout, true, $headers);
		$result = $http->post($url, $body);

		return $this->toResponse($http, $result);
	}

	public function get(string $url, array $headers, int $timeout, int $maxBytes, bool $allowPrivate = false): Response
	{
		$http = $this->create($timeout, $allowPrivate, $headers);

		// Сверх лимита HttpClient обрывает чтение и отдаёт ошибку.
		$http->setBodyLengthMax($maxBytes);

		$result = $http->get($url);

		return $this->toResponse($http, $result);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function create(int $timeout, bool $allowPrivate, array $headers): HttpClient
	{
		$http = new HttpClient([
			'socketTimeout' => min(30, $timeout),
			'streamTimeout' => $timeout,
			'redirect' => false,
		]);
		$http->setPrivateIp($allowPrivate);

		foreach($headers as $name => $value)
		{
			$http->setHeader($name, $value);
		}

		return $http;
	}

	private function toResponse(HttpClient $http, mixed $result): Response
	{
		$errors = $http->getError();
		$location = $http->getHeaders()->get('Location');

		return new Response(
			(int)$http->getStatus(),
			is_string($result) ? $result : '',
			is_array($errors) ? implode('; ', $errors) : '',
			is_string($location) ? $location : '',
		);
	}
}

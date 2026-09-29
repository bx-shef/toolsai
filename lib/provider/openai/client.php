<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Config;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Http\TransportInterface;
use Shef\ToolsAi\Provider\ProviderException;

/**
 * Клиент OpenAI-совместимого API.
 *
 * «Совместимого» — сознательно: тот же протокол у OpenAI, у своего
 * whisper-сервера (faster-whisper-server, whisper.cpp server), у vLLM,
 * LocalAI, Ollama (/v1) и у прокси. Провайдер меняется настройкой
 * API_baseurl, а не кодом.
 *
 * Ключ — только заголовком и только из настроек; в текст ошибки не попадает
 * ни ключ, ни тело ответа целиком — тело бывает с персональными данными из
 * разговора.
 */
final class Client
{
	/** Сколько символов ответа провайдера класть в текст ошибки. */
	private const ERROR_SNIPPET = 300;

	public function __construct(
		private readonly Config $config,
		private readonly TransportInterface $transport,
	)
	{
	}

	/**
	 * POST JSON, ответ — разобранный JSON.
	 *
	 * @throws ProviderException
	 */
	public function postJson(string $path, array $payload): array
	{
		$response = $this->transport->post(
			$this->getUrl($path),
			(string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			$this->getHeaders() + ['Content-Type' => 'application/json'],
			$this->config->getTimeout()
		);

		return $this->decode($response);
	}

	/**
	 * POST multipart/form-data с одним файлом.
	 *
	 * Тело собираем сами, а не отдаём HttpClient массивом: формат multipart
	 * в ядре менялся между версиями, а здесь он проверяется тестом.
	 *
	 * @param array<string, string> $fields
	 * @throws ProviderException
	 */
	public function postFile(string $path, array $fields, string $fileField, string $fileName, string $contentType, string $content): array
	{
		$boundary = '----shef-toolsai-'.bin2hex(random_bytes(12));

		$response = $this->transport->post(
			$this->getUrl($path),
			static::buildMultipart($boundary, $fields, $fileField, $fileName, $contentType, $content),
			$this->getHeaders() + ['Content-Type' => 'multipart/form-data; boundary='.$boundary],
			$this->config->getTimeout()
		);

		return $this->decode($response);
	}

	/**
	 * @param array<string, string> $fields
	 */
	public static function buildMultipart(string $boundary, array $fields, string $fileField, string $fileName, string $contentType, string $content): string
	{
		$escape = static fn(string $value): string => str_replace(['"', "\r", "\n"], ['%22', '', ''], $value);

		$body = '';
		foreach($fields as $name => $value)
		{
			$body .= '--'.$boundary."\r\n"
				.'Content-Disposition: form-data; name="'.$escape((string)$name).'"'."\r\n\r\n"
				.$value."\r\n";
		}

		$body .= '--'.$boundary."\r\n"
			.'Content-Disposition: form-data; name="'.$escape($fileField).'"; filename="'.$escape($fileName).'"'."\r\n"
			.'Content-Type: '.$escape($contentType)."\r\n\r\n"
			.$content."\r\n"
			.'--'.$boundary."--\r\n";

		return $body;
	}

	private function getUrl(string $path): string
	{
		return $this->config->getBaseUrl().'/'.ltrim($path, '/');
	}

	/**
	 * @return array<string, string>
	 */
	private function getHeaders(): array
	{
		$key = $this->config->getApiKey();

		// Свой сервер в закрытой сети ключа может не требовать.
		return $key !== '' ? ['Authorization' => 'Bearer '.$key] : [];
	}

	/**
	 * @throws ProviderException
	 */
	private function decode(Response $response): array
	{
		if($response->status === 0)
		{
			throw new ProviderException('Провайдер недоступен: '.$response->error, 'provider_unavailable');
		}

		$data = json_decode($response->body, true);

		if(!$response->isOk())
		{
			$message = is_array($data) && is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null)
				? $data['error']['message']
				: mb_substr($response->body, 0, self::ERROR_SNIPPET);

			// Провайдер бывает рад повторить присланный ключ в тексте ошибки
			// («Incorrect API key sk-...»), а текст уходит в журнал и в карточку.
			$key = $this->config->getApiKey();
			if($key !== '')
			{
				$message = str_replace($key, '***', $message);
			}

			throw new ProviderException(
				sprintf('Провайдер ответил %d: %s', $response->status, mb_substr($message, 0, self::ERROR_SNIPPET)),
				match(true)
				{
					$response->status === 401, $response->status === 403 => 'provider_auth',
					$response->status === 429 => 'provider_rate_limit',
					$response->status >= 500 => 'provider_unavailable',
					default => 'provider_error',
				}
			);
		}

		if(!is_array($data))
		{
			throw new ProviderException('Провайдер вернул не JSON', 'provider_bad_response');
		}

		return $data;
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Http;

/**
 * Исходящий HTTP.
 *
 * Отдельный интерфейс ради одного: провайдеры и колбэк проверяются тестами без
 * сети и без ядра — тест подставляет свой транспорт и смотрит, что ушло.
 */
interface TransportInterface
{
	/**
	 * @param array<string, string> $headers
	 */
	public function post(string $url, string $body, array $headers, int $timeout): Response;

	/**
	 * @param array<string, string> $headers
	 * @param int $maxBytes больше — ошибка, тело не читается целиком в память
	 */
	public function get(string $url, array $headers, int $timeout, int $maxBytes): Response;
}

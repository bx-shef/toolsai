<?php declare(strict_types=1);

namespace Shef\ToolsAi\Http;

/**
 * Исходящий HTTP.
 *
 * Отдельный интерфейс ради одного: провайдеры и колбэк проверяются тестами без
 * сети и без ядра — тест подставляет свой транспорт и смотрит, что ушло.
 *
 * Редиректы транспорт НЕ проходит: адрес назначения проверяется до запроса
 * (Security\CallbackGuard), а редирект с разрешённого хоста увёл бы запрос
 * куда угодно, в том числе во внутреннюю сеть. Кому редирект нужен (скачивание
 * записи), проходит его сам, проверяя каждый шаг.
 */
interface TransportInterface
{
	/**
	 * Приватные адреса разрешены: колбэк уходит на сам портал, провайдер
	 * бывает в той же сети. Адрес колбэка проверен до вызова, адрес
	 * провайдера задан администратором.
	 *
	 * @param array<string, string> $headers
	 */
	public function post(string $url, string $body, array $headers, int $timeout): Response;

	/**
	 * @param array<string, string> $headers
	 * @param int $maxBytes больше — ошибка, тело не читается целиком в память
	 * @param bool $allowPrivate true — только для хоста портала
	 */
	public function get(string $url, array $headers, int $timeout, int $maxBytes, bool $allowPrivate = false): Response;
}

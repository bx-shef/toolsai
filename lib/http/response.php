<?php declare(strict_types=1);

namespace Shef\ToolsAi\Http;

/**
 * Ответ HTTP: статус, тело и адрес редиректа.
 *
 * Статус 0 — соединения не было (таймаут, DNS, отказ).
 */
final class Response
{
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly string $error = '',
		/** Заголовок Location при 3xx: редиректы транспорт сам не проходит. */
		public readonly string $location = '',
	)
	{
	}

	public function isOk(): bool
	{
		return $this->status >= 200 && $this->status < 300;
	}

	public function isRedirect(): bool
	{
		return $this->status >= 300 && $this->status < 400 && $this->location !== '';
	}
}

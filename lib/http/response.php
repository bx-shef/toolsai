<?php declare(strict_types=1);

namespace Shef\ToolsAi\Http;

/**
 * Ответ HTTP: статус и тело.
 *
 * Статус 0 — соединения не было (таймаут, DNS, отказ).
 */
final class Response
{
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly string $error = '',
	)
	{
	}

	public function isOk(): bool
	{
		return $this->status >= 200 && $this->status < 300;
	}
}

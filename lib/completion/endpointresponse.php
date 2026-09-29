<?php declare(strict_types=1);

namespace Shef\ToolsAi\Completion;

/**
 * Что ответить на запрос и что сделать после ответа.
 */
final class EndpointResponse
{
	public function __construct(
		public readonly int $status,
		public readonly array $body,
		/** Задание в фон — только при 202. */
		public readonly ?Request $job = null,
	)
	{
	}
}

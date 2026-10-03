<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\Llm;

/**
 * Ответ LLM и его цена.
 */
final class LlmResult
{
	public function __construct(
		public readonly string $text,
		/** Разобранный JSON — только у completeJson() и completeJsonObject(). */
		public readonly ?array $json = null,
		public readonly int $tokensIn = 0,
		public readonly int $tokensOut = 0,
		public readonly int $costMicro = 0,
	)
	{
	}

	public function getTokens(): int
	{
		return $this->tokensIn + $this->tokensOut;
	}
}

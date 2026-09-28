<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Shef\ToolsAi\Provider\Llm\LlmResult;

/**
 * Итог анализа: вердикт и, если модель звали, её ответ с ценой.
 */
final class Analysis
{
	public function __construct(
		public readonly Verdict $verdict,
		public readonly ?LlmResult $llm = null,
	)
	{
	}
}

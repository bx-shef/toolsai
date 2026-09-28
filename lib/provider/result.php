<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider;

/**
 * Результат работы провайдера: что вернул и во что обошлось.
 */
final class Result
{
	public function __construct(
		public readonly string $text,
		/** Секунды аудио для распознавания, токены для LLM. */
		public readonly int $units = 0,
		/** Стоимость в 1/1_000_000 единицы валюты. */
		public readonly int $costMicro = 0,
	)
	{
	}
}

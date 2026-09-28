<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\Llm;

use Shef\ToolsAi\Provider\ProviderException;

/**
 * Прямой доступ к LLM — минуя контракт движка Битрикса.
 *
 * Нужен анализу сделки (Shef\ToolsAi\Deal), который мы запускаем сами, а не
 * по цепочке Копилота.
 */
interface LlmProviderInterface
{
	public function getCode(): string;

	/**
	 * Ответ строго по JSON Schema.
	 *
	 * @param array $schema JSON Schema ответа
	 * @throws ProviderException если модель не вернула валидный JSON
	 */
	public function completeJson(string $system, string $user, array $schema): LlmResult;

	/**
	 * @param array<int, array{role: string, content: string}> $messages
	 * @throws ProviderException
	 */
	public function complete(array $messages): LlmResult;
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider;

use Shef\ToolsAi\Completion\Request;

/**
 * Провайдер для одной категории движка: audio или text.
 */
interface ProviderInterface
{
	/** Код для журнала расхода. */
	public function getCode(): string;

	/**
	 * Оценка стоимости ДО запроса — по ней Dispatcher решает, хватает ли
	 * квоты. Точность не важна, важно не уйти в минус: округляем вверх.
	 */
	public function estimateCostMicro(Request $request): int;

	/** @throws ProviderException если провайдер не справился */
	public function run(Request $request): Result;
}

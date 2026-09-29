<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Откуда берутся факты о сделке. Интерфейс — ради теста анализатора без CRM.
 */
interface FactsSourceInterface
{
	/** @throws \RuntimeException сделки нет */
	public function build(int $dealId): DealFacts;
}

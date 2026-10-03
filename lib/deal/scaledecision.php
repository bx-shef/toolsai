<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Решение шкалы профиля (RiskScale::decide()): какие шаги делать.
 */
final class ScaleDecision
{
	public function __construct(
		/** Дело ответственному за сделку. */
		public readonly bool $managerTodo = false,
		/** Комментарий в таймлайне — вместе с эскалацией. */
		public readonly bool $comment = false,
		/** Дело старшему профиля. */
		public readonly bool $seniorTodo = false,
		/** Эскалация нужна, но старший в профиле не задан. */
		public readonly bool $seniorMissing = false,
	)
	{
	}

	public function isEmpty(): bool
	{
		return !$this->managerTodo && !$this->comment && !$this->seniorTodo;
	}
}

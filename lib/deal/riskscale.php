<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Что делать по вердикту — шкала профиля. Чистая функция — без ядра.
 *
 *   риск <  LOW_BORDER  — ничего, только запись о проверке;
 *   риск >= LOW_BORDER  — дело МЕНЕДЖЕРУ (ответственному), но только если у
 *                         сделки нет запланированных дел: есть — менеджер и
 *                         так знает, что делать;
 *   риск >= HIGH_BORDER и модель сказала needSenior — комментарий в
 *                         таймлайне и дело СТАРШЕМУ (Verdict::shouldEscalate).
 *
 * needSenior по-прежнему обязателен для старшего: модель отличает «можно
 * спасти вмешательством» от «уже мертва». Высокий риск без needSenior —
 * уровень менеджера: дело ему, если дел нет.
 *
 * Старший задан и он же ответственный — одно дело (старшему, с его
 * текстом), а не два одному человеку.
 *
 * Просрочка (1.4.0): «запланированные» — только незавершённые дела со
 * сроком в будущем или без срока (DealFacts::activityState()); просроченные
 * менеджера от дела не освобождают. Если менеджеру ставится дело, а у
 * сделки есть просроченные дела, — старшему профиля дело «проконтролировать»
 * (seniorOverdueTodo). Не ставится, когда старшего нет, он же ответственный
 * или старшему и так уходит дело эскалации (одно дело одному человеку).
 *
 * Повторы: дело менеджеру — не чаще REANALYZE_DAYS (MANAGER_TODO_AT в
 * таблице проверок), эскалация — тоже (ESCALATED_AT), дело старшему о
 * просрочке — тоже (OVERDUE_NOTIFIED_AT).
 */
final class RiskScale
{
	/**
	 * @param bool $managerRecent дело менеджеру ставили меньше REANALYZE_DAYS назад
	 * @param bool $seniorRecent эскалация была меньше REANALYZE_DAYS назад
	 * @param int $openActivities запланированных дел (просроченные не в счёт)
	 * @param int $overdueActivities просроченных незавершённых дел
	 * @param bool $overdueRecent старшему о просрочке писали меньше REANALYZE_DAYS назад
	 */
	public static function decide(
		Verdict $verdict,
		Profile $profile,
		int $managerId,
		int $openActivities,
		bool $managerRecent = false,
		bool $seniorRecent = false,
		int $overdueActivities = 0,
		bool $overdueRecent = false,
	): ScaleDecision
	{
		if($verdict->skipped || $verdict->risk < $profile->lowBorder)
		{
			return new ScaleDecision();
		}

		$senior = !$seniorRecent && $verdict->shouldEscalate($profile->highBorder);
		$seniorTodo = $senior && $profile->seniorId > 0;

		$manager = !$managerRecent && $openActivities === 0 && $managerId > 0;
		// Один человек — одно дело: дело старшему его и так застанет.
		if($manager && $seniorTodo && $managerId === $profile->seniorId)
		{
			$manager = false;
		}

		$overdue = $manager
			&& $overdueActivities > 0
			&& !$overdueRecent
			&& !$seniorTodo
			&& $profile->seniorId > 0
			&& $profile->seniorId !== $managerId;

		return new ScaleDecision(
			managerTodo: $manager,
			comment: $senior,
			seniorTodo: $seniorTodo,
			seniorMissing: $senior && $profile->seniorId <= 0,
			seniorOverdueTodo: $overdue,
		);
	}
}

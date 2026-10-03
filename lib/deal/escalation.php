<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

/**
 * Шаги по решению шкалы профиля (RiskScale::decide()).
 *
 * По возрастанию навязчивости:
 *   1) дело «Сделать» (To-Do) ответственному — у сделки высокий риск и нет
 *      ни одного запланированного дела;
 *   2) комментарий в таймлайне сделки — видно при открытии карточки;
 *   3) дело старшему профиля с дедлайном — остаётся в списке, пока его не
 *      закроют. Старший не задан — только комментарий, и отчёт говорит почему.
 *
 * Уведомления в чат сознательно нет: их быстро перестают читать, а дело
 * в списке остаётся. Смена ответственного — слишком грубо для автомата.
 */
final class Escalation
{
	/** Дедлайн дела, часов. */
	private const DEADLINE_HOURS = 24;

	/**
	 * @return string[] что сделано: manager_todo, comment, todo
	 */
	public function apply(int $dealId, Verdict $verdict, Profile $profile, ScaleDecision $decision, int $managerId): array
	{
		if(($decision->isEmpty() && !$decision->seniorMissing) || !Loader::includeModule('crm'))
		{
			return [];
		}

		$done = [];

		if($decision->managerTodo && $this->addTodo($dealId, $managerId, static::buildManagerText($verdict)))
		{
			$done[] = 'manager_todo';
		}

		$text = static::buildText($verdict);
		if($decision->comment && $this->addComment($dealId, $text, $profile->seniorId))
		{
			$done[] = 'comment';
		}

		// Старший не задан — дело не ставится, и отчёт прогона говорит почему,
		// а не молчит (приёмка, bx-shef/toolsai#3).
		if($decision->seniorMissing)
		{
			$done[] = 'старший не задан — дела нет';
		}
		elseif($decision->seniorTodo && $this->addTodo($dealId, $profile->seniorId, $text))
		{
			$done[] = 'todo';
		}

		return $done;
	}

	public static function buildText(Verdict $verdict): string
	{
		return sprintf(
			"ИИ-анализ сделки: риск потери %d%%, нужен старший.\nПочему: %s\nЧто сделать: %s",
			$verdict->risk,
			$verdict->why !== '' ? $verdict->why : '—',
			$verdict->nextStep !== '' ? $verdict->nextStep : '—'
		);
	}

	public static function buildManagerText(Verdict $verdict): string
	{
		return sprintf(
			"ИИ-анализ сделки: нет запланированных дел, риск %d%%.\nЧто сделать: %s\nПочему: %s",
			$verdict->risk,
			$verdict->nextStep !== '' ? $verdict->nextStep : '—',
			$verdict->why !== '' ? $verdict->why : '—'
		);
	}

	private function addComment(int $dealId, string $text, int $authorId): bool
	{
		if(!class_exists(\Bitrix\Crm\Timeline\CommentEntry::class))
		{
			return false;
		}

		$id = \Bitrix\Crm\Timeline\CommentEntry::create([
			'TEXT' => $text,
			'AUTHOR_ID' => $authorId ?: 1,
			'BINDINGS' => [
				['ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => $dealId],
			],
		]);

		return (int)$id > 0;
	}

	private function addTodo(int $dealId, int $responsibleId, string $text): bool
	{
		if(!class_exists(\Bitrix\Crm\Activity\Entity\ToDo::class))
		{
			return false;
		}

		// В crm 26.800 конструктор — (ItemIdentifier, ActivityProvider):
		// с одним аргументом ArgumentCountError, и дело старшему молча не
		// ставилось (приёмка 2026-10-02, bx-shef/toolsai#3). В старых
		// версиях провайдера в конструкторе нет — его не передаём.
		$owner = new ItemIdentifier(\CCrmOwnerType::Deal, $dealId);
		$constructor = (new \ReflectionClass(\Bitrix\Crm\Activity\Entity\ToDo::class))->getConstructor();
		$todo = $constructor !== null && $constructor->getNumberOfRequiredParameters() >= 2
			? new \Bitrix\Crm\Activity\Entity\ToDo($owner, new \Bitrix\Crm\Activity\Provider\ToDo\ToDo())
			: new \Bitrix\Crm\Activity\Entity\ToDo($owner);
		$todo
			->setDescription($text)
			->setResponsibleId($responsibleId)
			->setDeadline((new DateTime())->add('+'.self::DEADLINE_HOURS.' hours'));

		return $todo->save()->isSuccess();
	}
}

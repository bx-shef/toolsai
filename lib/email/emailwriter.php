<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAi\Container;

/**
 * Запись в CRM по письмам: комментарий в ленту и дело «Сделать» (To-Do) —
 * так же, как пишут анализ сделок (Deal\Escalation) и оценка чатов, но в
 * сделку или лид.
 */
final class EmailWriter
{
	/** Комментарий в таймлайн сделки или лида (Timeline\CommentEntry::create()). */
	public static function addComment(int $ownerTypeId, int $ownerId, string $text): bool
	{
		if(!class_exists(\Bitrix\Crm\Timeline\CommentEntry::class))
		{
			return false;
		}

		$id = \Bitrix\Crm\Timeline\CommentEntry::create([
			'TEXT' => $text,
			'AUTHOR_ID' => Container::getAgentUserId() ?: 1,
			'BINDINGS' => [
				['ENTITY_TYPE_ID' => $ownerTypeId, 'ENTITY_ID' => $ownerId],
			],
		]);

		return (int)$id > 0;
	}

	/**
	 * Дело «Сделать» сотруднику в сделке или лиде. Конструктор ToDo — как в
	 * Deal\Escalation::addTodo(): в crm 26.800 два аргумента
	 * (ItemIdentifier, провайдер), в старых — один.
	 */
	public static function addTodo(int $ownerTypeId, int $ownerId, int $responsibleId, string $subject, string $description, int $deadline): bool
	{
		if($responsibleId <= 0 || !class_exists(\Bitrix\Crm\Activity\Entity\ToDo::class))
		{
			return false;
		}

		$owner = new ItemIdentifier($ownerTypeId, $ownerId);
		$constructor = (new \ReflectionClass(\Bitrix\Crm\Activity\Entity\ToDo::class))->getConstructor();
		$todo = $constructor !== null && $constructor->getNumberOfRequiredParameters() >= 2
			? new \Bitrix\Crm\Activity\Entity\ToDo($owner, new \Bitrix\Crm\Activity\Provider\ToDo\ToDo())
			: new \Bitrix\Crm\Activity\Entity\ToDo($owner);
		$todo
			->setSubject(mb_substr($subject, 0, 255))
			->setDescription($description !== '' ? $description : $subject)
			->setResponsibleId($responsibleId)
			->setDeadline(DateTime::createFromTimestamp($deadline));

		return $todo->save()->isSuccess();
	}
}

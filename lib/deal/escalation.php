<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAi\Config;

/**
 * Что делаем, когда вердикт говорит «зови старшего».
 *
 * Два шага по возрастанию навязчивости:
 *   1) комментарий в таймлайне сделки — видно при открытии карточки;
 *   2) дело «Сделать» (To-Do) на старшего с дедлайном — остаётся в списке,
 *      пока его не закроют. Старший не задан — только комментарий.
 *
 * Уведомления в чат сознательно нет: их быстро перестают читать, а дело
 * в списке остаётся. Смена ответственного — слишком грубо для автомата.
 */
final class Escalation
{
	/** Дедлайн дела старшему, часов. */
	private const DEADLINE_HOURS = 24;

	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * @return string[] что сделано: comment, todo
	 */
	public function escalate(int $dealId, Verdict $verdict): array
	{
		if(!$verdict->shouldEscalate($this->config->getEscalationThreshold()) || !Loader::includeModule('crm'))
		{
			return [];
		}

		$text = static::buildText($verdict);
		$done = [];

		if($this->addComment($dealId, $text))
		{
			$done[] = 'comment';
		}

		// Старший не задан (например, после переустановки) — дело не ставится,
		// и отчёт прогона говорит почему, а не молчит (приёмка, bx-shef/toolsai#3).
		$seniorId = $this->config->getSeniorUserId();
		if($seniorId <= 0 && $done !== [])
		{
			$done[] = 'старший не задан — дела нет';
		}
		elseif($seniorId > 0 && $this->addTodo($dealId, $seniorId, $text))
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

	private function addComment(int $dealId, string $text): bool
	{
		if(!class_exists(\Bitrix\Crm\Timeline\CommentEntry::class))
		{
			return false;
		}

		$id = \Bitrix\Crm\Timeline\CommentEntry::create([
			'TEXT' => $text,
			'AUTHOR_ID' => $this->config->getSeniorUserId() ?: 1,
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

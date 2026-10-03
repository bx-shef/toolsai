<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

/**
 * Тип клиента сделки — те же коды, что у речевой аналитики CRM
 * (crm/lib/Copilot/CallAssessment/Enum/ClientType.php): NEW=1, IN_WORK=2,
 * REPEATED_APPROACH=3, RETURN_CUSTOMER=4.
 *
 * Сам тип считает ядро — \Bitrix\Crm\Client\ClientTypeResolver::getType(),
 * отдаёт enum \Bitrix\Crm\Client\ClientType. Перевод в коды — ровно как
 * AssessmentClientTypeResolver::resolveByIdentifier(); здесь по имени
 * варианта, чтобы перевод проверялся тестом без ядра.
 *
 * Как ядро решает (crm/lib/Client/ClientTypeResolver.php), простыми словами.
 * Клиент сделки — её компания, нет компании — контакт (pickClient());
 * нет обоих — тип не определён (null), и подходят только профили «любой».
 *
 * * Вернувшийся (WithSale) — у клиента есть хотя бы одна сделка в успешной
 *   стадии (в том числе, если выиграна сама эта сделка).
 * * Повторное обращение (PreviouslyContacted) — успешных нет, есть
 *   проваленная.
 * * Новый (New) — клиент создан меньше часа назад. Но в классическом режиме
 *   CRM (с лидами) контакт и компания новыми не бывают: ядро сразу считает
 *   их «в работе». А анализ сделки идёт через дни после создания — для нас
 *   «новый» почти не встречается.
 * * В работе (Existing) — всё остальное.
 */
final class ClientType
{
	public const NEW = 1;
	public const IN_WORK = 2;
	public const REPEATED_APPROACH = 3;
	public const RETURN_CUSTOMER = 4;

	public const CLIENT_COMPANY = 'company';
	public const CLIENT_CONTACT = 'contact';

	/**
	 * По кому считать тип: компания сделки главнее контакта (решение
	 * владельца, 1.2.0) — сделка с компанией и контактом оценивается по
	 * истории компании. Нет обоих — null, тип не определён.
	 *
	 * @return array{0: string, 1: int}|null
	 */
	public static function pickClient(int $companyId, int $contactId): ?array
	{
		return match(true)
		{
			$companyId > 0 => [static::CLIENT_COMPANY, $companyId],
			$contactId > 0 => [static::CLIENT_CONTACT, $contactId],
			default => null,
		};
	}

	/** @return int[] */
	public static function getAll(): array
	{
		return [static::NEW, static::IN_WORK, static::REPEATED_APPROACH, static::RETURN_CUSTOMER];
	}

	/**
	 * Вариант \Bitrix\Crm\Client\ClientType по имени -> код. Unrecognised и
	 * незнакомое — null: такой сделке подходит только профиль «любой».
	 */
	public static function fromCoreName(string $name): ?int
	{
		return match($name)
		{
			'New' => static::NEW,
			'Existing' => static::IN_WORK,
			'PreviouslyContacted' => static::REPEATED_APPROACH,
			'WithSale' => static::RETURN_CUSTOMER,
			default => null,
		};
	}

	/**
	 * «1,3» -> [1, 3]. Незнакомые коды отбрасываются, повторы — тоже.
	 *
	 * @return int[]
	 */
	public static function parseList(mixed $value): array
	{
		if(is_array($value))
		{
			$value = implode(',', array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : '', $value));
		}
		if(!is_string($value) && !is_int($value))
		{
			return [];
		}

		$result = [];
		foreach(explode(',', (string)$value) as $part)
		{
			$part = trim($part);
			if(1 === preg_match('/^\d+$/', $part) && in_array((int)$part, static::getAll(), true))
			{
				$result[(int)$part] = (int)$part;
			}
		}
		ksort($result);

		return array_values($result);
	}

	/** [3, 1] -> «1,3» — как хранится в CLIENT_TYPES. */
	public static function toList(array $types): string
	{
		return implode(',', static::parseList($types));
	}
}

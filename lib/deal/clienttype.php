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
 */
final class ClientType
{
	public const NEW = 1;
	public const IN_WORK = 2;
	public const REPEATED_APPROACH = 3;
	public const RETURN_CUSTOMER = 4;

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

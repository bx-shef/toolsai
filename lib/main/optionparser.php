<?php declare(strict_types=1);

namespace Shef\ToolsAi\Main;

/**
 * Строгий разбор значений настроек.
 *
 * Значение приходит из формы строкой, и приведение типом на нём ошибается
 * молча: (int)'5 62' — это 5, (int)'' — 0, (float)'1,5' — 1. Здесь деньги и ID
 * пользователя, поэтому правило одно: значение либо разбирается целиком, либо
 * берётся умолчание. Половины значения не бывает.
 *
 * Класс чистый — без ядра: проверяется tests/config_test.php.
 */
class OptionParser
{
	/**
	 * ID сущности: целое > 0 или строка из цифр без ведущего нуля.
	 * Иначе 0 — «не задано».
	 */
	public static function id(mixed $value): int
	{
		if(is_int($value))
		{
			return $value > 0 ? $value : 0;
		}

		if(is_string($value) && 1 === preg_match('/^[1-9][0-9]{0,18}$/', trim($value)))
		{
			return (int)trim($value);
		}

		return 0;
	}

	/**
	 * Целое в границах; мусор и выход за границы — умолчание.
	 */
	public static function int(mixed $value, int $default, int $min = 0, int $max = PHP_INT_MAX): int
	{
		if(is_string($value))
		{
			$value = trim($value);
			if(1 !== preg_match('/^(0|[1-9][0-9]{0,17})$/', $value))
			{
				return $default;
			}

			$value = (int)$value;
		}

		if(!is_int($value) || $value < $min || $value > $max)
		{
			return $default;
		}

		return $value;
	}

	/**
	 * Сумма в валюте -> микро-единицы (1/1_000_000). Запятая и точка, до шести
	 * знаков после неё. Пусто — 0. Мусор — умолчание.
	 *
	 * Считаем строкой, а не через float: 0.1 * 1_000_000 во float — это
	 * 100000.00000000001, и на границе квоты такое округление решает, пройдёт
	 * запрос или нет.
	 */
	public static function micro(mixed $value, int $default = 0): int
	{
		if(is_int($value))
		{
			return $value >= 0 ? $value * 1_000_000 : $default;
		}

		if(!is_string($value))
		{
			return $default;
		}

		$value = str_replace([' ', "\u{00A0}"], '', trim($value));
		if($value === '')
		{
			return 0;
		}

		if(1 !== preg_match('/^([0-9]{1,12})(?:[.,]([0-9]{1,6}))?$/', $value, $match))
		{
			return $default;
		}

		return (int)$match[1] * 1_000_000 + (int)str_pad($match[2] ?? '', 6, '0');
	}

	/**
	 * Флажок: только 'Y'. Строка 'N' при (bool) дала бы true.
	 */
	public static function flag(mixed $value): bool
	{
		return $value === 'Y';
	}

	/**
	 * Список ID из множественного выбора: сериализованный массив (так хранит
	 * Enum с setShowRows() > 1) или одиночное значение.
	 *
	 * 0 допустим: это направление сделок по умолчанию.
	 *
	 * @return int[]|null null — настройка задана, но не разбирается; [] — пусто
	 */
	public static function idList(mixed $value): ?array
	{
		if($value === null || $value === '')
		{
			return [];
		}

		if(is_string($value) && str_starts_with($value, 'a:'))
		{
			$list = @unserialize(htmlspecialchars_decode($value), ['allowed_classes' => false]);
			if(!is_array($list))
			{
				return null;
			}
		}
		else
		{
			$list = [$value];
		}

		$result = [];
		foreach($list as $item)
		{
			if(is_int($item) && $item >= 0)
			{
				$result[] = $item;
				continue;
			}

			if(is_string($item) && 1 === preg_match('/^(0|[1-9][0-9]{0,18})$/', $item))
			{
				$result[] = (int)$item;
				continue;
			}

			return null;
		}

		return array_values(array_unique($result));
	}

	/**
	 * Адрес http(s) без хвостового слэша; иначе ''.
	 */
	public static function url(mixed $value): string
	{
		if(!is_string($value))
		{
			return '';
		}

		$value = rtrim(trim($value), '/');
		if($value === '' || 1 !== preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', $value))
		{
			return '';
		}

		return $value;
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Security;

/**
 * Токен эндпоинта.
 *
 * Эндпоинт публичный: без проверки чужой POST сожжёт квоту у платного
 * провайдера. Проверить подпись заголовком нельзя — запрос шлёт ядро, и своих
 * заголовков оно не добавляет. Поэтому секрет едет в completions_url
 * (?token=...), а адрес знают только ядро и администратор.
 *
 * Сравнение — hash_equals, в постоянном времени.
 */
final class Token
{
	public static function generate(): string
	{
		return bin2hex(random_bytes(32));
	}

	public static function check(string $expected, mixed $given): bool
	{
		if($expected === '' || !is_string($given) || $given === '')
		{
			return false;
		}

		return hash_equals($expected, $given);
	}
}

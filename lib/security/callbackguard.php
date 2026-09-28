<?php declare(strict_types=1);

namespace Shef\ToolsAi\Security;

use Shef\ToolsAi\Config;

/**
 * Куда эндпоинт согласен слать результат.
 *
 * URL колбэка приходит в теле запроса. Путь не проверяем — маршруты колбэков
 * ядро держит у себя (QueueJob.php:39,42) и может переименовать, мы берём их
 * как есть (docs/03-upgrade-watch.md, п. 3). Проверяем хост: он обязан быть
 * хостом портала. Иначе токен, утёкший вместе с адресом, давал бы слать POST
 * с результатом куда угодно, в том числе во внутреннюю сеть.
 */
final class CallbackGuard
{
	/**
	 * @param string[] $allowedHosts хост[:порт] строчными
	 */
	public function __construct(private readonly array $allowedHosts)
	{
	}

	public function isAllowed(string $url): bool
	{
		if($this->allowedHosts === [])
		{
			return false;
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);
		if(!is_string($scheme) || !in_array(mb_strtolower($scheme), ['http', 'https'], true))
		{
			return false;
		}

		// user:pass@ в адресе колбэка ядро не ставит; такой адрес — подделка.
		if(parse_url($url, PHP_URL_USER) !== null)
		{
			return false;
		}

		$host = Config::hostOf($url);

		return $host !== '' && in_array($host, $this->allowedHosts, true);
	}
}

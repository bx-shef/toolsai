<?php declare(strict_types=1);

namespace Shef\ToolsAi\Engine;

use Bitrix\AI\Model\EngineTable;
use Bitrix\AI\ThirdParty\Manager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Shef\ToolsAi\Main\Constants;

/**
 * Регистрация собственных ИИ-движков в таблице b_ai_engine.
 *
 * ПОЧЕМУ НЕ ЧЕРЕЗ REST
 * Метод ai.engine.register вешается только при Bitrix24::shouldUseB24()
 * (ai/lib/Rest.php:28), а тот первым делом проверяет установленный модуль
 * bitrix24 (ai/lib/Facade/Bitrix24.php:30). На коробке его нет — метода не
 * существует в списке REST вовсе.
 *
 * ПОЧЕМУ ЭТО ВСЁ-ТАКИ РАБОТАЕТ
 * Engine::loadThirdParty() (ai/lib/Engine.php:130-148) читает b_ai_engine
 * без единой проверки на bitrix24. Достаточно строки в таблице.
 *
 * ПОЧЕМУ ИМЕННО Manager::register(), А НЕ ThirdPartyRegisterService
 * Manager::register() (ai/lib/ThirdParty/Manager.php:26) — публичный метод,
 * его параметры $service и $server объявлены как mixed ... = null, так что он
 * вызывается и без REST-сервера. Он валидирует данные тем же сервисом, что и
 * REST, И СБРАСЫВАЕТ КЕШ (Manager.php:43). Ключ кеша — приватная константа
 * (Manager.php:14), снаружи пришлось бы хардкодить, а суффикс в нём Битрикс
 * наращивает при смене формата. Поэтому прямой вызов ThirdPartyRegisterService
 * в модуле ЗАПРЕЩЁН — docs/03-upgrade-watch.md, п. 10.
 */
final class Registrar
{
	/**
	 * Зарегистрировать движок штатно — Manager::register().
	 *
	 * ВНИМАНИЕ: в момент регистрации Битрикс делает GET на $completionsUrl и
	 * требует ровно 200 (ThirdPartyRegisterService::validateCompletionsUrl).
	 * Эндпоинт обязан быть поднят и доступен с самого портала.
	 *
	 * Существующий движок так не обновить: validateUniqueCode() смотрит в
	 * статический список движков процесса (Engine::loadThirdParty(), static
	 * $loaded), — смену адреса ведёт Setup::ensureEngines(): снять, потом
	 * зарегистрировать (решение владельца 2026-10-03: только штатно, два
	 * шага — нормально).
	 *
	 * @return Result data: engineId
	 */
	public function register(string $category, string $name, string $completionsUrl): Result
	{
		$result = new Result();

		if(!Loader::includeModule('ai'))
		{
			return $result->addError(new Error('Модуль ai не установлен', 'AI_MODULE_MISSING'));
		}

		try
		{
			// $service и $server опускаем: мы не приложение Маркета,
			// app_code останется null.
			$engineId = Manager::register([
				'name' => $name,
				'code' => Constants::getEngineCode($category),
				'category' => $category,
				'completions_url' => $completionsUrl,
				'settings' => [],
			]);
		}
		catch(\Throwable $throwable)
		{
			// RestException из валидатора прилетает сюда же.
			return $result->addError(new Error($throwable->getMessage(), 'ENGINE_REGISTER_FAILED'));
		}

		return $result->setData(['engineId' => (int)$engineId]);
	}

	/**
	 * Адрес зарегистрированного движка; null — движка нет.
	 */
	public function getUrl(string $category): ?string
	{
		$row = $this->getRow($category);

		return $row === null ? null : (string)($row['COMPLETIONS_URL'] ?? '');
	}

	/**
	 * Ответит ли адрес ядру при регистрации: тот же GET, что делает
	 * ThirdPartyRegisterService::validateCompletionsUrl() (setPrivateIp(false),
	 * ждём ровно 200). Только чтение — нужен, чтобы не снимать движок ради
	 * адреса, на котором ядро его всё равно не зарегистрирует.
	 *
	 * @return int HTTP-статус; 0 — нет ответа
	 */
	public function probe(string $completionsUrl): int
	{
		$http = new HttpClient();
		$http->setPrivateIp(false);
		$http->get($completionsUrl);

		return (int)$http->getStatus();
	}

	/**
	 * Снять движок с регистрации.
	 *
	 * Manager::unRegister() ищет строку по паре code + app_code и сам
	 * сбрасывает кеш (Manager.php:61-85). Наш app_code = null — так же, как
	 * при регистрации.
	 */
	public function unregister(string $category): bool
	{
		if(!Loader::includeModule('ai'))
		{
			return false;
		}

		return (bool)Manager::unRegister(['code' => Constants::getEngineCode($category)]);
	}

	public function isRegistered(string $category): bool
	{
		return $this->getRow($category) !== null;
	}

	private function getRow(string $category): ?array
	{
		if(!Loader::includeModule('ai'))
		{
			return null;
		}

		$row = EngineTable::getList([
			'select' => ['ID', 'CODE', 'CATEGORY', 'COMPLETIONS_URL'],
			'filter' => [
				'=CODE' => Constants::getEngineCode($category),
				'=CATEGORY' => $category,
			],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}
}

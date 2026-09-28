<?php declare(strict_types=1);

namespace Shef\ToolsAi\Engine;

use Bitrix\AI\Model\EngineTable;
use Bitrix\AI\ThirdParty\Manager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
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
	 * Зарегистрировать движок; если он есть с другим адресом — перерегистрировать.
	 *
	 * ВНИМАНИЕ: в момент регистрации Битрикс делает GET на $completionsUrl и
	 * требует ровно 200 (ThirdPartyRegisterService::validateCompletionsUrl).
	 * Эндпоинт обязан быть поднят и доступен с самого портала.
	 *
	 * @return Result data: engineId, action = registered | unchanged | updated
	 */
	public function ensure(string $category, string $name, string $completionsUrl): Result
	{
		$result = new Result();

		if(!Loader::includeModule('ai'))
		{
			return $result->addError(new Error('Модуль ai не установлен', 'AI_MODULE_MISSING'));
		}

		$current = $this->getRow($category);
		if($current !== null && (string)($current['COMPLETIONS_URL'] ?? '') === $completionsUrl)
		{
			return $result->setData(['engineId' => (int)$current['ID'], 'action' => 'unchanged']);
		}

		if($current !== null)
		{
			// Пара категория+код уникальна (validateUniqueCode): поменять адрес
			// можно только снятием и повторной регистрацией.
			$this->unregister($category);
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

		return $result->setData([
			'engineId' => (int)$engineId,
			'action' => $current === null ? 'registered' : 'updated',
		]);
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

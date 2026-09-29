<?php declare(strict_types=1);

/**
 * Подключение модуля поднимает зависимости линейки.
 *
 * include.php -> autoload.php -> requireModules из .settings.php. Модуль
 * опирается на shef.options (Pid, FixUser, страница настроек) и
 * shef.problems (логгер проблем): не поднятый вовремя модуль — это
 * «Class not found» посреди фоновой задачи, после того как ядру уже
 * ответили 202, то есть молча.
 *
 * Тест подключает настоящий include.php и смотрит, что поднялось, а не
 * ищет строку в исходнике.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Loader;

\Bitrix\Main\Config\Configuration::$settings = require $root.'/.settings.php';

$loaded = [];
foreach(['shef.options', 'shef.problems', 'ai', 'crm'] as $module)
{
	Loader::$onInclude[$module] = static function() use (&$loaded, $module): void
	{
		$loaded[] = $module;
	};
}

require_once $root.'/include.php';

Check::group('подключение модуля');

Check::same('подняты shef.options и shef.problems, по порядку', $loaded, ['shef.options', 'shef.problems']);
Check::same('ai и crm на каждом хите не поднимаются', array_intersect($loaded, ['ai', 'crm']), []);
Check::same('чужих namespace не зарегистрировано', Loader::$namespaces, []);

Check::finish();

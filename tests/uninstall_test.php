<?php declare(strict_types=1);

/**
 * Установка и удаление: своё уносим, чужое не трогаем.
 *
 * Что держит:
 *
 * * настройки уходят вместе с модулем, savedata = Y их оставляет; настройки
 *   соседа на месте — Option::delete() работает по модулю;
 * * журнал расхода и проверки сделок — туда же: уходят только без savedata
 *   (журнал — финансовая история);
 * * заглушки эндпоинта и страницы расхода пишутся из каталога, где модуль
 *   стоит на самом деле, и при удалении уходят только свои;
 * * агенты модуля снимаются, блокировки агента убираются без SIGTERM;
 * * обход BaaS снимается, только если включали его мы: если его включил
 *   кто-то до нас, это его решение.
 *
 * Ядро подменяется заглушками, установщик подключается настоящий.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\PublicPage;

// region Заглушка ядра ////
class CoreCalls
{
	/** @var list<string> */
	public static array $unregistered = [];

	/** @var list<string> */
	public static array $agentsRemoved = [];

	/** @var list<array{0: string, 1: int}> */
	public static array $pidRemoved = [];

	public static int $cacheCleaned = 0;

	public static function reset(): void
	{
		static::$unregistered = [];
		static::$agentsRemoved = [];
		static::$pidRemoved = [];
		static::$cacheCleaned = 0;
		Application::$connection = null;
		\Bitrix\Crm\Integration\AI\BaasManager::$ignored = false;
	}
}

if(!class_exists('CModule'))
{
	class CModule
	{
	}
}

class CAgent
{
	public static function RemoveModuleAgents(string $moduleId): void
	{
		CoreCalls::$agentsRemoved[] = $moduleId;
	}
}

function UnRegisterModule(string $moduleId): void
{
	CoreCalls::$unregistered[] = $moduleId;
}

function RegisterModule(string $moduleId): void
{
}

$GLOBALS['APPLICATION'] = new class
{
	public function ThrowException(string $message): void {}
};

$GLOBALS['CACHE_MANAGER'] = new class
{
	public function CleanAll(): void
	{
		CoreCalls::$cacheCleaned++;
	}
};

\Bitrix\Main\Config\Configuration::$settings = require $root.'/.settings.php';
// endregion ////

// region Заглушки crm и shef.options ////
eval(<<<'PHP'
namespace Bitrix\Crm\Integration\AI
{
	class BaasManager
	{
		public static bool $ignored = false;

		public static function isIgnored(): bool
		{
			return static::$ignored;
		}

		public static function setIgnored(bool $value): void
		{
			static::$ignored = $value;
		}
	}
}

namespace Shef\Options\Main\TempFile
{
	class Pid
	{
		public static function removeByGroup(string $group, int $signal = 15): void
		{
			\CoreCalls::$pidRemoved[] = [$group, $signal];
		}

		public static function getBasePath(string $group): string
		{
			return sys_get_temp_dir().'/shef-toolsai-uninstall-pid-'.getmypid().'/'.$group;
		}
	}
}
PHP);
// endregion ////

require_once $root.'/install/index.php';

const NEIGHBOUR = 'shef.options';

$given = static function(): shef_toolsai
{
	CoreCalls::reset();
	Option::$values = [];
	Loader::$missing = ['ai'];

	Option::set('shef.toolsai', 'DEF_quota', '500');
	Option::set('shef.toolsai', 'SYS_token', str_repeat('ab', 32));
	Option::set(NEIGHBOUR, 'DEF_systemuserid', '9');

	$connection = Application::getConnection();
	$connection->tables = ['shef_toolsai_usage', 'shef_toolsai_deal_check'];

	return new shef_toolsai();
};

Check::group('удаление уносит настройки и таблицы');

$module = $given();
$module->UnInstallDB();

Check::same('идентификатор модуля тот самый', $module->MODULE_ID, 'shef.toolsai');
Check::same('квота стёрта', Option::get('shef.toolsai', 'DEF_quota', 'нет'), 'нет');
Check::same('токен стёрт', Option::get('shef.toolsai', 'SYS_token', 'нет'), 'нет');
Check::same('таблицы удалены', Application::getConnection()->queries, ['DROP TABLE shef_toolsai_usage', 'DROP TABLE shef_toolsai_deal_check']);
Check::same('модуль снят с регистрации', CoreCalls::$unregistered, ['shef.toolsai']);
Check::same('кеш сброшен', CoreCalls::$cacheCleaned, 1);
Check::same('настройка shef.options на месте', Option::get(NEIGHBOUR, 'DEF_systemuserid', 'нет'), '9');

Check::group('savedata');

$module = $given();
$module->UnInstallDB(['savedata' => 'Y']);
Check::same('savedata = Y оставляет настройки', Option::get('shef.toolsai', 'DEF_quota', 'нет'), '500');
Check::same('и журнал расхода', Application::getConnection()->queries, []);

Check::group('установка создаёт таблицы');

$module = $given();
Application::getConnection()->tables = [];
$module->InstallDB();
$queries = Application::getConnection()->queries;
Check::same('две таблицы', count($queries), 2);
Check::same('журнал с уникальным хэшем задания', str_contains($queries[0] ?? '', 'CREATE TABLE shef_toolsai_usage') && str_contains($queries[0] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_JOB (JOB_HASH)'), true);
Check::same('стоимость — BIGINT', str_contains($queries[0] ?? '', 'COST_MICRO BIGINT'), true);
Check::same('проверки сделок — одна строка на сделку', str_contains($queries[1] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_DEAL (DEAL_ID)'), true);

$module = $given();
$module->InstallDB();
Check::same('таблицы уже есть — повторно не создаются', Application::getConnection()->queries, []);

Check::group('движки, агент, блокировки, BaaS');

$module = $given();
$module->UnInstallEngine();
Check::same('агенты модуля сняты', CoreCalls::$agentsRemoved, ['shef.toolsai']);
Check::same('блокировки агента убраны без SIGTERM', CoreCalls::$pidRemoved, [[Constants::LOCK_GROUP_DEAL_HEALTH, 0]]);

$module = $given();
\Bitrix\Crm\Integration\AI\BaasManager::$ignored = true;
$module->UnInstallEngine();
Check::same('обход BaaS включали не мы — остаётся', \Bitrix\Crm\Integration\AI\BaasManager::$ignored, true);

$module = $given();
\Bitrix\Crm\Integration\AI\BaasManager::$ignored = true;
Option::set('shef.toolsai', 'SYS_baasset', 'Y');
$module->UnInstallEngine();
Check::same('обход BaaS включали мы — снимается', \Bitrix\Crm\Integration\AI\BaasManager::$ignored, false);

Check::group('файлы: из каталога модуля, удаляются только свои');

$portal = sys_get_temp_dir().'/shef-toolsai-uninstall-'.getmypid();
Application::$documentRoot = $portal.'/www';

$touch = static function(string $path, string $content = 'x'): void
{
	if(!is_dir(dirname($path)))
	{
		mkdir(dirname($path), 0777, true);
	}
	file_put_contents($path, $content);
};

$touch($portal.'/www/bitrix/admin/settings.php');
$touch($portal.'/www/bitrix/tools/crm_show_file.php');

$module = $given();
Check::same('InstallFiles отработал', $module->InstallFiles(), true);

foreach(PublicPage::getList() as $page)
{
	// Модуль здесь лежит вне корня сайта-песочницы — путь абсолютный, и
	// ведёт он в настоящий каталог репозитория.
	Check::same(
		'заглушка '.$page->file.' ведёт в этот модуль',
		(string)@file_get_contents($page->getTarget($portal.'/www')),
		$page->getContent($portal.'/www', $root)
	);
}

$module = $given();
Check::same('UnInstallFiles отработал', $module->UnInstallFiles(), true);

foreach(PublicPage::getList() as $page)
{
	Check::same('своя заглушка '.$page->file.' убрана', is_file($page->getTarget($portal.'/www')), false);
}
Check::same('файлы ядра в /bitrix/admin на месте', is_file($portal.'/www/bitrix/admin/settings.php'), true);
Check::same('файлы ядра в /bitrix/tools на месте', is_file($portal.'/www/bitrix/tools/crm_show_file.php'), true);

[$endpoint] = PublicPage::getList();
$touch($endpoint->getTarget($portal.'/www'), '<?php // свой эндпоинт проекта');
$module = $given();
$module->UnInstallFiles();
Check::same('чужой файл на месте заглушки не удалён', (string)file_get_contents($endpoint->getTarget($portal.'/www')), '<?php // свой эндпоинт проекта');

\Bitrix\Main\IO\Directory::deleteDirectory($portal);

Check::finish();

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
 * * установка портал не меняет: только токен и заглушки, обход BaaS,
 *   движки и агент — по кнопке «Проверить и включить» (решение владельца);
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

namespace Bitrix\AI\Tuning
{
	class Manager
	{
		public static array $values = [];
		public static int $saved = 0;

		public function getItem(string $code): ?object
		{
			if(!array_key_exists($code, static::$values))
			{
				return null;
			}

			return new class($code)
			{
				public function __construct(private readonly string $code) {}

				public function getValue(): string
				{
					return Manager::$values[$this->code];
				}

				public function setValue(string $value): void
				{
					Manager::$values[$this->code] = $value;
				}
			};
		}

		public function save(): void
		{
			static::$saved++;
		}
	}
}

namespace Bitrix\Crm\Integration\AI
{
	class EventHandler
	{
		public const SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE = 'crm_copilot_fill_item_from_call_engine_audio';
		public const SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE = 'crm_copilot_fill_item_from_call_engine_text';
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
	$connection->tables = ['shef_toolsai_usage', 'shef_toolsai_deal_check', 'shef_toolsai_deal_profile', 'shef_toolsai_chat_assessment'];

	return new shef_toolsai();
};

Check::group('удаление уносит настройки и таблицы');

$module = $given();
$module->UnInstallDB();

Check::same('идентификатор модуля тот самый', $module->MODULE_ID, 'shef.toolsai');
Check::same('квота стёрта', Option::get('shef.toolsai', 'DEF_quota', 'нет'), 'нет');
Check::same('токен стёрт', Option::get('shef.toolsai', 'SYS_token', 'нет'), 'нет');
Check::same('таблицы удалены', Application::getConnection()->queries, ['DROP TABLE shef_toolsai_usage', 'DROP TABLE shef_toolsai_deal_check', 'DROP TABLE shef_toolsai_deal_profile', 'DROP TABLE shef_toolsai_chat_assessment']);
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
Check::same('четыре таблицы', count($queries), 4);
Check::same('журнал с уникальным хэшем задания', str_contains($queries[0] ?? '', 'CREATE TABLE shef_toolsai_usage') && str_contains($queries[0] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_JOB (JOB_HASH)'), true);
Check::same('стоимость — BIGINT', str_contains($queries[0] ?? '', 'COST_MICRO BIGINT'), true);
Check::same('проверки сделок — одна строка на сделку', str_contains($queries[1] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_DEAL (DEAL_ID)'), true);
Check::same('профили анализа сделок', str_contains($queries[2] ?? '', 'CREATE TABLE shef_toolsai_deal_profile'), true);
Check::same('оценки чатов — одна строка на дело открытой линии', str_contains($queries[3] ?? '', 'CREATE TABLE shef_toolsai_chat_assessment') && str_contains($queries[3] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_CHAT_ACT (ACTIVITY_ID)'), true);

$module = $given();
$module->InstallDB();
Check::same('таблицы уже есть — повторно не создаются', Application::getConnection()->queries, []);

Check::group('движки, агент, блокировки, BaaS');

$module = $given();
$module->UnInstallEngine();
Check::same('агенты модуля сняты', CoreCalls::$agentsRemoved, ['shef.toolsai']);
Check::same('блокировки обоих агентов убраны без SIGTERM', CoreCalls::$pidRemoved, [[Constants::LOCK_GROUP_DEAL_HEALTH, 0], [Constants::LOCK_GROUP_CHAT_ASSESSMENT, 0]]);

$module = $given();
\Bitrix\Crm\Integration\AI\BaasManager::$ignored = true;
$module->UnInstallEngine();
Check::same('обход BaaS включали не мы — остаётся', \Bitrix\Crm\Integration\AI\BaasManager::$ignored, true);

$module = $given();
\Bitrix\Crm\Integration\AI\BaasManager::$ignored = true;
Option::set('shef.toolsai', 'SYS_baasset', 'Y');
$module->UnInstallEngine();
Check::same('обход BaaS включали мы — снимается', \Bitrix\Crm\Integration\AI\BaasManager::$ignored, false);
Check::same('и пометка снята: с savedata = Y она не сняла бы чужой обход после переустановки', Option::get('shef.toolsai', 'SYS_baasset'), 'N');

Check::group('выбор движка в настройках ИИ');

$module = $given();
Loader::$missing = [];
\Bitrix\AI\Tuning\Manager::$values = [
	'crm_copilot_fill_item_from_call_engine_audio' => 'sheftoolsai_audio',
	'crm_copilot_fill_item_from_call_engine_text' => 'sheftoolsai_text',
];
Option::set('shef.toolsai', 'SYS_selected_audio', 'Y');
Option::set('shef.toolsai', 'SYS_previous_audio', 'ChatGPT');
$module->UnInstallEngine();
Check::same('выбирал модуль — возвращено, что стояло до него', \Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_audio'], 'ChatGPT');
Check::same('наш выбран руками — очищено: CRM не ищет удалённый движок', \Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_text'], '');
Check::same('сохранено', \Bitrix\AI\Tuning\Manager::$saved, 1);

$module = $given();
Loader::$missing = [];
\Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_audio'] = 'Other';
Option::set('shef.toolsai', 'SYS_selected_audio', 'Y');
$module->UnInstallEngine();
Check::same('выбирал модуль, но потом сменили на чужой — не тронуто', \Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_audio'], 'Other');
Loader::$missing = ['ai'];

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
Loader::$missing = [];
Option::set('shef.toolsai', 'SYS_token', '');
$module->InstallEngine();
$report = (new ReflectionProperty($module, 'setupReport'))->getValue($module);
Check::same(
	'установка не включает модуль: только токен и заглушки — без обхода BaaS, движков и агента',
	[array_keys($report), \Bitrix\Crm\Integration\AI\BaasManager::$ignored, Option::get('shef.toolsai', 'SYS_baasset')],
	[['token', 'page '.Constants::ENDPOINT_FILE, 'page '.Constants::QUOTA_FILE, 'page '.Constants::DEAL_PROFILES_FILE, 'page '.Constants::STATS_FILE], false, '']
);
Check::same('токен сгенерирован', 1 === preg_match('/^[a-f0-9]{64}$/', (string)Option::get('shef.toolsai', 'SYS_token')), true);

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

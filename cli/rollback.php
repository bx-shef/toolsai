<?php declare(strict_types=1);

/**
 * Откат включения БЕЗ удаления модуля — журнал расхода и настройки модуля
 * остаются. ИЗМЕНЯЕТ портал, поэтому без CONFIRM=1 только показывает план.
 *
 *   sudo -u bitrix php -f bitrix/modules/shef.toolsai/cli/rollback.php            # план
 *   sudo -u bitrix CONFIRM=1 php -f bitrix/modules/shef.toolsai/cli/rollback.php  # выполнить
 *
 * Что делает — то же, что удаление модуля делает с порталом (UnInstallEngine()),
 * но таблицы, настройки и файлы не трогает:
 *   1) выбор движка в настройках ИИ: где ещё наш код — вернуть, что стояло до
 *      «Выбрать движок модуля» (SYS_previous_*), выбранный руками — очистить;
 *   2) снять движки audio и text из b_ai_engine;
 *   3) снять агент анализа сделок;
 *   4) обход BaaS — выключить, только если включал его модуль (SYS_baasset).
 *
 * Автозапуск в воронке не трогает — это настройка CRM, выключается руками.
 * Вернуть всё — «Проверить и включить» и «Выбрать движок модуля».
 */

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

if(php_sapi_name() !== 'cli')
{
	die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = (string)(getenv('DOCUMENT_ROOT') ?: realpath(__DIR__.'/../../../../'));

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Shef\ToolsAi\Engine\Registrar;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\Setup;

if(!Loader::includeModule('shef.toolsai'))
{
	fwrite(STDERR, "Модуль shef.toolsai не установлен\n");
	exit(1);
}

$apply = getenv('CONFIRM') === '1';
echo $apply ? "Откат — ВЫПОЛНЯЮ\n" : "Откат — только план (выполнить: CONFIRM=1)\n";

// 1. Выбор движка в настройках ИИ.
if(Loader::includeModule('ai') && Loader::includeModule('crm') && class_exists(\Bitrix\AI\Tuning\Manager::class))
{
	$settings = [
		Constants::CATEGORY_AUDIO => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
		Constants::CATEGORY_TEXT => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
	];
	$manager = new \Bitrix\AI\Tuning\Manager();
	$changed = false;
	foreach($settings as $category => $code)
	{
		$item = $manager->getItem($code);
		$value = $item === null ? null : (string)$item->getValue();
		if($value !== Constants::getEngineCode($category))
		{
			printf("  выбор %-5s  %s — не наш, не трогаю\n", $category, $value ?? 'настройки нет');
			continue;
		}

		$previous = Option::get(Constants::MODULE_ID, Setup::OPTION_SELECTED_PREFIX.$category, 'N') === 'Y'
			? (string)Option::get(Constants::MODULE_ID, Setup::OPTION_PREVIOUS_PREFIX.$category, '')
			: '';
		printf("  выбор %-5s  %s → %s\n", $category, $value, $previous === '' ? 'пусто' : $previous);
		if($apply)
		{
			$item->setValue($previous);
			Option::set(Constants::MODULE_ID, Setup::OPTION_SELECTED_PREFIX.$category, 'N');
			$changed = true;
		}
	}
	if($changed)
	{
		$manager->save();
	}
}

// 2. Движки.
$registrar = new Registrar();
foreach([Constants::CATEGORY_AUDIO, Constants::CATEGORY_TEXT] as $category)
{
	if(!$registrar->isRegistered($category))
	{
		printf("  движок %-5s не зарегистрирован\n", $category);
		continue;
	}
	printf("  движок %-5s снять\n", $category);
	if($apply)
	{
		$registrar->unregister($category);
	}
}

// 3. Агент.
$agent = \CAgent::GetList([], ['NAME' => Setup::AGENT_NAME])->Fetch();
printf("  агент анализа сделок %s\n", $agent ? 'снять' : 'нет');
if($apply && $agent)
{
	\CAgent::Delete((int)$agent['ID']);
}

// 4. Обход BaaS — только свой.
if(Loader::includeModule('crm') && class_exists(\Bitrix\Crm\Integration\AI\BaasManager::class))
{
	$own = Option::get(Constants::MODULE_ID, Setup::OPTION_BAAS_SET, 'N') === 'Y';
	$ignored = \Bitrix\Crm\Integration\AI\BaasManager::isIgnored();
	printf(
		"  crm::AI_IGNORE_BAAS %s\n",
		!$ignored ? 'и так N' : ($own ? 'Y, включал модуль — выключить' : 'Y, включал не модуль — не трогаю')
	);
	if($apply && $ignored && $own)
	{
		\Bitrix\Crm\Integration\AI\BaasManager::setIgnored(false);
		Option::set(Constants::MODULE_ID, Setup::OPTION_BAAS_SET, 'N');
	}
}

echo $apply
	? "Готово. Проверка: php -f preflight.php\n"
	: "Ничего не изменено.\n";
echo "Автозапуск распознавания в воронке выключите в настройках Копилота руками.\n";

<?php declare(strict_types=1);

/**
 * Проверить и включить — то же, что кнопка на странице расхода, из консоли.
 *
 *   php -f bitrix/modules/shef.toolsai/cli/setup.php
 *   PUBLIC_URL=https://crm.example.by php -f .../cli/setup.php   # заодно задать внешний адрес
 *
 * Токен, заглушки эндпоинта и страницы расхода, обход BaaS, регистрация
 * движков audio и text, агент анализа сделок. Шаги идемпотентны: гонять
 * после установки и после КАЖДОГО обновления платформы.
 *
 * Печатает отчёт и остаток квоты. Код возврата: 0 — всё на месте, 1 — нет.
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

// Скрипт лежит в <корень>/bitrix|local/modules/shef.toolsai/cli/: корень сайта
// на четыре уровня выше. Другое место — задайте DOCUMENT_ROOT в окружении.
$_SERVER['DOCUMENT_ROOT'] = (string)(getenv('DOCUMENT_ROOT') ?: realpath(__DIR__.'/../../../../'));

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\OptionParser;
use Shef\ToolsAi\Main\Setup;

if(!Loader::includeModule('shef.toolsai'))
{
	fwrite(STDERR, "Модуль shef.toolsai не установлен\n");
	exit(1);
}

$publicUrl = getenv('PUBLIC_URL');
if(is_string($publicUrl) && $publicUrl !== '')
{
	if(OptionParser::url($publicUrl) === '')
	{
		fwrite(STDERR, "PUBLIC_URL не похож на адрес http(s): $publicUrl\n");
		exit(1);
	}

	Option::set(Constants::MODULE_ID, 'DEF_publicurl', OptionParser::url($publicUrl));
}

$report = (new Setup(Container::getConfig()))->run(
	(string)$_SERVER['DOCUMENT_ROOT'],
	dirname(__DIR__)
);

$failed = 0;
foreach($report as $step => $row)
{
	printf("  %-5s %-48s %s\n", $row['ok'] ? 'OK' : 'FAIL', $step, $row['message']);
	$failed += $row['ok'] ? 0 : 1;
}

$config = Container::getConfig();
$balance = Container::getMeter()->getMonthly();

echo PHP_EOL;
printf("  внешний адрес:  %s\n", $config->getPublicUrl() ?: '—');
printf("  эндпоинт:       %s\n", $config->getPublicUrl() !== '' ? $config->getPublicUrl().Constants::ENDPOINT_FILE : '—');
printf("  провайдеры:     audio=%s text=%s\n", $config->getProviderCode(Constants::CATEGORY_AUDIO), $config->getProviderCode(Constants::CATEGORY_TEXT));
printf(
	"  расход месяца:  %s из %s\n",
	number_format($balance->spentMicro / 1_000_000, 2, '.', ' '),
	$balance->isUnlimited() ? 'без ограничения' : number_format($balance->limitMicro / 1_000_000, 2, '.', ' ')
);

exit($failed > 0 ? 1 : 0);

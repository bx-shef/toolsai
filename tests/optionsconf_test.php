<?php declare(strict_types=1);

/**
 * Страница настроек: options_conf.php собирается против API shef.options 3.x.
 *
 * Что держит:
 *
 * * options_conf.php зовёт ShOptionsConfig так, как его понимает
 *   shef.options 3.x (без indexDoc — в shef.problems 1.1.7 на этом падала
 *   страница настроек «Unknown named parameter»);
 * * у каждой подписи есть перевод: setName() и setTitle() принимают строку,
 *   и пропущенный ключ языкового файла — TypeError, то есть
 *   неоткрывающаяся страница;
 * * коды вкладок и опций — те, что читает Shef\ToolsAi\Config: переименуете
 *   опцию на странице и забудете в коде — модуль молча читает умолчание;
 * * в списке провайдеров — ровно известные коды;
 * * токен эндпоинта на странице не показывается.
 *
 * API shef.options подменяет tests/stub/options.php, файлы модуля настоящие.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/options.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAi\Main\Constants;

define('LANGUAGE_ID', 'ru');
Loc::loadLangFile($root.'/lang/ru/options.php');

$token = str_repeat('ef', 32);
Option::set('shef.toolsai', 'SYS_token', $token);
Option::set('shef.toolsai', 'DEF_publicurl', 'https://crm.example.by');

Check::group('options_conf.php собирается');

$tabs = require $root.'/options_conf.php';

Check::same('вернул список вкладок', is_array($tabs), true);
Check::same('вкладки', array_map(static fn(Options\Tab $tab): string => $tab->getCode(), $tabs), ['DEF', 'API', 'DEAL']);

$names = [];
$codes = [];
$untitled = [];
$descriptions = '';
foreach($tabs as $tab)
{
	$names[] = $tab->getName();
	foreach($tab->getOptionList() as $option)
	{
		$codes[] = $tab->getCode().'_'.$option->getCode();
		$descriptions .= $option->getDescription();
		if(!$option instanceof Options\RowInfo && $option->getTitle() === '')
		{
			$untitled[] = $tab->getCode().'_'.$option->getCode();
		}
	}
}

Check::same('у вкладок есть названия', in_array('', $names, true), false);
Check::same('у каждой опции есть подпись', $untitled, []);

Check::group('коды опций — те, что читает Config');

$config = (string)file_get_contents($root.'/lib/config.php');
preg_match_all("/'((?:DEF|API|DEAL)_[a-z]+)'/", $config, $match);
$read = array_values(array_unique($match[1]));
sort($read);

$onPage = array_values(array_filter($codes, static fn(string $code): bool => !str_ends_with($code, '_Engine')));
sort($onPage);

Check::same('всё, что читает Config, есть на странице, и наоборот', $onPage, $read);

require $root.'/default_option.php';
$unknownDefaults = array_diff(array_keys($shef_toolsai_default_option), $read);
Check::same('умолчания — только для настроек, которые читаются', array_values($unknownDefaults), []);

Check::group('провайдеры и токен');

foreach($tabs[0]->getOptionList() as $option)
{
	if($option instanceof Options\Enum)
	{
		Check::same('список провайдеров '.$option->getCode(), array_keys($option->getList()), Constants::getProviderList());
	}
}

Check::same('адрес эндпоинта показан', str_contains($descriptions, 'https://crm.example.by'.Constants::ENDPOINT_FILE), true);
Check::same('токена на странице нет', str_contains($descriptions, $token), false);

Check::finish();

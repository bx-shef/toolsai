<?php declare(strict_types=1);

/**
 * Пункт модуля в меню административной части.
 *
 * Ядро подключает этот файл само — для каждого установленного модуля, при
 * построении меню. Регистрировать и копировать его не нужно.
 *
 * Только администратору: на странице расход в деньгах и кнопка, которая
 * регистрирует движки; профили анализа сделок тратят квоту.
 */

use Bitrix\Main\Localization\Loc;
use Shef\ToolsAi\Main\Constants;

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

/** @var \CUser $USER */
global $USER;

if(!($USER instanceof \CUser) || !$USER->IsAdmin())
{
	return false;
}

if(!\Bitrix\Main\Loader::includeModule('shef.toolsai'))
{
	return false;
}

Loc::loadMessages(__FILE__);

return [
	'parent_menu' => 'global_menu_services',
	'section' => Constants::MODULE_ID,
	'sort' => 1000,
	'text' => (string)Loc::getMessage('SH_TOOLSAI_MENU'),
	'title' => (string)Loc::getMessage('SH_TOOLSAI_MENU_TITLE'),
	'url' => Constants::QUOTA_FILE.'?lang='.LANGUAGE_ID,
	'icon' => 'sys_menu_icon',
	'items_id' => 'menu_shef_toolsai',
	'items' => [
		[
			'text' => (string)Loc::getMessage('SH_TOOLSAI_MENU'),
			'url' => Constants::QUOTA_FILE.'?lang='.LANGUAGE_ID,
		],
		[
			'text' => (string)Loc::getMessage('SH_TOOLSAI_MENU_PROFILES'),
			'url' => Constants::DEAL_PROFILES_FILE.'?lang='.LANGUAGE_ID,
			'more_url' => [Constants::DEAL_PROFILES_FILE],
		],
	],
];

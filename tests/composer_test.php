<?php declare(strict_types=1);

/**
 * composer.json: решения, за которые заплачено в shef.options.
 *
 * composer validate (он в CI) проверяет, что файл — правильный манифест.
 * Здесь другое: что он правильный ДЛЯ МОДУЛЯ БИТРИКСА.
 *
 * * тип bitrix-module, а не bitrix-d7-module: installer-name подменяет
 *   только {$name}, и d7 развернул бы модуль в
 *   bitrix/modules/bxshef.shef.toolsai/ — каталог, которого ядро не знает;
 * * потолок composer/installers: bitrix-module помечен deprecated, в v3 его
 *   уберут, и без потолка модуль молча уехал бы в чужой каталог;
 * * вендор bxshef: shef на Packagist занят чужим пакетом;
 * * линейка — своими пакетами, версии — те же, что проверяет установщик.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

$composer = json_decode((string)file_get_contents($root.'/composer.json'), true);

Check::group('пакет');

Check::same('composer.json разобран', is_array($composer), true);
Check::same('имя', $composer['name'] ?? null, 'bxshef/toolsai');
Check::same('тип', $composer['type'] ?? null, 'bitrix-module');
Check::same('installer-name — id модуля', $composer['extra']['installer-name'] ?? null, 'shef.toolsai');
Check::same('лицензия в тон LICENSE', $composer['license'] ?? null, 'MIT');
Check::same('LICENSE — MIT', str_starts_with((string)file_get_contents($root.'/LICENSE'), 'MIT License'), true);

Check::group('зависимости');

$require = $composer['require'] ?? [];

Check::same('потолок composer/installers', $require['composer/installers'] ?? null, '^1.0 || ^2.0');
Check::same('shef.options — своим пакетом', $require['bxshef/options'] ?? null, '^3.0');
Check::same('shef.problems — своим пакетом', $require['bxshef/problems'] ?? null, '^2.0');
Check::same('shef.uiclear не нужен', array_key_exists('shef/uiclear', $require) || array_key_exists('bxshef/uiclear', $require), false);

Check::group('версии — одни на всех');

$installer = (string)file_get_contents($root.'/install/index.php');
preg_match("/PHP_MIN_VER = '([0-9.]+)'/", $installer, $phpMin);
Check::same('установщик и composer.json требуют один PHP', '>='.($phpMin[1] ?? ''), $require['php'] ?? null);

foreach(['options' => 'shef.options', 'problems' => 'shef.problems'] as $package => $module)
{
	preg_match("/'".preg_quote($module, '/')."' => '([0-9]+)\\./", $installer, $major);
	Check::same(
		'мажорная версия '.$module.' — та же, что в установщике',
		'^'.($major[1] ?? '?').'.0',
		$require['bxshef/'.$package] ?? null
	);
}

Check::group('ветка разработки — текущая мажорная');

$arModuleVersion = [];
require $root.'/install/version.php';
$major = (int)explode('.', (string)($arModuleVersion['VERSION'] ?? '0'))[0];

Check::same('branch-alias', $composer['extra']['branch-alias']['dev-main'] ?? null, $major.'.x-dev');

Check::finish();

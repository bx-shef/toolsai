<?php declare(strict_types=1);

/**
 * .settings.php: ссылки наружу обязаны никуда не висеть.
 *
 * Что держит:
 *
 * * обязательные модули — shef.options и shef.problems: autoload.php
 *   поднимает их на каждой загрузке, и снятие любого из них раньше этого
 *   модуля установщик не пропустит;
 * * ai и crm — НЕ в requireModules: без них модуль не ставится (NEED_MODULES
 *   установщика), но autoload.php не должен поднимать их на каждом хите;
 * * свой namespace в registerNamespace не нужен — его даёт соглашение;
 * * installDir пуст: публичные файлы модуль не копирует, а пишет
 *   (Main\PublicPage) — копия с зашитым путём сломала бы модуль из
 *   /local/modules;
 * * событий нет, ajax-контроллеров нет.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/bitrix.php';
require_once $root.'/tests/assert.php';

$settings = require $root.'/.settings.php';

Check::group('структура файла');

Check::same('.settings.php вернул массив', is_array($settings), true);

$shape = [];
foreach($settings as $key => $section)
{
	if(!is_array($section) || !array_key_exists('value', $section) || !array_key_exists('readonly', $section))
	{
		$shape[] = $key;
	}
}

Check::same('у каждой секции есть value и readonly', $shape, []);

Check::group('зависимости');

Check::same('обязательные модули линейки', $settings['requireModules']['value'], ['shef.options', 'shef.problems']);
Check::same('ai и crm не поднимаются на каждом хите', array_intersect($settings['requireModules']['value'], ['ai', 'crm']), []);

$installer = (string)file_get_contents($root.'/install/index.php');
Check::same('ai и crm — в NEED_MODULES установщика', 1 === preg_match("/NEED_MODULES = \\[\\s*'ai',\\s*'crm',\\s*\\]/", $installer), true);

foreach($settings['requireModules']['value'] as $module)
{
	Check::same(
		'версия '.$module.' проверяется при установке',
		1 === preg_match("/'".preg_quote($module, '/')."' => '[0-9.]+'/", $installer),
		true
	);
}

Check::group('раскладка');

Check::same('свой namespace не регистрируется — его даёт соглашение', $settings['registerNamespace']['value'], []);
Check::same('installDir пуст — страницы пишутся, а не копируются', $settings['installDir']['value'], []);
Check::same('событий нет', $settings['installEvents']['value'], []);
Check::same('своих ajax-контроллеров нет', $settings['controllers']['value']['namespaces'], []);

Check::finish();

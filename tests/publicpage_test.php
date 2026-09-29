<?php declare(strict_types=1);

/**
 * Заглушки эндпоинта (/bitrix/tools) и страницы расхода (/bitrix/admin):
 * путь — куда модуль стоит на самом деле, удаляется — только своя.
 *
 * * Кит клал модуль в /local/modules, линейка ставит в /bitrix/modules.
 *   Заглушка, зашитая на один из них, в другом дала бы белую страницу —
 *   а на эндпоинте это значит «движок не регистрируется». Поэтому заглушку
 *   пишут, а не копируют.
 * * В /bitrix/admin и /bitrix/tools лежат файлы всех модулей: на месте нашей
 *   заглушки проект мог положить свой файл. Удаление не вправе его снести, а
 *   установка — перезаписать.
 *
 * Держит \Shef\ToolsAi\Main\PublicPage; подключается он явным require_once —
 * ровно как в установщике.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';
require_once $root.'/lib/main/constants.php';
require_once $root.'/lib/main/publicpage.php';

use Shef\ToolsAi\Main\PublicPage;

$portal = sys_get_temp_dir().'/shef-toolsai-publicpage-'.getmypid();
$www = $portal.'/www';
mkdir($www.'/bitrix/admin', 0777, true);
mkdir($www.'/bitrix/tools', 0777, true);

$require = static fn(string $path): string => "<?php require(\$_SERVER['DOCUMENT_ROOT'].'".$path."');\n";

Check::group('какие заглушки');

Check::same(
	'эндпоинт и страница расхода',
	array_map(static fn(PublicPage $page): string => $page->file.' -> '.$page->modulePage, PublicPage::getList()),
	[
		'/bitrix/tools/shef_toolsai_completions.php -> /endpoint/completions.php',
		'/bitrix/admin/shef_toolsai_quota.php -> /admin/quota.php',
	]
);
foreach(PublicPage::getList() as $page)
{
	Check::same('страница модуля есть: '.$page->modulePage, is_file($root.$page->modulePage), true);
}

[$endpoint, $quota] = PublicPage::getList();
$target = $endpoint->getTarget($www);

Check::group('путь — туда, где стоит модуль');

Check::same('/bitrix/modules', $endpoint->getContent($www, $www.'/bitrix/modules/shef.toolsai'), $require('/bitrix/modules/shef.toolsai/endpoint/completions.php'));
Check::same('/local/modules', $quota->getContent($www, $www.'/local/modules/shef.toolsai'), $require('/local/modules/shef.toolsai/admin/quota.php'));
Check::same(
	'вне корня сайта — абсолютный путь',
	$endpoint->getContent($www, '/opt/modules/shef.toolsai'),
	"<?php require('/opt/modules/shef.toolsai/endpoint/completions.php');\n"
);
Check::same(
	'сосед корня с тем же началом имени — тоже вне корня',
	$endpoint->getContent($www, $www.'-old/bitrix/modules/shef.toolsai'),
	"<?php require('".$www."-old/bitrix/modules/shef.toolsai/endpoint/completions.php');\n"
);

$probe = $portal.'/probe.php';
file_put_contents($probe, $endpoint->getContent($www, $www.'/local/modules/shef.toolsai'));
exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($probe).' 2>&1', $lint, $code);
Check::same('заглушка — синтаксически верный PHP', $code, 0);

Check::group('своя или чужая');

Check::same('своя — /bitrix/modules', $endpoint->isOwn($require('/bitrix/modules/shef.toolsai/endpoint/completions.php')), true);
Check::same('своя — /local/modules', $endpoint->isOwn($require('/local/modules/shef.toolsai/endpoint/completions.php')), true);
Check::same('чужая — страница расхода на месте эндпоинта', $endpoint->isOwn($require('/bitrix/modules/shef.toolsai/admin/quota.php')), false);
Check::same('чужая — другой модуль', $endpoint->isOwn($require('/bitrix/modules/acme.ai/endpoint/completions.php')), false);
Check::same('чужая — наш require плюс ещё код', $endpoint->isOwn($require('/bitrix/modules/shef.toolsai/endpoint/completions.php').'<?php echo 1;'), false);

Check::group('установка');

Check::same('файла нет — пишет', $endpoint->install($www, $www.'/local/modules/shef.toolsai'), true);
Check::same('путь из /local/modules', (string)file_get_contents($target), $require('/local/modules/shef.toolsai/endpoint/completions.php'));
Check::same('модуль переехал — переписывает', $endpoint->install($www, $www.'/bitrix/modules/shef.toolsai'), true);
Check::same('путь новый', (string)file_get_contents($target), $require('/bitrix/modules/shef.toolsai/endpoint/completions.php'));

file_put_contents($target, '<?php // свой эндпоинт проекта');
Check::same('чужой файл — не трогает', $endpoint->install($www, $www.'/bitrix/modules/shef.toolsai'), false);
Check::same('чужой файл цел', (string)file_get_contents($target), '<?php // свой эндпоинт проекта');
Check::same('нет каталога — не создаёт его', $endpoint->install($portal.'/нет', $www.'/bitrix/modules/shef.toolsai'), false);

Check::group('удаление');

Check::same('чужой файл — не удаляет', [$endpoint->uninstall($www), is_file($target)], [false, true]);
unlink($target);
$endpoint->install($www, $www.'/bitrix/modules/shef.toolsai');
Check::same('своя — удаляет', [$endpoint->uninstall($www), is_file($target)], [true, false]);
Check::same('уже нет — не ошибка', $endpoint->uninstall($www), true);

// Каталог модуля — клон репозитория с другим именем; заглушку писал он сам.
$endpoint->install($www, '/opt/toolsai');
Check::same('клон с другим именем — своя по точному совпадению', [$endpoint->uninstall($www, '/opt/toolsai'), is_file($target)], [true, false]);

// region Уборка ////
exec('rm -rf '.escapeshellarg($portal));
// endregion ////

Check::finish();

<?php declare(strict_types=1);

/**
 * Настроить shef.toolsai на стенде и прогнать «Проверить и включить».
 *
 *   docker compose exec -u www-data portal php /opt/stand/configure.php            # заглушка echo
 *   docker compose exec -u www-data portal env PROVIDER=openai php /opt/stand/configure.php   # mock-openai
 *
 * Внешний адрес — http://portal: по этому имени портал виден сам себе в сети
 * compose. Тот же адрес — в ai::public_url: от него ядро строит колбэк и
 * ссылку на запись звонка.
 */

const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

$_SERVER['DOCUMENT_ROOT'] = getenv('DOCUMENT_ROOT') ?: '/var/www/html';
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

if(!Loader::includeModule('shef.toolsai'))
{
	fwrite(STDERR, "shef.toolsai не установлен\n");
	exit(1);
}

$publicUrl = getenv('PUBLIC_URL') ?: 'http://portal';
$provider = getenv('PROVIDER') ?: 'echo';

Option::set('ai', 'public_url', $publicUrl);
Option::set('shef.toolsai', 'DEF_publicurl', $publicUrl);
Option::set('shef.toolsai', 'DEF_provideraudio', $provider);
Option::set('shef.toolsai', 'DEF_providertext', $provider);
Option::set('shef.toolsai', 'API_baseurl', getenv('API_BASEURL') ?: 'http://mock-openai:8000/v1');
Option::set('shef.toolsai', 'API_apikey', getenv('API_KEY') ?: '');
Option::set('shef.toolsai', 'API_asrprice', '0.6');
Option::set('shef.toolsai', 'API_llmpricein', '15');
Option::set('shef.toolsai', 'API_llmpriceout', '60');

if(getenv('QUOTA') !== false)
{
	Option::set('shef.toolsai', 'DEF_quota', (string)getenv('QUOTA'));
}

printf("ai::public_url = %s, провайдер = %s\n", $publicUrl, $provider);

$report = (new \Shef\ToolsAi\Main\Setup(\Shef\ToolsAi\Container::getConfig()))->run(
	$_SERVER['DOCUMENT_ROOT'],
	(string)realpath($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/shef.toolsai')
);

$failed = 0;
foreach($report as $step => $row)
{
	printf("  %-5s %-48s %s\n", $row['ok'] ? 'OK' : 'FAIL', $step, $row['message']);
	$failed += $row['ok'] ? 0 : 1;
}

exit($failed > 0 ? 1 : 0);

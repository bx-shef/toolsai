<?php declare(strict_types=1);

/**
 * Предполётная проверка на боевом портале — ТОЛЬКО ЧТЕНИЕ, ничего не меняет.
 *
 *   php -f bitrix/modules/shef.toolsai/cli/preflight.php
 *   NO_NETWORK=1 php -f .../cli/preflight.php   # без сетевых проверок
 *
 * Собирает то, на чём включение падало на приёмке (bx-shef/toolsai#3):
 * версии, настройки модуля, адрес движка (публичный IP — ядро ходит на него с
 * setPrivateIp(false)), ответ эндпоинта так, как его проверяет ядро, TLS до
 * провайдера каждого направления (audio, text, deal), обход BaaS, движки и
 * выбор в настройках ИИ, агент, лог CRM.
 *
 * Автозапуск по воронкам и разбор звонка — cli/ai-call-autostart-diag.php.
 *
 * Код возврата: 0 — FAIL нет, 1 — есть. WARN код не меняет.
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

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Web\HttpClient;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\Setup;

if(!Loader::includeModule('shef.toolsai'))
{
	fwrite(STDERR, "Модуль shef.toolsai не установлен\n");
	exit(1);
}

$failed = 0;
$line = static function(string $level, string $what, string $value) use (&$failed): void
{
	$failed += $level === 'FAIL' ? 1 : 0;
	printf("  %-5s %-34s %s\n", $level, $what, $value);
};
$section = static fn(string $title) => print(PHP_EOL.$title.PHP_EOL);
$mask = static fn(string $secret): string => $secret === '' ? '—' : substr($secret, 0, 3).'…'.substr($secret, -2).' ('.strlen($secret).' симв.)';

$config = Container::getConfig();
$network = getenv('NO_NETWORK') !== '1';

// ---------------------------------------------------------------------------
$section('1. Версии');

foreach(['main' => true, 'ai' => true, 'crm' => true, 'voximplant' => false, 'rest' => false, 'shef.options' => true, 'shef.problems' => true, 'shef.toolsai' => true] as $module => $required)
{
	$installed = ModuleManager::isModuleInstalled($module);
	$line(
		$installed ? 'OK' : ($required ? 'FAIL' : 'WARN'),
		$module,
		$installed ? (string)ModuleManager::getVersion($module) : 'не установлен'.($required ? '' : ' — звонки через телефонию Битрикса без него не распознаются')
	);
}
$line(ModuleManager::isModuleInstalled('bitrix24') ? 'WARN' : 'OK', 'bitrix24', ModuleManager::isModuleInstalled('bitrix24') ? 'есть — это не коробка?' : 'нет (коробка)');
$line(PHP_VERSION_ID >= 80200 ? 'OK' : 'FAIL', 'PHP', PHP_VERSION);
$line(PHP_SAPI === 'cli' ? 'OK' : 'WARN', 'SAPI скрипта', PHP_SAPI.' (веб-сервер может отличаться: apache+mod_php — см. docs/prod-check.md, шаг 5)');

// ---------------------------------------------------------------------------
$section('2. Настройки модуля');

$publicUrl = $config->getPublicUrl();
$rejected = $config->getRejectedPublicUrl();
$line(
	$publicUrl !== '' ? 'OK' : 'FAIL',
	'внешний адрес',
	$publicUrl !== '' ? $publicUrl : ($rejected !== '' ? $rejected.' не разобран (нужно https://…)' : 'не задан')
);
$line($config->getAiPublicUrl() !== '' ? 'OK' : 'WARN', 'ai::public_url', $config->getAiPublicUrl() ?: 'пусто — берётся внешний адрес модуля');
$line($config->getToken() !== '' ? 'OK' : 'FAIL', 'токен эндпоинта', $config->getToken() !== '' ? 'есть, '.strlen($config->getToken()).' симв.' : 'нет — «Проверить и включить»');

foreach([Constants::CATEGORY_AUDIO, Constants::CATEGORY_TEXT] as $category)
{
	$line('OK', 'провайдер '.$category, $config->getProviderCode($category));
}
$usesApi = in_array(Constants::PROVIDER_OPENAI, [$config->getProviderCode(Constants::CATEGORY_AUDIO), $config->getProviderCode(Constants::CATEGORY_TEXT)], true);
// Направления, которые ходят к провайдеру: audio — по своему провайдеру,
// text и анализ сделок — по провайдеру text.
$directions = [];
foreach(Config::getDirectionList() as $direction)
{
	$category = $direction === Config::DIRECTION_AUDIO ? Constants::CATEGORY_AUDIO : Constants::CATEGORY_TEXT;
	if($config->getProviderCode($category) === Constants::PROVIDER_OPENAI)
	{
		$directions[$direction] = $config->getEndpoint($direction);
	}
}
if($usesApi)
{
	// Своя точка доступа у направления (1.5.0): адрес, ключ (маской), модель, таймаут.
	foreach($directions as $direction => $endpoint)
	{
		$line(
			'OK',
			'API '.$direction,
			sprintf('%s, ключ %s, модель %s, таймаут %d с', $endpoint->getDisplayUrl(), $mask($endpoint->apiKey), $endpoint->model, $endpoint->timeout)
		);
	}
	foreach($config->getRejectedApiUrls() as $code)
	{
		$line('FAIL', $code, 'не разобран (нужно http(s)://…, без ?параметров) — берётся запасной адрес');
	}
	$line(
		$config->isLlmExtraBroken() ? 'FAIL' : 'OK',
		'доп. параметры модели текста',
		$config->isLlmExtraBroken() ? 'не JSON-объект — не применяются' : ((string)json_encode($config->getLlmExtra(), JSON_UNESCAPED_UNICODE) ?: '{}')
	);
	$line('OK', 'промпты резюме и полей', $config->isOwnPromptsEnabled() ? 'свои модуля' : 'ядра (готовый prompt)');
	$line(
		$config->getAsrPricePerMinuteMicro() > 0 ? 'OK' : 'WARN',
		'цены',
		sprintf(
			'минута=%s, 1М вход=%s, 1М выход=%s%s',
			$config->getAsrPricePerMinuteMicro() / 1_000_000,
			$config->getLlmPriceInMicro() / 1_000_000,
			$config->getLlmPriceOutMicro() / 1_000_000,
			$config->getAsrPricePerMinuteMicro() > 0 ? '' : ' — без цен расход в деньгах будет 0'
		)
	);
}
if($config->getProviderCode(Constants::CATEGORY_TEXT) !== Constants::PROVIDER_ECHO)
{
	$line('WARN', 'text-провайдер', 'не заглушка: промпты Копилота на коробке обфусцированы, резюме и поля на LLM будут мусором до своих промптов (bx-shef/toolsai#3)');
}
$quota = $config->getMonthlyQuotaMicro();
$balance = Container::getMeter()->getMonthly();
$line(
	$usesApi && $quota === 0 ? 'WARN' : 'OK',
	'квота месяца',
	($quota === 0 ? 'без ограничения' : number_format($quota / 1_000_000, 2, '.', ' '))
	.', потрачено '.number_format($balance->spentMicro / 1_000_000, 2, '.', ' ')
);

// ---------------------------------------------------------------------------
$section('3. Сеть: адрес движка');

$host = (string)parse_url($publicUrl, PHP_URL_HOST);
if($host === '')
{
	$line('FAIL', 'хост внешнего адреса', 'нет');
}
else
{
	$ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (@gethostbynamel($host) ?: []);
	$private = array_filter(
		$ips,
		static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
	);
	$line(
		$ips === [] ? 'FAIL' : ($private !== [] ? 'FAIL' : 'OK'),
		$host.' → IP с сервера',
		$ips === []
			? 'не резолвится'
			: implode(', ', $ips).($private !== [] ? ' — приватный: ядро ai не пойдёт на такой адрес (setPrivateIp(false))' : '')
	);
	// Частый источник приватного IP — /etc/hosts: BitrixVM пишет домен
	// портала на локальный адрес в «ANSIBLE MANAGED BLOCK» и может вернуть
	// закомментированную строку при следующей настройке (bx-shef/toolsai#11).
	$hostsLines = is_readable('/etc/hosts') ? (array)file('/etc/hosts', FILE_IGNORE_NEW_LINES) : [];
	$hostsHits = array_values(array_filter(
		$hostsLines,
		static fn(string $row): bool => !str_starts_with(ltrim($row), '#')
			&& in_array(mb_strtolower($host), array_map('mb_strtolower', array_slice(preg_split('/\s+/', trim(explode('#', $row)[0])) ?: [], 1)), true)
	));
	$ansible = array_filter($hostsLines, static fn(string $row): bool => str_contains($row, 'ANSIBLE MANAGED BLOCK')) !== [];
	if($hostsHits !== [])
	{
		$line(
			$private !== [] ? 'FAIL' : 'WARN',
			$host.' в /etc/hosts',
			trim($hostsHits[0]).($ansible ? ' — блок BitrixVM: при перенастройке строка может вернуться' : '')
		);
	}
	$line(str_starts_with($publicUrl, 'https://') ? 'OK' : 'WARN', 'схема', str_starts_with($publicUrl, 'https://') ? 'https' : 'http — REST и ссылки на записи могут требовать https');
}

if($network && $publicUrl !== '')
{
	// Так проверяет ядро при регистрации: GET, приватные адреса запрещены, ровно 200.
	$http = new HttpClient(['redirect' => false, 'socketTimeout' => 10, 'streamTimeout' => 10]);
	$http->setPrivateIp(false);
	$started = microtime(true);
	$body = $http->get($publicUrl.Constants::ENDPOINT_FILE);
	$status = (int)$http->getStatus();
	$line(
		$status === 200 ? 'OK' : 'FAIL',
		'GET эндпоинта, как ядро',
		$status === 200
			? sprintf('200 за %.3f с, %s', microtime(true) - $started, trim((string)$body))
			: 'статус '.$status.' '.implode('; ', array_map('strval', (array)$http->getError()))
	);
}

// ---------------------------------------------------------------------------
$section('4. Сеть: провайдер');

if(!$usesApi)
{
	$line('OK', 'провайдер', 'заглушка — сеть не нужна');
}
elseif(!$network)
{
	$line('WARN', 'провайдер', 'NO_NETWORK=1 — не проверялось');
}
else
{
	// GET /models: дёшево, денег не стоит; 401 — сеть и TLS в порядке, ключ нет.
	// По одному запросу на каждую разную точку доступа (адрес + ключ).
	$checked = [];
	foreach($directions as $direction => $endpoint)
	{
		$id = $endpoint->baseUrl."\n".$endpoint->apiKey;
		if(isset($checked[$id]))
		{
			$line('OK', 'GET '.$endpoint->getDisplayUrl().'/models ('.$direction.')', 'как у '.$checked[$id]);
			continue;
		}
		$checked[$id] = $direction;

		$http = new HttpClient(['redirect' => false, 'socketTimeout' => 10, 'streamTimeout' => 15]);
		// Как транспорт модуля для POST: адрес задал администратор, свой
		// whisper на 127.0.0.1 — законный случай.
		$http->setPrivateIp(true);
		if($endpoint->apiKey !== '')
		{
			$http->setHeader('Authorization', 'Bearer '.$endpoint->apiKey);
		}
		$http->get(rtrim($endpoint->baseUrl, '/').'/models');
		$status = (int)$http->getStatus();
		$error = implode('; ', array_map('strval', (array)$http->getError()));
		$line(
			match(true) { $status === 200 => 'OK', $status === 0 => 'FAIL', default => 'WARN' },
			'GET '.$endpoint->getDisplayUrl().'/models ('.$direction.')',
			match(true)
			{
				$status === 200 => '200 — сеть, TLS и ключ в порядке',
				$status === 401 || $status === 403 => $status.' — сеть есть, ключ не принят',
				$status === 404 => '404 — сервер отвечает, /models у него нет (для своих серверов бывает)',
				$status === 0 => 'нет ответа: '.$error.' (TLS-перехват антивирусом/прокси? — приёмка, этап 9; свой сервер — запущен ли контейнер?)',
				default => 'статус '.$status,
			}
		);
	}
}

// ---------------------------------------------------------------------------
$section('5. Ядро: BaaS, движки, выбор, агент');

if(Loader::includeModule('crm') && class_exists(\Bitrix\Crm\Integration\AI\BaasManager::class))
{
	$ignored = \Bitrix\Crm\Integration\AI\BaasManager::isIgnored();
	$line($ignored ? 'OK' : 'WARN', 'crm::AI_IGNORE_BAAS', $ignored ? 'Y' : 'N — включит «Проверить и включить»');
}

if(Loader::includeModule('ai') && class_exists(\Bitrix\AI\Model\EngineTable::class))
{
	$expected = $config->getCompletionsUrl();
	foreach([Constants::CATEGORY_AUDIO, Constants::CATEGORY_TEXT] as $category)
	{
		$row = \Bitrix\AI\Model\EngineTable::getList([
			'select' => ['ID', 'COMPLETIONS_URL'],
			'filter' => ['=CODE' => Constants::getEngineCode($category), '=CATEGORY' => $category],
			'limit' => 1,
		])->fetch();
		$line(
			match(true) { !$row => 'WARN', (string)$row['COMPLETIONS_URL'] !== $expected => 'FAIL', default => 'OK' },
			'движок '.$category,
			match(true)
			{
				!$row => 'не зарегистрирован — «Проверить и включить»',
				(string)$row['COMPLETIONS_URL'] !== $expected => 'адрес в b_ai_engine не совпадает с настройками — «Проверить и включить» (дважды: bx-shef/toolsai#3)',
				default => 'зарегистрирован, адрес совпадает',
			}
		);
	}
}

try
{
	// Тот же отчёт, что у «Проверить и включить»: штатный движок рядом с
	// движком модуля — не сбой, другие сценарии CRM — для сведения.
	foreach((new Setup($config))->checkEngineSelection() as $key => $row)
	{
		$line(
			($row['info'] ?? false) ? 'INFO' : ($row['ok'] ? 'OK' : ($key === '*' ? 'FAIL' : 'WARN')),
			'выбор в настройках ИИ: '.$key,
			$row['message']
		);
	}
}
catch(\Throwable $throwable)
{
	$line('WARN', 'выбор в настройках ИИ', 'не прочитался: '.$throwable->getMessage());
}

$agent = \CAgent::GetList([], ['NAME' => Setup::AGENT_NAME])->Fetch();
$line($agent ? 'OK' : 'WARN', 'агент анализа сделок', $agent ? 'есть, ACTIVE='.$agent['ACTIVE'] : 'нет — «Проверить и включить»');

// ---------------------------------------------------------------------------
$section('6. Лог и диагностика');

$loggers = (array)(\Bitrix\Main\Config\Configuration::getValue('loggers') ?? []);
$line(
	isset($loggers['crm.Integration.AI']) ? 'OK' : 'WARN',
	'лог crm.Integration.AI',
	isset($loggers['crm.Integration.AI']) ? 'настроен' : 'нет — отказы автозапуска уйдут в никуда (docs/04-runbook.md, шаг 1)'
);
if(Loader::includeModule('shef.problems') && class_exists(\Shef\Problems\Main\Constants::class))
{
	$dir = (string)\Shef\Problems\Main\Constants::getLogDir();
	$line(is_dir($dir) && is_writable($dir) ? 'OK' : 'WARN', 'каталог логов shef.problems', $dir.(is_dir($dir) && is_writable($dir) ? '' : ' — нет или не доступен на запись'));
}

echo PHP_EOL.'Автозапуск по воронкам и гейты звонка: php -f cli/ai-call-autostart-diag.php'.PHP_EOL;
echo ($failed > 0 ? "FAIL: $failed" : 'FAIL нет').PHP_EOL;

exit($failed > 0 ? 1 : 0);

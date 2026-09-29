<?php

/**
 * СТОРОЖ ТОЧЕК ОПОРЫ: проверяет, что обновление Битрикс24 не сломало то,
 * на что опирается модуль shef.toolsai.
 *
 * Модуль не правит ядро, но рассчитывает на его поведение. Почти всё, что
 * может сломаться, ломается ТИХО. Этот скрипт проверяет каждую точку опоры
 * и печатает расхождения.
 *
 * Запускать ПОСЛЕ КАЖДОГО обновления платформы, до того как пустить людей.
 *
 *   /usr/bin/php -f core-api-guard.php              # коротко
 *   VERBOSE=1 /usr/bin/php -f core-api-guard.php    # с пояснением по каждой точке
 *
 * Код возврата: 0 — всё на месте, 1 — есть расхождения.
 * Удобно снимать эталон до обновления и сравнивать после:
 *   /usr/bin/php -f core-api-guard.php > /tmp/guard-before.txt
 *   ... обновление ...
 *   /usr/bin/php -f core-api-guard.php > /tmp/guard-after.txt
 *   diff /tmp/guard-before.txt /tmp/guard-after.txt
 *
 * Подробности по каждой точке — docs/03-upgrade-watch.md.
 *
 * Скрипт ТОЛЬКО ЧИТАЕТ. Приватные константы ядра читаются через Reflection —
 * это диагностика, в рантайме модуля такого нет.
 */

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = "Y";
const NO_AGENT_STATISTIC = "Y";
const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

// Скрипт лежит в <корень>/bitrix|local/modules/shef.toolsai/cli/: корень сайта
// на четыре уровня выше. Другое место — задайте DOCUMENT_ROOT в окружении.
$_SERVER["DOCUMENT_ROOT"] = (string)(getenv('DOCUMENT_ROOT') ?: realpath(__DIR__ . "/../../../../"));
$_SERVER['SCRIPT_URI'] = 'https://' . (getenv('PORTAL_HOST') ?: 'localhost') . '/';

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only');
}

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

$verbose = (bool)getenv('VERBOSE');
$failures = 0;
$warnings = 0;

/** Ожидаемые значения. Правятся ОСОЗНАННО, вместе с кодом модуля. */
$EXPECT = [
    // ai/lib/Engine/ThirdParty.php
    'thirdparty.http_status_ok' => 202,
    'thirdparty.http_timeout' => 5,
    'thirdparty.limited_code' => 'itsolutionru.gptconnector',
    // crm/lib/Copilot/Pipeline/TargetResolver.php
    'targetresolver.whitelist' => [1, 2],           // Lead, Deal
    // crm/lib/Service/Timeline/Config.php
    'audio.extensions' => ['mp3', 'mp4', 'm4a', 'vp6', 'aac', 'wav'],
    // crm/lib/integration/ai/suitableaudioschecker.php
    'audio.min_size' => 61440,
    'audio.max_size' => 26214400,
    'audio.min_time' => 10,
    'audio.max_time' => 3600,
];

// ---------------------------------------------------------------------------

function ok(string $point, string $detail = ''): void
{
    printf("  OK    %-46s %s\n", $point, $detail);
}

function fail(string $point, string $detail, string $why = ''): void
{
    global $failures, $verbose;
    $failures++;
    printf("  FAIL  %-46s %s\n", $point, $detail);
    if ($verbose && $why !== '') {
        echo "        -> $why\n";
    }
}

function warn(string $point, string $detail, string $why = ''): void
{
    global $warnings, $verbose;
    $warnings++;
    printf("  WARN  %-46s %s\n", $point, $detail);
    if ($verbose && $why !== '') {
        echo "        -> $why\n";
    }
}

function info(string $point, string $detail): void
{
    printf("  ..    %-46s %s\n", $point, $detail);
}

function head(string $title): void
{
    echo "\n", $title, "\n", str_repeat('-', 90), "\n";
}

/** Читает приватную/защищённую константу класса. */
function constOf(string $class, string $name): mixed
{
    if (!class_exists($class)) {
        return null;
    }

    try {
        $rc = new ReflectionClass($class);

        return $rc->hasConstant($name) ? $rc->getConstant($name) : null;
    } catch (\Throwable) {
        return null;
    }
}

/** Нормализованное тело метода — чтобы ловить смену логики, а не форматирования. */
function methodBody(string $class, string $method): ?string
{
    if (!class_exists($class)) {
        return null;
    }

    try {
        $rm = new ReflectionMethod($class, $method);
        $file = $rm->getFileName();
        if (!$file || !is_readable($file)) {
            return null;
        }

        $lines = array_slice(
            file($file),
            $rm->getStartLine() - 1,
            $rm->getEndLine() - $rm->getStartLine() + 1
        );

        return preg_replace('/\s+/', ' ', trim(implode('', $lines)));
    } catch (\Throwable) {
        return null;
    }
}

// ---------------------------------------------------------------------------

echo str_repeat('=', 90), "\n";
echo "СТОРОЖ ТОЧЕК ОПОРЫ shef.toolsai   ", date('Y-m-d H:i:s'), "\n";
echo str_repeat('=', 90), "\n";

head('ВЕРСИИ');
foreach (['main', 'crm', 'ai', 'voximplant', 'baas', 'bizproc'] as $module) {
    info(
        "модуль $module",
        ModuleManager::isModuleInstalled($module)
            ? (string)ModuleManager::getVersion($module)
            : 'НЕ УСТАНОВЛЕН'
    );
}

// Редакция портала меняет сразу много правил — см. docs/03-upgrade-watch.md.
if (ModuleManager::isModuleInstalled('bitrix24')) {
    warn(
        'модуль bitrix24',
        'ПОЯВИЛСЯ',
        'меняются правила лимитов: check_limits станет Y, включится суточный промо-лимит (5/сутки). '
        . 'Проверь /bitrix/admin/settings.php?mid=ai'
    );
} else {
    ok('модуль bitrix24', 'отсутствует (как и ожидалось)');
}

// ---------------------------------------------------------------------------

head('КРИТИЧНО 1. ThirdParty::checkLimits() — на нём держится наш учёт расхода');

if (!Loader::includeModule('ai')) {
    fail('модуль ai', 'не подключается', 'без него не работает ничего');
} else {
    $body = methodBody(\Bitrix\AI\Engine\ThirdParty::class, 'checkLimits');
    if ($body === null) {
        fail('checkLimits()', 'метод не найден', 'сигнатура изменилась — читай ai/lib/Engine/ThirdParty.php');
    } elseif (str_contains($body, $EXPECT['thirdparty.limited_code'])) {
        ok('checkLimits()', 'лимитер по-прежнему только для itsolutionru.gptconnector');
    } else {
        fail(
            'checkLimits()',
            'условие ИЗМЕНИЛОСЬ',
            'лимитер может включиться для нашего движка -> запросы начнут упираться в LIMIT_IS_EXCEEDED '
            . 'при отсутствии пакетов BaaS. Тело метода сейчас: ' . mb_substr($body, 0, 200)
        );
    }
}

// ---------------------------------------------------------------------------

head('КРИТИЧНО 2. Статус-код ответа на completions');

$status = constOf(\Bitrix\AI\Engine\ThirdParty::class, 'HTTP_STATUS_OK');
if ($status === null) {
    fail('HTTP_STATUS_OK', 'константа исчезла', 'проверь ai/lib/Engine/ThirdParty.php');
} elseif ($status === $EXPECT['thirdparty.http_status_ok']) {
    ok('HTTP_STATUS_OK', (string)$status . ' (эндпоинт отвечает 202 на POST)');
} else {
    fail(
        'HTTP_STATUS_OK',
        "было {$EXPECT['thirdparty.http_status_ok']}, стало $status",
        'endpoint/completions.php отдаёт 202 — поправь на новое значение, иначе ВСЕ задания будут падать'
    );
}

$timeout = constOf(\Bitrix\AI\Engine\ThirdParty::class, 'HTTP_TIMEOUT');
if ($timeout === null) {
    warn('HTTP_TIMEOUT', 'константа исчезла');
} elseif ($timeout >= $EXPECT['thirdparty.http_timeout']) {
    ok('HTTP_TIMEOUT', $timeout . ' сек');
} else {
    warn('HTTP_TIMEOUT', "стал $timeout сек (был {$EXPECT['thirdparty.http_timeout']})", 'эндпоинт должен отвечать ещё быстрее');
}

// ---------------------------------------------------------------------------

head('КРИТИЧНО 3. Колбэки берутся из запроса, а не хардкодятся');

$moduleLib = dirname(__DIR__) . '/lib';
if (!is_dir($moduleLib)) {
    info('исходники модуля', 'не найдены, проверка пропущена');
} else {
    $hardcoded = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleLib));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $src = (string)file_get_contents($file->getPathname());
            if (str_contains($src, 'thirdparty.callback') || str_contains($src, 'ai.thirdparty')) {
                $hardcoded[] = str_replace($_SERVER['DOCUMENT_ROOT'], '', $file->getPathname());
            }
        }
    }

    if ($hardcoded === []) {
        ok('в модуле нет захардкоженных путей', 'колбэки и ключ кеша берутся у ядра');
    } else {
        fail(
            'захардкоженные пути ядра',
            implode(', ', $hardcoded),
            'маршруты колбэков и ключ кеша ai.thirdparty01 меняются при обновлениях — убери хардкод'
        );
    }
}

// ---------------------------------------------------------------------------

head('КРИТИЧНО 4. Разбор успешного ответа');

$body = methodBody(\Bitrix\AI\Engine\ThirdParty::class, 'getResultFromRaw');
if ($body === null) {
    fail('getResultFromRaw()', 'метод не найден');
} elseif (str_contains($body, "'result'") || str_contains($body, '"result"')) {
    ok('getResultFromRaw()', 'по-прежнему ждёт ключ result');
} else {
    fail(
        'getResultFromRaw()',
        'формат ИЗМЕНИЛСЯ',
        'колбэк отдаёт {"result": ["текст"]}. Если ключ другой — транскрипт станет пустым, '
        . 'а цепочка оборвётся МОЛЧА (transcribecallrecording.php:83-93)'
    );
}

// ---------------------------------------------------------------------------

head('КРИТИЧНО 5. Свой движок поднимается на коробке');

$body = methodBody(\Bitrix\AI\Engine::class, 'loadThirdParty');
if ($body === null) {
    fail('Engine::loadThirdParty()', 'метод не найден');
} elseif (str_contains($body, 'shouldUseB24') || str_contains($body, "includeModule('bitrix24')")) {
    fail(
        'Engine::loadThirdParty()',
        'ПОЯВИЛСЯ гейт на bitrix24',
        'третьесторонние движки на коробке больше не поднимаются — нужен другой путь, см. docs/03-upgrade-watch.md п.5'
    );
} else {
    ok('Engine::loadThirdParty()', 'читает b_ai_engine без проверки на bitrix24');
}

// Движок реально виден — С ТЕМ ЖЕ ФИЛЬТРОМ КАЧЕСТВА, что и в CRM.
//
// Для audio CRM строит список с Quality('transcribe') (crm/.../eventhandler.php:107-111),
// а ThirdParty::hasQuality() (ai/lib/Engine/ThirdParty.php:297-310) для audio
// возвращает true ТОЛЬКО если есть хоть один движок категории text.
// Без text-движка наш audio-движок из списка выпадает, дефолт становится null,
// и Engine::getByCode('') в abstractoperation.php:693 ничего не находит.
$qualityByCategory = [
    'audio' => new \Bitrix\AI\Quality([\Bitrix\AI\Quality::QUALITIES['transcribe']]),
    'text' => null,
];
foreach ($qualityByCategory as $category => $quality) {
    $engines = \Bitrix\AI\Engine::getListAvailable($category, $quality);
    $own = array_filter($engines, static fn($e) => str_starts_with($e->getCode(), 'sheftoolsai'));

    if ($own !== []) {
        ok("движок категории $category (с фильтром качества)", reset($own)->getCode());
    } elseif ($engines !== []) {
        warn(
            "движок категории $category",
            'наш не найден, но есть чужие: ' . implode(', ', array_map(static fn($e) => $e->getCode(), $engines))
        );
    } else {
        fail(
            "движок категории $category",
            'НЕТ НИ ОДНОГО',
            $category === 'audio'
                ? 'audio-движок виден только при наличии text-движка (ThirdParty::hasQuality). Зарегистрируй оба'
                : 'операции упадут на abstractoperation.php:332'
        );
    }
}

// Какой движок ВЫБРАН в настройках ИИ. CRM берёт движок строго по коду из
// настройки (abstractoperation.php:684-697, Engine::getByCode без фолбэка):
// если там сохранён код облачного движка, которого на коробке нет, — операция
// не найдёт движок, даже если наш зарегистрирован.
if (Loader::includeModule('crm')) {
    $tuning = new \Bitrix\AI\Tuning\Manager();
    $engineSettings = [
        \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE => 'audio',
        \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE => 'text',
    ];
    foreach ($engineSettings as $code => $category) {
        $item = $tuning->getItem($code);
        $value = $item ? (string)$item->getValue() : '';

        if ($item === null) {
            warn("настройка $code", 'не найдена', 'группа настроек Копилота не загрузилась — проверь isAiCallProcessingEnabled()');
        } elseif ($value === '') {
            fail("выбранный движок $category", 'ПУСТО', 'в /settings/configs/?page=ai выбери наш движок явно');
        } elseif (str_starts_with($value, 'sheftoolsai')) {
            ok("выбранный движок $category", $value);
        } else {
            warn("выбранный движок $category", "$value (не наш)", 'если этого движка нет на коробке — операция не найдёт движок');
        }
    }
}

// ---------------------------------------------------------------------------

head('ЗАМЕТНО 6. Обход BaaS');

if (!Loader::includeModule('crm')) {
    fail('модуль crm', 'не подключается');
} else {
    $ignored = \Bitrix\Crm\Integration\AI\BaasManager::isIgnored();
    if ($ignored) {
        ok('crm::AI_IGNORE_BAAS', 'включён — автозапуск не блокируется отсутствием пакетов');
    } else {
        fail(
            'crm::AI_IGNORE_BAAS',
            'ВЫКЛЮЧЕН',
            'автозапуск заблокирован (autolauncher.php:38). Вернуть: BaasManager::setIgnored(true) '
            . 'или запустить cli/setup.php'
        );
    }

    $launcher = \Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher::isEnabled();
    if ($launcher) {
        ok('AutoLauncher::isEnabled()', 'true');
    } else {
        fail('AutoLauncher::isEnabled()', 'false', 'запусти ai-call-autostart-diag.php — он покажет, какой гейт закрыт');
    }
}

// ---------------------------------------------------------------------------

head('ЗАМЕТНО 7. Белый список целей (только сделка и лид)');

$whitelist = constOf(\Bitrix\Crm\Copilot\Pipeline\TargetResolver::class, 'ENTITY_TYPE_WHITELIST');
if ($whitelist === null) {
    warn('TargetResolver::ENTITY_TYPE_WHITELIST', 'константа исчезла', 'проверь, как теперь ищется цель');
} else {
    $actual = array_values($whitelist);
    sort($actual);
    $expected = $EXPECT['targetresolver.whitelist'];
    sort($expected);

    $names = implode(', ', array_map(static fn($id) => \CCrmOwnerType::ResolveName($id), $actual));

    if ($actual === $expected) {
        ok('TargetResolver::ENTITY_TYPE_WHITELIST', $names);
    } else {
        warn(
            'TargetResolver::ENTITY_TYPE_WHITELIST',
            "СОСТАВ ИЗМЕНИЛСЯ: $names",
            'расширение — нам на пользу; сужение — часть звонков перестанет обрабатываться'
        );
    }
}

// ---------------------------------------------------------------------------

head('ЗАМЕТНО 8-9. Аудио: расширения и пороги');

$ext = constOf(\Bitrix\Crm\Service\Timeline\Config::class, 'ALLOWED_AUDIO_EXTENSIONS');
if ($ext === null) {
    warn('ALLOWED_AUDIO_EXTENSIONS', 'константа исчезла');
} elseif ($ext === $EXPECT['audio.extensions']) {
    ok('ALLOWED_AUDIO_EXTENSIONS', implode(', ', $ext));
} else {
    warn(
        'ALLOWED_AUDIO_EXTENSIONS',
        'ИЗМЕНИЛСЯ: ' . implode(', ', $ext),
        'было: ' . implode(', ', $EXPECT['audio.extensions'])
    );
}

$thresholds = [
    'ai_integration_audiofile_min_size' => $EXPECT['audio.min_size'],
    'ai_integration_audiofile_max_size' => $EXPECT['audio.max_size'],
    'ai_integration_audio_min_call_time' => $EXPECT['audio.min_time'],
    'ai_integration_audio_max_call_time' => $EXPECT['audio.max_time'],
];
foreach ($thresholds as $option => $default) {
    $value = (int)Option::get('crm', $option, $default);
    if ($value === $default) {
        info("crm::$option", (string)$value . ' (по умолчанию)');
    } else {
        info("crm::$option", (string)$value . " (переопределено, дефолт $default)");
    }
}

// ---------------------------------------------------------------------------

head('НАСТРОЙКИ, от которых зависят колбэк и загрузка записи');

$publicUrl = \Bitrix\AI\Config::getValue('public_url');
if (empty($publicUrl)) {
    warn(
        'ai::public_url',
        'не задан',
        'хост колбэка возьмётся из UrlManager::getHostUrl() — за прокси легко получить внутренний адрес, '
        . 'и тогда не дойдёт ни колбэк, ни загрузка записи (QueueJob.php:338, transcribecallrecording.php:180)'
    );
} else {
    ok('ai::public_url', (string)$publicUrl);
}

$checkLimits = \Bitrix\AI\Config::getValue('check_limits');
if ($checkLimits === 'Y') {
    warn(
        'ai::check_limits',
        'Y — промо-лимит ВКЛЮЧЁН',
        'на коробке обычно N. При Y пользователи упрутся в 5 запросов в сутки (Daily.php:14)'
    );
} else {
    ok('ai::check_limits', (string)($checkLimits ?: 'N') . ' — промо-лимит не мешает');
}

// Логгер: без него все отказы автозапуска молчат.
$loggers = (array)(\Bitrix\Main\Config\Configuration::getValue('loggers') ?? []);
if (isset($loggers['crm.Integration.AI'])) {
    ok('логгер crm.Integration.AI', 'настроен');
} else {
    warn(
        'логгер crm.Integration.AI',
        'не настроен',
        'все отказы автозапуска уходят в NullLogger. Включение — docs/04-runbook.md'
    );
}

// ---------------------------------------------------------------------------

head('ИТОГ');

if ($failures === 0 && $warnings === 0) {
    echo "  Все точки опоры на месте.\n";
} else {
    printf("  Критичных расхождений: %d, предупреждений: %d\n", $failures, $warnings);
    echo "  Разбор каждой точки — docs/03-upgrade-watch.md\n";
}

echo "\n";

exit($failures > 0 ? 1 : 0);

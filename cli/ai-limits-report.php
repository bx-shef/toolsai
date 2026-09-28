<?php

/**
 * ОТЧЁТ: лимиты ИИ и фактический расход запросов.
 *
 * Скрипт ТОЛЬКО ЧИТАЕТ. Показывает три независимых уровня, на которых
 * коробка считает запросы к ИИ:
 *
 *   1) Промо-лимит (локальный, таблица b_ai_usage) — сутки/месяц.
 *      На коробке по умолчанию ВЫКЛЮЧЕН: ai::check_limits = 'N'
 *      (ai/default_option.php:8), поэтому Usage::isInLimit() сразу
 *      возвращает true (ai/lib/Limiter/Usage.php:63).
 *
 *   2) Пакеты BaaS (b_baas_services, код услуги ai_copilot_token) —
 *      ЕДИНСТВЕННОЕ место, где есть число «сколько осталось»
 *      (baas/lib/Entity/Service.php:227).
 *
 *   3) Удалённая проверка у облачного AI-прокси
 *      (ai/lib/Limiter/LimitControlBoxService.php:21) — она возвращает
 *      только baasAvailable + errorLimitType, БЕЗ остатка.
 *
 * Плюс фактический расход по журналу операций CRM (b_crm_ai_queue):
 * сколько запросов и какого типа реально ушло.
 *
 * Важно: стоимость ЛЮБОГО запроса = 1 (ai/lib/Payload/Payload.php:15).
 * Транскрибация часового звонка стоит столько же, сколько короткий текст.
 * Обнуление стоимости (isSponsoredOperation) работает только в облаке —
 * оно обёрнуто в Loader::includeModule('bitrix24')
 * (crm/lib/integration/ai/operation/abstractoperation.php:425).
 *
 * Запуск:
 *   /usr/bin/php -f ai-limits-report.php
 *   DAYS=90 /usr/bin/php -f ai-limits-report.php   # глубина отчёта, по умолчанию 30
 *   USER_ID=353 /usr/bin/php -f ai-limits-report.php  # промо-счётчик по юзеру
 */

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = "Y";
const NO_AGENT_STATISTIC = "Y";
const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

// Скрипт лежит в <корень>/bitrix|local/modules/shef.toolsai/cli/: корень сайта
// на четыре уровня выше. Другое место — задайте DOCUMENT_ROOT в окружении.
$_SERVER["DOCUMENT_ROOT"] = (string)(getenv('DOCUMENT_ROOT') ?: realpath(__DIR__ . "/../../../../"));
$_SERVER['SCRIPT_URI'] = 'https://crm.agrox.by/';

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only');
}

use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

Loader::includeModule('crm');

$OPERATION_NAMES = [
    TranscribeCallRecording::TYPE_ID => 'Transcribe — распознавание записи',
    SummarizeCallTranscription::TYPE_ID => 'Summarize — краткое содержание',
    FillItemFieldsFromCallTranscription::TYPE_ID => 'FillFields — заполнение полей',
    ScoreCall::TYPE_ID => 'ScoreCall — оценка по скрипту',
    ExtractScoringCriteria::TYPE_ID => 'ExtractScoringCriteria — разбор скрипта',
    FillRepeatSaleTips::TYPE_ID => 'FillRepeatSaleTips — подсказки повторной продажи',
    ScreeningRepeatSaleItem::TYPE_ID => 'ScreeningRepeatSaleItem — отбор повторной продажи',
    AnalyzeCommunication::TYPE_ID => 'AnalyzeCommunication — анализ беседы',
];

function head(string $title): void
{
    echo "\n", str_repeat('=', 96), "\n", $title, "\n", str_repeat('=', 96), "\n";
}

function line(string $label, $value): void
{
    if (is_bool($value)) {
        $value = $value ? 'да' : 'нет';
    } elseif ($value === null) {
        $value = '(null)';
    }
    printf("  %-62s %s\n", $label, $value);
}

$days = max(1, (int)(getenv('DAYS') ?: 30));
$userId = (int)(getenv('USER_ID') ?: 0);

// ---------------------------------------------------------------------------
// 1. Промо-лимит (локальные счётчики)
// ---------------------------------------------------------------------------

head('1. ПРОМО-ЛИМИТ — локальные счётчики, таблица b_ai_usage');

$checkLimits = \Bitrix\AI\Config::getValue('check_limits');
line('ai::check_limits', $checkLimits);
if ($checkLimits !== 'Y') {
    echo "\n  Промо-лимит ОТКЛЮЧЁН: Usage::isInLimit() выходит на первой строке\n";
    echo "  (ai/lib/Limiter/Usage.php:63). Суточные 5 запросов на пользователя\n";
    echo "  и месячный потолок НЕ применяются. Включается в настройках модуля ai:\n";
    echo "  /bitrix/admin/settings.php?mid=ai\n";
}

if ($userId > 0 && Loader::includeModule('ai')) {
    $context = new \Bitrix\AI\Context('crm', 'diag', $userId);
    $daily = new \Bitrix\AI\Limiter\Period\Daily($context);
    $monthly = new \Bitrix\AI\Limiter\Period\Monthly($context);
    echo "\n";
    line("сутки ($userId): использовано / потолок", $daily->getCurrentUsage() . ' / ' . $daily->getMaximumUsage());
    line('месяц (весь портал): использовано / потолок', $monthly->getCurrentUsage() . ' / ' . $monthly->getMaximumUsage());
    echo "\n  Внимание на асимметрию: суточный счётчик считается ПО ПОЛЬЗОВАТЕЛЮ\n";
    echo "  (Daily.php:48), а месячный — СУММОЙ ПО ВСЕМУ ПОРТАЛУ (Monthly.php:44-49).\n";
} elseif (Loader::includeModule('ai')) {
    echo "\n  Передай USER_ID=<id>, чтобы увидеть промо-счётчики конкретного пользователя.\n";
}

// ---------------------------------------------------------------------------
// 2. Пакеты BaaS — единственный источник «сколько осталось»
// ---------------------------------------------------------------------------

head('2. ПАКЕТЫ BaaS — здесь и только здесь есть остаток');

if (!Loader::includeModule('baas')) {
    line('модуль baas', 'НЕ подключается');
} else {
    $baas = \Bitrix\Baas\Baas::getInstance();
    line('BaaS доступен (isAvailable)', $baas->isAvailable());
    line('портал зарегистрирован в BaaS (isRegistered)', $baas->isRegistered());

    $service = $baas->getService(\Bitrix\AI\Integration\Baas\BaasTokenService::SERVICE_CODE);
    echo "\n  --- услуга ai_copilot_token ---\n";
    line('  можно списать хотя бы 1 (canConsume)', $service->canConsume(1));

    // getService() объявлен как Contract\Service, а остаток и даты живут
    // на конкретном Entity\Service — поэтому спрашиваем аккуратно.
    $extra = [
        'ОСТАТОК (value)' => 'getValue',
        'максимум пакета (maximalValue)' => 'getMaximalValue',
        'услуга доступна (isAvailable)' => 'isAvailable',
        'пакеты не просрочены (isActual)' => 'isActual',
        'можно расходовать (isActive)' => 'isActive',
        'возобновляемая (isRenewable)' => 'isRenewable',
    ];
    foreach ($extra as $label => $method) {
        if (method_exists($service, $method)) {
            line('  ' . $label, $service->{$method}());
        }
    }
    if (method_exists($service, 'getExpirationDate')) {
        line('  действует до', $service->getExpirationDate()->toString());
    }

    echo "\n  Тот же ответ отдаёт внутренний AJAX-контроллер:\n";
    echo "    /bitrix/services/main/ajax.php?action=baas.Service.get&code=ai_copilot_token\n";
    echo "  Поле `value` в JSON — это и есть остаток (baas/lib/Entity/Service.php:246).\n";
    echo "  Через REST-хук это НЕ достаётся: у модулей ai и baas нет REST-методов чтения лимитов.\n";
    echo "\n  Страница с пакетами: /bitrix/admin/baas_marketplace.php\n";
    echo "  (она показывает список только если isAvailable() и isRegistered() — иначе\n";
    echo "  выводит предупреждение вместо таблицы, baas/admin/baas_marketplace.php:25-41).\n";
}

// ---------------------------------------------------------------------------
// 3. Фактический расход по журналу CRM
// ---------------------------------------------------------------------------

head("3. ФАКТИЧЕСКИЙ РАСХОД — журнал b_crm_ai_queue за $days дн.");

$since = DateTime::createFromTimestamp(time() - $days * 86400);

$rows = QueueTable::query()
    ->addSelect('TYPE_ID')
    ->addSelect('EXECUTION_STATUS')
    ->addSelect(Query::expr()->count('ID'), 'CNT')
    ->where('CREATED_TIME', '>=', $since)
    ->setGroup(['TYPE_ID', 'EXECUTION_STATUS'])
    ->fetchAll()
;

if (!$rows) {
    echo "  За период записей нет.\n";
} else {
    $byType = [];
    $total = 0;
    foreach ($rows as $row) {
        $type = (int)$row['TYPE_ID'];
        $byType[$type][$row['EXECUTION_STATUS']] = (int)$row['CNT'];
        $total += (int)$row['CNT'];
    }

    printf("  %-52s %8s %8s %8s\n", 'операция', 'всего', 'успех', 'ошибка');
    echo '  ', str_repeat('-', 80), "\n";
    foreach ($byType as $type => $statuses) {
        $success = $statuses[QueueTable::EXECUTION_STATUS_SUCCESS] ?? 0;
        $error = $statuses[QueueTable::EXECUTION_STATUS_ERROR] ?? 0;
        $sum = array_sum($statuses);
        printf(
            "  %-52s %8d %8d %8d\n",
            $OPERATION_NAMES[$type] ?? ('тип ' . $type),
            $sum,
            $success,
            $error
        );
    }
    echo '  ', str_repeat('-', 80), "\n";
    printf("  %-52s %8d\n", 'ИТОГО запросов (каждый стоит 1)', $total);
    printf("  %-52s %8.1f\n", 'в среднем в сутки', $total / $days);
}

// частые коды ошибок — обычно там и видно упор в лимит
$errors = QueueTable::query()
    ->addSelect('ERROR_CODE')
    ->addSelect(Query::expr()->count('ID'), 'CNT')
    ->where('CREATED_TIME', '>=', $since)
    ->where('EXECUTION_STATUS', QueueTable::EXECUTION_STATUS_ERROR)
    ->whereNotNull('ERROR_CODE')
    ->setGroup(['ERROR_CODE'])
    ->setOrder(['CNT' => 'DESC'])
    ->setLimit(15)
    ->fetchAll()
;

if ($errors) {
    echo "\n  --- коды ошибок ---\n";
    foreach ($errors as $row) {
        printf("  %-62s %d\n", $row['ERROR_CODE'], (int)$row['CNT']);
    }
    echo "\n  Коды упора в лимит (ai/lib/Engine.php:908-940):\n";
    echo "    LIMIT_IS_EXCEEDED_BAAS            — пакеты BaaS кончились\n";
    echo "    LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT — частотное ограничение BaaS\n";
    echo "    LIMIT_IS_EXCEEDED_DAILY           — суточный промо-лимит\n";
    echo "    LIMIT_IS_EXCEEDED_MONTHLY         — месячный промо-лимит\n";
    echo "  Ни один из них НЕ содержит числа остатка — только факт упора.\n";
}

// ---------------------------------------------------------------------------
// 4. Сколько запросов съедает один звонок
// ---------------------------------------------------------------------------

head('4. ЦЕНА ОДНОГО ЗВОНКА В ЗАПРОСАХ');

echo "  Сценарии — это цепочки, каждый шаг = отдельный запрос к ИИ\n";
echo "  (crm/lib/Copilot/Pipeline/Scenario/*.php):\n\n";
line('SUMMARIZE   = Transcribe + Summarize', '2 запроса');
line('CALL_SCORING = Transcribe + ScoreCall', '2 запроса');
line('ANALYZE_COMMUNICATION = Transcribe + AnalyzeCommunication', '2 запроса');
line('FILL_FIELDS = Transcribe + Summarize + FillFields', '3 запроса');
line('FULL = Transcribe + Summarize + FillFields + ScoreCall + AnalyzeCommunication', '5 запросов');
echo "\n  FULL включается автоматически, когда разрешено больше одного сценария\n";
echo "  (callautostartstrategy.php:241-251). То есть «включить всё» = x5 к расходу.\n";

head('ГОТОВО');

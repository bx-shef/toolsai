<?php

/**
 * ДИАГНОСТИКА: почему не запускается автораспознавание (транскрибация) звонков
 * в CRM.
 *
 * Скрипт ТОЛЬКО ЧИТАЕТ. Он проходит ровно по тем же проверкам, что и штатный
 * автозапуск Битрикса, и печатает, на какой из них цепочка обрывается.
 *
 * Порядок проверок повторяет код ядра:
 *   Call::onAfterAdd/onAfterUpdate
 *     -> EventHandler::onAfterCallActivityAdd    (crm/lib/integration/ai/eventhandler.php:371)
 *     -> AutoLauncher::isEnabled()               (.../operation/autostart/autolauncher.php:35)
 *     -> CallAutoStartStrategy::run()            (.../autolauncher/callautostartstrategy.php:28)
 *          - настройки автозапуска по воронке    (.../autostart/fillfieldssettings.php:129)
 *          - речевая аналитика по звонку         (crm/lib/Copilot/CallAssessment/ItemFactory.php:18)
 *          - цель = ТОЛЬКО сделка или лид        (crm/lib/Copilot/Pipeline/TargetResolver.php:18)
 *          - пригодность записи                  (crm/lib/integration/ai/suitableaudioschecker.php:36)
 *     -> AIManager::launchCallRecordingTranscription()
 *
 * Запуск:
 *   # общая часть + последние 10 звонков
 *   /usr/bin/php -f ai-call-autostart-diag.php
 *   # разбор конкретного звонка
 *   ACTIVITY_ID=586564 /usr/bin/php -f ai-call-autostart-diag.php
 *   # сколько последних звонков смотреть (по умолчанию 10)
 *   LIMIT=30 /usr/bin/php -f ai-call-autostart-diag.php
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

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\SuitableAudiosChecker;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

Loader::includeModule('crm');

$OPERATION_NAMES = [
    TranscribeCallRecording::TYPE_ID => 'Transcribe (распознавание записи)',
    SummarizeCallTranscription::TYPE_ID => 'Summarize (краткое содержание)',
    FillItemFieldsFromCallTranscription::TYPE_ID => 'FillFields (заполнение полей)',
    ScoreCall::TYPE_ID => 'ScoreCall (оценка по скрипту)',
    AnalyzeCommunication::TYPE_ID => 'AnalyzeCommunication (анализ общения)',
];

$DIRECTION_NAMES = [
    \CCrmActivityDirection::Incoming => 'входящие',
    \CCrmActivityDirection::Outgoing => 'исходящие',
];

function mark(bool $ok): string
{
    return $ok ? '[ OK ]' : '[ STOP ]';
}

function line(string $label, $value, ?bool $ok = null): void
{
    $prefix = $ok === null ? '      ' : mark($ok);
    if (is_bool($value)) {
        $value = $value ? 'да' : 'нет';
    } elseif (is_array($value)) {
        $value = $value === [] ? '(пусто)' : implode(', ', $value);
    } elseif ($value === null) {
        $value = '(null)';
    }
    printf("%-8s %-58s %s\n", $prefix, $label, $value);
}

function head(string $title): void
{
    echo "\n", str_repeat('=', 96), "\n", $title, "\n", str_repeat('=', 96), "\n";
}

// ---------------------------------------------------------------------------
// 1. Общие выключатели
// ---------------------------------------------------------------------------

head('1. ОБЩИЕ ВЫКЛЮЧАТЕЛИ (AutoLauncher::isEnabled)');

$region = Application::getInstance()->getLicense()->getRegion();
$isRuZone = $region === 'ru';

line('регион лицензии (License::getRegion)', $region ?? '(не определён)');
line('  -> зона ru? (влияет на ДЕФОЛТ автозапуска)', $isRuZone);
if (!$isRuZone) {
    echo "         ! ВНИМАНИЕ: вне зоны ru дефолтный набор автозапуска НЕ содержит\n";
    echo "           Transcribe (callchannelsettings.php:130-140). Если настройки воронки\n";
    echo "           никогда не сохраняли руками — распознавание не стартует вообще.\n";
}

$isAvailable = AIManager::isAvailable();
line('модуль ai подключён и регион разрешён (isAvailable)', $isAvailable, $isAvailable);

$isProcessing = AIManager::isAiCallProcessingEnabled();
line('обработка звонков ИИ разрешена (isAiCallProcessingEnabled)', $isProcessing, $isProcessing);

$autoOption = Option::get('crm', 'AI_CALL_PROCESSING_ALLOWED_AUTO_V2', '(не задана)');
line('опция crm::AI_CALL_PROCESSING_ALLOWED_AUTO_V2', $autoOption);

$isAutoAllowed = AIManager::isAiCallAutomaticProcessingAllowed();
line('автообработка разрешена (isAiCallAutomaticProcessingAllowed)', $isAutoAllowed, $isAutoAllowed);

echo "\n--- BaaS (пакеты ИИ) ---\n";
line('crm::AI_IGNORE_BAAS (обход проверки пакетов)', BaasManager::isIgnored());
line('модуль baas подключается', Loader::includeModule('baas'));
try {
    $baasAvailable = BaasManager::isAvailable();
} catch (\Throwable $e) {
    $baasAvailable = 'ОШИБКА: ' . $e->getMessage();
}
line('BaasManager::isAvailable()', $baasAvailable);
try {
    $hasPackage = BaasManager::hasPackage();
} catch (\Throwable $e) {
    $hasPackage = false;
    line('BaasManager::hasPackage() бросил исключение', $e->getMessage());
}
line('BaasManager::hasPackage()  <-- жёсткий гейт автозапуска', $hasPackage, (bool)$hasPackage);
if (!$hasPackage) {
    echo "         ! Без пакетов BaaS автозапуск выключен целиком (autolauncher.php:38).\n";
    echo "           Ручной запуск из карточки при этом может работать.\n";
}

echo "\n--- Глобальные настройки Копилота (Настройки ИИ) ---\n";
foreach (GlobalSetting::cases() as $setting) {
    line('  ' . $setting->name . ' (' . $setting->value . ')', AIManager::isEnabledInGlobalSettings($setting));
}

$launcherEnabled = AutoLauncher::isEnabled();
echo "\n";
line('ИТОГ: AutoLauncher::isEnabled()', $launcherEnabled, $launcherEnabled);

// ---------------------------------------------------------------------------
// 2. Движки ИИ
// ---------------------------------------------------------------------------

head('2. ДВИЖКИ ИИ (без движка audio распознавать нечем)');

if (Loader::includeModule('ai')) {
    foreach (['audio', 'text'] as $category) {
        $engines = \Bitrix\AI\Engine::getListAvailable($category);
        $names = array_map(
            static fn($e) => $e->getName() . ' [' . $e->getCode() . ']',
            $engines
        );
        line('категория "' . $category . '"', $names ?: '(нет ни одного)', $engines !== []);
    }
} else {
    line('модуль ai', 'НЕ подключается', false);
}

// ---------------------------------------------------------------------------
// 3. Настройки автозапуска по воронкам
// ---------------------------------------------------------------------------

head('3. НАСТРОЙКИ АВТОЗАПУСКА ПО ВОРОНКАМ (цель может быть только СДЕЛКА или ЛИД)');

echo "Опция хранится отдельно для КАЖДОЙ воронки: crm::ai_autostart_settings_<typeId>[_<catId>]\n";
echo "(fillfieldssettings.php:183-198)\n\n";

$targets = [];
$leadFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Lead);
if ($leadFactory) {
    $targets[] = [\CCrmOwnerType::Lead, null, 'Лид'];
}
$dealFactory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
if ($dealFactory) {
    foreach ($dealFactory->getCategories() as $category) {
        $targets[] = [\CCrmOwnerType::Deal, $category->getId(), 'Сделка / ' . $category->getName()];
    }
}

foreach ($targets as [$entityTypeId, $categoryId, $title]) {
    $optionName = 'ai_autostart_settings_' . $entityTypeId . ($categoryId === null ? '' : '_' . $categoryId);
    $raw = Option::get('crm', $optionName, '');

    echo "--- $title ---\n";
    line('  опция', $optionName . ($raw === '' ? '  (НЕ СОХРАНЕНА -> берётся дефолт)' : ''));

    $settings = FillFieldsSettings::get($entityTypeId, $categoryId);
    $callSettings = $settings->getChannelSettings('call');
    if (!$callSettings) {
        line('  настройки канала "call"', 'отсутствуют', false);
        continue;
    }

    $types = $callSettings->getOperationTypes();
    $typeNames = array_map(
        fn(int $t) => $OPERATION_NAMES[$t] ?? ('тип ' . $t),
        $types
    );
    $hasTranscribe = in_array(TranscribeCallRecording::TYPE_ID, $types, true);

    line('  операции автозапуска', $typeNames);
    line('  есть Transcribe? (без него цепочка не стартует)', $hasTranscribe, $hasTranscribe);

    $asArray = $callSettings->toArray();
    $directions = array_map(
        fn(int $d) => $DIRECTION_NAMES[$d] ?? ('направление ' . $d),
        $asArray['autostartCallDirections'] ?? []
    );
    line('  направления', $directions, $directions !== []);
    line('  только первый звонок с записью', (bool)($asArray['autostartTranscriptionOnlyOnFirstCallWithRecording'] ?? false));
    echo "\n";
}

// ---------------------------------------------------------------------------
// 4. Разбор конкретных звонков
// ---------------------------------------------------------------------------

$activityId = (int)(getenv('ACTIVITY_ID') ?: 0);
$limit = (int)(getenv('LIMIT') ?: 10);

head('4. РАЗБОР ЗВОНКОВ' . ($activityId > 0 ? " (ACTIVITY_ID=$activityId)" : " (последние $limit)"));

$query = ActivityTable::query()
    ->setSelect(['ID'])
    ->where('PROVIDER_ID', Call::ACTIVITY_PROVIDER_ID)
    ->setOrder(['ID' => 'DESC'])
;
if ($activityId > 0) {
    $query->where('ID', $activityId);
} else {
    $query->setLimit($limit);
}
$activityIds = array_column($query->fetchAll(), 'ID');

if (!$activityIds) {
    echo "Звонков не найдено.\n";
    return;
}

$targetResolver = new TargetResolver();

foreach ($activityIds as $id) {
    $id = (int)$id;
    $activity = \CCrmActivity::GetByID($id, false);
    if (!is_array($activity)) {
        continue;
    }

    echo "\n", str_repeat('-', 96), "\n";
    echo "Дело #$id  (создано " . ($activity['CREATED'] ?? '?') . ")\n";
    echo str_repeat('-', 96), "\n";

    $originId = (string)($activity['ORIGIN_ID'] ?? '');
    $isVox = VoxImplantManager::isActivityBelongsToVoximplant($activity);
    line('ORIGIN_ID', $originId ?: '(пусто)');
    line('звонок опознан как телефония (нужен префикс VI_)', $isVox, $isVox);
    if (!$isVox) {
        echo "         ! Распознавание доступно ТОЛЬКО для дел телефонии\n";
        echo "           (transcribecallrecording.php:66-81). Дело, созданное через\n";
        echo "           crm.activity.add, не годится — нужен telephony.externalcall.register.\n";
        continue;
    }

    $direction = (int)($activity['DIRECTION'] ?? 0);
    line('направление', $DIRECTION_NAMES[$direction] ?? ('код ' . $direction));

    $hasRecordings = Call::hasRecordings($activity);
    line('STORAGE_TYPE_ID', $activity['STORAGE_TYPE_ID'] ?? null);
    line('STORAGE_ELEMENT_IDS', \CCrmActivity::extractStorageElementIds($activity) ?: []);
    line('запись прикреплена к делу', $hasRecordings, $hasRecordings);
    if (!$hasRecordings) {
        echo "         ! Запись не доехала до дела. Проверь telephony.externalCall.attachRecord\n";
        echo "           (vi_crm_helper.php:839) — без неё автозапуск невозможен.\n";
        continue;
    }

    $callId = VoxImplantManager::extractCallIdFromOriginId($originId);
    line('длительность звонка (CALL_DURATION)', VoxImplantManager::getCallDuration($callId));

    $bindings = \CCrmActivity::GetBindings($id) ?: [];
    $bindingList = array_map(
        static fn(array $b) => \CCrmOwnerType::ResolveName((int)$b['OWNER_TYPE_ID']) . ' #' . $b['OWNER_ID'],
        $bindings
    );
    line('привязки дела', $bindingList);

    $target = $targetResolver->findTargetByBindings($bindings);
    line(
        'цель для ИИ (только СДЕЛКА или ЛИД!)',
        $target
            ? \CCrmOwnerType::ResolveName($target->getEntityTypeId()) . ' #' . $target->getEntityId()
                . ' (воронка ' . ($target->getCategoryId() ?? '-') . ')'
            : 'НЕ НАЙДЕНА',
        (bool)$target
    );
    if (!$target) {
        echo "         ! TargetResolver берёт только сделку и лид (TargetResolver.php:18-21).\n";
        echo "           Звонок, привязанный лишь к контакту/компании/заказу, не обрабатывается.\n";
        continue;
    }

    $userId = null;
    if (\CCrmOwnerType::isUseFactoryBasedApproach($target->getEntityTypeId())) {
        $factory = Container::getInstance()->getFactory($target->getEntityTypeId());
        $userId = $factory?->getItem($target->getEntityId(), [Item::FIELD_NAME_ASSIGNED])?->getAssignedById();
    }
    line('ответственный цели (от его имени идёт запуск)', $userId, (int)$userId > 0);

    if ((int)$userId > 0) {
        $accepted = AIManager::isAILicenceAccepted((int)$userId);
        line('соглашение AI_BOX_AGREEMENT принято этим юзером', $accepted, $accepted);
        if (!$accepted) {
            echo "         ! Операция отвалится на abstractoperation.php:246 с LICENSE_NOT_ACCEPTED.\n";
        }
    }

    // настройки именно этой воронки
    $settings = FillFieldsSettings::get($target->getEntityTypeId(), $target->getCategoryId());
    $shouldFillFields = $settings->shouldAutostart(TranscribeCallRecording::TYPE_ID, $direction);
    line('настройки воронки разрешают автозапуск Transcribe', $shouldFillFields, $shouldFillFields);

    // речевая аналитика
    $assessmentItem = ItemFactory::getByActivityId($id);
    $assessmentCheck = CallAssessmentItemChecker::getInstance()->setItem($assessmentItem)->run();
    line(
        'скрипт речевой аналитики подобран',
        $assessmentItem ? ('#' . $assessmentItem->getId() . ' ' . $assessmentItem->getTitle()) : 'нет',
        $assessmentItem !== null
    );
    if ($assessmentItem && !$assessmentCheck->isSuccess()) {
        line('  скрипт пригоден', implode('; ', $assessmentCheck->getErrorMessages()), false);
    }

    if (!$shouldFillFields && !$assessmentItem) {
        echo "         ! Ни настройки воронки, ни речевая аналитика не дали повода запускаться\n";
        echo "           (callautostartstrategy.php:43-49).\n";
        continue;
    }

    // пригодность аудио
    $storageTypeId = (int)($activity['STORAGE_TYPE_ID'] ?? 0);
    $storageElementIds = \CCrmActivity::extractStorageElementIds($activity) ?: [];
    $audioResult = (new SuitableAudiosChecker(
        $originId,
        $storageTypeId,
        serialize(array_map('intval', $storageElementIds))
    ))->run();
    line(
        'запись пригодна для распознавания',
        $audioResult->isSuccess() ? 'да' : implode('; ', $audioResult->getErrorMessages()),
        $audioResult->isSuccess()
    );
    if (!$audioResult->isSuccess()) {
        echo "         ! Пороги: размер 60 КБ..25 МБ, длительность 10 сек..60 мин,\n";
        echo "           расширение из Timeline\\Config::ALLOWED_AUDIO_EXTENSIONS\n";
        echo "           (suitableaudioschecker.php:17-20).\n";
    }

    // уже запущенные задания
    $jobExists = JobRepository::getInstance()->isJobOfSameTypeAlreadyExistsForTarget(
        new ItemIdentifier(\CCrmOwnerType::Activity, $id),
        TranscribeCallRecording::TYPE_ID
    );
    line('задание Transcribe уже заводилось для дела', $jobExists);

    $transcribeResult = JobRepository::getInstance()->getTranscribeCallRecordingResultByActivity($id);
    line(
        'результат распознавания в базе',
        $transcribeResult ? 'есть' : 'нет'
    );
}

head('ГОТОВО');
echo "Если всё выше зелёное, а распознавания нет — включи подробный лог ИИ:\n";
echo "в www/bitrix/.settings_extra.php добавь\n\n";
echo "  'loggers' => ['value' => ['crm.Integration.AI' => [\n";
echo "      'className' => '\\\\Bitrix\\\\Main\\\\Diag\\\\FileLogger',\n";
echo "      'constructorParams' => ['/home/bitrix/www/local/log/crm-ai.log'],\n";
echo "      'level' => \\Psr\\Log\\LogLevel::DEBUG,\n";
echo "  ]], 'readonly' => false],\n\n";
echo "и сделай тестовый звонок — каждая проверка напишет причину отказа.\n";

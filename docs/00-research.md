# Исследование: ИИ в коробочном Битрикс24

Всё проверено по исходникам коробки. Ссылки вида `файл:строка` — относительно
`www/bitrix/modules/`. Стенд: Битрикс24 коробка, main 26.700, PHP 8.1,
**модуля `bitrix24` нет**, зона лицензии не `ru`.

> Этот файл — фундамент модуля. Прежде чем менять архитектуру `shef.toolsai`,
> прочитай его целиком: почти каждое решение в модуле продиктовано конкретной
> строкой ядра, и «упростить» обычно означает «сломать».

---

## 1. Как запускается распознавание звонка

Агента и крона нет. Запуск **синхронный, из события дела-звонка**:

```
telephony.externalcall.register      -> дело VOXIMPLANT_CALL, ORIGIN_ID = VI_<callId>
telephony.externalCall.attachRecord  -> CCrmActivity::Update   vi_crm_helper.php:874
        v
Call::onAfterAdd / onAfterUpdate               crm/lib/activity/provider/call.php:425,447
        v
EventHandler::onAfterCallActivityAdd           crm/lib/integration/ai/eventhandler.php:371
        v
AutoLauncher::isEnabled()                      .../operation/autostart/autolauncher.php:35
        v
CallAutoStartStrategy::run()                   .../autolauncher/callautostartstrategy.php:28
        v
AIManager::launchCallRecordingTranscription()  crm/lib/integration/ai/aimanager.php:230
        v
TranscribeCallRecording::launch()  ->  Engine::completions()
```

Провайдерские хуки вызываются из `CCrmActivity`:
`onAfterAdd` — `crm/classes/general/crm_activity.php:446`,
`onAfterUpdate` — там же, `:1190`.

**Следствие, которое ломает интуицию:** если запись прилетает позже регистрации
звонка (обычный случай для внешней АТС), автозапуск случается не при создании
дела, а в момент `attachRecord` — через `onAfterUpdate`. Нет записи — нет
запуска никогда.

---

## 2. Гейты, которые молча роняют автозапуск

Каждый из них — тихий `return`. Ни исключения, ни сообщения в интерфейсе.

| # | Гейт | Где | Чем ломает |
|---|---|---|---|
| 1 | `BaasManager::hasPackage()` | `autolauncher.php:38` | без пакетов BaaS автозапуска нет совсем |
| 2 | Цель только Deal/Lead | `crm/lib/Copilot/Pipeline/TargetResolver.php:18-21` | звонок к контакту/компании/заказу не обрабатывается |
| 3 | Зона лицензии ≠ `ru` | `.../fillfieldssettings/callchannelsettings.php:112-140` | в дефолтном наборе **нет** `TranscribeCallRecording` |
| 4 | Настройки по воронке | `fillfieldssettings.php:183-198` | опция `crm::ai_autostart_settings_<typeId>[_<catId>]`, своя на каждую воронку |
| 5 | Направление звонка | `callchannelsettings.php:17,86` | по умолчанию только входящие |
| 6 | Пороги аудио | `suitableaudioschecker.php:17-20` | 60 КБ…25 МБ, 10 сек…60 мин, расширение из белого списка |
| 7 | Только телефония | `operation/transcribecallrecording.php:66-81` | нужен `PROVIDER_ID = VOXIMPLANT_CALL` **и** `ORIGIN_ID` с префиксом `VI_` |
| 8 | Соглашение на коробке | `abstractoperation.php:246` | `AI_BOX_AGREEMENT` принят **ответственным за цель**, не администратором |
| 9 | Движок категории | `abstractoperation.php:332-354` | нет движка `audio` — `critical` в лог и выход |
| 10 | Движок выбран по коду | `abstractoperation.php:684-697` | берётся строго код из настройки ИИ, `Engine::getByCode` без фолбэка |
| 11 | audio требует text | `ai/lib/Engine/ThirdParty.php:297-310` | третьесторонний audio-движок невидим, пока нет ни одного text-движка |
| 12 | Права ответственного | `transcribecallrecording.php:56-64` | ответственный за цель должен иметь право **изменять** дело звонка |
| 13 | Одно задание на дело | `callautostartstrategy.php:86-89`, `abstractoperation.php:101-130` | любое прежнее задание Transcribe (даже с ошибкой) глушит автозапуск при обновлении дела; ретрай — ровно один |

### Детали, стоившие больше всего времени

**Гейт 2.** `TargetResolver` содержит жёсткий белый список:

```php
private const ENTITY_TYPE_WHITELIST = [
    CCrmOwnerType::Deal => CCrmOwnerType::Deal,
    CCrmOwnerType::Lead => CCrmOwnerType::Lead,
];
```

Ни контакта, ни компании, ни заказа. Причём `findAssigned()` берёт
**ответственного за найденную цель**, а не автора звонка
(`basechannelautostartstrategy.php:49-52,69-79`) — именно у него потом
проверяется соглашение из гейта 8.

**Гейт 3.** `CallChannelSettings::getDefault()` разветвляется по зоне:

```php
if (self::isRuZone()) {
    return new self([Transcribe, Summarize, FillFields, AnalyzeCommunication], true, [Incoming]);
}
return new self([Summarize, FillFields, AnalyzeCommunication], false, [Incoming]);
```

Вне `ru` **`TranscribeCallRecording` отсутствует**, а вся цепочка стартует
именно с него (`callautostartstrategy.php:43-49`). То есть на белорусской или
казахстанской коробке «из коробки» автораспознавания нет вообще, пока настройки
воронки не сохранены руками.

`isRuZone()` — `basechannelsettings.php:37-46`, читает
`Application::getInstance()->getLicense()->getRegion()`.

**Гейт 6.** Пороги переопределяются опциями модуля `crm`:
`ai_integration_audiofile_min_size`, `ai_integration_audiofile_max_size`,
`ai_integration_audio_min_call_time`, `ai_integration_audio_max_call_time`.
Длительность берётся из статистики телефонии и проверяется только если
`ORIGIN_ID` начинается с `VI_` (`suitableaudioschecker.php:136-171`).

### Почему ничего не видно в логах

`AIManager::logger()` -> `Container::getLogger('Integration.AI')` ->
`LoggerFactory::create()` (`crm/lib/Service/Logger/LoggerFactory.php:22-24`):
если логгер не описан в конфиге — возвращается `NullLogger`. Ядро аккуратно
пишет причину каждого отказа в никуда.

Включение — см. [04-runbook.md](04-runbook.md), шаг 1 «Включить лог».

---

## 3. Цена в запросах

**Стоимость любого запроса = 1.** `ai/lib/Payload/Payload.php:15`:
`protected const DEFAULT_USAGE_COST = 1;`. Транскрибация часового звонка стоит
столько же, сколько строка текста.

Обнуление стоимости (`isSponsoredOperation`) обёрнуто в
`Loader::includeModule('bitrix24')` — `abstractoperation.php:425`. **На коробке
бесплатных операций нет.**

Сценарий — цепочка, каждый шаг отдельный запрос
(`crm/lib/Copilot/Pipeline/Scenario/*.php`):

| Сценарий | Шаги | Запросов |
|---|---|---|
| `SUMMARIZE` | Transcribe -> Summarize | 2 |
| `CALL_SCORING` | Transcribe -> ScoreCall | 2 |
| `ANALYZE_COMMUNICATION` | Transcribe -> AnalyzeCommunication | 2 |
| `FILL_FIELDS` | Transcribe -> Summarize -> FillFields | 3 |
| `FULL` | все пять | **5** |

`FULL` включается автоматически, как только разрешено больше одного сценария
(`callautostartstrategy.php:241-251`). «Включить всё» = ×5 к расходу на звонок.

---

## 4. Лимиты: три уровня

### Уровень 1 — промо-лимит, локальный

Таблица `b_ai_usage`, классы `ai/lib/Limiter/Period/{Daily,Monthly}.php`.

| | Daily | Monthly |
|---|---|---|
| Считает | **по пользователю** (`Daily.php:48`) | **суммой по всему порталу** (`Monthly.php:44-49`) |
| Потолок без Маркета | 5 (`Daily.php:14`) | `Plan::createByB24()->getMaxUsage()` |
| На коробке без `bitrix24` | `isFreeLicense()` = true -> 5 | `QueryPackage::DEFAULT_MAX_USAGE` = 6000 |

**Но на коробке он выключен.** `ai/default_option.php:8`:

```php
'check_limits' => ModuleManager::isModuleInstalled('bitrix24') ? 'Y' : 'N',
```

Нет модуля `bitrix24` -> `check_limits = 'N'` -> `Usage::isInLimit()` выходит
на первой строке (`Usage.php:63-66`). Пятёрки в сутки не существует.

Переключается в `/bitrix/admin/settings.php?mid=ai` (`ai/options.php:47`).

### Уровень 2 — пакеты BaaS

`b_baas_services`, услуга с кодом `ai_copilot_token`
(`ai/lib/Integration/Baas/BaasTokenService.php:13`).

**Единственное место во всей системе, где есть число остатка** —
`baas/lib/Entity/Service.php:227` -> `getValue()`.

Достать можно так:

```php
\Bitrix\Baas\Baas::getInstance()
    ->getService('ai_copilot_token')
    ->getValue();
```

или внутренним AJAX-контроллером (`baas/lib/Controller/Service.php:17`):

```
/bitrix/services/main/ajax.php?action=baas.Service.get&code=ai_copilot_token
-> {"service": {"value": 1234, "isActive": true, ...}}
```

Через REST-хук — **никак**: у модулей `ai` и `baas` нет REST-методов чтения
лимитов (`ai/lib/Rest.php:19-25` — только `engine.*`, `prompt.*`, `history.*`).

Страница: `/bitrix/admin/baas_marketplace.php`. Показывает таблицу пакетов
только при `isAvailable()` и `isRegistered()`, иначе выводит предупреждение
вместо неё (`baas/admin/baas_marketplace.php:25-41`). Отсюда частое
«страницы вроде нет».

### Уровень 3 — удалённая проверка

На коробке `LimitControlBoxService::isAllowedQuery()`
(`ai/lib/Limiter/LimitControlBoxService.php:21`) ходит в облачный AI-прокси и
получает **только** два поля:

```php
new ReserveBoxRequest($count, (bool)$data['baasAvailable'], (string)$data['errorLimitType']);
```

Остатка нет. Принципиально.

### Формат ошибки при упоре

`Engine::throwError()` (`ai/lib/Engine.php:983-1037`) отдаёт
`Bitrix\Main\Error` с кодом:

| Код | Значение |
|---|---|
| `LIMIT_IS_EXCEEDED_BAAS` | пакеты кончились |
| `LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT` | частотное ограничение |
| `LIMIT_IS_EXCEEDED_DAILY` | суточный промо |
| `LIMIT_IS_EXCEEDED_MONTHLY` | месячный промо |

В `customData` — `sliderCode`, `limitCode`, `msgForIm`. **Числа остатка нет
ни в одном ответе:** это флаг «упёрлись», а не счётчик.

### Для своего движка не работает НИЧЕГО из перечисленного

`ai/lib/Engine/ThirdParty.php:289-292`:

```php
public function checkLimits(): bool
{
    return $this->item->getCode() === 'itsolutionru.gptconnector';
}
```

Лимитер включается **только** для одного конкретного приложения Маркета.
Для любого другого кода -> `false` -> в `Engine::completions()`
(`Engine.php:852-870`) весь блок лимитера пропускается: ни `reserveRequest`,
ни `commitRequest`, ни записи в `b_ai_usage`.

**Вывод, на котором стоит весь модуль:** со своим движком у Битрикса не
останется ни одного счётчика. Учёт расхода — наш, и это не костыль, а
единственный корректный вариант. См. `lib/quota/` и страницу «ИИ: расход и остаток».

---

## 5. Регистрация своего движка

### REST-путь закрыт

`ai/lib/Rest.php:28`:

```php
if (self::isAllowedRegisterEngine()) {
    $actions['ai.engine.register'] = [ThirdParty\Manager::class, 'register'];
}
// :42-45
private static function isAllowedRegisterEngine(): bool { return Bitrix24::shouldUseB24(); }
```

`ai/lib/Facade/Bitrix24.php:28-33`:

```php
public static function shouldUseB24(): bool
{
    if (!ModuleManager::isModuleInstalled('bitrix24')) { return false; }
    ...
}
```

Модуля `bitrix24` на коробке нет -> метода `ai.engine.register` **не
существует** в списке REST-методов. Плюс он живёт в scope `ai_admin`
(`Rest.php:9,34`).

### PHP-путь открыт

`Engine::loadThirdParty()` (`ai/lib/Engine.php:130-148`) читает таблицу
`b_ai_engine` **без единой проверки** на `bitrix24`:

```php
foreach (ThirdParty\Manager::getCollection() as $item) {
    self::$engines[$item->getCategory()][] = ['engine' => Engine\ThirdParty::class, 'data' => $item];
}
```

Поэтому регистрируем через публичный `\Bitrix\AI\ThirdParty\Manager::register()`
(`ai/lib/ThirdParty/Manager.php:26`) — тот самый метод, который дёргал бы REST.
Оба параметра `$service` и `$server` объявлены как `mixed ... = null`, так что
вызывается он и без REST-сервера:

```php
\Bitrix\AI\ThirdParty\Manager::register([
    'name' => '…', 'code' => '…', 'category' => 'audio', 'completions_url' => '…',
]);
```

При `$server = null` -> `Rest::getApplicationCode(null)` -> `AppTable::getByClientId(null)`
-> нет строки -> `app_code = null`. Это нормально: мы не приложение Маркета.

**Почему именно `Manager::register`, а не `ThirdPartyRegisterService` напрямую:**
`Manager` дополнительно сбрасывает кеш (`Manager.php:43`), а ключ кеша
`ai.thirdparty01` объявлен приватной константой (`Manager.php:14`) — снаружи
его не прочитать, только захардкодить. Захардкоженный ключ поедет при
ближайшем обновлении (суффикс `01` Битрикс наращивает при смене формата).

### Валидация при регистрации

`ai/lib/ThirdParty/Service/ThirdPartyRegisterService.php:66-205`:

| Проверка | Требование |
|---|---|
| `validateFieldsInput` | `name`, `code`, `category`, `completions_url` — непустые строки |
| `validateCodeFormat` | `code` только `[A-Za-z0-9-_]` |
| `validateCategory` | из `text\|image\|audio\|call\|vision\|classify` (`Engine::CATEGORIES`, `Engine.php:38-45`) |
| `validateUniqueCode` | пара категория+код ещё не занята |
| `validateCompletionsUrl` | **делается GET на URL, ожидается ровно 200** |
| `validateSettings` | если передан — массив |

Последнее важно: эндпоинт обязан отвечать 200 на **GET**, а не только на POST,
иначе регистрация упадёт с `ENGINE_REGISTER_ERROR_COMPLETIONS_URL_FAIL`.

---

## 6. Контракт completions

Подробно — [01-engine-contract.md](01-engine-contract.md). Коротко:

Битрикс POST'ит JSON на `completions_url` (`ai/lib/Engine/ThirdParty.php:226-248`),
ждёт **202 = «принял»** (не 200: сравнение строгое, `ThirdParty.php:23,254`),
результат забирает асинхронно колбэком. На GET при регистрации движка — ровно 200.

Разбор успеха — `ThirdParty::getResultFromRaw()` (`ThirdParty.php:159-179`):
берётся `result[0]`, для категории `image` — весь массив.

Колбэки принимает `\Bitrix\AI\Controller\Integration\Thirdparty`
(`ai/lib/controller/integration/thirdparty.php`), пути зашиты в
`QueueJob.php:39,42`:

```
…/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackSuccess&hash={hash}
…/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackError&hash={hash}
```

Хост колбэка берётся из `Config::getValue('public_url')`, а если пусто — из
`UrlManager::getHostUrl()` (`QueueJob.php:336-347`). **За прокси легко получить
внутренний адрес, и колбэк не дойдёт.**

Контроллер без префильтров (`configureActions`, `getDefaultPreFilters` пустые),
но требует, чтобы хотя бы один движок был зарегистрирован
(`processBeforeAction` -> `Manager::hasEngines()`).

---

## 7. Анализа сделки в коробке нет

### `AnalyzeCommunication` — это не оценка риска

`crm/lib/integration/ai/dto/analyzecommunicationpayload.php`:

```php
public array $actions = [];              // до 5 дел-напоминаний
public bool $isClient = false;
public ?string $reasonIfIsClientFalse = null;
```

Генератор задач из разговора. Ни балла, ни флага эскалации.

### Узел автоматизации «AI-обработка» недоступен

`ai/install/activities/bitrix/aiprocessingactivity/` — готовый узел роботов с
параметрами `prompt`, `returnType` (`text`/`json`), `jsonSchema`, `jsonPath`,
`usePseudonymizer`; возвращает `aiResult` и `errorMessage`
(`aiprocessingactivity.php:24-36`).

Но `.description.php:36`:

```php
->setExcluded(!Loader::includeModule('ai') || !$isAiNodeAvailable)
```

а `$isAiNodeAvailable` -> `NodeAvailabilityService::isAvailable()`
(`bizproc/lib/Public/Service/AiAgent/NodeAvailabilityService.php:13,36`) требует
модуль **`aiassistant`**, которого на коробке нет. Узел не появится в
конструкторе, а при запуске выйдет на `execute()` строка 105-110.

Регион не мешает: заблокирована только `cn`
(`bizproc/lib/Public/Service/AiAgent/RegionAvailabilityService.php:13`).

Поэтому анализ сделки делаем сами — см. [02-deal-health.md](02-deal-health.md) и `lib/deal/`.

---

## 8. Сводка: что именно мы обходим и чем

| Препятствие | Обход в модуле |
|---|---|
| `hasPackage()` блокирует автозапуск | `BaasManager::setIgnored(true)` — штатный метод (`baasmanager.php:104-121`) |
| Нет движка `audio`/`text` | свой third-party движок через `Manager::register()` |
| `ai.engine.register` недоступен по REST | регистрируем из PHP: кнопка «Проверить и включить» (`\Shef\ToolsAi\Main\Setup`) |
| audio-движок виден только при text-движке (гейт 11) | `audio` и `text` регистрируются одним прогоном; отчёт не считает audio готовым без text |
| CRM берёт движок строго по коду из настройки ИИ (гейт 10) | «Проверить и включить» выбор показывает; записать — ссылка «Выбрать движок модуля» на странице настроек модуля |
| Ядро шлёт запрос движку без своих заголовков | секрет — токеном в `completions_url`, колбэк — только на хост портала |
| Битрикс не считает расход своего движка | своя таблица `shef_toolsai_usage` + страница остатка |
| Нет анализа сделки | свой `\Shef\ToolsAi\Deal\HealthAnalyzer` поверх LLM-провайдера |
| Тихие отказы автозапуска | `cli/ai-call-autostart-diag.php` + включённый лог |

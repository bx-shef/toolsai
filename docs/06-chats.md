# Чаты открытых линий

> Последняя сверка: 2026-10-03 (crm 26.800, ai 26.1000, im 26.900,
> imopenlines 26.600 — дерево боевого портала)

## Что и зачем

Менеджеры отвечают клиентам не только по телефону, но и в чатах открытых
линий (сайт, Telegram, WhatsApp и т. п.). С 1.6.0 модуль работает и с ними:

1. **Штатный Копилот CRM для чатов** — резюме, заполнение полей и дела после
   разговора. Это делает сама CRM; модуль подключается как движок текста
   (как для звонков), а со «Своими промптами» объясняет модели, что перед
   ней переписка: где менеджер, где клиент, а где автоответ бота.
2. **Своя оценка переписки по скрипту.** Для чатов в CRM её нет — оценивает
   модуль: раз в час берёт закрытые диалоги, оценивает по скрипту речевой
   аналитики и пишет итог комментарием в сделку или лид. Цифры — на
   странице «ИИ: статистика».

Как включить на портале — [04-runbook.md](04-runbook.md), шаг 7б.

## 1. Копилот CRM для чатов — как устроено в ядре

### Какие операции и коды

Чат открытой линии — дело с `PROVIDER_ID = IMOPENLINES_SESSION`
(`Activity\Provider\OpenLine`). Для него CRM умеет три операции из четырёх
(`Scenario::isManualFullScenarioAvailable()`,
`crm/lib/integration/ai/operation/scenario.php:71-103`):

| Операция | `TYPE_ID` | Код промпта (`payload_raw`) | Маркеры | Движок в настройках ИИ |
|---|---|---|---|---|
| резюме | 2 | `summarize_transcript` | `original_message` — текст переписки, `company_name`, `manager_name` | `crm_copilot_fill_item_from_call_engine_text` |
| заполнение полей | 3 | `extract_form_fields` | `original_message` — **резюме**, `fields`, `enum_fields_values`, `current_*` | тот же |
| дела после разговора | 9 | `client_dialogue_action_extraction` | `dialogue` — текст переписки, `employee_name`, `dialogue_start_datetime` | `crm_copilot_analyze_communication_engine_code` |

Оценки звонка (`call_scoring`) для чатов нет: в списке настроек для
`OpenLine` нет `CallAssessment`, а подбор скрипта
(`Copilot\CallAssessment\ItemFactory::getByActivityId()`) на любое дело не
звонок отвечает `null` (`ItemFactory.php:26`).

Коды и маркеры **те же, что у звонка** — `SummarizeCallTranscription` и
`AnalyzeCommunication` для чата получают вместо расшифровки текст переписки
(`crm/lib/Copilot/Pipeline/StepFactory.php:112-158`, `:246-290`, ветка
`$isOpenLine`). Отдельного маркера «это чат» в запросе к движку нет: контекст
операции (`target` — дело) остаётся у ядра и в тело запроса не попадает
(`ai/lib/Engine/ThirdParty.php:226-248`).

### В каком виде приходит переписка

`OpenLine::getMessagesForCopilot()` (`crm/lib/activity/provider/openline.php:335-424`):

* до 100 **последних** сообщений **всего чата** (`OpenLineManager::
  getMessageData()` → `Im\Chat::getMessages()`), а не только этого диалога;
  сообщения с `author_id = 0` (системные) отброшены;
* реплика — `«Имя [дата]:» + перевод строки + текст`, дата —
  `date('c')`, например `2026-10-03T12:01:00+03:00`;
* реплики склеены пробелом, BB-коды сняты, **все пробелы и переводы строк
  схлопнуты в один пробел** (`normalizeMessagesForCopilot()`);
* нет данных чата — запасной путь: тексты сообщений сессии без имён и дат
  (`getSessionMessagesForCopilot()`).

**Кто клиент, ядро не помечает.** Подписан только именем. Хуже: автоответы
открытой линии при закрытии диалога («Диалог закрыт», «Оцените работу
оператора») отправляются **от имени оператора** — `FROM_USER_ID =
OPERATOR_ID`, `SYSTEM = Y` (`imopenlines/lib/session.php:1279`, `:1352`), и
в тексте для Копилота выглядят как слова менеджера. Скрытые сообщения
оператора (тихий режим) тоже попадают в текст.

### Как модуль отличает чат от звонка

`CopilotPrompt::isChat()`: в тексте хотя бы две подписи вида
`[ГГГГ-ММ-ДДTчч:мм:сс+пояс]:`. В расшифровке звонка такой подписи нет.
Узнали чат — свои промпты пишут про переписку и добавляют правила «как
читать переписку»: менеджер — сотрудник из справки (`manager_name` /
`employee_name`), клиент — собеседник, сообщения бота и автоответы — не слова
менеджера, даже если подписаны его именем; вложения — не додумывать.
Не узнали (запасной путь без дат, звонок) — формулировка нейтральная:
«расшифровку звонка или переписку в чате». Заполнение полей получает резюме,
а не переписку, — там всегда нейтрально.

Формат даты в подписи (`date('c')`) сверен по коду `im/lib/chat.php:603-610`;
что подпись переживает `TextHelper::cleanTextByType()` и `cleanTag()` —
**проверяется на стенде**.

### Когда CRM запускает Копилот для чата

**Автоматически** — когда дело чата завершается (диалог закрыт):
`OpenLine::onAfterUpdate()` → `EventHandler::onAfterOpenLineActivityComplete()`
(`openline.php:239-253`, `eventhandler.php:453-459`) → `AutoLauncher` →
`ChatAutoStartStrategy`. Условия по порядку:

1. `AutoLauncher::isEnabled()` (`autostart/autolauncher.php:35-46`):
   * Копилот CRM доступен (`AIManager::isAiCallProcessingEnabled()`: модуль
     `ai`, регион не `ua`/`cn`; фича `crm_copilot` на коробке без модуля
     `bitrix24` всегда есть);
   * **автоматическая обработка разрешена** — опция
     `crm::AI_CALL_PROCESSING_ALLOWED_AUTO_V2`, по умолчанию =
     `BaasManager::isAvailable()`;
   * **пакет BaaS** — `BaasManager::hasPackage()`; на коробке без пакетов —
     только с обходом `crm::AI_IGNORE_BAAS` (его включает «Проверить и
     включить» модуля);
   * в настройках ИИ включён хотя бы один из сценариев Копилота в CRM.
2. Сделка или лид у дела есть (`TargetResolver`), у неё есть ответственный.
3. Настройки автозапуска воронки (`crm::ai_autostart_settings_<тип>_<воронка>`,
   канал `chat`, `ChatChannelSettings`): список операций и «только первый
   чат». По умолчанию в зоне `ru` — резюме, поля и дела, **только первый чат
   с клиентом** (`chatchannelsettings.php:75-95`); вне `ru` — ничего.
4. Операция включена в настройках ИИ портала
   (`ChatAutoStartStrategy::resolveLaunchScenarioByOperationTypes()`): поля —
   только при включённом резюме.
5. Текста достаточно: **не меньше 1000 символов** переписки
   (`OpenLine::CHAT_MESSAGE_COPILOT_PROCESSING_LIMIT`) сверх объёма, уже
   обработанного прошлым запуском (`SETTINGS.LAST_MESSAGES_VOLUME` дела,
   `isCopilotProcessingAvailable()`, `openline.php:426-449`). Короткий чат
   («здравствуйте — спасибо») Копилот не обрабатывает вовсе.

**Вручную** — пункты меню Копилота в деле чата в таймлайне (`*InChat`,
`crm/lib/Service/Timeline/Item/Activity/AI/Action/Type/`): тот же порог
1000 символов, повторный запуск — когда переписки прибавилось ещё на 1000.

### Настройки портала

| Где | Что | Код |
|---|---|---|
| Настройки ИИ (`/configs/?page=ai`), «CoPilot в CRM» | «…для резюме разговора» | `crm_copilot_summarize_enabled` |
| там же | «…для заполнения полей в CRM» + «модель для текста» | `crm_copilot_fill_item_from_call_enabled`, `crm_copilot_fill_item_from_call_engine_text` |
| там же | «…для автоматических дел и антиспама» + модель | `crm_copilot_analyze_communication_enabled`, `crm_copilot_analyze_communication_engine_code` |
| сделка/лид → кнопка настроек → «Копилот в CRM» → «Автоматическая расшифровка в чатах» | «Только первый чат с клиентом» / «Все чаты с клиентом» / «Отключить» | `crm::ai_autostart_settings_<тип>_<воронка>`, канал `chat` |
| опции `crm` | автообработка и обход BaaS | `AI_CALL_PROCESSING_ALLOWED_AUTO_V2`, `AI_IGNORE_BAAS` |

Названия пунктов — `crm/lang/ru/lib/integration/ai/eventhandler.php` и
`crm/install/js/crm/settings-button-extender/lang/ru/config.php`.

## 2. Своя оценка переписки по скрипту

### Путь

`Agent\ChatAssessmentAgent` — раз в `DEAL_interval` минут (60), под своей
блокировкой (`Constants::LOCK_GROUP_CHAT_ASSESSMENT`), от служебного
пользователя, только при `CHAT_enabled = Y`. Ручной прогон —
`cli/chat-assessment.php` (`ACTIVITIES=101,102` — только эти дела).

1. **Кандидаты** — `Chat\DialogSource::getCandidates()`: дела
   `IMOPENLINES_SESSION`, сессия (`b_imopenlines_session`, `ID =
   ASSOCIATED_ENTITY_ID` дела) закрыта (`CLOSED = Y`) за последние
   `CHAT_days` дней, не спам, дело привязано к сделке или лиду, строки в
   `shef_toolsai_chat_assessment` нет. Сначала давние — окно сдвигается.
   Берём `CHAT_maxperrun × 3`: часть уйдёт в пропуск без модели.
2. **Сделка или лид** — `DialogSource::pickOwner()`: сделка важнее лида, из
   нескольких — новее.
3. **Текст** — `DialogSource::getMessages()` + `Chat\Transcript::build()`.
   Сообщения **этого диалога**: `b_im_message` чата сессии от `START_ID` до
   `END_ID`, как у CRM (`OpenLineManager::getSessionMessages()`), без
   системных (`AUTHOR_ID = 0`, `NOTIFY_EVENT = private_system`). Готовый метод
   CRM не берём: он соединяет параметры `FILE_ID` и `ATTACH` через
   `LEFT JOIN`, и сообщение с двумя файлами приходит дважды, а дат и признака
   автоответа в нём нет. Кто есть кто:

   | роль | как узнаём |
   |---|---|
   | клиент | `b_user.EXTERNAL_AUTH_ID = imconnector` (`Im\User::isConnector()`) |
   | бот | `EXTERNAL_AUTH_ID = bot` (`Im\Bot::EXTERNAL_AUTH_ID`) |
   | автоответ | параметр сообщения `CLASS` содержит `bx-messenger-content-item-ol-` (output, end, start, attention) — даже если автор оператор |
   | скрытое | `CLASS` содержит `bx-messenger-content-item-system` (тихий режим оператора, служебное коннектора) — клиент не видел, в текст не идёт |
   | менеджер | остальные |

   Строка — «[03.10 12:02] Менеджер (Иван Петров): текст [файлов: 2]». Имя
   клиента модели не уходит. BB-коды и HTML сняты (`Transcript::cleanText()`).
   Длиннее 30 000 символов — начало и конец, середина пропущена с отметкой.
   Нет реплики менеджера или клиента — пропуск без модели.
4. **Скрипт** — `DialogSource::pickScript()`. Своего подбора для чатов у CRM
   нет, поэтому:
   * задан «Скрипт для чатов» (`CHAT_script`, ID в
     `b_crm_copilot_call_assessment`) — он, **даже выключенный**: скрипт для
     чатов можно держать выключенным, чтобы CRM не подобрала его звонкам
     (её подбор берёт только `IS_ENABLED = Y`);
   * иначе — как CRM подбирает звонку (`ItemFactory::
     getAssessmentByClientAndCallType()`): тип клиента по делу
     (`AssessmentClientTypeResolver::resolveByActivityId()`), `CALL_TYPE` —
     «все» или по направлению дела, включённый, доступный сейчас по
     расписанию, самый свежий по `UPDATED_AT`.

   Критерии — «суть» скрипта (`GIST`, через перевод строки), её выделяет CRM
   операцией `ExtractScoringCriteria` при сохранении скрипта. Сути нет —
   пропуск без модели.
5. **Модель** — `Chat\ChatScorer`: текстовая точка доступа модуля
   (`Container::getLlm('text')`), промпт своей оценки звонка в режиме
   переписки (`CopilotPrompt::scoringMessages($markers, true)`), плоский JSON
   через `Llm::completeJsonObject()` с потолком 8192 токена, приведение —
   `CopilotPrompt::normalizeScoring()`. Ни одного критерия —
   `provider_bad_response` с расходом. Провайдер текста — заглушка: ответ без
   модели, все пункты «выполнены» с пометкой `[заглушка]`.
6. **Расход** — журнал `shef_toolsai_usage`, категория `chat`, код
   `sheftoolsai_chat`: запись «в работе» с оценкой цены **до** запроса,
   итог — после (как у Копилота). Квота исчерпана — прогон стоп.
7. **Итог** — комментарий в таймлайн сделки или лида
   (`Timeline\CommentEntry::create()`, текст — `Chat\ChatScore::formatComment()`):

   ```
   Оценка переписки по скрипту «Продажа запчастей»: 67%.
   Не выполнено: Назвал цену.
   Итог: …
   Что сделать иначе: …
   ```

   Процент — как у звонка в CRM (`ScoreCall::getAssessmentsValue()`):
   выполненные делить на оценённые, `null` не в счёт; ни одного оценённого —
   «не оценена».

### Таблица `shef_toolsai_chat_assessment`

Строка на дело (`ACTIVITY_ID` уникален): чат оценивается один раз.

| поле | что |
|---|---|
| `ACTIVITY_ID`, `SESSION_ID`, `CHAT_ID` | дело, сессия и чат открытой линии |
| `OWNER_TYPE_ID`, `OWNER_ID` | сделка (2) или лид (1), куда ушёл комментарий |
| `ASSESSMENT_ID` | ID скрипта |
| `STATUS` | `DONE` — оценён; `SKIPPED` — без модели (нет реплик, скрипта, критериев, сделки); `ERROR` — модель ответила негодно (оплачено) |
| `SCORE` | процент; `null` — ни один пункт не оценён |
| `CRITERIA` | `{"criteria": [{criterion, status, explanation}]}` — читает `Stats\ScoreResult` |
| `SUMMARY`, `REASON` | итог модели; причина пропуска или ошибки |
| `RESPONSIBLE_ID`, `CREATED_AT` | ответственный дела, когда оценено |

Создаётся и дописывается идемпотентно (`ChatAssessmentTable::init()`):
установщик, «Проверить и включить», агент перед прогоном. Уходит вместе с
модулем без `savedata = Y`. Сбой провайдера (нет связи, лимит, ключ) строку
не пишет — чат снова кандидат; три таких сбоя подряд — прогон стоп.

### Настройки (вкладка «Чаты»)

| код | что | умолчание |
|---|---|---|
| `CHAT_enabled` | оценивать чаты | `N` |
| `CHAT_maxperrun` | оценок моделью за прогон, 1–200 | 20 |
| `CHAT_days` | диалоги, закрытые за последние N дней, 1–60 | 3 |
| `CHAT_script` | ID скрипта для всех чатов; пусто — подбор как у звонка | — |

Вне границ или мусор — умолчание. Интервал агента — общий с анализом
сделок (`DEAL_interval`), применяется кнопкой «Проверить и включить».

### Страница статистики

Блоки «Оценки чатов по менеджерам» (оценено, пропущено, ошибок; по
`RESPONSIBLE_ID`: чатов, средняя и худшая оценка) и «Что не делают в чатах»
(`Stats\FailureCounter` по `CRITERIA`) — [05-stats.md](05-stats.md).

## Что не проверено

* Всё, что касается базы и ядра, — на заглушках: SQL выборки кандидатов и
  сообщений, `Im`-параметры `CLASS`/`FILE_ID`/`ATTACH`, подбор скрипта
  (`CopilotCallAssessmentController`, `AssessmentClientTypeResolver` на деле
  чата), `CommentEntry::create()` в лид. **Проверяется на стенде.**
* Подпись реплик в тексте Копилота (`isChat()`) — по коду, без живого
  запроса ядра.
* Скрипт с `CALL_TYPE` «входящие» подбирается к чату по `DIRECTION` дела —
  у чатов это обычно «входящее»; исходящие чаты (рассылка) получат скрипт
  исходящих звонков.

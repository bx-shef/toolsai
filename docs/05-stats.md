# Страница «ИИ: статистика»

## Что и зачем

Одна страница, где видно, что ИИ сделал за период: сколько звонков разобрано,
сколько сделок проверено, как оценены звонки менеджеров и какие пункты
скрипта они чаще всего пропускают.

Адрес — `/bitrix/admin/shef_toolsai_stats.php` (заглушка, пишет
`Main\PublicPage`), пункт «ИИ: статистика» в меню модуля. Только
администратор. Страница **только читает** базу.

Период — «с» и «по» включительно, по умолчанию с первого числа текущего
месяца по сегодня. GET-параметры `from`, `to` — строго `Y-m-d`; мусор или
несуществующий день — значение по умолчанию, «с» позже «по» — меняются
местами (`Stats\Period`).

## Блоки

| Блок | Откуда | Как считаем |
|---|---|---|
| Звонки | `b_crm_act`, `TYPE_ID = 2`, по `CREATED` | всего, входящих (`DIRECTION = 1`), исходящих (`= 2`), без записи (`STORAGE_ELEMENT_IDS` пусто) |
| Очередь ИИ CRM | `b_crm_ai_queue`, по `CREATED_TIME` | `EXECUTION_STATUS` SUCCESS/ERROR для `TYPE_ID` 1 расшифровка, 2 резюме, 3 поля, 4 оценка, 9 дела от ИИ |
| Анализ сделок | `shef_toolsai_deal_check`, по `CHECKED_AT` | проверено, разобрано моделью (`SKIPPED = 'N'`), пропущено; дел менеджерам — `MANAGER_TODO_AT` в периоде, старшему — `ESCALATED_AT` |
| По ответственному и по профилю | то же + `b_crm_deal.ASSIGNED_BY_ID`, `PROFILE_ID` | сделок, средний риск (только разобранные моделью), дел, к старшему |
| Оценки звонков | `b_crm_ai_quality_assessment`, по `CREATED_AT` | по `RATED_USER_ID`: звонков, средняя и худшая `ASSESSMENT` |
| Что не делают менеджеры | `b_crm_ai_queue` `TYPE_ID = 4` SUCCESS + `b_crm_act.RESPONSIBLE_ID` | топ-10 проваленных критериев по всем, топ-5 на менеджера |

Звонки «слишком короткие» не считаем: длительность есть только у своей
телефонии (`b_voximplant_statistic`), а звонки приходят и от сторонней —
цифра была бы нечестной.

У сделки в `shef_toolsai_deal_check` хранится **одна** строка — последняя
проверка. Сделка, проверенная в периоде и ещё раз после него, в периоде не
видна.

## RESULT оценки звонка

Как пишет ядро (`crm/lib/integration/ai/operation`):

1. `scorecall.php`, `extractPayloadFromAIResult()` — из ответа модели берёт
   `call_review.criteria`, `overall_summary`, `recommendations` и собирает
   `Dto\Scoring\ScoreCallPayload`;
2. `abstractoperation.php` — `$job->setResult(Json::encode($payload, 0))`;
   `Dto::jsonSerialize()` = `toArray()`, свойства без `null`.

В `RESULT` лежит:

```json
{"criteria":[{"criterion":"Назвал цену","status":false,"explanation":"…"}],
 "overallSummary":"…","recommendations":"…"}
```

`status` — `?bool`; ядро считает оценку только по критериям, где он `bool`
(`getAssessmentsValue()`). `Stats\ScoreResult::parseCriteria()` берёт
`criteria` из корня, нет — из `call_review` (сырой ответ модели, на случай
другой версии ядра), оставляет только критерии с названием и `bool`.
`Stats\FailureCounter` считает провалы (`status = false`): критерий в
звонке — один раз; доля — от оценённых звонков (всех или этого
менеджера).

Строку топа собирает `FailureCounter::formatRow()` склейкой: в `"$n×"` PHP
считает «×» частью имени переменной, и число пропадает.

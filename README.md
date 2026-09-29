# shef.toolsai — свой ИИ-движок для Копилота коробочного Битрикс24

Модуль для **коробки** Битрикс24: распознавание звонков и текстовые сценарии
Копилота без пакетов BaaS — на своём провайдере, с собственным учётом расхода
и месячной квотой. Плюс анализ сделок: «пора звать старшего».

Почему это вообще нужно — на коробке без модуля `bitrix24`:

* автозапуск распознавания заблокирован отсутствием пакетов BaaS;
* REST-метод `ai.engine.register` не существует — свой движок иначе как из
  PHP не зарегистрировать;
* для своего движка ядро **не считает расход вообще** — ни лимита, ни
  остатка, ни страницы;
* анализа риска сделки в коробке нет.

Разбор по строкам ядра — [docs/00-research.md](https://github.com/bx-shef/toolsai/blob/main/docs/00-research.md).

## Что делает

| | |
|---|---|
| **Движок** | регистрирует категории `audio` и `text` в `b_ai_engine`; эндпоинт отвечает ядру **202** и работает в фоне; результат — колбэком |
| **Провайдеры** | заглушка без денег (по умолчанию) и любое OpenAI-совместимое API: OpenAI, свой whisper-сервер, vLLM, LocalAI, Ollama, прокси |
| **Расход** | свой журнал: секунды аудио и токены, деньги в микро-единицах; месячная квота; страница «ИИ: расход и остаток» |
| **Анализ сделок** | агент: дешёвый фильтр без ИИ, вердикт по JSON Schema, комментарий в таймлайн и дело старшему |
| **Безопасность** | токен в адресе эндпоинта, колбэк только на портал, ключ провайдера не попадает в тексты ошибок |
| **Обновления** | «Проверить и включить» и `cli/core-api-guard.php` — после каждого обновления платформы |

## Требования

* коробка Битрикс24 с модулями `ai`, `crm`; главный модуль 22.600.300+;
* PHP 8.2+, UTF-8;
* [shef.options](https://github.com/bx-shef/options) 3.0.0+ и
  [shef.problems](https://github.com/bx-shef/problems) 2.0.0+.

## Ограничения 1.0

* **На живой коробке ещё не прогонялся** — приёмка по
  [docs/agent-docker-test.md](https://github.com/bx-shef/toolsai/blob/main/docs/agent-docker-test.md)
  до выпуска релиза.
* Схема таблиц — MySQL, как у коробки; PostgreSQL не проверялся.
* Токен эндпоинта — в адресе (ядро не шлёт своих заголовков), поэтому он
  оседает в access-логах веб-сервера: не логируйте query-строку для
  `/bitrix/tools/shef_toolsai_completions.php` и при утечке меняйте токен
  кнопкой «Сменить токен эндпоинта».
* Оценка расхода на распознавание до скачивания — час записи: при почти
  исчерпанной квоте отказ получит и короткий звонок.
* Модуль опирается на недокументированное поведение ядра: после каждого
  обновления Битрикса — «Проверить и включить» и `cli/core-api-guard.php`.

## Установка

Архивом: распаковать `shef.toolsai.zip` со [страницы релизов](https://github.com/bx-shef/toolsai/releases)
в `bitrix/modules/`, поставить в **Настройки → Модули**. Или Composer:

```bash
composer require bxshef/toolsai
```

Дальше — внешний адрес портала в настройках модуля и «Проверить и включить»
на странице **Сервисы → ИИ: расход и остаток**. По шагам —
[docs/04-runbook.md](https://github.com/bx-shef/toolsai/blob/main/docs/04-runbook.md).

## Документация

| | |
|---|---|
| [00-research.md](https://github.com/bx-shef/toolsai/blob/main/docs/00-research.md) | исследование коробки по строкам ядра — фундамент модуля |
| [01-engine-contract.md](https://github.com/bx-shef/toolsai/blob/main/docs/01-engine-contract.md) | контракт движка: что шлёт ядро, что ждёт обратно |
| [02-deal-health.md](https://github.com/bx-shef/toolsai/blob/main/docs/02-deal-health.md) | анализ сделок |
| [03-upgrade-watch.md](https://github.com/bx-shef/toolsai/blob/main/docs/03-upgrade-watch.md) | что ломается при обновлениях платформы |
| [04-runbook.md](https://github.com/bx-shef/toolsai/blob/main/docs/04-runbook.md) | включение на сервере по шагам |
| [portal-check.md](https://github.com/bx-shef/toolsai/blob/main/docs/portal-check.md) | приёмка на стенде |
| [agent-docker-test.md](https://github.com/bx-shef/toolsai/blob/main/docs/agent-docker-test.md) | промпт для ИИ-агента: приёмка на коробке в Docker |

## Лицензия

MIT, см. [LICENSE](https://github.com/bx-shef/toolsai/blob/main/LICENSE).

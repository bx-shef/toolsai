# Раскладка репозитория

Файл про устройство репозитория. Опорные точки модуля — в [CLAUDE.md](../CLAUDE.md),
процесс — в [CONTRIBUTING.md](../CONTRIBUTING.md), сборка — в
[build-and-install.md](build-and-install.md).

## Модуль лежит в корне, и это вынужденно

Composer разворачивает в целевой каталог **корень пакета целиком** и подкаталоги
выбирать не умеет. Поэтому `lib/`, `install/`, `lang/` лежат прямо в корне
репозитория, рядом с `build.sh` и `.github/`.

Плата за это — два списка в шапке `build.sh`:

* **SHIP** — уезжает на портал и в Composer-пакет;
* **KEEP** — остаётся в репозитории.

**Файл, не попавший ни в один список, роняет сборку.** Тот же список продублирован
в `.gitattributes` через `export-ignore`; списки обязаны совпадать, сверяется
автоматически, см. `check_gitattributes`.

## Что где лежит

| путь | | что это |
|---|---|---|
| `install/index.php` | SHIP | установщик, класс `shef_toolsai extends CModule` |
| `install/version.php` | SHIP | `VERSION` и `VERSION_DATE` — источник истины о версии |
| `endpoint/completions.php` | SHIP | эндпоинт движка; открывается заглушкой `/bitrix/tools/shef_toolsai_completions.php` |
| `admin/quota.php` | SHIP | страница «ИИ: расход и остаток»; заглушка `/bitrix/admin/shef_toolsai_quota.php` |
| `admin/menu.php` | SHIP | пункт в меню «Сервисы»; ядро подключает его само, из каталога модуля |
| `cli/` | SHIP | включение (`setup.php`), анализ сделок руками (`deal-health.php`), диагностика ядра из кита (`core-api-guard.php`, `ai-call-autostart-diag.php`, `ai-limits-report.php`) |
| `.settings.php` | SHIP | зависимости линейки; раскладки, событий, контроллеров нет |
| `include.php`, `autoload.php` | SHIP | точка входа: поднимает `shef.options` и `shef.problems` |
| `default_option.php` | SHIP | умолчания настроек |
| `options.php`, `options_conf.php` | SHIP | страница настроек на `ShOptionsConfig` из `shef.options` |
| `lib/` | SHIP | классы модуля, **имена файлов строго строчными** |
| `lang/ru/` | SHIP | языковые файлы |
| `README.md`, `CHANGELOG.md`, `LICENSE` | SHIP | |
| `composer.json` | SHIP | манифест пакета `bxshef/toolsai` |
| `docs/` | KEEP | документация: исследование ядра, контракт, runbook, приёмка, промпт для Docker, правила агента |
| `docker/` | KEEP | стенд: коробка в контейнерах, заглушка OpenAI-совместимого API, тестовый звонок |
| `build.sh` | KEEP | сборка и проверки |
| `tests/` | KEEP | тесты и заглушки ядра |
| `examples/` | KEEP | запускаемые примеры |
| `.claude/skills/` | KEEP | навыки агента: копия из `bx-shef/options` плюс локальный `shef-new-ai-provider` |
| `.github/` | KEEP | CI и релиз |
| `CONTRIBUTING.md`, `CLAUDE.md` | KEEP | процесс и памятка агенту |
| `.gitattributes`, `.gitignore` | KEEP | |

## `lib/`

```
lib/
  config.php, container.php       настройки и сборка зависимостей
  main/                           константы, строгий разбор настроек, заглушки страниц, Setup
  completion/                     эндпоинт, запрос ядра, диспетчер, колбэк
  security/                       токен эндпоинта, сверка хоста колбэка
  http/                           транспорт: интерфейс и HttpClient ядра
  provider/                       заглушка echo, OpenAI-совместимые ASR и LLM
  quota/                          журнал расхода, остаток, таблица
  engine/                         регистрация движков в b_ai_engine
  deal/                           анализ сделки: факты, вердикт, эскалация, таблица проверок
  agent/                          агент анализа сделок
```

Всё, что можно проверить без портала, — без ядра: `Completion\Endpoint`,
`Completion\Request`, `Completion\Dispatcher`, `Quota\Balance`,
`Main\OptionParser`, провайдеры (через `Http\TransportInterface`),
`Deal\Verdict`, `Deal\DealFacts`. ORM и CRM — только в `quota/meter.php`,
`quota/ledger.php`, `*/model/*`, `deal/contextbuilder.php`,
`deal/escalation.php`, `engine/`, агенте и `main/setup.php`.

## Нижний регистр в `lib/` обязателен

`Bitrix\Main\Loader` отображает класс в путь **строчными**, разбирая первые два
сегмента namespace как id модуля: `Shef\ToolsAi\Main\Setup` ищется как
`bitrix/modules/shef.toolsai/lib/main/setup.php`. Поэтому `registerNamespace`
пуст. В ките классы лежали с заглавными (`lib/Quota/Balance.php`) — на
Linux ни один из них не нашёлся бы.

Проверяется в `build.sh`, `check_lowercase`, и в `tests/autoload_test.php`.

## Публичные страницы пишутся, а не копируются

Каталог модуля браузеру недоступен (в поставке nginx закрывает
`/bitrix/modules`). Эндпоинт и страница расхода открываются заглушками в одну
строку — `require` на файл модуля. Заглушку пишет `Main\PublicPage` с путём
туда, где модуль стоит на самом деле, и удаляет только свою.

## Документация не едет на портал

Документация живёт в репозитории. В поставке остаётся только `README.md` — как
readme пакета, — и все ссылки из него ведут на GitHub.

# Установка через Composer

Линейка ставится Composer'ом целиком: `bxshef/toolsai` тянет
`bxshef/problems` (а тот — Monolog) и `bxshef/options`. Тип пакетов —
`bitrix-module`, `composer/installers` раскладывает их в
`<bitrix-dir>/modules/shef.*` — туда, где их ищет Битрикс. Проверено
установкой в пустой каталог, 2026-10-03.

| пакет | откуда | версия |
|---|---|---|
| `bxshef/options` | Packagist | `^3.0` (тег) |
| `bxshef/problems` | GitHub, пока не на Packagist | `2.x-dev` (ветка `main`) |
| `bxshef/toolsai` | GitHub, пока не на Packagist | `1.x-dev` (ветка `main`) |

Пока `problems` и `toolsai` не опубликованы (тег и Packagist — после
приёмки, решение владельца), их репозитории указываются в
`composer.json` явно.

## Где лежит composer.json

**Вне корня сайта.** Каталог `vendor` под корнем отдавался бы веб-сервером.
Для BitrixVM (`/home/bitrix/www` — корень):

```
/home/bitrix/composer.json
/home/bitrix/composer.lock
/home/bitrix/vendor/            ← Monolog, composer/installers
/home/bitrix/www/bitrix/modules/shef.options
/home/bitrix/www/bitrix/modules/shef.problems
/home/bitrix/www/bitrix/modules/shef.toolsai
```

`/home/bitrix/composer.json`:

```json
{
	"name": "local/portal",
	"type": "project",
	"repositories": [
		{"type": "vcs", "url": "https://github.com/bx-shef/problems", "no-api": true},
		{"type": "vcs", "url": "https://github.com/bx-shef/toolsai", "no-api": true}
	],
	"require": {
		"bxshef/toolsai": "1.x-dev"
	},
	"minimum-stability": "dev",
	"prefer-stable": true,
	"config": {
		"allow-plugins": {"composer/installers": true}
	},
	"extra": {
		"bitrix-dir": "www/bitrix"
	}
}
```

* `bitrix-dir` — путь к каталогу `bitrix` **от composer.json**; без него
  модули лягут в `./bitrix/modules` рядом с composer.json, мимо сайта.
* `allow-plugins` — без разрешения `composer/installers` пакеты лягут в
  `vendor/bxshef/…`, и Битрикс их не увидит.
* `no-api` — забирать репозитории git'ом, без GitHub API (без токена API
  быстро упирается в лимит).
* `minimum-stability: dev` + `prefer-stable` — ветки только для
  `problems` и `toolsai`, остальное — стабильные версии.
* На уже существующий `composer.json` проекта — добавить `repositories`,
  `require`, `allow-plugins` и `bitrix-dir` в него.

### Зафиксировать версию

`composer.lock` фиксирует коммиты — его хранить. Чтобы поставить ровно
проверенный коммит, а не голову ветки: `"bxshef/toolsai": "dev-main#<коммит>"`.

## Установка

**От пользователя веб-сервера**, не от root: файлы должны принадлежать
`bitrix`, а Composer под root без `COMPOSER_ALLOW_SUPERUSER=1` молча
отключает плагины — и `composer/installers` не раскладывает модули.

```bash
cd /home/bitrix
sudo -u bitrix composer install --no-dev --no-interaction
ls www/bitrix/modules | grep shef     # shef.options, shef.problems, shef.toolsai
```

Composer только кладёт файлы. **Установить модули** — как обычно,
**Настройки → Модули**, по порядку: `shef.options` → `shef.problems` →
`shef.toolsai`. Установщик toolsai портал не меняет; включение —
«Проверить и включить» ([04-runbook.md](04-runbook.md), шаг 3).

### Composer проекта и Битрикс

Чтобы Битрикс подключал `vendor/autoload.php` проекта, а shef.problems брал
Monolog оттуда, а не свою копию, — штатный ключ `composer` в
`/home/bitrix/www/bitrix/.settings_extra.php`:

```php
'composer' => [
	'value' => ['config_path' => '/home/bitrix/composer.json'],
],
```

Без ключа тоже работает: shef.problems подключит свою копию Monolog из
каталога модуля.

## Обновление

```bash
cd /home/bitrix
sudo -u bitrix composer update 'bxshef/*' --no-dev --no-interaction
```

Замена файлов **не запускает установщик**. После обновления shef.toolsai —
«Проверить и включить» на странице «ИИ: расход и остаток» (или
`cli/setup.php`); `cli/preflight.php` — что всё на месте.

## Удаление

Сначала модули — **Настройки → Модули** в обратном порядке (удаление
toolsai уносит журнал расхода — [04-runbook.md](04-runbook.md), «Откат»),
потом `composer remove bxshef/toolsai`. Наоборот нельзя: Composer удалит
файлы, а модуль останется зарегистрированным без кода.

## После выпуска на Packagist

`repositories` и `minimum-stability` не нужны:

```bash
sudo -u bitrix composer require bxshef/toolsai:^1.0
```

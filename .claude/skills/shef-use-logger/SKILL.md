---
name: shef-use-logger
description: Логировать через модуль shef.problems (Monolog) в любом модуле Битрикс24 «коробки» или БУС, свой вендор — записать сбой как проблему в журнал событий Битрикса и в файл с типом (синхронизация, товар, продажи) и ответственным из настроек; отдать логгеру исключение, Result или Error ядра; вывести отладку на экран только администратору; завести свой Monolog-логгер через Diag\Logger. Брать на задачи «сбой должен попадать в журнал событий», «кто ответственный за ошибку», «логируй ошибки импорта/выгрузки/обмена», «покажи админу, что приходит в обработчик», «подключи Monolog». Не для простого следа работы класса в файл без shef.problems — это трейт Log из shef-options-traits; не для создания агента — shef-new-agent.
---

# Логирование через shef.problems

Операция: сделать так, чтобы сбой в коде вашего модуля не терялся — лёг в
файл, в журнал событий Битрикса с нужным типом, с ответственным, — или чтобы
отладка показывалась администратору, а не всем.

Модуль [shef.problems](https://github.com/bx-shef/problems) — Monolog для
Битрикса. Всё ниже требует, чтобы он был **установлен** на портале (а он
требует `shef.options` 3.0.0+).

## Где лежат логи

**Вне корня сайта**: `\Shef\Problems\Main\Constants::getLogDir()` — по
умолчанию на уровень выше `DOCUMENT_ROOT`, для BitrixVM `/home/bitrix/sh_log`.
Путь к файлу — только `\Shef\Problems\Main\Constants::getLogFullPath('имя')`
(без `.log`), строкой не собирать: проект может перенести каталог
(`/bitrix/.settings_extra.php`, ключ `shef.problems` → `logDir`). Прямой ссылки
на лог нет и быть не должно — смотрят через **Настройки → Учёт проблем →
Логи** (`/bitrix/admin/shef_problems_logs.php`, только администратору).

## Что брать

| задача | что | где |
|---|---|---|
| сбой, который надо найти потом по типу и понять, кому он | трейт `LoggerProblems` | ниже, «Проблема» |
| увидеть, что пришло, — только администратору, на экране | `Logger::PrHtml` / трейт `DebuggerProblems` | ниже, «Отладка» |
| след работы в файл `log.log` каталога логов | `Logger::Log` | ниже, «Файл» |
| свой набор обработчиков (Telegram, почта) | свой `Integration\Monolog\Logger` | [docs/4_monolog.md](https://github.com/bx-shef/problems/blob/main/docs/4_monolog.md) |

## Модуль подключается до класса

Файл, в котором класс делает `use` трейта из `Shef\Problems\…`, начинается с
подключения модуля **до** слова `class`: PHP разбирает трейт при чтении файла,
и `includeModule()` в конструкторе к этому моменту ещё не выполнялся — будет
`Trait "Shef\Problems\Factory\Trait\LoggerProblems" not found`.

```php
<?php declare(strict_types=1);

namespace Acme\Exchange\Service;

\Bitrix\Main\Loader::includeModule('shef.problems');

use Monolog\Level;
use Shef\Problems\Factory\Trait\LoggerProblems;
use Shef\Problems\Main\Constants;
```

И в `.settings.php` вашего модуля — зависимость, чтобы `autoload.php` поднимал
модуль сам и удаление `shef.problems` отказывало, пока вы стоите:

```php
'requireModules' => [
	'value' => ['shef.options', 'shef.problems'],
	'readonly' => true,
],
```

## Проблема — трейт `LoggerProblems`

`\Shef\Problems\Factory\Trait\LoggerProblems` даёт `$this->logger`: запись
уходит сразу в `<тип>.log` каталога логов и в журнал событий с этим типом, с
модулем, классом и ответственным.

```php
final class OrdersExport
{
	use LoggerProblems;

	public function __construct()
	{
		$this->initLogger();          // без этого $this->logger не инициализирован
	}

	// Обязательные — абстрактные в трейте.
	public static function getClassName(): string
	{
		return static::class;
	}

	public static function getModuleId(): string
	{
		return 'acme.exchange';
	}

	// Необязательные — у трейта есть умолчания.
	protected static function getLogLevel(): Level      // умолчание: Level::Info
	{
		return Level::Warning;
	}

	protected static function getAuditType(): string    // умолчание: Constants::AuditTypeProblem
	{
		return Constants::AuditTypeSync;
	}

	public static function getAssignedId(): int         // умолчание: Constants::getDefUserId()
	{
		return Constants::getSyncUserId();
	}

	public function run(int $orderId): void
	{
		try
		{
			// …
		}
		catch(\Throwable $throwable)
		{
			$this->logger->error($throwable, ['itemId' => $orderId]);
		}
	}
}
```

Точные сигнатуры трейта — повторяйте дословно, модификаторы тоже:

```php
abstract public static function getClassName(): string;
abstract public static function getModuleId(): string;
public static function getAssignedId(): int;
protected static function getLogLevel(): \Monolog\Level;
protected static function getAuditType(): string;
protected function initLogger(): void;
public function configureLogger(\Psr\Log\LoggerInterface $logger): static;   // подменить, например в тесте
protected \Psr\Log\LoggerInterface $logger;
```

**Тип проблемы** — одна из констант `\Shef\Problems\Main\Constants`, другое
фабрика молча заменит на тип по умолчанию:

| константа | журнал событий | ответственный из настроек |
|---|---|---|
| `AuditTypeProblem` | `SH_PROBLEMS_PROBLEM` | `getDefUserId()` |
| `AuditTypeSync` | `SH_PROBLEMS_SYNC` | `getSyncUserId()` |
| `AuditTypeProduct` | `SH_PROBLEMS_PRODUCT` | `getProductsUserId()` |
| `AuditTypeSale` | `SH_PROBLEMS_SALE` | `getSaleUserId()` |

Ещё есть `getAdminId()` и `getDirectorId()` — для проблем Битрикс24 и очень
важных.

**Статический метод (агент, обработчик события)** — `$this` нет, берите
фабрику напрямую, аргументы те же:

```php
$logger = \Shef\Problems\Factory\SystemLoggerFactory::build(
	logLevel: \Monolog\Level::Warning,
	auditType: \Shef\Problems\Main\Constants::AuditTypeSale,
	moduleId: 'acme.exchange',
	className: static::class,
	assigned: \Shef\Problems\Main\Constants::getSaleUserId(),
);
$logger->error('Оплата не сопоставлена', ['itemId' => $paymentId]);
```

## Что отдавать логгеру

Логгер модуля — `\Shef\Problems\Integration\Monolog\Logger`, первым аргументом
принимает не только строку:

| передали | сообщение | в контексте |
|---|---|---|
| `\Throwable` | текст исключения | исключение (из него берётся трассировка) |
| `\Bitrix\Main\Result` | `[Result::Error: N] первая ошибка` / `[Result::Success]` | все ошибки и данные |
| `\Bitrix\Main\Error` | текст и код | ошибка |
| массив, `Arrayable`, `JsonSerializable` | имя типа | содержимое под `_message` |
| строка, `Stringable` | как есть | — |

Число, `null`, объект без контракта — `InvalidArgumentException`. Не
приводите к строке «на всякий случай» — `Result` целиком лучше строки.

В контексте два ключа особые, они уходят в поля журнала событий:
`itemId` (**int**) — «Элемент», `moduleId` (**string**) — «Модуль».

## Отладка — только администратору

```php
\Bitrix\Main\Loader::includeModule('shef.problems');

\Shef\Problems\Logger::PrHtml->getLogger()->debug('пришло в обработчик', ['fields' => $fields]);
```

`Pr` — без оформления, `PrHtml` — с цветом по уровню. Видит только
администратор, вывод экранируется. В классе — трейт
`\Shef\Problems\Factory\Trait\DebuggerProblems`: `$this->initDebugger()`, потом
`$this->debugger->debug(...)`.

## Файл

```php
\Shef\Problems\Logger::Log->getLogger()->info('Импорт начат', ['rows' => $count]);
```

`Logger::Log` — `log.log`, дописывается. `Logger::Log1` —
`log1.log`, первая запись за запрос стирает файл: для разбора одной цепочки.
`Logger::Problems->getLogger()` бросает `LogicException` — это фабрика, не
логгер.

## Чего не делать

- **Не писать проблему ниже уровня логгера и удивляться.** `getLogLevel()`
  возвращает порог: при `Level::Warning` вызов `->info()` не пишет никуда.
- **Не ждать в журнале CRITICAL.** Журнал знает пять важностей; `Critical`,
  `Alert`, `Emergency` ложатся как `ERROR`, `Notice` — как `INFO`. Исходный
  уровень — в описании записи.
- **Не класть в контекст секреты** — пароли, токены, полные номера карт. Логи
  читают глазами, журнал событий — любой администратор.
- **Не делать логгер для отладки видимым всем** (`isShowForAll: true`) вне
  стенда.
- **Не звать `new \Monolog\Logger` напрямую** для проблем: пропадут журнал
  событий, тип и ответственный.
- **Не класть свой лог под корень сайта** (`/local/…`, `/upload/…`): его
  отдаст веб-сервер. Свой файл — `Constants::getLogFullPath('acme-import')`.

## Проверка

- `php -l` на файлах с логгером;
- в `.settings.php` модуля `shef.problems` есть в `requireModules`;
- `includeModule('shef.problems')` стоит до `class` в каждом файле с трейтом;
- в классе с `LoggerProblems` конструктор зовёт `initLogger()`;
- тип проблемы — константа `Constants::AuditType…`, а не строка.

Запускаемый пример —
[examples/problems.php](https://github.com/bx-shef/problems/blob/main/examples/problems.php),
на портале: `DOCUMENT_ROOT=/var/www/portal php examples/problems.php`.

## В конце

Оставьте отзыв о навыке — shef-feedback.

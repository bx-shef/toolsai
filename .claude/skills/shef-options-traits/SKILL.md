---
name: shef-options-traits
description: Готовые механики shef.options для классов любого модуля (свой вендор), чтобы не писать их заново. Брать, когда нужно: разобрать входные поля из строк — цена «1 234,50», неразрывные пробелы, обязательное поле отсутствует (PrepareFields); выполнить код от служебного пользователя и вернуть прежнего после — в агенте, cron, обработчике (FixUser); логировать по канону (Log); проверить, что нужные модули подключены (Modules); собрать ошибки в коллекцию (ErrorCollection); защитить обработчик события от повторного вызова (Events); работать с датой, кодировкой, XmlId, константами. Брать и на «сервис-класс, который принимает массив полей». Не для создания компонентов, агентов или страниц настроек — для этого есть отдельные навыки.
---

# Набор трейтов

`lib/traitlist/` — девятнадцать трейтов. Половина из них нужна почти в каждом
классе линейки, поэтому смотреть сюда стоит **до** того, как писать своё.

**`shef.options` должен быть подключён до объявления вашего класса.** Трейт
подключается через `use` в теле класса, а это композиция при разборе файла:
`\Bitrix\Main\Loader::includeModule('shef.options')` внутри метода уже
опоздает, файл упадёт с `Trait ... not found`. Строка ставится до слова
`class`.

Трейты подключаются в класс обычным `use`. Часть методов `protected`: они
рассчитаны на вызов изнутри класса, и открывать их наружу нужно осознанно —
`use PrepareFields { prepareRowList as public; }`.

## Что есть

| трейт | что даёт | берут когда |
|---|---|---|
| `\Shef\Options\TraitList\Modules` | `includeModules()` по списку из `getModulesList()` | класс зависит от чужих модулей |
| `\Shef\Options\TraitList\Events` | `disableHandler()` / `enableHandler()` / `isEnabledHandler()` | обработчик события правит сущность и снова вызывает сам себя |
| `\Shef\Options\TraitList\EventResponse` | возврат значений из обработчиков событий | обработчик обязан что-то вернуть ядру |
| `\Shef\Options\TraitList\Log` | `log()` в файл, который задаёт сам класс — **по умолчанию молчит**, см. ниже | нужен след работы |
| `\Shef\Options\TraitList\Tools\ErrorCollection` | `addError()`, `getErrors()`, `getErrorCollection()` | класс обязан копить ошибки, а не бросать первую |
| `\Shef\Options\TraitList\Tools\PrepareFields` | разбор и проверка входных полей | вход от человека или чужой системы |
| `\Shef\Options\TraitList\Tools\DateTime` | `getCurDateTime()` / `setCurDateTime()` — одно «сейчас» на весь объект | в одном прогоне все записи должны получить одинаковое время |
| `\Shef\Options\TraitList\Tools\Encoding` | `convertEncoding()` / `unConvertEncoding()`, кодировку источника объявляет класс | обмен с системой в другой кодировке |
| `\Shef\Options\TraitList\Tools\IsDebug` | `setIsDebug()` / `isDebug()` | поведение отличается на разработке |
| `\Shef\Options\TraitList\Tools\OptionCollection` | набор опций объекта в `Dictionary` | классу передают пачку настроек |
| `\Shef\Options\TraitList\Tools\LogCollection` | накопление сообщений в `Dictionary` | нужен отчёт о прогоне |
| `\Shef\Options\TraitList\Tools\SelfClass` | `getClassName()` — FQCN с ведущим слэшем | имя класса идёт в строку: в событие, в лог, в настройку |
| `\Shef\Options\TraitList\Tools\XmlId` | идемпотентные XmlId и коды | сущность создаётся повторно и не должна двоиться |
| `\Shef\Options\TraitList\Constants\Catalog` | `getCatalogId()`, `getCatalogSKUId()` | работа с торговым каталогом |
| `\Shef\Options\TraitList\Constants\Price` | `getBaseCurrency()` | работа с ценами |
| `\Shef\Options\TraitList\Constants\Site` | `getBaseSiteId()` | многосайтовость |
| `\Shef\Options\TraitList\Constants\User` | `getSystemUserId()` — из настроек модуля | работа от имени системы |
| `\Shef\Options\TraitList\Security\FixUser` | подмена текущего пользователя | агент или cron работает от имени пользователя |
| `\Shef\Options\TraitList\UF\Entity` | словарь пользовательских полей сущности, создание недостающих | класс описывает набор UF и должен их завести |

## Подключение модулей

```php
use Shef\Options\TraitList\Modules;

final class Importer
{
    use Modules;

    protected static function getModulesList(): array
    {
        return ['crm', 'catalog'];
    }
}
```

`getModulesList()` — **абстрактный** метод трейта: не объявите его, класс не
соберётся. Так же устроены `Encoding` (`getEncodingFrom()`),
`Security\FixUser` (`getInitedUserId()`) и `Constants\Catalog`
(`getModuleId()`): трейт спрашивает у класса то, чего сам знать не может. `includeModules()` возвращает `\Bitrix\Main\Result` с ошибкой на
первом незагрузившемся модуле, а не бросает исключение.

## `Log` по умолчанию не пишет ничего

Сигнатуры дословно — угадывать их не надо:

```php
protected static function isSkipLog(): bool;                                  // по умолчанию true
protected static function getLogFile(): string;                               // имя файла без пути и расширения
protected static function log(array $value = [], bool $isNotSkip = false): void;
```

`log()` принимает **массив**. `static::log('строка')` — это `TypeError`, а не
запись в лог.

Трейт подключили, `static::log([...])` позвали, в логе пусто и ни одной
ошибки. Так и задумано: `isSkipLog()` в трейте возвращает `true`, и `log()`
выходит, не дойдя до записи.

Включают одним из двух способов:

```php
protected static function isSkipLog(): bool { return false; }   // насовсем
static::log($value, true);                                      // разово
```

Заодно переопределите `getLogFile()` — умолчание у него общее на всех
(`shef-options-trait-list-Events`), и ваши записи лягут в чужой файл. Пишет
он через `_log()` в `<DOCUMENT_ROOT>/local/log/`, каталог должен
существовать.

## Защита обработчика от самого себя

Классическая ловушка Битрикса: обработчик `OnAfterCrmDealUpdate` правит сделку
и тем самым снова вызывает сам себя. `Events` даёт счётчик-замок:

```php
use Shef\Options\TraitList\Events;

final class DealHandler
{
    use Events;

    public static function onAfterUpdate(array &$fields): bool
    {
        if(!static::isEnabledHandler(__FUNCTION__, (int)$fields['ID'], 'DEAL'))
        {
            return true;
        }

        static::disableHandler(__FUNCTION__, (int)$fields['ID'], 'DEAL');
        // ... правим сделку, обработчик сюда больше не зайдёт
        static::enableHandler(__FUNCTION__, (int)$fields['ID'], 'DEAL');

        return true;
    }
}
```

Замок именно счётчик, а не флаг: вложенные `disable` снимаются по одному.
Пару `disable`/`enable` обязан соблюдать вызывающий — забыли `enable`,
обработчик останется выключенным до конца запроса.

## Приведение входных полей

`PrepareFields` — самый крупный трейт набора, у него [запускаемый
пример](https://github.com/bx-shef/options/blob/main/examples/preparefields.php).

```php
use Shef\Options\TraitList\Tools\PrepareFields;

final class PriceRow
{
    use PrepareFields;

    public static function fromArray(array $row): static
    {
        return new static(
            // true — обязательное: нет значения, будет ArgumentNullException
            name: static::checkFieldString('Наименование', $row['NAME'] ?? null, true),
            // false + умолчание
            quantity: static::checkFieldInt('Количество', $row['QUANTITY'] ?? null, false, 1),
            price: static::checkFieldFloat('Цена', $row['PRICE'] ?? null, false, 0.0),
        );
    }
}
```

Чем это лучше приведения типом:

| вход | `(int)` / `(float)` | трейт |
|---|---|---|
| `'1 200'` | `1` | `1200` |
| `'1 234,50'` | `1.0` | `1234.5` |
| `'1'.chr(194).chr(160).'200'` (неразрывный пробел из 1С) | `1` | `1200` |
| `'  Болт М8  '` | как есть | `'Болт М8'` |
| нет значения, поле обязательное | `0` | `ArgumentNullException` с именем поля |

Отдельно запомнить: `parseString(0)` вернёт **пустую строку** — ноль для
`empty()` пустой. Если ждёте `'0'`, разбирайте сами.

`prepareRowList($code, $row)` всегда отдаёт массив: скаляр завернёт, список
оставит, отсутствующий ключ даст `[]`. Принимает массив строки **по ссылке** и
возвращает ссылку.

## Сбор ошибок вместо исключений

`ErrorCollection` — контракт `\Bitrix\Main\Errorable`: класс копит ошибки и
отдаёт их наружу, а не падает на первой. Так устроены компоненты линейки:
`executeComponent()` после каждого шага смотрит, пуста ли коллекция.

```php
$this->addError(new \Bitrix\Main\Error('Нет обязательного параметра'));

if(!$this->getErrorCollection()->isEmpty())
{
    return;
}
```

## Чего трейты не делают

Трейт — это копия кода в вашем классе, а не общий объект. Статические
свойства трейта (`Events::$handlerDisallow`) у каждого класса **свои**: два
класса с одним трейтом не видят замков друг друга. Когда нужно общее
состояние — это синглетон, см. skill `shef-options-blocks`.

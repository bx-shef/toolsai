---
name: shef-options-settings
description: Справка о том, как устроена страница настроек модуля на ShOptionsConfig из shef.options: откуда берётся options.php, как читается options_conf.php, как проверяются права, как хранятся значения. Брать, когда надо понять или починить существующую страницу настроек. Чтобы добавить новую опцию или вкладку — брать shef-new-option.
---

# Страница настроек модуля

Битрикс сам показывает `/bitrix/admin/settings.php?mid=<id модуля>`, если в
каталоге модуля лежит `options.php`. `shef.options` даёт к этому слой:
вкладки и типы опций описываются декларативно, разметку и сохранение берёт на
себя модуль.

## Три файла

| файл | что в нём |
|---|---|
| `options.php` | страница: подключает модуль, читает `options_conf.php`, рисует и сохраняет |
| `options_conf.php` | **описание** вкладок и опций — то, что вы пишете |
| `lang/ru/options_conf.php` | подписи |

В `shef.options` `options.php` уже написан, и его берут **как есть, копией
файла**: `bitrix/modules/shef.options/options.php` на проекте либо тот же файл
в репозитории по нужному тегу —
[options.php](https://github.com/bx-shef/options/blob/main/options.php).
Если `shef.options` в рабочей копии нет — откройте его в репозитории, а не
восстанавливайте по памяти. Ваш модуль пишет только `options_conf.php`.

**`ShOptionsConfig` — глобальный класс без namespace.** Объявлен в
`bitrix/modules/shef.options/optionsconfig.php`, и никакого пространства имён
в этом файле нет. Пишется `\ShOptionsConfig` (или просто `ShOptionsConfig` —
`options_conf.php` тоже без namespace). Чего писать нельзя:
`use Shef\Options\Main\ShOptionsConfig;` — такого класса не существует, и
файл упадёт на первой строке.

## Как выглядит описание

```php
use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;

$response = ShOptionsConfig::getInstance(moduleId: 'shef.demo');
if(!$response->isSuccess())
{
    return $response;
}

/** @var ShOptionsConfig $options */
$options = $response->getData()['OPTIONS'];

$options->addTab(
    (new Options\Tab('DEF'))
        ->setName(Loc::getMessage($options->moduleId.'_TAB_DEF_NAME'))
        ->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_TITLE'))
        ->addOption(
            (new Options\Text('apikey'))
                ->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_apikey'))
                ->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_apikey_descr'))
        )
);

return $options->get();
```

## Имя опции собирается само — и это важно

Опция сохраняется под именем `<код вкладки>_<код опции>`:
`\Shef\Options\Main\Options\AOption::getComplexCode()`. Вкладка `DEF` и опция
`apikey` дают настройку `DEF_apikey`, читается она обычным способом:

```php
\Bitrix\Main\Config\Option::get('shef.demo', 'DEF_apikey');
```

Отсюда два следствия. **Код вкладки менять нельзя** — переименовали `DEF` в
`MAIN`, и все сохранённые значения стали недоступны, хотя в базе они лежат.
И **код опции — это идентификатор**, а не подпись: подпись живёт в языковом
файле.

## Типы опций

Все в namespace `\Shef\Options\Main\Options`:

| класс | для чего |
|---|---|
| `Text` | строка |
| `TextArea` | большой текст |
| `Checkbox` | да/нет, хранится Y/N |
| `NumberInt` | целое |
| `NumberFloat` | дробное |
| `Enum` | список значений, задаётся `setList()` |
| `Users` | выбор пользователей с фильтром |
| `Department` | сотрудники и отделы через `ui.entity-selector`, результат в json |
| `EnumIblock` | инфоблоки |
| `EnumHl` | highload-блоки |
| `EnumMeasure` | единицы измерения |
| `EnumVat` | ставки НДС |
| `EnumCurrency` | валюты |
| `EnumPriceType` | типы цен |
| `EnumCrmDealCategory` | направления сделок |
| `EnumCrmSource` | справочник источников |
| `EnumCrmSmartProcessType` | типы смарт-процессов |
| `EnumCrmRqPreset` | пресеты реквизитов |
| `RowInfo` | не опция, а сообщение на странице |

`RowInfo` показывает предупреждение или подсказку:

```php
(new Options\RowInfo('WARNING'))
    ->setDescription(Loc::getMessage('...'))
    ->setType(Options\TypeUIAlert::Warning)   // Error | Note | Warning
```

## Языковые файлы

Ключ собирается из идентификатора модуля: `Loc::getMessage($options->moduleId.'_TAB_DEF_NAME')`
ищет `$MESS['shef.demo_TAB_DEF_NAME']`. Точка в идентификаторе модуля — часть
ключа, так и пишется.

Общие подписи самого `shef.options` (вкладка зависимостей, тексты ошибок)
живут под префиксом `shef_` и подставляются автоматически.

## Что появляется само

* **Вкладка «Зависимости»** собирается, если в `.settings.php` непусты
  `requireModules` или `requirePhpExt`, и показывает, чего не хватает.
* **Вкладка «Настройки PHP»** появляется вместо всего остального, если не
  хватает расширения PHP из `requirePhpExt`: пускать в настройки модуль,
  который всё равно не заработает, незачем.

## Права: кого пускают на страницу

Проверяет их `options.php`, вам делать ничего не нужно — но знать, как именно,
стоит, потому что жалоба «у сотрудника не открывается» разбирается здесь.

* право на модуль берётся как `GetGroupRight($mid)`, где `$mid` — идентификатор
  модуля из адреса страницы;
* ниже `R` — страница не открывается вовсе;
* ниже `W` — страница открывается, но сохранение запрещено.

Уровень назначается в **Настройки → Пользователи → Группы пользователей** →
нужная группа → вкладка **Доступ**. Модуль обязан объявить
`$MODULE_GROUP_RIGHTS = 'Y'` в установщике, иначе назначать будет нечего.

## Разбор сохранённого значения — не мелочь

Значение приходит из формы строкой. Приведение типом на нём ошибается молча:

```php
// НЕ НАДО
$id = (int)Option::get('shef.demo', 'DEF_userid');
```

`(int)''` даст `0` — работу «от имени никого», а `(int)'5 62'` даст `5` — права
пользователя, которого никто не выбирал. Разбирайте строго: целое больше нуля
либо строка из одних цифр, иначе умолчание. Готовый разбор для служебного
пользователя — `\Shef\Options\Main\Constants::getSystemUserId()`, но учтите:
он читает жёстко свой модуль и свою опцию `DEF_systemuserid`, для настройки
вашего модуля это образец, а не готовая функция. Правило в нём — целое больше
нуля либо строка из одних цифр **без ведущего нуля** (`/^[1-9][0-9]*$/`):
`ctype_digit('05')` истинно, и без этой оговорки `'05'` даст `5`. Для полей
вообще — трейт `\Shef\Options\TraitList\Tools\PrepareFields`, см. skill
`shef-options-traits`.

## Проверка руками

1. `/bitrix/admin/settings.php?mid=<ваш модуль>` открывается, вкладки на месте.
2. Заполнить, сохранить, перезайти — значение осталось.
3. Стили применились: `/bitrix/css/shef.options/admin-options.css` отдаётся
   с кодом 200, а `/bitrix/modules/shef.options/...` — 403.
4. Русский текст читается везде: подписи, описания, тексты ошибок.

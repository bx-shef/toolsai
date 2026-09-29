---
name: shef-options-blocks
description: Строительные блоки shef.options для любого модуля Битрикс24 «коробки» или БУС — синглетон (Singleton), реестр настроек запроса (Config), объект из массива (SmartStd), блокировка процесса pid-файлом (TempFile\Pid), утилиты (Utils). Брать, когда в модуле нужен общий объект с состоянием, передача структур между слоями, защита процесса от запуска в два экземпляра или разбор входных данных. Справочный навык: как устроено, не «сделай».
---

# Строительные блоки

Модуль `shef.options` не меняет поведение платформы — он даёт другим модулям
линейки готовые куски. Здесь те, которые нужны чаще всего.

Всё ниже проверяемо: каждый блок закрыт запускаемым примером, и пример
работает как на заглушках, так и на живом портале.

```bash
php examples/singleton.php                                  # без портала
DOCUMENT_ROOT=/var/www/portal php examples/singleton.php    # на портале
```

## Что брать под задачу

| задача | блок | пример |
|---|---|---|
| общий объект с состоянием в пределах запроса | `\Shef\Options\Options\Singleton` | [singleton.php](https://github.com/bx-shef/options/blob/main/examples/singleton.php) |
| разобрали настройку один раз — берут все | `\Shef\Options\Options\Config` | [config.php](https://github.com/bx-shef/options/blob/main/examples/config.php) |
| передать структуру между слоями или модулями | `\Shef\Options\Options\SmartStd` | [smartstd.php](https://github.com/bx-shef/options/blob/main/examples/smartstd.php) |
| агент или cron не должен идти в два экземпляра | `\Shef\Options\Main\TempFile\Pid` | [pid.php](https://github.com/bx-shef/options/blob/main/examples/pid.php) |
| привести вход от человека или из 1С | `\Shef\Options\TraitList\Tools\PrepareFields` | [preparefields.php](https://github.com/bx-shef/options/blob/main/examples/preparefields.php) |

## Синглетон

```php
use Shef\Options\Options\Singleton;

final class DealRegistry extends Singleton
{
    private array $ids = [];

    // protected — иначе снаружи появится второй способ создать объект
    protected function __construct()
    {
        parent::__construct();   // обязателен, см. ниже
    }

    public function add(int $id): static
    {
        $this->ids[$id] = $id;
        return $this;
    }
}

DealRegistry::getInstance()->add(10);
```

* Экземпляры хранятся **по имени класса**: у каждого наследника свой.
* `getInstance()` возвращает `static` — переопределять ради типа не нужно.
* `parent::__construct()` обязателен: базовый конструктор сейчас пуст, но
  `\Shef\Options\Main\TempFile\Manager` вешает на него регистрацию
  shutdown-функции, и наследник без вызова ломает не себя, а базовый класс.
* `clone` и `unserialize` закрыты намеренно.
* Состояние живёт **до конца запроса**. Это не кеш и не хранилище: между
  хитами ничего не переносится, для этого `\Bitrix\Main\Config\Option`.

## Реестр настроек запроса

`\Shef\Options\Options\Config` — синглетон со строковыми значениями и стопкой
состояний.

```php
Config::getInstance()->setValue('mode', 'import');

Config::getInstance()->push();            // запомнили
Config::getInstance()->setValue('mode', 'test');
// ... код, которому нужен режим test
Config::getInstance()->restore();         // вернули как было
```

* `getValue($key, $default)` — умолчание вторым аргументом.
* `hasValue($key)` — потому что «ключа нет» и «значение пустое» после
  `getValue()` уже не различить.
* `push()` без `restore()` оставит стопку расти: парность на вас.

## Объект из массива

`\Shef\Options\Options\SmartStd` — массив превращается в объект с обращением
через `->`, обратно разворачивается в массив.

```php
$deal = SmartStd::toObject([
    'title' => 'Поставка',
    'client' => ['name' => 'ООО «Ромашка»'],
    'tags' => ['новый', 'срочно'],
]);

$deal->client->name;   // вложенный ассоциативный массив стал объектом
$deal->tags;           // а СПИСОК остался массивом — иначе foreach сломался бы
$deal->toArray();      // круговой рейс не меняет структуру
```

Края, на которых ошибаются:

* **список на верхнем уровне всё равно станет объектом** с числовыми
  свойствами — не прогоняйте через `toObject()` то, что должно остаться
  списком;
* `toArray()` разворачивает `\Bitrix\Main\Type\Date`, `Arrayable` и
  `JsonSerializable`, остальные объекты кладутся как есть;
* `clone` здесь **глубокий** — в отличие от обычного PHP.

## Блокировка процесса

`\Shef\Options\Main\TempFile\Pid` — pid-файл на группу задач.

```php
$lock = new Pid('import');          // группа — имя задачи
$lock->add();                       // создать блокировку
$lock->isExist();                   // проверить
$lock->remove();                    // убрать свою

Pid::removeByGroup('import');       // остановить ВСЮ группу
```

Чего здесь легко не заметить:

* **`removeByGroup()` по умолчанию шлёт SIGTERM** процессам из найденных
  файлов. Нужно только убрать файлы — передавайте `0` вторым аргументом.
* Имя файла содержит pid: два процесса группы получают разные файлы.
  Префикс задаётся вторым аргументом конструктора — `new Pid('import', 'agent')`.
* `add()` сам убирает блокировки **мёртвых** процессов — упавший по фатальной
  ошибке агент не держит группу вечно. Живые и те, про которые выяснить
  нечем, не трогаются: лишняя блокировка — это задержка, лишнее удаление —
  два процесса там, где должен быть один.
* Файл живёт до `remove()`: концом скрипта он сам не удаляется.

## Прежде чем писать своё

1. Проверьте, нет ли нужного в `lib/traitlist/` — трейтов там девятнадцать,
   от подключения модулей до работы с ошибками.
2. Новый класс кладите по соглашению автозагрузки: `Shef\Options\Main\Utils`
   живёт в `lib/main/utils.php`, **строчными**. Иначе на боевом Linux класс
   не найдётся, а тест `tests/autoload_test.php` покраснеет заранее.
3. Новый блок — новый пример в `examples/` с шапкой ЦЕЛЬ / ГДЕ ПРИМЕНЯТЬ /
   ЧТО ДОЛЖНО ПОЛУЧИТЬСЯ / ЗАПУСК. Наличие разделов проверяет тест.

---
name: shef-options-installer
description: Установка сущностей CRM из установщика любого модуля Битрикс24 через слой Installator модуля shef.options — смарт-процессы, пользовательские поля (UF), пресеты реквизитов; стратегии установки, описание сущности, сохранение ID созданной сущности в настройки, идемпотентность и удаление. Брать на любую задачу «при установке модуля создать поле/смарт-процесс/пресет», «UF_… у сделки/лида/контакта при установке».
---

# Установка сущностей CRM

Когда модуль при установке обязан создать на портале смарт-процесс, набор
пользовательских полей или пресет реквизитов, руками это выливается в сотни
строк в `install/index.php`. Слой `\Shef\Options\Installator` делит задачу на
две части: **что** ставить (сущность) и **как** ставить (стратегия).

Полный перечень классов — в [docs/2_installer.md](https://github.com/bx-shef/options/blob/main/docs/2_installer.md).

## Схема

Код ниже выполняется из `install/index.php`, а там автозагрузка чужого модуля
сама не включается — подключите его перед первым обращением:

```php
use Bitrix\Main\Type\Dictionary;
use Shef\Options\Installator;

if(!\Bitrix\Main\Loader::includeModule('shef.options'))
{
    $this->ShowForm('ERROR', 'Не удалось подключить shef.options');   // метод заканчивается die()
}

$manager = new Installator\Manager(
    new Installator\Strategy\SmartProcessTypeStrategy()
);

$response = $manager->build(new Dictionary([
    new MyDealSmartProcess(),
    new MyTaskSmartProcess(),
]));

if(!$response->isSuccess())
{
    // ошибки копятся по ВСЕМ сущностям, а не обрываются на первой
    $errors = $response->getErrorMessages();
}
```

* `Manager::process()` ставит одну сущность, `build()` — коллекцию.
* `build()` **не останавливается** на первой ошибке: собирает все и отдаёт
  одним `Result`. Это осознанно — при установке модуля важно увидеть сразу
  весь список проблем, а не чинить их по одной.

## Стратегия — это «как»

| стратегия | что ставит |
|---|---|
| `\Shef\Options\Installator\Strategy\SmartProcessTypeStrategy` | тип смарт-процесса |
| `\Shef\Options\Installator\Strategy\CrmPresetStrategy` | пресет реквизитов |
| `\Shef\Options\Installator\Strategy\UfStrategy` | UF через `\Bitrix\Main\Controller\UserFieldConfig` |
| `\Shef\Options\Installator\Strategy\UfOldStrategy` | UF через старые функции ядра |

`UfOldStrategy` — **не устаревшая копия** `UfStrategy`, а запасной путь для
случаев, когда `UserFieldConfig` неприменим. Обе рабочие.

## Сущность — это «что»

Сущность реализует `\Shef\Options\Installator\IEntity`: `getId()` и
`getInstallSettings()` — массив, готовый к передаче в ядро. Абстракции на
каждый случай уже есть:

| абстракция | для чего |
|---|---|
| `\Shef\Options\Installator\Entity\Crm\ASmartProcessType` | смарт-процесс |
| `\Shef\Options\Installator\Entity\Crm\ASmartProcessTypeUf` | UF смарт-процесса |
| `\Shef\Options\Installator\Entity\Crm\APreset` | пресет реквизитов |
| `\Shef\Options\Installator\Entity\Crm\PresetField` | поле пресета |
| `\Shef\Options\Installator\Entity\UF\AEntity` | пользовательское поле |
| `\Shef\Options\Installator\Entity\UF\AEntityEnum` | UF-перечисление |
| `\Shef\Options\Installator\Entity\UF\EnumItem` | элемент перечисления |

Перечисления, которые пригодятся: `Entity\UF\EEntityId` — к чему можно
привязать UF, `Entity\UF\EType` — типы UF, `Entity\UF\EEnumStatus` — статусы.

## Запомнить ID созданной сущности

Смарт-процесс получает ID только при создании, а модулю он нужен потом.
Для этого есть `\Shef\Options\Installator\ISaveOption`: сущность объявляет, в
какой модуль и в какую настройку положить ID, — и установщик кладёт его сам.

```php
public function getModuleIdForSaveOption(): string { return 'shef.demo'; }
public function getCodeForSaveOption(): string     { return 'DEF_smartTypeId'; }
```

Дальше это обычная настройка модуля: `Option::get('shef.demo', 'DEF_smartTypeId')`.
Имя настройки собирается как `<код вкладки>_<код опции>`, см. skill
`shef-options-settings`.

## Две ветки установки UF — не перепутать

В модуле живут **две независимые** реализации, и это не дубль:

* `Installator\Entity\UF\*` со стратегиями `Installator\Entity\UF\Strategy\*` —
  та, что описана выше: сущность несёт своё описание, стратегия под каждый тип
  UF отдаёт настройки;
* `Installator\Uf\*` — своя ветка со своим `Installator\Uf\Manager`, типами
  `Installator\Uf\Type\*` и стратегиями `Installator\Uf\Type\Strategy\*`.

Выбирайте одну и держитесь её. Смешивать классы из разных веток нельзя: у них
разные интерфейсы, и собираться это не будет.

## Куда это встраивается

Вызов идёт из `install/index.php` вашего модуля, из `DoInstall()`. Там же
помните про правило установщика: **прервать установку можно только через**
`ShowForm('ERROR', ...)` — метод заканчивается `die()`, и это единственный
способ не продолжить. `return` из `DoInstall()` установку не остановит.

## Проверка

Установка на портале — и только она. Слой работает с ядром CRM, заглушками
это не проверяется: тестами закрыта раскладка файлов и соглашения, а не
создание смарт-процесса. Ставьте на стенд, смотрите созданное глазами,
удаляйте модуль и смотрите, что осталось.

## В конце

Оставьте отзыв о навыке — shef-feedback: что пригодилось (`helped`),
чего не хватило. Это последний шаг задачи, не пропускать.

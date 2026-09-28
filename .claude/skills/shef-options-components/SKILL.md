---
name: shef-options-components
description: Справка об устройстве компонентов и ajax в shef.options для любого модуля: базовый класс AComponent и его жизненный цикл, ajax-компонент AControllerable, обработчик AjaxProcessor, Builder, фильтры прав Actions\Normal и Actions\Free. Брать, когда надо понять или починить существующий компонент или ajax. Чтобы создать компонент — shef-new-component, добавить ajax — shef-new-ajax-action, подключить компонент из кода — shef-use-component.
---

# Компоненты и ajax

Модуль даёт три точки входа: обычный компонент, компонент с ajax и ajax без
компонента. Плюс `Builder` — подключение компонента из кода.

## Обычный компонент

`\Shef\Options\Components\AComponent` — наследник `\CBitrixComponent` с
готовым жизненным циклом. Вам остаются `process()` и `getModulesList()` —
второй абстрактный, его требует трейт `\Shef\Options\TraitList\Modules`.
Всё остальное — переопределяемые крючки.

```php
use Shef\Options\Components\AComponent;

class ShefDemoListComponent extends AComponent
{
    protected static function getModulesList(): array
    {
        return ['shef.options'];
    }

    protected function initParams(): void
    {
        $this->arParams['COUNT'] = (int)($this->arParams['COUNT'] ?? 20);
    }

    protected function checkRequiredParams(): void
    {
        if($this->arParams['COUNT'] < 1)
        {
            $this->addError(new \Bitrix\Main\Error('COUNT должен быть больше нуля'));
        }
    }

    protected function process(): void
    {
        $this->arResult['ITEMS'] = [];
    }
}
```

Порядок в `executeComponent()` — и он важен:

1. `initErrorCollection()` — коллекция ошибок;
2. `initAutoloader()` — автозагрузка классов рядом с компонентом;
3. `includeModules()` — по списку `getModulesList()`; не загрузился — стоп;
4. `initParams()` — разбор `arParams`;
5. `checkRequiredParams()` — проверка; есть ошибки — стоп;
6. `setPageProperty()` — заголовок и свойства страницы;
7. `initResult()` — `arResult`;
8. `process()` — **ваш код**; есть ошибки — стоп;
9. `renderTemplate()` — шаблон.

На каждом «стоп» вызывается `printErrors()`, шаблон не подключается. Поэтому
ошибку надо **класть в коллекцию**, а не бросать исключение: иначе пользователь
увидит белый экран вместо текста.

`initParams()` обязан отработать **до** `initResult()` — на это в коде стоит
отдельная пометка.

## Компонент с ajax

`\Shef\Options\Components\AControllerable` — то же самое плюс контракт
`\Bitrix\Main\Engine\Contract\Controllerable`.

```php
class ShefDemoListComponent extends \Shef\Options\Components\AControllerable
{
    public function configureActions(): array
    {
        return [
            'load' => $this->getConfigureActionsDefFilter(),
        ];
    }

    public function loadAction(int $id): array
    {
        return ['id' => $id];
    }

    protected function process(): void {}
    protected static function getModulesList(): array { return ['shef.options']; }
}
```

`getConfigureActionsDefFilter()` возвращает `Actions\Normal::get()` — умолчания
ядра. Нужен доступ гостям — верните `Actions\Free::get()` **явно** в этом
действии.

## Ajax без компонента

`\Shef\Options\Components\AjaxProcessor` — наследник
`\Bitrix\Main\Engine\Controller`. Обязан объявить `getModulesList()` и
`getComponentName()`; модули подключаются в `init()`, а
`createComponentBuilder()` отдаёт готовый `Builder` на тот самый компонент.

Контроллер регистрируется в `.settings.php`, ключ `controllers`:

```php
'controllers' => [
    'value' => [
        'namespaces' => [
            '\\Shef\\Demo\\Ajax' => 'demo',
        ],
    ],
    'readonly' => true,
],
```

Namespace обязан соответствовать каталогу в `lib/` по соглашению
автозагрузки: `\Shef\Demo\Ajax` → `lib/ajax/`. Иначе ajax молча ответит 404.

## Фильтры прав — здесь легко потерять защиту

```php
\Shef\Options\Components\Actions\Normal::get()   // умолчания ядра
\Shef\Options\Components\Actions\Free::get()     // без аутентификации и csrf
```

**Ключ `prefilters` замещает умолчания ядра целиком, а не дополняет их.**
`\Bitrix\Main\Engine\Controller::buildFilters()` берёт свои умолчания
(`Authentication`, `HttpMethod`, `Csrf`) **только когда ключа `prefilters` в
конфигурации действия нет**. Напишете его даже с одним фильтром — базой станет
он, а аутентификация и csrf исчезнут молча.

Правят умолчания ключи `-prefilters` и `+prefilters`:

```php
return [
    '-prefilters' => [ActionFilter\Csrf::class],           // убрать
    '+prefilters' => [new ActionFilter\CloseSession()],    // добавить
];
```

На этом уже обожглись: `Actions\Normal` задавал голый `prefilters` с одним
`HttpMethod`, и класс с названием «обычная проверка прав» проверял **меньше**,
чем отсутствие конфигурации. Поэтому `Normal::get()` сейчас возвращает пустой
массив — это и есть умолчания ядра, и оно не отстанет, когда ядро добавит себе
фильтр.

**Права проверяйте и на показ кнопки, и в самом действии.** Адрес действия
виден в коде страницы и вызывается напрямую.

## Подключение компонента из кода

`\Shef\Options\Components\Builder` — вместо `$APPLICATION->IncludeComponent()`
с шестью аргументами:

```php
$component = (new Builder('shef.demo:list'))
    ->setTemplate('')
    ->addOptionCollection('SET_TITLE', 'Заголовок')
    ->setIsActive(true)
    ->setIsHideIcons(true)
;

$component->include();         // обычное подключение
$component->includeSlider();   // в слайдере
$component->includeSmart();    // выбрать само
$component->buildClass();      // только объект класса компонента, без вывода
```

`buildClass()` полезен, когда из кода нужна логика компонента, а не вывод:
получаете объект и зовёте его методы.

## Имена классов компонента

`Trait\ComponentNameTrait` выводит их из пути: `getSelfClass()`,
`getSelfNamespace()`, `getSelfClassWithNamespace()`, `getSelfAjaxClass()`.
`Trait\AutoloaderTrait` регистрирует автозагрузку файлов рядом с компонентом,
поэтому классы компонента можно раскладывать по своим файлам, не прописывая
их нигде.

## Проверка на портале

Каталог модуля браузеру недоступен — в поставке nginx закрывает
`/bitrix/modules/`. Фронт компонента живёт в шаблоне, а общие css и js модуль
раскладывает установщиком в `/bitrix/css` и `/bitrix/js`.

После правок фронта нужен Ctrl+F5 или сброс автокеширования: путь
`/bitrix/cache/js/s1/...` в консоли означает, что вы смотрите на кеш.

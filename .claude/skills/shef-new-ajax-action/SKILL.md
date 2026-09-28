---
name: shef-new-ajax-action
description: Добавить ajax-действие в любой модуль Битрикс на базе shef.options (свой вендор) — в компоненте через AControllerable или отдельным контроллером через AjaxProcessor, с фильтрами прав Actions\Normal и Actions\Free, регистрацией namespace в .settings.php и проверкой прав внутри действия. Брать на любую задачу «ajax-метод», «действие по кнопке», «запрос с фронта», «отменить/сохранить/удалить по ID» из админки или публичной части Битрикс24 либо БУС.
---

# Новое ajax-действие

Операция: добавить действие, которое вызывается из браузера. Два пути —
внутри компонента и отдельным контроллером; выбор зависит от того, нужен ли
действию контекст компонента.

Здесь же главная ловушка платформы, на которой этот модуль уже обжёгся.

## Путь 1: действие внутри компонента

Компонент наследует `\Shef\Options\Components\AControllerable` вместо
`AComponent` — это тот же жизненный цикл плюс контракт
`\Bitrix\Main\Engine\Contract\Controllerable`.

```php
// Как и у обычного компонента: наследование разбирается при чтении файла,
// поэтому модуль подключается до слова class, а не в жизненном цикле.
if(!\Bitrix\Main\Loader::includeModule('shef.options'))
{
    return;
}

class ShefDemoOrderListComponent extends \Shef\Options\Components\AControllerable
{
    public function configureActions(): array
    {
        return [
            'load' => $this->getConfigureActionsDefFilter(),
        ];
    }

    // Тип возврата ОБЯЗАН допускать null: действие, вернувшее ошибку,
    // возвращает именно его. Объявите `: array` — и отказ в доступе
    // свалится в TypeError вместо ответа с ошибкой.
    public function loadAction(int $id): null|array
    {
        // isAllowed() пишете вы: у базового класса такого метода нет.
        if(!$this->isAllowed($id))
        {
            $this->addError(new \Bitrix\Main\Error('Access Denied'));
            return null;
        }

        return ['id' => $id];
    }

    protected function process(): void {}
    protected static function getModulesList(): array { return ['shef.options']; }
}
```

Имя метода — `<действие>Action`, в `configureActions()` пишется без суффикса.
Из браузера зовётся `BX.ajax.runComponentAction`.

## Путь 2: отдельный контроллер

Файл контроллера начинается тем же стражем: `extends AjaxProcessor` — это
наследование, оно разбирается до выполнения `init()`.

`\Shef\Options\Components\AjaxProcessor` — наследник
`\Bitrix\Main\Engine\Controller`. Обязан объявить `getModulesList()` (модули
подключатся в `init()`) и `getComponentName()`; `createComponentBuilder()`
отдаст готовый `\Shef\Options\Components\Builder` на этот компонент, если
действию нужна его логика.

Namespace регистрируется в `.settings.php`:

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

**Namespace обязан соответствовать каталогу в `lib/`** по тому же соглашению,
что и автозагрузка: `\Shef\Demo\Ajax` → `lib/ajax/`, строчными. Промах здесь
не даёт ошибки — ajax просто молча отвечает 404. В `shef.options` это
закрыто тестом `tests/settings_test.php`, заведите такой же.

**Адрес действия собирайте `UrlManager`, а не строкой:**

```php
\Bitrix\Main\Engine\UrlManager::getInstance()->createByController(
    controller: $controller,
    action: 'load',
    params: [],
    absolute: true
);
```

Так делал сам модуль, пока у него был свой контроллер. Руками собранный
адрес разойдётся с префиксом из `.settings.php` в тот день, когда префикс
поменяют.

## Ловушка: `prefilters` замещает умолчания ядра

```php
\Shef\Options\Components\Actions\Normal::get()   // умолчания ядра
\Shef\Options\Components\Actions\Free::get()     // без аутентификации и csrf
```

`\Bitrix\Main\Engine\Controller::buildFilters()` берёт свои умолчания —
`Authentication`, `HttpMethod`, `Csrf` — **только когда ключа `prefilters` в
конфигурации действия нет вовсе**. Напишете его даже с одним фильтром, и
базой станет он: аутентификация и csrf исчезнут молча, ошибки не будет,
действие просто начнёт отвечать кому угодно.

```php
// ТАК НЕЛЬЗЯ: сняли с действия Authentication и Csrf, сами того не заметив
return ['prefilters' => [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]];

// ТАК: умолчания остаются, правим их
return [
    '-prefilters' => [ActionFilter\Csrf::class],
    '+prefilters' => [new ActionFilter\CloseSession()],
];
```

Именно на этом обжёгся `Actions\Normal`: класс с названием «обычная проверка
прав доступа» проверял **меньше**, чем отсутствие конфигурации. Поэтому
`Normal::get()` сейчас возвращает пустой массив — это и есть умолчания ядра,
и оно не отстанет, когда ядро добавит себе фильтр.

Гостям действие нужно — выбирайте `Free::get()` **явно и в этом действии**, а
не меняйте умолчание на весь класс.

## Права проверяются внутри действия

Спрятать кнопку мало: адрес действия виден в коде страницы и вызывается
напрямую. Проверка — в самом методе, первой строкой.

Для административных действий это право на модуль, тем же способом, каким
пускает к себе страница настроек:

```php
$APPLICATION->GetGroupRight('shef.demo') >= 'R'
```

## Проверка

1. Действие отвечает из браузера.
2. **Выйти из портала и позвать адрес действия напрямую** — должно прийти
   «требуется авторизация», а не данные. Это единственный способ поймать
   потерянный `Authentication`.
3. Позвать под пользователем без прав на модуль — отказ.
4. POST без `sessid` — отказ (если действие не для гостей).

## В конце

Оставьте отзыв о навыке — shef-feedback: что пригодилось (`helped`),
чего не хватило. Это последний шаг задачи, не пропускать.

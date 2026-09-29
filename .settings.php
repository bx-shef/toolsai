<?php declare(strict_types=1);

/**
 * Настраиваемые параметры модуля
 *
 * * requireModules -> обязательные модули линейки: autoload.php подключает их
 *   при каждой загрузке, установщик их удаление не пропустит
 * * requirePhpExt -> обязательные расширения PHP
 * * registerAutoLoadClasses -> авто подгрузка классов
 * * registerNamespace -> авто подгрузка чужих namespace
 * * options -> опции устанавливаемые через окружение
 * * installEvents -> события для установки
 * * installDir -> пути установки файлов
 * * controllers -> контроллеры для ajax
 *
 * Классы модуля (Shef\ToolsAi\...) в registerNamespace не нужны: ядро
 * отображает их в lib/ по соглашению, путь строчными. Чужих библиотек у
 * модуля нет.
 *
 * installDir пуст сознательно: публичных файлов модуль не копирует, а
 * ПИШЕТ — заглушки эндпоинта и страницы расхода с путём туда, где модуль
 * стоит на самом деле (Main\PublicPage).
 *
 * ai и crm — не здесь, а в NEED_MODULES установщика: без них модуль не
 * ставится, но и не роняет чужую страницу, если их когда-нибудь снимут.
 */

return [
	'requireModules' => [
		'value' => [
			'shef.options',
			'shef.problems',
		],
		'readonly' => true,
	],
	'requirePhpExt' => [
		'value' => [
			'mbstring',
			'json',
		],
		'readonly' => true,
	],
	'registerAutoLoadClasses' => [
		'value' => [],
		'readonly' => true,
	],
	'registerNamespace' => [
		'value' => [],
		'readonly' => true,
	],
	'options' => [
		'value' => [],
		'readonly' => true,
	],
	'installEvents' => [
		'value' => [],
		'readonly' => true,
	],
	'installDir' => [
		'value' => [],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'namespaces' => [],
		],
		'readonly' => true,
	],
];

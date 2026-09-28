<?php declare(strict_types=1);

/**
 * Примеры обязаны работать — иначе это не примеры, а рассказ о них.
 *
 * Пример устаревает молча: код рядом переименовали, а в примере осталось
 * старое имя. Прочитавший его повторит и получит fatal, а мы об этом узнаем
 * от него же. Поэтому каждый пример здесь ЗАПУСКАЕТСЯ.
 *
 * Проверяется:
 *
 * 1. пример отрабатывает с нулевым кодом возврата. Внутри он сверяет свои
 *    обещания через check() из examples/_bootstrap.php: расхождение — это
 *    ненулевой код, а не строчка в выводе;
 * 2. в выводе нет ни одного warning, notice и deprecated. Пример, засоряющий
 *    лог портала, примером быть не может;
 * 3. пример дошёл до конца — напечатал «ГОТОВО: <имя>»;
 * 4. у примера есть инструкция: цель, где применять, что должно получиться и
 *    как запустить. Без неё пример читать нечем.
 *
 * Гоняются примеры в режиме заглушек (DOCUMENT_ROOT пуст). На живом портале
 * те же файлы запускаются с DOCUMENT_ROOT — это отдельная проверка руками,
 * тестами её не заменить.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

/** Разделы, которые обязана содержать шапка примера. */
const REQUIRED_SECTIONS = [
	'ЦЕЛЬ',
	'ГДЕ ПРИМЕНЯТЬ',
	'ЧТО ДОЛЖНО ПОЛУЧИТЬСЯ',
	'ЗАПУСК',
];

$examples = [];

foreach(glob($root.'/examples/*.php') ?: [] as $path)
{
	$name = basename($path, '.php');

	// Обвязка примером не является: она их запускает.
	if(str_starts_with($name, '_'))
	{
		continue;
	}

	$examples[$name] = $path;
}

ksort($examples);

Check::group('состав');

Check::same('примеры нашлись', count($examples) > 0, true);
Check::same('обвязка на месте', is_file($root.'/examples/_bootstrap.php'), true);
Check::same('инструкция на месте', is_file($root.'/examples/README.md'), true);

Check::group('инструкция у каждого примера');

$withoutSection = [];

foreach($examples as $name => $path)
{
	$text = file_get_contents($path);

	foreach(REQUIRED_SECTIONS as $section)
	{
		if(!str_contains($text, $section))
		{
			$withoutSection[] = sprintf('%s: нет раздела «%s»', $name, $section);
		}
	}
}

Check::same('в шапке есть цель, применение, результат и запуск', $withoutSection, []);

Check::group('прогон');

foreach($examples as $name => $path)
{
	// DOCUMENT_ROOT пуст намеренно: гоняем на заглушках, а не на портале.
	$command = sprintf(
		'DOCUMENT_ROOT= %s %s 2>&1',
		escapeshellarg(PHP_BINARY),
		escapeshellarg($path)
	);

	$output = [];
	$code = 0;
	exec($command, $output, $code);

	$text = implode(PHP_EOL, $output);

	Check::same($name.': код возврата', $code, 0);
	Check::same($name.': дошёл до конца', str_contains($text, 'ГОТОВО: '.$name), true);

	$noise = array_values(array_filter(
		$output,
		static fn(string $line): bool => (bool)preg_match('/\b(Warning|Notice|Deprecated|Fatal error)\b/u', $line)
	));

	Check::same($name.': без warning и notice', $noise, []);

	// На случай, если пример когда-нибудь перестанет возвращать код возврата.
	$failures = array_values(array_filter(
		$output,
		static fn(string $line): bool => str_contains($line, 'FAIL')
	));

	Check::same($name.': обещания сошлись', $failures, []);
}

Check::finish();

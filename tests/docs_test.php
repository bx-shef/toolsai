<?php declare(strict_types=1);

/**
 * Документация: ссылки ведут куда обещают, а классы в ней существуют.
 *
 * Документация сведена в репозиторий, и это её единственный дом — значит, врать
 * она может только тихо. Битую ссылку видно лишь тому, кто по ней щёлкнул;
 * переименованный класс в таблице выглядит ровно так же убедительно, как
 * настоящий. Ни то, ни другое не ловится ни php -l, ни глазами в PR: правишь
 * код в одном файле, а разъезжается он с документом в другом.
 *
 * Проверяется:
 *
 * 1. относительная ссылка из любого *.md ведёт в существующий файл;
 * 2. ссылка на GitHub вида blob/main/<путь> — тоже (адрес в репозитории есть);
 * 3. класс в первой колонке таблицы существует. Соглашение документов: H1
 *    объявляет базовый namespace — «# [`\Shef\ToolsAi\Completion`] …», — а
 *    ячейки пишутся относительно него ЛИБО относительно его родителя, как
 *    сложилось исторически. Принимаем оба, потому что промах виден и так:
 *    ошибочное имя не сходится ни с одним;
 * 4. FQCN в обратных кавычках существует, а если указан метод — существует и он.
 *
 * CHANGELOG.md и CLAUDE.md из проверок 3 и 4 исключены СОЗНАТЕЛЬНО: первый
 * описывает прошлое, второй — решения владельца, и оба обязаны называть
 * удалённые классы по имени. Ссылки в них проверяются как везде.
 *
 * Навыки в .claude/skills/ — копия из shef.options и проверяются как все
 * документы: \Shef\Options\... там сверяет источник, а \Shef\ToolsAi\...
 * — этот тест. Навык, рассказывающий про класс этого модуля, которого нет,
 * покраснеет здесь.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

const VENDOR_PREFIX = 'Shef\\ToolsAi\\';

/** Только то, что под контролем git: черновики рядом с репозиторием не считаются. */
exec('git -c core.quotePath=false ls-files "*.md"', $markdown, $code);

// vendor/ — чужие документы (README и CHANGELOG Monolog), со своими ссылками
// на файлы, которых в копии нет и быть не должно: копируется только src/.
$markdown = array_values(array_filter(
	$markdown,
	static fn(string $file): bool => !str_starts_with($file, 'vendor/')
));

Check::group('файлы документации');

Check::same('git отдал список файлов', $code, 0);
Check::same('документов нашлось больше пяти', count($markdown) > 5, true);

/** FQCN -> путь файла по тому же соглашению, что и автозагрузка. */
$toPath = static function(string $fqcn): string
{
	return 'lib/'.mb_strtolower(str_replace('\\', '/', mb_substr($fqcn, mb_strlen(VENDOR_PREFIX))));
};

/**
 * Файл класса, каталог namespace либо null.
 */
$resolve = static function(string $fqcn) use ($root, $toPath): null|string
{
	if(!str_starts_with($fqcn, VENDOR_PREFIX))
	{
		return null;
	}

	$path = $toPath($fqcn);

	if(is_file($root.'/'.$path.'.php'))
	{
		return $path.'.php';
	}

	if(is_dir($root.'/'.$path))
	{
		return $path.'/';
	}

	return null;
};

/**
 * Метод, константа или случай enum объявлены в файле?
 *
 * После «::» в документации этого модуля стоит не только метод:
 * `\Shef\ToolsAi\Main\Constants::MODULE_ID` — константа. Промах виден
 * одинаково: имени нет.
 */
$hasMethod = static function(string $file, string $method) use ($root): bool
{
	$name = preg_quote($method, '/');

	return 1 === preg_match(
		'/(?:function\s+'.$name.'\s*\(|\bcase\s+'.$name.'\b|\bconst\s+'.$name.'\b)/i',
		file_get_contents($root.'/'.$file)
	);
};

Check::group('ссылки');

$brokenLinks = [];
$brokenGithub = [];
$links = 0;

foreach($markdown as $file)
{
	$text = file_get_contents($root.'/'.$file);
	$dir = dirname($file);

	preg_match_all('/\]\(([^)\s]+)\)/u', $text, $matches);

	foreach($matches[1] as $link)
	{
		$links++;

		// Якорь внутри страницы.
		if(str_starts_with($link, '#'))
		{
			continue;
		}

		// Ссылка на GitHub на файл этого же репозитория: адрес обязан
		// существовать здесь, иначе на сайте будет 404.
		if(preg_match('#^https://github\.com/bx-shef/toolsai/blob/[^/]+/(.+)$#', $link, $github))
		{
			$target = preg_replace('/#.*$/', '', $github[1]);

			if(!file_exists($root.'/'.$target))
			{
				$brokenGithub[] = sprintf('%s -> %s', $file, $link);
			}

			continue;
		}

		// Прочая внешняя ссылка — не наше дело.
		if(preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//)#i', $link))
		{
			continue;
		}

		$target = preg_replace('/#.*$/', '', $link);
		$path = ('.' === $dir ? $target : $dir.'/'.$target);

		if(false === realpath($root.'/'.$path))
		{
			$brokenLinks[] = sprintf('%s -> %s', $file, $link);
		}
	}
}

// Сторож на случай, если разбор перестанет находить что-либо вовсе.
Check::same('ссылок разобрано больше двадцати', $links > 20, true);
Check::same('каждая относительная ссылка ведёт в существующий файл', $brokenLinks, []);
Check::same('каждая ссылка на GitHub ведёт в файл репозитория', $brokenGithub, []);

Check::group('классы в документации');

/** Прошлое и решения владельца обязаны называть удалённое по имени. */
$historical = ['CHANGELOG.md', 'CLAUDE.md'];

$brokenClasses = [];
$brokenMethods = [];
$checked = 0;

foreach($markdown as $file)
{
	if(in_array($file, $historical, true))
	{
		continue;
	}

	$text = file_get_contents($root.'/'.$file);

	// 4. FQCN в обратных кавычках, с методом или без.
	//
	// Скобки после имени метода необязательны и в имя не входят: в тексте
	// метод пишут и как `Класс::метод`, и как `Класс::метод()`. Вторая форма
	// встречается чаще, и пока её не разбирали, шесть упоминаний в навыках и
	// документации не проверялись вовсе.
	preg_match_all(
		'/`\\\\?('.preg_quote(VENDOR_PREFIX, '/').'[A-Za-z0-9_\\\\]+(?:::[A-Za-z0-9_]+)?)(?:\(\))?`/u',
		$text,
		$matches
	);

	foreach(array_unique($matches[1]) as $reference)
	{
		[$class, $method] = array_pad(explode('::', $reference, 2), 2, null);
		$checked++;

		$found = $resolve($class);

		if(null === $found)
		{
			$brokenClasses[] = sprintf('%s :: %s', $file, $reference);
			continue;
		}

		if(null !== $method && str_ends_with($found, '.php') && !$hasMethod($found, $method))
		{
			$brokenMethods[] = sprintf('%s :: %s — метода нет в %s', $file, $reference, $found);
		}
	}

	// 3. Первая колонка таблицы — относительно базы из H1 или её родителя.
	if(!preg_match('/^#\s+\[`(\\\\?[A-Za-z0-9_\\\\]+)`\]/mu', $text, $heading))
	{
		continue;
	}

	$base = ltrim($heading[1], '\\');

	if(!str_starts_with($base, VENDOR_PREFIX) && $base !== rtrim(VENDOR_PREFIX, '\\'))
	{
		continue;
	}

	$parent = implode('\\', array_slice(explode('\\', $base), 0, -1));

	preg_match_all(
		'/^\|\s*(?:\*\*)?(?:\(enum\) )?([A-Z][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)*(?:::[A-Za-z0-9_]+)?)(?:\*\*)?\s*\|/mu',
		$text,
		$rows
	);

	foreach(array_unique($rows[1]) as $cell)
	{
		[$class, $method] = array_pad(explode('::', $cell, 2), 2, null);
		$checked++;

		$found = null;
		$tried = [];

		foreach([$base, $parent] as $candidate)
		{
			$fqcn = $candidate.'\\'.$class;
			$tried[] = $fqcn;
			$found = $resolve($fqcn);

			if(null !== $found)
			{
				break;
			}
		}

		if(null === $found)
		{
			$brokenClasses[] = sprintf('%s :: %s (пробовал %s)', $file, $cell, implode(', ', $tried));
			continue;
		}

		if(null !== $method && str_ends_with($found, '.php') && !$hasMethod($found, $method))
		{
			$brokenMethods[] = sprintf('%s :: %s — метода нет в %s', $file, $cell, $found);
		}
	}
}

// Тот же сторож: пустая выборка не должна выглядеть успехом.
Check::same('упоминаний классов разобрано больше двадцати', $checked > 20, true);
Check::same('каждый класс из документации существует', $brokenClasses, []);
Check::same('каждый метод из документации существует', $brokenMethods, []);

Check::finish();

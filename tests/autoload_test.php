<?php declare(strict_types=1);

/**
 * Соглашение автозагрузки: класс обязан лежать там, где его будет искать ядро.
 *
 * \Bitrix\Main\Loader отображает Shef\ToolsAi\Main\Setup в
 * bitrix/modules/shef.toolsai/lib/main/setup.php: первые два сегмента
 * namespace — идентификатор модуля, остальные — путь СТРОЧНЫМИ. Настройки у
 * этого отображения нет, registerNamespace модуля пуст, и
 * починить промах в одном файле нечем.
 *
 * Промах не виден ни php -l, ни глазами в PR: файл лежит, класс объявлен,
 * синтаксис верный. Виден он только на портале и только в момент, когда
 * этот класс кому-то понадобится, — «Class not found» посреди работы.
 *
 * Отдельно ловим одинаковые FQCN в разных файлах: такой класс автозагрузка
 * берёт из одного файла, а на портал едут оба. В shef.options так жил целый
 * каталог lib/main/oldoptions/.
 *
 * Ядро тут не нужно: файлы не подключаются, а разбираются токенайзером —
 * подключить их все разом всё равно нельзя, половина требует Битрикс.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

/** Первые два сегмента namespace — идентификатор модуля. */
const VENDOR_PREFIX = 'Shef\\ToolsAi\\';

/**
 * Объявления верхнего уровня в файле: namespace и имена классов.
 *
 * Токенайзер, а не регулярное выражение: «class» встречается и в `::class`,
 * и в `new class`, и внутри строки — там это не объявление.
 *
 * @return array{namespace: string, names: string[]}
 */
$declarations = static function(string $file): array
{
	$tokens = token_get_all(file_get_contents($file));
	$total = count($tokens);
	$namespace = '';
	$names = [];

	/** Ближайший значащий токен слева. */
	$before = static function(int $i) use ($tokens): mixed
	{
		for($j = $i - 1; $j >= 0; $j--)
		{
			if(is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
			{
				continue;
			}

			return $tokens[$j];
		}

		return null;
	};

	for($i = 0; $i < $total; $i++)
	{
		$token = $tokens[$i];

		if(!is_array($token))
		{
			continue;
		}

		if(T_NAMESPACE === $token[0])
		{
			for($j = $i + 1; $j < $total; $j++)
			{
				if(is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true))
				{
					$namespace = $tokens[$j][1];
					break;
				}

				if(';' === $tokens[$j] || '{' === $tokens[$j])
				{
					break;
				}
			}

			continue;
		}

		if(!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true))
		{
			continue;
		}

		$previous = $before($i);

		// Foo::class — обращение, а не объявление.
		if(is_array($previous) && T_DOUBLE_COLON === $previous[0])
		{
			continue;
		}

		// new class {...} — анонимный класс, имени у него нет.
		if(is_array($previous) && T_NEW === $previous[0])
		{
			continue;
		}

		for($j = $i + 1; $j < $total; $j++)
		{
			if(is_array($tokens[$j]) && T_STRING === $tokens[$j][0])
			{
				$names[] = $tokens[$j][1];
				break;
			}

			if(is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0])
			{
				continue;
			}

			break;
		}
	}

	return ['namespace' => $namespace, 'names' => $names];
};

/** Путь, по которому ядро будет искать класс. */
$expectedPath = static function(string $fqcn): string
{
	return 'lib/'.mb_strtolower(str_replace('\\', '/', mb_substr($fqcn, mb_strlen(VENDOR_PREFIX)))).'.php';
};

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/lib'));
foreach($iterator as $file)
{
	if($file->isDir() || 'php' !== $file->getExtension())
	{
		continue;
	}

	$files[] = mb_substr($file->getPathname(), mb_strlen($root) + 1);
}

sort($files);

Check::group('соглашение автозагрузки');

Check::same('файлы в lib/ нашлись', count($files) > 0, true);

$misplaced = [];
$foreign = [];
$byFqcn = [];

foreach($files as $path)
{
	$found = $declarations($root.'/'.$path);

	foreach($found['names'] as $name)
	{
		$fqcn = $found['namespace'].'\\'.$name;

		if(!str_starts_with($fqcn, VENDOR_PREFIX))
		{
			$foreign[] = $path.' => '.$fqcn;
			continue;
		}

		$byFqcn[$fqcn][] = $path;

		$expected = $expectedPath($fqcn);
		if($expected !== $path)
		{
			$misplaced[] = sprintf('%s объявляет %s, ядро ищет его в %s', $path, $fqcn, $expected);
		}
	}
}

Check::same('классов разобрано больше тридцати', count($byFqcn) > 30, true);
Check::same('каждый класс лежит там, где его ищет ядро', $misplaced, []);
Check::same('чужих namespace в lib/ нет', $foreign, []);

$duplicates = [];
foreach($byFqcn as $fqcn => $paths)
{
	if(count($paths) > 1)
	{
		$duplicates[] = $fqcn.' объявлен в: '.implode(', ', $paths);
	}
}

Check::same('один класс — один файл', $duplicates, []);

Check::finish();

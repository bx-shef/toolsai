<?php declare(strict_types=1);

/**
 * Skills: агент читает их вместо того, чтобы разбираться в модуле заново.
 *
 * Skill подключается по описанию: модель сравнивает задачу с полем
 * description и решает, брать ли файл. Поэтому пустое или общее описание —
 * это не «менее удобно», а «skill не сработает никогда», и заметить это
 * нечем: ошибки нет, просто агент пишет своё.
 *
 * Здесь проверяется оформление: frontmatter на месте, имя совпадает с
 * каталогом, описание непустое и одной строкой. Содержимое проверяет
 * tests/docs_test.php — там же, где остальную документацию: каждый класс,
 * названный в skill, обязан существовать.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

$skills = glob($root.'/.claude/skills/*/SKILL.md') ?: [];
sort($skills);

Check::group('состав');

Check::same('skills нашлись', count($skills) > 0, true);

/**
 * Разбор frontmatter: три дефиса, строки «ключ: значение», три дефиса.
 * Полноценный YAML здесь не нужен и не заводится — лишняя зависимость ради
 * двух полей.
 *
 * @return array<string, string>
 */
$frontmatter = static function(string $text): array
{
	if(!preg_match('/\A---\R(.*?)\R---\R/su', $text, $matches))
	{
		return [];
	}

	$fields = [];

	foreach(preg_split('/\R/u', $matches[1]) ?: [] as $line)
	{
		if(!preg_match('/^([a-z_]+):\s*(.+)$/u', $line, $pair))
		{
			continue;
		}

		$fields[$pair[1]] = trim($pair[2]);
	}

	return $fields;
};

Check::group('оформление');

$broken = [];
$names = [];

foreach($skills as $path)
{
	$directory = basename(dirname($path));
	$text = file_get_contents($path);
	$fields = $frontmatter($text);

	if(empty($fields))
	{
		$broken[] = $directory.': нет frontmatter';
		continue;
	}

	$name = $fields['name'] ?? '';
	$description = $fields['description'] ?? '';

	if($name === '')
	{
		$broken[] = $directory.': нет поля name';
	}
	elseif($name !== $directory)
	{
		$broken[] = sprintf('%s: name = «%s», а каталог другой', $directory, $name);
	}
	elseif(1 !== preg_match('/^[a-z0-9-]+$/', $name))
	{
		$broken[] = sprintf('%s: name не из [a-z0-9-]', $directory);
	}
	else
	{
		$names[] = $name;
	}

	// Описание — единственное, по чему skill находят. Одной строкой:
	// перенос обрывает разбор frontmatter на полуслове.
	if($description === '')
	{
		$broken[] = $directory.': нет поля description';
	}
	elseif(mb_strlen($description) < 80)
	{
		$broken[] = sprintf('%s: описание короче 80 символов — по нему не выбрать', $directory);
	}
	elseif(mb_strlen($description) > 1024)
	{
		$broken[] = sprintf('%s: описание длиннее 1024 символов', $directory);
	}

	// Заголовок нужен человеку, открывшему файл.
	if(!preg_match('/^#\s+\S/mu', $text))
	{
		$broken[] = $directory.': нет заголовка';
	}
}

Check::same('frontmatter, имя и описание на месте', $broken, []);
Check::same('имена не повторяются', count($names), count(array_unique($names)));

Check::group('манифест');

/**
 * Манифест — список хешей, по которому соседние репозитории сверяют свою
 * копию навыков с источником. Правка навыка без пересборки манифеста делает
 * сверку бессмысленной: у получателя всё «совпадает», а навык уже другой.
 *
 * Поэтому здесь зовётся сам sync.sh — тот же, что поедет в копию.
 */
$sync = $root.'/.claude/skills/sync.sh';

Check::same('sync.sh на месте', is_file($sync), true);
Check::same('sync.sh исполняемый', is_executable($sync), true);
Check::same('манифест на месте', is_file($root.'/.claude/skills/MANIFEST'), true);

$output = [];
$code = 0;
exec(escapeshellarg($sync).' --check 2>&1', $output, $code);

if(0 !== $code)
{
	echo implode(PHP_EOL, $output), PHP_EOL;
}

Check::same('манифест сходится с навыками', $code, 0);

// Каждый навык обязан быть в манифесте: иначе получатель его не проверит.
// Манифестов два: MANIFEST — копия линейки из shef.options, LOCAL.MANIFEST —
// навыки про сам shef.toolsai (shef-new-ai-provider), их источник здесь.
$paths = static function(string $file): array
{
	$list = [];

	foreach(is_file($file) ? (file($file) ?: []) : [] as $line)
	{
		$line = trim($line);

		if('' === $line || str_starts_with($line, '#'))
		{
			continue;
		}

		$list[] = preg_split('/\s+/', $line, 2)[1] ?? '';
	}

	return $list;
};

$listed = $paths($root.'/.claude/skills/MANIFEST');
$local = $paths($root.'/.claude/skills/LOCAL.MANIFEST');

$missing = [];

foreach($skills as $path)
{
	$relative = basename(dirname($path)).'/SKILL.md';

	if(!in_array($relative, $listed, true) && !in_array($relative, $local, true))
	{
		$missing[] = $relative;
	}
}

Check::same('каждый навык перечислен в одном из манифестов', $missing, []);
Check::same('sync.sh перечислен в манифесте линейки', in_array('sync.sh', $listed, true), true);
Check::same('локальные навыки не пересекаются с навыками линейки', array_values(array_intersect($listed, $local)), []);

// Навыки про сам модуль: их классы проверяет docs_test.php этого
// репозитория, а наличие — здесь, чтобы раскладка не могла их потерять.
$own = ['shef-new-ai-provider'];
Check::same(
	'навыки shef.toolsai — локальные и на месте',
	array_values(array_filter($own, static fn(string $name): bool => !in_array($name.'/SKILL.md', $local, true))),
	[]
);

Check::finish();

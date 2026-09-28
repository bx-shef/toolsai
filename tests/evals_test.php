<?php declare(strict_types=1);

/**
 * Evals навыков: по каким фразам агент должен брать навык.
 *
 * У навыка может лежать evals/selection.json — список пар «фраза задачи →
 * ожидаемый навык». Их гоняет через модель `bitrixsh eval`: даёт модели
 * описания всех навыков и смотрит, какой она выбирает первым. Так ловится
 * главная поломка навыка — описание, по которому его не находят.
 *
 * Модель здесь не зовётся: это дорого и нестабильно для CI. Здесь только
 * оформление:
 *
 * * файл разбирается и это список;
 * * фраз не меньше трёх, каждая не короче десяти символов;
 * * ожидаемый навык существует либо это «<none>»;
 * * есть хотя бы одна фраза «на себя» и хотя бы одна «на соседа» или
 *   «<none>» — без отрицательных примеров eval ничего не ловит, модель
 *   просто всегда что-нибудь выбирает;
 * * одна и та же фраза в двух навыках не ждёт разных ответов — такая
 *   разметка противоречива, один из двух кейсов провалится всегда.
 *
 * Требования «на себя» и «на соседа» применяются к любому файлу evals, а не
 * только к операционным навыкам: файл завели — значит он должен что-то
 * проверять.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

// Каталог ровно один. Раньше здесь стояла ветка на .agents/skills — стоило
// такому каталогу появиться с любым SKILL.md, и тест переключался на него,
// переставая смотреть настоящие evals, оставаясь при этом зелёным. Ровно то,
// что в CLAUDE.md названо «пропущенная проверка выглядит как пройденная».
$skillsDir = $root.'/.claude/skills';
$skills = glob($skillsDir.'/*/SKILL.md') ?: [];
sort($skills);

$names = array_map(static fn(string $p): string => basename(dirname($p)), $skills);

Check::group('состав');

Check::same('skills нашлись', count($names) > 0, true);

$evals = [];

foreach($names as $name)
{
	$file = $skillsDir.'/'.$name.'/evals/selection.json';

	if(is_file($file))
	{
		$evals[$name] = $file;
	}
}

// Операционные навыки («сделай») обязаны иметь evals: по ним агент
// принимает решение писать код. Справочные — по желанию.
//
// Справочные это `shef-options-*`, всё остальное считается операционным.
// Раньше признаком была подстрока «-new-», и навык вне этого шаблона —
// например shef-feedback — молча оставался без обязательных evals, хотя
// README числит его операционным.
$operational = array_values(array_filter(
	$names,
	static fn(string $n): bool => !str_starts_with($n, 'shef-options-')
));
$withoutEvals = array_values(array_diff($operational, array_keys($evals)));

Check::same('у каждого операционного навыка есть evals/selection.json', $withoutEvals, []);

Check::group('оформление');

$broken = [];
$allInputs = [];

foreach($evals as $name => $file)
{
	$cases = json_decode((string)file_get_contents($file), true);

	if(!is_array($cases) || array_is_list($cases) === false)
	{
		$broken[] = $name.': selection.json — не список';
		continue;
	}

	if(count($cases) < 3)
	{
		$broken[] = $name.': меньше трёх фраз';
	}

	$self = 0;
	$other = 0;

	foreach($cases as $i => $case)
	{
		$input = is_array($case) ? ($case['input'] ?? '') : '';
		$expected = is_array($case) ? ($case['expected'] ?? '') : '';

		if(!is_string($input) || mb_strlen(trim($input)) < 10)
		{
			$broken[] = sprintf('%s[%d]: input пустой или короче 10 символов', $name, $i);
			continue;
		}

		// expected — строка либо список: одна и та же задача бывает решаема
		// двумя навыками, и оба ответа верные. Ждать ровно один значило бы
		// считать промахом то, что на стенде дало 5 из 5.
		$expectedList = is_array($expected) ? array_values($expected) : [$expected];

		if(empty($expectedList))
		{
			$broken[] = sprintf('%s[%d]: нет expected', $name, $i);
			continue;
		}

		$isBad = false;

		foreach($expectedList as $one)
		{
			if(!is_string($one) || $one === '')
			{
				$broken[] = sprintf('%s[%d]: expected содержит не строку', $name, $i);
				$isBad = true;
				break;
			}

			if($one !== '<none>' && !in_array($one, $names, true))
			{
				$broken[] = sprintf('%s[%d]: expected «%s» — такого навыка нет', $name, $i, $one);
				$isBad = true;
				break;
			}
		}

		if($isBad)
		{
			continue;
		}

		// «Взять навык X» и «не брать ничего» вместе не бывает: такая фраза
		// проходит при любом поведении модели и не проверяет ничего.
		if(count($expectedList) > 1 && in_array('<none>', $expectedList, true))
		{
			$broken[] = sprintf('%s[%d]: <none> вместе с именем навыка — фраза ничего не проверяет', $name, $i);
			continue;
		}

		if(count($expectedList) !== count(array_unique($expectedList)))
		{
			$broken[] = sprintf('%s[%d]: expected перечисляет навык дважды', $name, $i);
			continue;
		}

		if(in_array($name, $expectedList, true))
		{
			$self++;
		}
		else
		{
			$other++;
		}

		$key = mb_strtolower(trim($input));

		// Сверяем как множества: порядок в списке ничего не значит.
		$normalized = $expectedList;
		sort($normalized);

		if(isset($allInputs[$key]) && $allInputs[$key] !== $normalized)
		{
			$broken[] = sprintf(
				'%s[%d]: та же фраза в другом навыке ждёт «%s»',
				$name,
				$i,
				implode(', ', $allInputs[$key])
			);
		}

		$allInputs[$key] = $normalized;
	}

	if($self === 0)
	{
		$broken[] = $name.': нет ни одной фразы, где ожидается сам навык';
	}

	if($other === 0)
	{
		$broken[] = $name.': нет ни одной фразы на соседа или <none>';
	}
}

Check::same('evals разбираются и ссылаются на существующие навыки', $broken, []);

Check::finish();

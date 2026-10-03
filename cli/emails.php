<?php declare(strict_types=1);

/**
 * Письма руками — то, что делает агент писем, но сейчас и с отчётом.
 *
 *   php -f bitrix/modules/shef.toolsai/cli/emails.php              # кандидаты, как у агента
 *   ACTIVITIES=101,102 php -f .../cli/emails.php                   # только эти дела-письма
 *
 * Делает только функции, включённые флажками на вкладке «Письма» (резюме,
 * дела, оценка, контроль скорости ответа); ни одной — ничего. Квота, лимит
 * за прогон и окно по дням — как у агента. По списку дел окна и отсева
 * разобранных нет: письмо разбирается снова и пишет второй комментарий;
 * контроля скорости ответа по списку нет.
 */

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

if(php_sapi_name() !== 'cli')
{
	die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = (string)(getenv('DOCUMENT_ROOT') ?: realpath(__DIR__.'/../../../../'));

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Shef\ToolsAi\Agent\EmailAgent;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Main\OptionParser;

if(!Loader::includeModule('shef.toolsai') || !EmailAgent::includeModules())
{
	fwrite(STDERR, "Нужны модули shef.toolsai и crm\n");
	exit(1);
}

if(!Container::getConfig()->isEmailEnabled())
{
	fwrite(STDERR, "На вкладке «Письма» не включено ни одной функции — делать нечего\n");
	exit(1);
}

$only = null;
$activities = getenv('ACTIVITIES');
if(is_string($activities) && $activities !== '')
{
	$only = array_values(array_filter(array_map(
		static fn(string $id): int => OptionParser::id(trim($id)),
		explode(',', $activities)
	)));
}

// Под той же блокировкой и от того же служебного пользователя, что агент.
$report = EmailAgent::runLocked(static fn(): array => EmailAgent::process($only));
if($report === null)
{
	fwrite(STDERR, "Разбор писем уже идёт в другом процессе (агент или второй запуск)\n");
	exit(1);
}

printf("Строк отчёта: %d\n", count($report));
foreach($report as $row)
{
	printf(
		"  %-9s дело %-8d %-14s %s%s\n",
		match($row['kind'])
		{
			'incoming' => 'входящее',
			'review' => 'оценка',
			default => 'ответ',
		},
		$row['activity'],
		$row['owner'] !== '' ? $row['owner'] : '—',
		match($row['status'])
		{
			'DONE' => 'готово',
			'SKIPPED' => 'пропущено',
			'ERROR' => 'ОШИБКА',
			default => 'провайдер недоступен, повтор в следующий прогон',
		},
		$row['info'] !== '' ? ': '.$row['info'] : ''
	);
}

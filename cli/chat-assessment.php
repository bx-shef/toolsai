<?php declare(strict_types=1);

/**
 * Оценка чатов руками — то, что делает агент, но сейчас и с отчётом.
 *
 *   php -f bitrix/modules/shef.toolsai/cli/chat-assessment.php              # кандидаты, как у агента
 *   ACTIVITIES=101,102 php -f .../cli/chat-assessment.php                   # только эти дела открытых линий
 *
 * Настройка «Оценивать чаты по скрипту» здесь не проверяется — запуск руками
 * и есть решение. Квота, лимит за прогон, окно по дням и скрипт — как у
 * агента. По списку дел окна по дням и отсева оценённых нет: оценка
 * повторяется и перезаписывает строку (и пишет второй комментарий).
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
use Shef\ToolsAi\Agent\ChatAssessmentAgent;
use Shef\ToolsAi\Main\OptionParser;

if(!Loader::includeModule('shef.toolsai') || !ChatAssessmentAgent::includeModules())
{
	fwrite(STDERR, "Нужны модули shef.toolsai, crm, im и imopenlines\n");
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
$report = ChatAssessmentAgent::runLocked(static fn(): array => ChatAssessmentAgent::process($only));
if($report === null)
{
	fwrite(STDERR, "Оценка уже идёт в другом процессе (агент или второй запуск)\n");
	exit(1);
}

printf("Разобрано дел: %d\n", count($report));
foreach($report as $activityId => $row)
{
	printf(
		"  дело %-8d %-12s %s%s%s\n",
		$activityId,
		$row['owner'] !== '' ? $row['owner'] : '—',
		match($row['status'])
		{
			'DONE' => 'оценка '.($row['score'] !== null ? $row['score'].'%' : 'без процента'),
			'SKIPPED' => 'пропущен',
			'ERROR' => 'ОШИБКА',
			default => 'провайдер недоступен, повтор в следующий прогон',
		},
		$row['script'] > 0 ? ', скрипт #'.$row['script'] : '',
		$row['reason'] !== '' ? ': '.$row['reason'] : ''
	);
}

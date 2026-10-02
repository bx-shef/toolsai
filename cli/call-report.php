<?php declare(strict_types=1);

/**
 * Что стало с тестовым звонком — ТОЛЬКО ЧТЕНИЕ.
 *
 *   php -f bitrix/modules/shef.toolsai/cli/call-report.php
 *   ACTIVITY_ID=123 LIMIT=20 php -f .../cli/call-report.php
 *
 * Две стороны одного звонка:
 *   1) журнал расхода модуля (shef_toolsai_usage) — что пришло на эндпоинт,
 *      какой провайдер, статус, секунды/токены, деньги, ошибка;
 *   2) очередь заданий ИИ в CRM (b_crm_ai_queue, если есть) — что ядро
 *      поставило и чем закончилось. Колонки берутся те, что есть: схема
 *      таблицы зависит от версии crm.
 *
 * Почему звонок вообще не дошёл до модуля — гейты автозапуска:
 *   ACTIVITY_ID=123 php -f .../cli/ai-call-autostart-diag.php
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

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Shef\ToolsAi\Container;

if(!Loader::includeModule('shef.toolsai'))
{
	fwrite(STDERR, "Модуль shef.toolsai не установлен\n");
	exit(1);
}

$limit = max(1, min(100, (int)(getenv('LIMIT') ?: 10)));
$activityId = (int)(getenv('ACTIVITY_ID') ?: 0);

echo PHP_EOL.'1. Журнал расхода модуля, последние '.$limit.PHP_EOL;
$rows = Container::getMeter()->getLast($limit);
if($rows === [])
{
	echo "  пусто — до эндпоинта модуля ничего не доходило\n";
}
foreach($rows as $row)
{
	printf(
		"  #%-6d %s  %-5s %-7s %-14s units=%-6d %8.4f  %s\n",
		(int)$row['ID'],
		(string)$row['CREATED_AT'],
		(string)$row['CATEGORY'],
		(string)$row['PROVIDER_CODE'],
		(string)$row['STATUS'],
		(int)$row['UNITS'],
		(int)$row['COST_MICRO'] / 1_000_000,
		mb_substr((string)($row['ERROR'] ?? ''), 0, 120)
	);
}

$balance = Container::getMeter()->getMonthly();
printf(
	"  месяц: потрачено %s из %s\n",
	number_format($balance->spentMicro / 1_000_000, 4, '.', ' '),
	$balance->isUnlimited() ? 'без ограничения' : number_format($balance->limitMicro / 1_000_000, 2, '.', ' ')
);

echo PHP_EOL.'2. Очередь заданий ИИ в CRM'.($activityId > 0 ? ' по делу '.$activityId : '').PHP_EOL;
$connection = Application::getConnection();
$table = 'b_crm_ai_queue';
if(!$connection->isTableExists($table))
{
	echo "  таблицы $table нет на этой версии crm — смотрите лог crm.Integration.AI\n";
	exit(0);
}

$columns = array_keys($connection->getTableFields($table));
$wanted = array_values(array_intersect(
	['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID', 'PARENT_ID', 'TYPE_ID', 'TYPE', 'EXECUTION_STATUS', 'STATUS', 'ENGINE_CODE', 'ERROR_CODE', 'ERROR_MESSAGE', 'IS_MANUAL_LAUNCH', 'CREATED_TIME', 'UPDATED_TIME', 'CREATED_AT', 'UPDATED_AT'],
	$columns
));
$filterColumn = $activityId > 0 ? (array_values(array_intersect(['ENTITY_ID', 'ACTIVITY_ID'], $columns))[0] ?? null) : null;

$sql = 'SELECT '.implode(', ', $wanted ?: ['*']).' FROM '.$table
	.($filterColumn !== null ? ' WHERE '.$filterColumn.' = '.$activityId : '')
	.' ORDER BY ID DESC LIMIT '.$limit;
$result = $connection->query($sql);
$found = false;
while($row = $result->fetch())
{
	$found = true;
	$parts = [];
	foreach($row as $key => $value)
	{
		$parts[] = $key.'='.mb_substr((string)($value instanceof \DateTimeInterface || $value instanceof \Bitrix\Main\Type\Date ? $value->toString() : $value), 0, 80);
	}
	echo '  '.implode('  ', $parts).PHP_EOL;
}
if(!$found)
{
	echo $filterColumn !== null
		? "  по делу $activityId заданий нет — ядро звонок не взяло: ACTIVITY_ID=$activityId php -f cli/ai-call-autostart-diag.php\n"
		: "  пусто\n";
}
if($activityId > 0 && $filterColumn === 'ENTITY_ID')
{
	echo "  (фильтр по ENTITY_ID без типа: сделка или лид с тем же номером тоже попадут — смотрите ENTITY_TYPE_ID)\n";
}
if($activityId > 0 && $filterColumn === null)
{
	echo "  (колонки ENTITY_ID/ACTIVITY_ID нет — показаны последние задания без фильтра)\n";
}

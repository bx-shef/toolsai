<?php declare(strict_types=1);

/**
 * Страница «ИИ: статистика» — что ИИ сделал за период: звонки и очередь ИИ
 * CRM, анализ сделок, оценки звонков и что чаще всего не делают менеджеры;
 * с 1.6.0 — оценки чатов модулем и что не делают в чатах.
 *
 * Открывается заглушкой /bitrix/admin/shef_toolsai_stats.php
 * (Main\PublicPage). Только администратор, только чтение: здесь нет ни
 * одной записи в базу.
 *
 * Чистая логика — в Stats\*: период (строгий разбор GET), разбор RESULT
 * оценки звонка и подсчёт проваленных критериев; там же тесты.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use Shef\ToolsAi\Chat\Model\ChatAssessmentTable;
use Shef\ToolsAi\Deal\Model\DealCheckTable;
use Shef\ToolsAi\Deal\Model\DealProfileTable;
use Shef\ToolsAi\Stats\FailureCounter;
use Shef\ToolsAi\Stats\Period;
use Shef\ToolsAi\Stats\ScoreResult;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

/** @var \CMain $APPLICATION */
/** @var \CUser $USER */
global $APPLICATION, $USER;

if(!$USER->IsAdmin())
{
	$APPLICATION->AuthForm(Loc::getMessage('SH_TOOLSAI_STATS_ACCESS_DENIED'));
}

if(!Loader::includeModule('shef.toolsai') || !Loader::includeModule('crm'))
{
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(Loc::getMessage('SH_TOOLSAI_STATS_NO_MODULE'));
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
	die();
}

$request = Application::getInstance()->getContext()->getRequest();
$period = Period::fromRequest($request->getQuery('from'), $request->getQuery('to'), new \DateTimeImmutable());

$APPLICATION->SetTitle(Loc::getMessage('SH_TOOLSAI_STATS_TITLE'));
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';

$connection = Application::getConnection();
$helper = $connection->getSqlHelper();
$toDb = static fn(\DateTimeImmutable $date): string => $helper->convertToDbDateTime(
	new \Bitrix\Main\Type\DateTime($date->format('Y-m-d H:i:s'), 'Y-m-d H:i:s')
);
$from = $toDb($period->from);
$till = $toDb($period->till);
/** Условие «поле в периоде» — даты уже через SqlHelper. */
$in = static fn(string $field): string => '('.$field.' >= '.$from.' AND '.$field.' < '.$till.')';

$h = static fn(mixed $value): string => htmlspecialcharsbx((string)$value);
$msg = static fn(string $code): string => htmlspecialcharsbx((string)Loc::getMessage('SH_TOOLSAI_STATS_'.$code));
$fetchAll = static fn(string $sql): array => $connection->query($sql)->fetchAll();

$checkTable = DealCheckTable::getTableName();
$profileTable = DealProfileTable::getTableName();
$activityType = (int)\CCrmOwnerType::Activity;

// region Звонки ////
// STORAGE_ELEMENT_IDS пусто — к делу не приложен файл записи: расшифровывать
// ИИ нечего. Длительность не считаем — она есть только у своей телефонии
// (b_voximplant_statistic), а звонки приходят и от сторонней.
$calls = $connection->query('
	SELECT COUNT(*) AS CNT,
		SUM(CASE WHEN DIRECTION = 1 THEN 1 ELSE 0 END) AS IN_CNT,
		SUM(CASE WHEN DIRECTION = 2 THEN 1 ELSE 0 END) AS OUT_CNT,
		SUM(CASE WHEN STORAGE_ELEMENT_IDS IS NULL OR STORAGE_ELEMENT_IDS = \'\' OR STORAGE_ELEMENT_IDS = \'a:0:{}\' THEN 1 ELSE 0 END) AS NO_RECORD
	FROM b_crm_act
	WHERE TYPE_ID = 2 AND '.$in('CREATED')
)->fetch() ?: [];

$queueTypes = [1, 2, 3, 4, 9];
$queue = array_fill_keys($queueTypes, ['SUCCESS' => 0, 'ERROR' => 0]);
foreach($fetchAll('
	SELECT TYPE_ID, EXECUTION_STATUS, COUNT(*) AS CNT
	FROM b_crm_ai_queue
	WHERE TYPE_ID IN ('.implode(',', $queueTypes).') AND '.$in('CREATED_TIME').'
	GROUP BY TYPE_ID, EXECUTION_STATUS
') as $row)
{
	$status = (string)$row['EXECUTION_STATUS'];
	if(isset($queue[(int)$row['TYPE_ID']][$status]))
	{
		$queue[(int)$row['TYPE_ID']][$status] = (int)$row['CNT'];
	}
}
// endregion ////

// region Анализ сделок ////
$deals = $connection->query('
	SELECT COUNT(*) AS CNT,
		SUM(CASE WHEN SKIPPED = \'N\' THEN 1 ELSE 0 END) AS ANALYZED,
		SUM(CASE WHEN SKIPPED = \'Y\' THEN 1 ELSE 0 END) AS SKIPPED_CNT
	FROM '.$helper->quote($checkTable).'
	WHERE '.$in('CHECKED_AT')
)->fetch() ?: [];
$dealTodo = $connection->query('
	SELECT SUM(CASE WHEN '.$in('MANAGER_TODO_AT').' THEN 1 ELSE 0 END) AS TODO,
		SUM(CASE WHEN '.$in('ESCALATED_AT').' THEN 1 ELSE 0 END) AS ESC
	FROM '.$helper->quote($checkTable).'
	WHERE '.$in('MANAGER_TODO_AT').' OR '.$in('ESCALATED_AT')
)->fetch() ?: [];

// Дела старшему о просрочке (1.4.0) — столбца нет, пока таблицу не
// обновили (агент, «Проверить и включить»): тогда цифры нет.
$hasOverdue = array_key_exists('OVERDUE_NOTIFIED_AT', array_change_key_case((array)$connection->getTableFields($checkTable), CASE_UPPER));
$dealOverdue = $hasOverdue ? ($connection->query('
	SELECT COUNT(*) AS CNT FROM '.$helper->quote($checkTable).' WHERE '.$in('OVERDUE_NOTIFIED_AT')
)->fetch() ?: []) : null;

$dealGroup = static fn(string $groupBy, string $join): string => '
	SELECT '.$groupBy.' AS GRP,
		SUM(CASE WHEN '.$in('c.CHECKED_AT').' THEN 1 ELSE 0 END) AS CNT,
		AVG(CASE WHEN '.$in('c.CHECKED_AT').' AND c.SKIPPED = \'N\' THEN c.RISK END) AS AVG_RISK,
		SUM(CASE WHEN '.$in('c.MANAGER_TODO_AT').' THEN 1 ELSE 0 END) AS TODO,
		SUM(CASE WHEN '.$in('c.ESCALATED_AT').' THEN 1 ELSE 0 END) AS ESC
	FROM '.$helper->quote($checkTable).' c
	'.$join.'
	WHERE '.$in('c.CHECKED_AT').' OR '.$in('c.MANAGER_TODO_AT').' OR '.$in('c.ESCALATED_AT').'
	GROUP BY '.$groupBy.'
	ORDER BY CNT DESC';
$byManager = $fetchAll($dealGroup('d.ASSIGNED_BY_ID', 'INNER JOIN b_crm_deal d ON d.ID = c.DEAL_ID'));
$byProfile = $fetchAll($dealGroup('c.PROFILE_ID', ''));

$profileNames = [];
foreach($fetchAll('SELECT ID, TITLE FROM '.$helper->quote($profileTable)) as $row)
{
	$profileNames[(int)$row['ID']] = (string)$row['TITLE'];
}
// endregion ////

// region Оценки звонков ////
$assessments = $fetchAll('
	SELECT RATED_USER_ID AS GRP, COUNT(*) AS CNT, AVG(ASSESSMENT) AS AVG_SCORE, MIN(ASSESSMENT) AS MIN_SCORE
	FROM b_crm_ai_quality_assessment
	WHERE '.$in('CREATED_AT').'
	GROUP BY RATED_USER_ID
	ORDER BY AVG_SCORE ASC
');
// endregion ////

// region Что не делают менеджеры ////
// RESULT — Json::encode(ScoreCallPayload), разбор — Stats\ScoreResult.
$failures = new FailureCounter();
$result = $connection->query('
	SELECT q.RESULT, a.RESPONSIBLE_ID
	FROM b_crm_ai_queue q
	INNER JOIN b_crm_act a ON a.ID = q.ENTITY_ID
	WHERE q.TYPE_ID = 4 AND q.EXECUTION_STATUS = \'SUCCESS\' AND q.ENTITY_TYPE_ID = '.$activityType.' AND '.$in('q.CREATED_TIME')
);
while($row = $result->fetch())
{
	$failures->add((int)$row['RESPONSIBLE_ID'], ScoreResult::parseCriteria($row['RESULT']));
}
// endregion ////

// region Оценки чатов (1.6.0) ////
// Своя таблица модуля; пока «Проверить и включить» или агент её не
// создали — блоков нет. CRITERIA — {"criteria": [...]}, разбор тот же
// Stats\ScoreResult, подсчёт — тот же FailureCounter.
$chatTable = ChatAssessmentTable::getTableName();
$hasChats = $connection->isTableExists($chatTable);
$chatTotals = [];
$chatScores = [];
$chatFailures = new FailureCounter();
if($hasChats)
{
	foreach($fetchAll('
		SELECT STATUS, COUNT(*) AS CNT FROM '.$helper->quote($chatTable).'
		WHERE '.$in('CREATED_AT').'
		GROUP BY STATUS
	') as $row)
	{
		$chatTotals[(string)$row['STATUS']] = (int)$row['CNT'];
	}

	$chatScores = $fetchAll('
		SELECT RESPONSIBLE_ID AS GRP, COUNT(*) AS CNT, AVG(SCORE) AS AVG_SCORE, MIN(SCORE) AS MIN_SCORE
		FROM '.$helper->quote($chatTable).'
		WHERE STATUS = \''.ChatAssessmentTable::STATUS_DONE.'\' AND SCORE IS NOT NULL AND '.$in('CREATED_AT').'
		GROUP BY RESPONSIBLE_ID
		ORDER BY AVG_SCORE ASC
	');

	$result = $connection->query('
		SELECT CRITERIA, RESPONSIBLE_ID FROM '.$helper->quote($chatTable).'
		WHERE STATUS = \''.ChatAssessmentTable::STATUS_DONE.'\' AND '.$in('CREATED_AT')
	);
	while($row = $result->fetch())
	{
		$chatFailures->add((int)$row['RESPONSIBLE_ID'], ScoreResult::parseCriteria($row['CRITERIA']));
	}
}
// endregion ////

// Имена — одним запросом на всех.
$userIds = array_unique(array_filter(array_merge(
	array_map(static fn(array $row): int => (int)$row['GRP'], $byManager),
	array_map(static fn(array $row): int => (int)$row['GRP'], $assessments),
	$failures->getUserIds(),
	array_map(static fn(array $row): int => (int)$row['GRP'], $chatScores),
	$chatFailures->getUserIds()
)));
$userNames = [];
if($userIds !== [])
{
	foreach(UserTable::getList([
		'select' => ['ID', 'LAST_NAME', 'NAME', 'LOGIN'],
		'filter' => ['@ID' => array_values($userIds)],
	])->fetchAll() as $row)
	{
		$name = trim($row['LAST_NAME'].' '.$row['NAME']);
		$userNames[(int)$row['ID']] = $name !== '' ? $name : (string)$row['LOGIN'];
	}
}
$user = static fn(int $id): string => $userNames[$id] ?? ($id > 0 ? '#'.$id : '—');

$int = static fn(mixed $value): string => (string)(int)$value;
$avg = static fn(mixed $value): string => $value === null ? '—' : (string)(int)round((float)$value);
?>
<div class="adm-detail-content-wrap">
<div class="adm-detail-content">

<form method="get" action="<?=$h($APPLICATION->GetCurPage())?>">
	<input type="hidden" name="lang" value="<?=$h(LANGUAGE_ID)?>">
	<?=$msg('FROM')?> <input type="date" name="from" value="<?=$h($period->getFromValue())?>">
	<?=$msg('TO')?> <input type="date" name="to" value="<?=$h($period->getToValue())?>">
	<input type="submit" class="adm-btn" value="<?=$msg('SHOW')?>">
</form>

<h3><?=$msg('CALLS')?></h3>
<ul>
	<li><?=$msg('CALLS_TOTAL')?>: <b><?=$int($calls['CNT'] ?? 0)?></b>
		(<?=$msg('CALLS_IN')?>: <?=$int($calls['IN_CNT'] ?? 0)?>, <?=$msg('CALLS_OUT')?>: <?=$int($calls['OUT_CNT'] ?? 0)?>)</li>
	<li><?=$msg('CALLS_NO_RECORD')?>: <b><?=$int($calls['NO_RECORD'] ?? 0)?></b></li>
</ul>
<table class="adm-list-table">
	<thead>
		<tr class="adm-list-table-header">
			<td class="adm-list-table-cell"><?=$msg('COL_STEP')?></td>
			<td class="adm-list-table-cell"><?=$msg('COL_SUCCESS')?></td>
			<td class="adm-list-table-cell"><?=$msg('COL_ERROR')?></td>
		</tr>
	</thead>
	<tbody>
	<?php foreach($queue as $typeId => $row): ?>
		<tr class="adm-list-table-row">
			<td class="adm-list-table-cell"><?=$msg('QUEUE_'.$typeId)?></td>
			<td class="adm-list-table-cell"><?=$int($row['SUCCESS'])?></td>
			<td class="adm-list-table-cell"><?=$int($row['ERROR'])?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?=$msg('DEALS')?></h3>
<ul>
	<li><?=$msg('DEALS_CHECKED')?>: <b><?=$int($deals['CNT'] ?? 0)?></b>,
		<?=$msg('DEALS_ANALYZED')?>: <b><?=$int($deals['ANALYZED'] ?? 0)?></b>,
		<?=$msg('DEALS_SKIPPED')?>: <b><?=$int($deals['SKIPPED_CNT'] ?? 0)?></b></li>
	<li><?=$msg('DEALS_TODO')?>: <b><?=$int($dealTodo['TODO'] ?? 0)?></b>,
		<?=$msg('DEALS_ESC')?>: <b><?=$int($dealTodo['ESC'] ?? 0)?></b><?php if($dealOverdue !== null): ?>,
		<?=$msg('DEALS_OVERDUE')?>: <b><?=$int($dealOverdue['CNT'] ?? 0)?></b><?php endif; ?></li>
</ul>
<p style="color:#777"><?=$msg('DEALS_NOTE')?></p>
<?php foreach(['MANAGER' => $byManager, 'PROFILE' => $byProfile] as $kind => $rows): ?>
	<table class="adm-list-table" style="margin-bottom:10px">
		<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell"><?=$msg('COL_'.$kind)?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_DEALS')?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_AVG_RISK')?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_TODO')?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_ESC')?></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach($rows as $row): ?>
			<tr class="adm-list-table-row">
				<td class="adm-list-table-cell"><?=$h($kind === 'MANAGER'
					? $user((int)$row['GRP'])
					: ($profileNames[(int)$row['GRP']] ?? ((int)$row['GRP'] > 0 ? '#'.(int)$row['GRP'] : Loc::getMessage('SH_TOOLSAI_STATS_NO_PROFILE'))))?></td>
				<td class="adm-list-table-cell"><?=$int($row['CNT'])?></td>
				<td class="adm-list-table-cell"><?=$h($avg($row['AVG_RISK']))?></td>
				<td class="adm-list-table-cell"><?=$int($row['TODO'])?></td>
				<td class="adm-list-table-cell"><?=$int($row['ESC'])?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>

<h3><?=$msg('SCORES')?></h3>
<table class="adm-list-table">
	<thead>
		<tr class="adm-list-table-header">
			<td class="adm-list-table-cell"><?=$msg('COL_MANAGER')?></td>
			<td class="adm-list-table-cell"><?=$msg('COL_CALLS')?></td>
			<td class="adm-list-table-cell"><?=$msg('COL_AVG_SCORE')?></td>
			<td class="adm-list-table-cell"><?=$msg('COL_MIN_SCORE')?></td>
		</tr>
	</thead>
	<tbody>
	<?php foreach($assessments as $row): ?>
		<tr class="adm-list-table-row">
			<td class="adm-list-table-cell"><?=$h($user((int)$row['GRP']))?></td>
			<td class="adm-list-table-cell"><?=$int($row['CNT'])?></td>
			<td class="adm-list-table-cell"><?=$h($avg($row['AVG_SCORE']))?>%</td>
			<td class="adm-list-table-cell"><?=$h($avg($row['MIN_SCORE']))?>%</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?=$msg('FAILS')?></h3>
<p style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_STATS_FAILS_NOTE', ['#COUNT#' => $failures->getAssessed()]))?></p>
<ol>
	<?php foreach($failures->getTop(10) as $row): ?>
		<li><?=$h(FailureCounter::formatRow($row))?></li>
	<?php endforeach; ?>
</ol>
<?php foreach($failures->getUserIds() as $userId): ?>
	<?php $top = $failures->getTopByUser($userId, 5); if($top === []) continue; ?>
	<p><b><?=$h($user($userId))?></b>
		<?=$h(Loc::getMessage('SH_TOOLSAI_STATS_FAILS_USER', ['#COUNT#' => $failures->getAssessedByUser($userId)]))?></p>
	<ul>
		<?php foreach($top as $row): ?>
			<li><?=$h(FailureCounter::formatRow($row))?></li>
		<?php endforeach; ?>
	</ul>
<?php endforeach; ?>

<h3><?=$msg('CHAT_SCORES')?></h3>
<?php if(!$hasChats): ?>
	<p style="color:#777"><?=$msg('CHAT_NO_TABLE')?></p>
<?php else: ?>
	<ul>
		<li><?=$msg('CHAT_DONE')?>: <b><?=$int($chatTotals[ChatAssessmentTable::STATUS_DONE] ?? 0)?></b>,
			<?=$msg('CHAT_SKIPPED')?>: <b><?=$int($chatTotals[ChatAssessmentTable::STATUS_SKIPPED] ?? 0)?></b>,
			<?=$msg('CHAT_ERROR')?>: <b><?=$int($chatTotals[ChatAssessmentTable::STATUS_ERROR] ?? 0)?></b></li>
	</ul>
	<table class="adm-list-table">
		<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell"><?=$msg('COL_MANAGER')?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_CHATS')?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_AVG_SCORE')?></td>
				<td class="adm-list-table-cell"><?=$msg('COL_MIN_SCORE')?></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach($chatScores as $row): ?>
			<tr class="adm-list-table-row">
				<td class="adm-list-table-cell"><?=$h($user((int)$row['GRP']))?></td>
				<td class="adm-list-table-cell"><?=$int($row['CNT'])?></td>
				<td class="adm-list-table-cell"><?=$h($avg($row['AVG_SCORE']))?>%</td>
				<td class="adm-list-table-cell"><?=$h($avg($row['MIN_SCORE']))?>%</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?=$msg('CHAT_FAILS')?></h3>
	<p style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_STATS_CHAT_FAILS_NOTE', ['#COUNT#' => $chatFailures->getAssessed()]))?></p>
	<ol>
		<?php foreach($chatFailures->getTop(10) as $row): ?>
			<li><?=$h(FailureCounter::formatRow($row))?></li>
		<?php endforeach; ?>
	</ol>
	<?php foreach($chatFailures->getUserIds() as $userId): ?>
		<?php $top = $chatFailures->getTopByUser($userId, 5); if($top === []) continue; ?>
		<p><b><?=$h($user($userId))?></b>
			<?=$h(Loc::getMessage('SH_TOOLSAI_STATS_CHAT_FAILS_USER', ['#COUNT#' => $chatFailures->getAssessedByUser($userId)]))?></p>
		<ul>
			<?php foreach($top as $row): ?>
				<li><?=$h(FailureCounter::formatRow($row))?></li>
			<?php endforeach; ?>
		</ul>
	<?php endforeach; ?>
<?php endif; ?>

</div>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

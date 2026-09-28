<?php declare(strict_types=1);

/**
 * Страница «ИИ: расход и остаток».
 *
 * Та самая, которой у Битрикса для своего движка нет в принципе: промо-лимит
 * на коробке выключен, пакетов BaaS нет, а удалённая проверка отдаёт только
 * факт упора без числа (docs/00-research.md, раздел 4).
 *
 * Открывается заглушкой /bitrix/admin/shef_toolsai_quota.php (Main\PublicPage).
 * Здесь же кнопка «Проверить и включить» — Main\Setup::run(): токен,
 * заглушки, обход BaaS, движки, агент. Её жмут после установки и после
 * каждого обновления платформы.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Deal\Model\DealCheckTable;
use Shef\ToolsAi\Engine\Registrar;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\Setup;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

/** @var \CMain $APPLICATION */
/** @var \CUser $USER */
global $APPLICATION, $USER;

if(!$USER->IsAdmin())
{
	$APPLICATION->AuthForm(Loc::getMessage('SH_TOOLSAI_QUOTA_ACCESS_DENIED'));
}

if(!Loader::includeModule('shef.toolsai'))
{
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(Loc::getMessage('SH_TOOLSAI_QUOTA_NO_MODULE'));
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
	die();
}

// Права проверены и на показ, и на действие: кнопка ведёт POST сюда же.
$setupReport = null;
$request = Application::getInstance()->getContext()->getRequest();
if($request->isPost() && $request->getPost('setup') === 'Y' && check_bitrix_sessid())
{
	$setupReport = (new Setup(Container::getConfig()))->run(
		(string)Application::getDocumentRoot(),
		dirname(__DIR__)
	);
}

$APPLICATION->SetTitle(Loc::getMessage('SH_TOOLSAI_QUOTA_TITLE'));
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';

$meter = Container::getMeter();
$balance = $meter->getMonthly();
$breakdown = $meter->getBreakdown($balance->periodFrom);
$last = $meter->getLast(20);
$registrar = new Registrar();
$config = Container::getConfig();

$fmt = static fn(int $micro): string => number_format($micro / 1_000_000, 2, '.', ' ');
$h = static fn(mixed $value): string => htmlspecialcharsbx((string)$value);
?>
<div class="adm-detail-content-wrap">
<div class="adm-detail-content">

<?php if(is_array($setupReport)): ?>
	<h3><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_SETUP_RESULT'))?></h3>
	<table class="adm-list-table">
		<?php foreach($setupReport as $step => $row): ?>
			<tr class="adm-list-table-row">
				<td class="adm-list-table-cell"><?=$h($step)?></td>
				<td class="adm-list-table-cell" style="color:<?=$row['ok'] ? '#2e7d32' : '#c62828'?>">
					<?=$row['ok'] ? 'OK' : 'FAIL'?> — <?=$h($row['message'])?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
<?php endif; ?>

<h3><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_BALANCE'))?></h3>
<?php if($balance->isUnlimited()): ?>
	<p><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_UNLIMITED', [
		'#FROM#' => $balance->periodFrom->format('d.m.Y'),
		'#SPENT#' => $fmt($balance->spentMicro),
	]))?></p>
<?php else: ?>
	<ul>
		<li><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_PERIOD'))?>: <b><?=$h($balance->periodFrom->format('d.m.Y'))?></b></li>
		<li><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_LIMIT'))?>: <b><?=$fmt($balance->limitMicro)?></b></li>
		<li><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_SPENT'))?>: <b><?=$fmt($balance->spentMicro)?></b> (<?=$h($balance->getPercent())?>%)</li>
		<li><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_LEFT'))?>: <b><?=$fmt($balance->getLeftMicro())?></b></li>
	</ul>
<?php endif; ?>

<h3><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_BREAKDOWN'))?></h3>
<table class="adm-list-table">
	<thead>
		<tr class="adm-list-table-header">
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_KEY'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_COUNT'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_UNITS'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_COST'))?></td>
		</tr>
	</thead>
	<tbody>
	<?php foreach($breakdown as $key => $row): ?>
		<tr class="adm-list-table-row">
			<td class="adm-list-table-cell"><?=$h($key)?></td>
			<td class="adm-list-table-cell"><?=(int)$row['count']?></td>
			<td class="adm-list-table-cell"><?=(int)$row['units']?></td>
			<td class="adm-list-table-cell"><?=$fmt((int)$row['costMicro'])?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<p style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_UNITS_NOTE'))?></p>

<h3><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_LAST'))?></h3>
<table class="adm-list-table">
	<thead>
		<tr class="adm-list-table-header">
			<td class="adm-list-table-cell">ID</td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_TIME'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_KEY'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_UNITS'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_COST'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_ERROR'))?></td>
		</tr>
	</thead>
	<tbody>
	<?php foreach($last as $row): ?>
		<tr class="adm-list-table-row">
			<td class="adm-list-table-cell"><?=(int)$row['ID']?></td>
			<td class="adm-list-table-cell"><?=$h($row['CREATED_AT'])?></td>
			<td class="adm-list-table-cell"><?=$h($row['CATEGORY'].'/'.$row['PROVIDER_CODE'].'/'.$row['STATUS'])?></td>
			<td class="adm-list-table-cell"><?=(int)$row['UNITS']?></td>
			<td class="adm-list-table-cell"><?=$fmt((int)$row['COST_MICRO'])?></td>
			<td class="adm-list-table-cell"><?=$h(mb_substr((string)$row['ERROR'], 0, 200))?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h3><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_ENGINES'))?></h3>
<ul>
	<?php foreach(Constants::getCategoryList() as $category): ?>
		<li><?=$h($category)?>: <b><?=$h(Loc::getMessage($registrar->isRegistered($category) ? 'SH_TOOLSAI_QUOTA_ENGINE_ON' : 'SH_TOOLSAI_QUOTA_ENGINE_OFF'))?></b>,
			<?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_PROVIDER'))?>: <?=$h($config->getProviderCode($category))?></li>
	<?php endforeach; ?>
	<li><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_PUBLIC_URL'))?>: <b><?=$h($config->getPublicUrl() ?: '—')?></b></li>
</ul>

<form method="post" action="<?=$h($APPLICATION->GetCurPage())?>?lang=<?=$h(LANGUAGE_ID)?>">
	<?=bitrix_sessid_post()?>
	<input type="hidden" name="setup" value="Y">
	<input type="submit" class="adm-btn-save" value="<?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_SETUP'))?>">
	<span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_SETUP_NOTE'))?></span>
</form>

<h3><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_DEALS'))?></h3>
<table class="adm-list-table">
	<thead>
		<tr class="adm-list-table-header">
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_DEAL'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_TIME'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_RISK'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_WHY'))?></td>
			<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_QUOTA_COL_ESCALATED'))?></td>
		</tr>
	</thead>
	<tbody>
	<?php foreach(DealCheckTable::getList(['order' => ['CHECKED_AT' => 'DESC'], 'limit' => 20])->fetchAll() as $row): ?>
		<tr class="adm-list-table-row">
			<td class="adm-list-table-cell"><a href="/crm/deal/details/<?=(int)$row['DEAL_ID']?>/">#<?=(int)$row['DEAL_ID']?></a></td>
			<td class="adm-list-table-cell"><?=$h($row['CHECKED_AT'])?></td>
			<td class="adm-list-table-cell"><?=$row['SKIPPED'] === 'Y' ? '—' : (int)$row['RISK'].($row['NEED_SENIOR'] === 'Y' ? ' ⚠' : '')?></td>
			<td class="adm-list-table-cell"><?=$h($row['WHY'])?></td>
			<td class="adm-list-table-cell"><?=$h($row['ESCALATED_AT'] ?: '')?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

</div>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

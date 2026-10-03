<?php declare(strict_types=1);

/**
 * Страница «ИИ: профили анализа сделок».
 *
 * Профиль — направление сделки, типы клиента, свой промпт и шкала риска
 * (Deal\Profile, Model\DealProfileTable). Образец — скрипты речевой
 * аналитики CRM. Подробности — docs/02-deal-health.md.
 *
 * Открывается заглушкой /bitrix/admin/shef_toolsai_deal_profiles.php
 * (Main\PublicPage). Только администратор; изменения — POST с
 * check_bitrix_sessid(); всё, что выводится, — через htmlspecialcharsbx.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Shef\ToolsAi\Container;
use Shef\ToolsAi\Deal\ClientType;
use Shef\ToolsAi\Deal\HealthAnalyzer;
use Shef\ToolsAi\Deal\Model\DealProfileTable;
use Shef\ToolsAi\Deal\Profile;
use Shef\ToolsAi\Deal\ProfileMigration;
use Shef\ToolsAi\Main\Constants;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';

Loc::loadMessages(__FILE__);

/** @var \CMain $APPLICATION */
/** @var \CUser $USER */
global $APPLICATION, $USER;

if(!$USER->IsAdmin())
{
	$APPLICATION->AuthForm(Loc::getMessage('SH_TOOLSAI_PROFILES_ACCESS_DENIED'));
}

if(!Loader::includeModule('shef.toolsai') || !Loader::includeModule('crm'))
{
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage(Loc::getMessage('SH_TOOLSAI_PROFILES_NO_MODULE'));
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
	die();
}

$h = static fn(mixed $value): string => htmlspecialcharsbx((string)$value);
$self = Constants::DEAL_PROFILES_FILE.'?lang='.LANGUAGE_ID;

// Обновление без установщика: таблица и перенос старых настроек.
$migration = ProfileMigration::run(Container::getConfig());

$request = Application::getInstance()->getContext()->getRequest();
$errors = [];
$input = null;

if($request->isPost() && check_bitrix_sessid())
{
	$id = (int)$request->getPost('ID');

	if($request->getPost('delete') === 'Y' && $id > 0)
	{
		DealProfileTable::delete($id);
		LocalRedirect($self.'&deleted=Y');
	}

	if($request->getPost('save') === 'Y')
	{
		$input = $request->getPostList()->toArray();
		[$fields, $invalid] = Profile::fromInput($input);
		$errors = array_map(static fn(string $field): string => (string)Loc::getMessage('SH_TOOLSAI_PROFILES_ERROR_'.$field), $invalid);

		if($errors === [])
		{
			$result = $id > 0 ? DealProfileTable::update($id, $fields) : DealProfileTable::add($fields);
			if($result->isSuccess())
			{
				LocalRedirect($self.'&saved=Y');
			}
			$errors = $result->getErrorMessages();
		}
	}
}

$categories = [];
foreach(\Bitrix\Crm\Category\DealCategory::getAll(true) as $category)
{
	$categories[(int)$category['ID']] = (string)$category['NAME'];
}

$clientTypeNames = [];
foreach(ClientType::getAll() as $type)
{
	$clientTypeNames[$type] = (string)Loc::getMessage('SH_TOOLSAI_PROFILES_CLIENT_'.$type);
}

$edit = (string)$request->getQuery('edit');
$editing = null;
if($input !== null)
{
	$editing = Profile::fromRow(['ID' => $request->getPost('ID')] + $input);
}
elseif($edit === 'new')
{
	$editing = Profile::fromRow(['IS_ENABLED' => 'Y']);
}
elseif((int)$edit > 0)
{
	$row = DealProfileTable::getById((int)$edit)->fetch();
	$editing = is_array($row) ? Profile::fromRow($row) : null;
}

$APPLICATION->SetTitle(Loc::getMessage('SH_TOOLSAI_PROFILES_TITLE'));
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';

if(!$migration['ok'])
{
	\CAdminMessage::ShowMessage($h($migration['message']));
}
if($errors !== [])
{
	\CAdminMessage::ShowMessage(['MESSAGE' => $h(Loc::getMessage('SH_TOOLSAI_PROFILES_ERRORS')), 'DETAILS' => implode('<br>', array_map($h, $errors)), 'HTML' => true]);
}
if($request->getQuery('saved') === 'Y' || $request->getQuery('deleted') === 'Y')
{
	\CAdminMessage::ShowNote(Loc::getMessage($request->getQuery('saved') === 'Y' ? 'SH_TOOLSAI_PROFILES_SAVED' : 'SH_TOOLSAI_PROFILES_DELETED'));
}
?>
<div class="adm-detail-content-wrap">
<div class="adm-detail-content">

<p><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_INTRO'))?></p>

<?php if($editing === null): ?>
	<table class="adm-list-table">
		<thead>
			<tr class="adm-list-table-header">
				<td class="adm-list-table-cell">ID</td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_TITLE'))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_IS_ENABLED'))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_CATEGORY_ID'))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_CLIENT_TYPES'))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_COL_SCALE'))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_SENIOR_ID'))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_PROMPT'))?></td>
				<td class="adm-list-table-cell"></td>
			</tr>
		</thead>
		<tbody>
		<?php foreach(DealProfileTable::getProfiles() as $profile): ?>
			<tr class="adm-list-table-row">
				<td class="adm-list-table-cell"><?=(int)$profile->id?></td>
				<td class="adm-list-table-cell"><a href="<?=$h($self.'&edit='.$profile->id)?>"><?=$h($profile->title)?></a> <span style="color:#777">(<?=(int)$profile->sort?>)</span></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage($profile->enabled ? 'SH_TOOLSAI_PROFILES_YES' : 'SH_TOOLSAI_PROFILES_NO'))?></td>
				<td class="adm-list-table-cell"><?=$h($categories[$profile->categoryId] ?? '#'.$profile->categoryId)?></td>
				<td class="adm-list-table-cell"><?=$h($profile->isAnyClient()
					? Loc::getMessage('SH_TOOLSAI_PROFILES_CLIENT_ANY')
					: implode(', ', array_map(static fn(int $type): string => $clientTypeNames[$type] ?? (string)$type, $profile->clientTypes)))?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_SCALE', [
					'#LOW#' => $profile->lowBorder,
					'#HIGH#' => $profile->highBorder,
					'#IDLE#' => $profile->idleDays,
					'#REANALYZE#' => $profile->reanalyzeDays,
					'#ACTIVE#' => $profile->activeDays > 0 ? $profile->activeDays : '∞',
				]))?></td>
				<td class="adm-list-table-cell"><?=$profile->seniorId > 0 ? (int)$profile->seniorId : '—'?></td>
				<td class="adm-list-table-cell"><?=$h(Loc::getMessage($profile->prompt === '' ? 'SH_TOOLSAI_PROFILES_PROMPT_COMMON' : 'SH_TOOLSAI_PROFILES_PROMPT_OWN'))?></td>
				<td class="adm-list-table-cell">
					<form method="post" action="<?=$h($self)?>" onsubmit="return confirm(<?=$h(\CUtil::PhpToJSObject((string)Loc::getMessage('SH_TOOLSAI_PROFILES_DELETE_CONFIRM')))?>);">
						<?=bitrix_sessid_post()?>
						<input type="hidden" name="ID" value="<?=(int)$profile->id?>">
						<input type="hidden" name="delete" value="Y">
						<input type="submit" class="adm-btn" value="<?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_DELETE'))?>">
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><a class="adm-btn adm-btn-save" href="<?=$h($self.'&edit=new')?>"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_ADD'))?></a></p>
<?php else: ?>
	<form method="post" action="<?=$h($self)?>">
		<?=bitrix_sessid_post()?>
		<input type="hidden" name="save" value="Y">
		<input type="hidden" name="ID" value="<?=(int)$editing->id?>">
		<table class="adm-detail-content-table edit-table">
			<tr>
				<td class="adm-detail-content-cell-l" width="40%"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_TITLE'))?>:</td>
				<td class="adm-detail-content-cell-r"><input type="text" name="TITLE" size="50" maxlength="255" value="<?=$h($editing->title)?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_IS_ENABLED'))?>:</td>
				<td class="adm-detail-content-cell-r"><input type="checkbox" name="IS_ENABLED" value="Y"<?=$editing->enabled ? ' checked' : ''?>></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_SORT'))?>:</td>
				<td class="adm-detail-content-cell-r"><input type="text" name="SORT" size="6" value="<?=(int)$editing->sort?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_CATEGORY_ID'))?>:</td>
				<td class="adm-detail-content-cell-r">
					<select name="CATEGORY_ID">
						<?php foreach($categories as $categoryId => $name): ?>
							<option value="<?=(int)$categoryId?>"<?=$categoryId === $editing->categoryId ? ' selected' : ''?>><?=$h($name)?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_CLIENT_TYPES'))?>:<br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_CLIENT_TYPES_DESCR'))?></span></td>
				<td class="adm-detail-content-cell-r">
					<?php foreach($clientTypeNames as $type => $name): ?>
						<label><input type="checkbox" name="CLIENT_TYPES[]" value="<?=(int)$type?>"<?=in_array($type, $editing->clientTypes, true) ? ' checked' : ''?>> <?=$h($name)?></label><br>
					<?php endforeach; ?>
					<p style="color:#777;max-width:600px"><?=nl2br($h(Loc::getMessage('SH_TOOLSAI_PROFILES_CLIENT_HELP')))?></p>
				</td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_IDLE_DAYS'))?>:</td>
				<td class="adm-detail-content-cell-r"><input type="text" name="IDLE_DAYS" size="6" value="<?=(int)$editing->idleDays?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_ACTIVE_DAYS'))?>:<br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_ACTIVE_DAYS_DESCR'))?></span></td>
				<td class="adm-detail-content-cell-r"><input type="text" name="ACTIVE_DAYS" size="6" value="<?=(int)$editing->activeDays?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_REANALYZE_DAYS'))?>:</td>
				<td class="adm-detail-content-cell-r"><input type="text" name="REANALYZE_DAYS" size="6" value="<?=(int)$editing->reanalyzeDays?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_LOW_BORDER'))?>:<br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_LOW_BORDER_DESCR'))?></span></td>
				<td class="adm-detail-content-cell-r"><input type="text" name="LOW_BORDER" size="6" value="<?=(int)$editing->lowBorder?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_HIGH_BORDER'))?>:<br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_HIGH_BORDER_DESCR'))?></span></td>
				<td class="adm-detail-content-cell-r"><input type="text" name="HIGH_BORDER" size="6" value="<?=(int)$editing->highBorder?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_SENIOR_ID'))?>:<br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_SENIOR_ID_DESCR'))?></span></td>
				<td class="adm-detail-content-cell-r"><input type="text" name="SENIOR_ID" size="6" value="<?=$editing->seniorId > 0 ? (int)$editing->seniorId : ''?>"></td>
			</tr>
			<tr>
				<td class="adm-detail-content-cell-l"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_PROMPT'))?>:<br><span style="color:#777"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_F_PROMPT_DESCR'))?></span></td>
				<td class="adm-detail-content-cell-r"><textarea name="PROMPT" rows="16" cols="80" placeholder="<?=$h(HealthAnalyzer::getSystemPrompt())?>"><?=$h($editing->prompt)?></textarea></td>
			</tr>
		</table>
		<input type="submit" class="adm-btn-save" value="<?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_SAVE'))?>">
		<a class="adm-btn" href="<?=$h($self)?>"><?=$h(Loc::getMessage('SH_TOOLSAI_PROFILES_CANCEL'))?></a>
	</form>
<?php endif; ?>

</div>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

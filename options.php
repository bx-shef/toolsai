<?php declare(strict_types=1);

/**
 * @global string $Update
 * @global string $RestoreDefaults
 * @global string $mid
 */

use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Config;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Request;
use Bitrix\Main\UI\Extension;
use Shef\Options\Main\Options;
use Shef\Options\Main\Utils;

Loc::loadMessages(Application::getDocumentRoot().'/bitrix/modules/main/options.php');
Loc::loadMessages(__FILE__);

try
{
	// region Include Module shef.options ////
	if(!Loader::includeModule('shef.options'))
	{
		CAdminMessage::ShowMessage('Need install module shef.options');
		return;
	}
	
	Loc::loadMessages(Application::getDocumentRoot().'/bitrix/modules/shef.options/optionsconfig.php');
	// endregion ////
	
	// region include class ShOptionsConfig ////
	$configClass = Application::getDocumentRoot().'/bitrix/modules/shef.options/optionsconfig.php';
	if(!is_file($configClass))
	{
		CAdminMessage::ShowMessage('Not find file bitrix/modules/shef.options/optionsconfig.php');
		return;
	}
	
	require_once $configClass;
	// endregion ////
	
	// region Right ////
	$modulePerms = Utils::getCMainApplication()->GetGroupRight($mid);
	if($modulePerms < 'R')
	{
		CAdminMessage::ShowMessage('Wrong right');
		return;
	}
	// endregion ////
	
	// region Options ////
	$tabs = require __DIR__.'/options_conf.php';
	if($tabs instanceof Result)
	{
		if(!$tabs->isSuccess())
		{
			\CAdminMessage::ShowMessage([
				'HTML' => true,
				'TYPE' => 'ERROR',
				'MESSAGE' => Loc::getMessage('shef_options_fail', [
					'#MODULE#' => $mid
				]),
				'DETAILS' => '<ul><li>'.join(
					'</li><li>',
					$tabs->getErrorMessages()
				).'</li></ul>',
			]);
		}
		
		return;
	}
	elseif(!is_array($tabs))
	{
		CAdminMessage::ShowMessage('Wrong tabs at options_conf.php');
		return;
	}
	// endregion ////
	
	// region Test Shareware ////
	$response = Loader::includeSharewareModule($mid);
	if($response === Loader::MODULE_DEMO)
	{
		\CAdminMessage::ShowMessage([
			'HTML' => true,
			'TYPE' => 'OK',
			'MESSAGE' => Loc::getMessage('shef_trial', [
				'#MODULE#' => $mid
			]),
			'DETAILS' => Loc::getMessage('shef_trial_action', [
				'#MODULE#' => $mid
			]),
		]);
	}
	// endregion ////
	
	$tabControl = new CAdminTabControl('tabControl', Options\Tab::prepareListForBitrixAdminArray($tabs));
	
	// region Operations ////
	$request = Context::getCurrent()?->getRequest();
	if(!$request instanceof Request)
	{
		CAdminMessage::ShowMessage('Wrong request instance');
		return;
	}
	
	if(
		$request->isPost() && $Update.$RestoreDefaults <> '' && check_bitrix_sessid()
	)
	{
		if($modulePerms < 'W')
		{
			CAdminMessage::ShowMessage('Wrong right for save');
		}
		else
		{
			if($RestoreDefaults <> '')
			{
				Config\Option::delete($mid);
			}
			else
			{
				/** @var Options\Tab $tab */
				foreach($tabs as $tab)
				{
					if(!($tab instanceof Options\Tab))
					{
						continue;
					}
					
					/** @var Options\AOption $option */
					foreach($tab->getOptionList() as $option)
					{
						if(
							!($option instanceof Options\AOption) || $option instanceof Options\RowInfo
						)
						{
							continue;
						}
						
						$complexCode = $option->getComplexCode();
						$value = $request->getPost('param_'.$complexCode);
						
						if(
							$option instanceof Options\Checkbox && (string)$value !== $option->getValueY()
						)
						{
							$value = $option->getValueN();
						}
						
						if(is_array($value))
						{
							$value = serialize($value);
						}
						else
						{
							$value = trim((string)$value);
						}
						
						Config\Option::set($mid, $complexCode, $value);
					}
				}
			}
		}
		
		if(
			$Update <> '' && (string)$request->get('back_url_settings') <> ''
		)
		{
			LocalRedirect($request->get('back_url_settings'));
		}
		else
		{
			LocalRedirect(
				Utils::getCMainApplication()->GetCurPage()
				.'?mid='.urlencode((string)$mid)
				.'&lang='.urlencode((string)LANGUAGE_ID)
				.'&back_url_settings='.urlencode((string)$request->get('back_url_settings'))
				.'&'.$tabControl->ActiveTabParam());
		}
	}
	// endregion ////
}
catch(Throwable $throwable)
{
	CAdminMessage::ShowMessage(implode("\n", [
		'Throwable: '.$throwable->getMessage(),
		'File: '.$throwable->getFile(),
		'Line: '.$throwable->getLine(),
		'Trace: '.print_r(str_replace(Application::getDocumentRoot(), '', $throwable->getTraceAsString()), true)
	]));
	return;
}
// region Render ////
Extension::load([
	'shef-options-admin',
]);

$tabControl->Begin();
?>
<form
	method="POST"
	action="<?=Utils::getCMainApplication()->GetCurPage();?>?mid=<?=urlencode((string)$mid)?>&lang=<?=LANGUAGE_ID;?>"
	name="opt_form"
>
<?php
	Utils::renderTab(
		$mid,
		$tabControl,
		$tabs
	);
	$tabControl->Buttons();?>
		<input
			type="submit"
			name="Update"
			<?=($modulePerms < 'W' ? 'disabled' : '')?>
			value="<?=GetMessage('MAIN_SAVE')?>"
			title="<?=GetMessage('MAIN_OPT_SAVE_TITLE')?>"
			class="adm-btn-save"
		>
		<input
			type="reset"
			name="reset"
			value="<?=Loc::getMessage('MAIN_RESET');?>"
		>
		<input type="submit"
			name="RestoreDefaults"
			<?= $modulePerms < 'W' ? 'disabled' : '' ?>
			title="<?=GetMessage('MAIN_HINT_RESTORE_DEFAULTS')?>"
			onclick="return confirm('<?=AddSlashes(GetMessage('MAIN_HINT_RESTORE_DEFAULTS_WARNING'))?>')"
			value="<?=GetMessage('MAIN_RESTORE_DEFAULTS')?>"
		>
		<?=bitrix_sessid_post();?>
	<?php $tabControl->End();?>
</form>
<?php
// endregion ////
<?php declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Main\Config;

$moduleId = 'shef.toolsai';
// region Loader.includeModule ////
$list = Config\Configuration::getInstance($moduleId)->get('requireModules');
if(!is_array($list)){ $list = []; }
if(!empty($list))
{
	/** @memo: you can set at www/bitrix/.settings_extra.php shefCli.modeAutoloaderError wrapper */
	$modeAutoloaderError = Config\Configuration::getInstance()->get('shefCli.modeAutoloaderError');
	$modeAutoloaderError = is_callable($modeAutoloaderError)
		? $modeAutoloaderError
		: fn(string $module, null|string $message = null) => throw new \Bitrix\Main\LoaderException(
			!empty((string)$message)
			? $message
			: 'module '.$module.' not loaded',
		);
	
	foreach($list as $module)
	{
		if(str_contains($module, '.'))
		{
			$response = Loader::includeSharewareModule($module);
			if(!in_array(
				$response,
				[
					Loader::MODULE_INSTALLED,
					Loader::MODULE_DEMO,
				]
			))
			{
				$modeAutoloaderError($module, (
					$response === Loader::MODULE_DEMO_EXPIRED
					? 'Demo expired for module '.$module
					: null
				));
			}
		}
		else
		{
			if(!Loader::includeModule($module))
			{
				$modeAutoloaderError($module);
			}
		}
	}
}
unset($list, $modeAutoloaderError, $module);
// endregion ////

// region vendor.ByFile ////
$list = Config\Configuration::getInstance($moduleId)->get('registerAutoLoadClasses');
if(!is_array($list)){ $list = []; }
if(!empty($list))
{
	Loader::registerAutoLoadClasses(
		moduleName: $moduleId,
		classes: $list
	);
}
unset($list);
// endregion ////

// region vendor.byNameSpace ////
$list = Config\Configuration::getInstance($moduleId)->get('registerNamespace');
if(!is_array($list)){ $list = []; }
if(!empty($list))
{
	$documentRoot = Loader::getDocumentRoot();
	foreach($list as $namespace => $namespacePath)
	{
		Loader::registerNamespace(
			namespace: $namespace,
			path: $documentRoot.$namespacePath
		);
	}
	unset($list, $namespace, $namespacePath, $documentRoot);
}
// endregion ////

unset($moduleId);
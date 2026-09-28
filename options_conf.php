<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;

/**
 * Опции для страницы настроек
 *
 * языковой файл lang/ru/options.php
 *
 * Tab(prefix)->Option(code) ~> код свойства: prefix_code. Коды вкладок
 * (DEF, API, DEAL) менять нельзя — их читает Shef\ToolsAi\Config.
 */

$response = ShOptionsConfig::getInstance(
	moduleId: 'shef.toolsai'
);
if(!$response->isSuccess())
{
	return $response;
}

/** @var ShOptionsConfig $options */
$options = $response->getData()['OPTIONS'];

$config = new Config();
$providers = [
	Constants::PROVIDER_ECHO => Loc::getMessage($options->moduleId.'_PROVIDER_echo'),
	Constants::PROVIDER_OPENAI => Loc::getMessage($options->moduleId.'_PROVIDER_openai'),
];

$options->addTab(
	(new Options\Tab('DEF'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_DEF_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_TITLE'))
		->addOption(
			// Состояние движка и ссылка на страницу расхода. Токен в адресе не
			// показываем: страницу настроек видит не только тот, кто её правит.
			(new Options\RowInfo('Engine'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_Engine', [
					'#URL#' => Constants::QUOTA_FILE.'?lang='.LANGUAGE_ID,
					'#ENDPOINT#' => htmlspecialcharsbx(
						$config->getPublicUrl() !== ''
							? $config->getPublicUrl().Constants::ENDPOINT_FILE
							: Loc::getMessage($options->moduleId.'_TAB_DEF_Engine_nourl')
					),
				]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new Options\Text('publicurl'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_publicurl'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_publicurl_descr'))
		)
		->addOption(
			(new Options\Enum('provideraudio'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_provideraudio'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_provideraudio_descr'))
				->setList($providers)
		)
		->addOption(
			(new Options\Enum('providertext'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_providertext'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_providertext_descr'))
				->setList($providers)
		)
		->addOption(
			(new Options\Text('quota'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_quota'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_quota_descr'))
		)
);

$options->addTab(
	(new Options\Tab('API'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_API_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_TITLE'))
		->addOption(
			(new Options\Text('baseurl'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_baseurl'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_baseurl_descr'))
		)
		->addOption(
			(new Options\Text('apikey'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_apikey'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_apikey_descr'))
		)
		->addOption(
			(new Options\Text('asrmodel'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrmodel'))
		)
		->addOption(
			(new Options\Text('llmmodel'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmmodel'))
		)
		->addOption(
			(new Options\Text('asrprice'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrprice'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_price_descr'))
		)
		->addOption(
			(new Options\Text('llmpricein'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmpricein'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_price_descr'))
		)
		->addOption(
			(new Options\Text('llmpriceout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmpriceout'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_price_descr'))
		)
		->addOption(
			(new Options\NumberInt('timeout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_timeout'))
		)
);

$options->addTab(
	(new Options\Tab('DEAL'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_DEAL_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_TITLE'))
		->addOption(
			(new Options\Checkbox('enabled'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_enabled'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_enabled_descr'))
		)
		->addOption(
			(new Options\EnumCrmDealCategory('categories'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_categories'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_categories_descr'))
				->initSimpleList([])
				->setShowRows(5)
		)
		->addOption(
			(new Options\NumberInt('threshold'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_threshold'))
		)
		->addOption(
			(new Options\Users('senior'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_senior'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_senior_descr'))
				->initSimpleUserList([
					'LOGIC' => 'OR',
					['%=GROUPS.GROUP.STRING_ID' => 'EMPLOYEES_%'],
					['=GROUPS.GROUP_ID' => 1],
				])->setShowRows(1)
		)
		->addOption(
			(new Options\NumberInt('maxperrun'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_maxperrun'))
		)
		->addOption(
			(new Options\NumberInt('reanalyzedays'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_reanalyzedays'))
		)
		->addOption(
			(new Options\NumberInt('idledays'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_idledays'))
		)
);

return $options->get();

<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\Setup;

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

// region Выбор движка в настройках ИИ ////
/**
 * CRM берёт движок строго по коду из настроек ИИ портала (гейт 10 в
 * docs/00-research.md). Писать туда модуль вправе только по явному
 * действию администратора отсюда, со страницы настроек модуля (решение
 * владельца 2026-09-29): установщик и «Проверить и включить» туда не
 * пишут.
 *
 * Действие — GET со sessid (options.php — каноническая копия из
 * shef.options и не правится, своей формы на странице нет), только
 * администратору, после — редирект, чтобы обновление страницы не повторяло
 * запись.
 */
$selectResult = null;
$selectCategories = [
	'Y' => null,
	Constants::CATEGORY_TEXT => [Constants::CATEGORY_TEXT],
	Constants::CATEGORY_AUDIO => [Constants::CATEGORY_AUDIO],
];
if(isset($_GET['shef_toolsai_select']) && is_string($_GET['shef_toolsai_select']) && array_key_exists($_GET['shef_toolsai_select'], $selectCategories))
{
	$isAdmin = isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof \CUser && $GLOBALS['USER']->IsAdmin();
	if($isAdmin && check_bitrix_sessid())
	{
		// Настройки ИИ — чужая подсистема: её сбой не должен закрывать
		// страницу настроек модуля.
		try
		{
			$report = (new Setup($config))->selectEngines($selectCategories[$_GET['shef_toolsai_select']]);
			$ok = array_filter($report, static fn(array $row): bool => !$row['ok']) === [];
		}
		catch(\Throwable $throwable)
		{
			$ok = false;
		}

		LocalRedirect('/bitrix/admin/settings.php?mid=shef.toolsai&lang='.LANGUAGE_ID.'&shef_toolsai_selected='.($ok ? 'ok' : 'fail'));
	}
}
if(isset($_GET['shef_toolsai_selected']) && in_array($_GET['shef_toolsai_selected'], ['ok', 'fail'], true))
{
	$selectResult = $_GET['shef_toolsai_selected'];
}

$selection = [];
try
{
	$selection = (new Setup($config))->getEngineSelection();
}
catch(\Throwable $throwable)
{
}
$showSelection = static fn(?string $value): string => match(true)
{
	$value === null => Loc::getMessage('shef.toolsai_TAB_DEF_Selection_none'),
	$value === '' => Loc::getMessage('shef.toolsai_TAB_DEF_Selection_empty'),
	default => htmlspecialcharsbx($value),
};
// endregion ////

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
			(new Options\RowInfo('Selection'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_Selection', [
					'#AUDIO#' => $showSelection($selection[Constants::CATEGORY_AUDIO] ?? null),
					'#TEXT#' => $showSelection($selection[Constants::CATEGORY_TEXT] ?? null),
					'#OWN_AUDIO#' => Constants::getEngineCode(Constants::CATEGORY_AUDIO),
					'#OWN_TEXT#' => Constants::getEngineCode(Constants::CATEGORY_TEXT),
					// Ссылка HTML, а не BB [URL]: тот открывает новую вкладку, и
					// исходная страница остаётся с «не выбран» (bx-shef/toolsai#3).
					'#URL#' => htmlspecialcharsbx('/bitrix/admin/settings.php?mid=shef.toolsai&lang='.LANGUAGE_ID.'&shef_toolsai_select=Y&'.bitrix_sessid_get()),
					'#URL_TEXT#' => htmlspecialcharsbx('/bitrix/admin/settings.php?mid=shef.toolsai&lang='.LANGUAGE_ID.'&shef_toolsai_select='.Constants::CATEGORY_TEXT.'&'.bitrix_sessid_get()),
					'#URL_AUDIO#' => htmlspecialcharsbx('/bitrix/admin/settings.php?mid=shef.toolsai&lang='.LANGUAGE_ID.'&shef_toolsai_select='.Constants::CATEGORY_AUDIO.'&'.bitrix_sessid_get()),
					'#RESULT#' => $selectResult === null ? '' : Loc::getMessage($options->moduleId.'_TAB_DEF_Selection_'.$selectResult),
				]))
				->setType(
					// Хотя бы одна категория на движке модуля — рабочий выбор:
					// вторая может быть штатной намеренно (bx-shef/toolsai#12).
					($selection[Constants::CATEGORY_AUDIO] ?? null) === Constants::getEngineCode(Constants::CATEGORY_AUDIO)
					|| ($selection[Constants::CATEGORY_TEXT] ?? null) === Constants::getEngineCode(Constants::CATEGORY_TEXT)
						? Options\TypeUIAlert::Note
						: Options\TypeUIAlert::Warning
				)
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

// region Вкладка API: общие настройки и точки доступа направлений (1.5.0) ////
/**
 * Куда сейчас смотрит направление: адрес без ключа и есть ли ключ. Ключ не
 * показываем и здесь: его поле ниже — обычное текстовое (поля-пароля в
 * shef.options нет), а в подсказку он попал бы второй раз.
 */
$showEndpoint = static function(string $direction) use ($config, $options): string
{
	$endpoint = $config->getEndpoint($direction);

	return Loc::getMessage($options->moduleId.'_TAB_API_now', [
		'#URL#' => htmlspecialcharsbx($endpoint->getDisplayUrl()),
		'#KEY#' => Loc::getMessage($options->moduleId.'_TAB_API_now_key_'.($endpoint->apiKey !== '' ? 'Y' : 'N')),
		'#MODEL#' => htmlspecialcharsbx($endpoint->model),
		'#TIMEOUT#' => $endpoint->timeout,
	]);
};

$options->addTab(
	(new Options\Tab('API'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_API_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_TITLE'))
		->addOption(
			(new Options\RowInfo('Common'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_Common'))
				->setType(Options\TypeUIAlert::Note)
		)
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
			(new Options\NumberInt('timeout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_timeout'))
		)
		// Распознавание (audio)
		->addOption(
			(new Options\RowInfo('Asr'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_Asr', ['#NOW#' => $showEndpoint(Config::DIRECTION_AUDIO)]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new Options\Text('asrbaseurl'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrbaseurl'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_asrbaseurl_descr'))
		)
		->addOption(
			(new Options\Text('asrapikey'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrapikey'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_ownkey_descr'))
		)
		->addOption(
			(new Options\NumberInt('asrtimeout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrtimeout'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_owntimeout_descr'))
		)
		->addOption(
			(new Options\Text('asrmodel'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrmodel'))
		)
		->addOption(
			(new Options\Text('asrprice'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_asrprice'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_price_descr'))
		)
		// Текст Копилота (text)
		->addOption(
			(new Options\RowInfo('Llm'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_Llm', ['#NOW#' => $showEndpoint(Config::DIRECTION_TEXT)]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new Options\Text('llmbaseurl'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmbaseurl'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_llmbaseurl_descr'))
		)
		->addOption(
			(new Options\Text('llmapikey'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmapikey'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_ownkey_descr'))
		)
		->addOption(
			(new Options\NumberInt('llmtimeout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmtimeout'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_owntimeout_descr'))
		)
		->addOption(
			(new Options\Text('llmmodel'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmmodel'))
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
			(new Options\Text('llmextra'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_llmextra'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_llmextra_descr'))
		)
		->addOption(
			(new Options\Checkbox('ownprompts'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_ownprompts'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_ownprompts_descr'))
		)
		// Анализ сделок
		->addOption(
			(new Options\RowInfo('Deal'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_Deal', ['#NOW#' => $showEndpoint(Config::DIRECTION_DEAL)]))
				->setType(Options\TypeUIAlert::Note)
		)
		->addOption(
			(new Options\Text('dealbaseurl'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_dealbaseurl'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_dealbaseurl_descr'))
		)
		->addOption(
			(new Options\Text('dealapikey'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_dealapikey'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_dealkey_descr'))
		)
		->addOption(
			(new Options\NumberInt('dealtimeout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_dealtimeout'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_dealfallback_descr'))
		)
		->addOption(
			(new Options\Text('dealmodel'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_dealmodel'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_dealfallback_descr'))
		)
		->addOption(
			(new Options\Text('dealpricein'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_dealpricein'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_dealfallback_descr'))
		)
		->addOption(
			(new Options\Text('dealpriceout'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_API_dealpriceout'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_API_dealfallback_descr'))
		)
);
// endregion ////

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
			(new Options\NumberInt('maxperrun'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_maxperrun'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_maxperrun_descr'))
		)
		->addOption(
			// Интервал агента, минут. Применяется кнопкой «Проверить и
			// включить» (Main\Setup::ensureAgent()). С 1.4.0.
			(new Options\NumberInt('interval'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEAL_interval'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_interval_descr'))
		)
		->addOption(
			// Направления, пороги, старший и промпт — в профилях (1.1.0).
			// Старые DEAL_categories / threshold / senior / reanalyzedays /
			// idledays переносит в профили Deal\ProfileMigration.
			(new Options\RowInfo('Profiles'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEAL_Profiles', [
					'#URL#' => Constants::DEAL_PROFILES_FILE.'?lang='.LANGUAGE_ID,
				]))
		)
);

return $options->get();

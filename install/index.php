<?php

use Bitrix\Main\Application;
use Bitrix\Main\Config;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

if(class_exists('shef_toolsai'))
{
	return;
}

Loc::loadMessages(__FILE__);

/**
 * Установка shef.toolsai.
 *
 * Канон — установщик shef.problems (проверки UTF-8, PHP, версий, savedata,
 * каталог модуля от самого установщика) плюс то, ради чего модуль есть
 * (docs/00-research.md):
 *
 *   1) таблицы расхода, проверок и профилей анализа сделок — Битрикс для
 *      своего движка не считает ничего;
 *   2) заглушки эндпоинта, страницы расхода и страницы профилей — пишутся,
 *      а не копируются;
 *   3) Main\Setup::prepare(): токен. Портал установка не меняет — обход
 *      BaaS, движки и агент включает администратор кнопкой «Проверить и
 *      включить» (Setup::run(), решение владельца 2026-10-02).
 *
 * На классы модуля в установщике автозагрузка не рассчитана: всё, что
 * нужно ДО регистрации модуля, подключается явным require_once.
 */
Class shef_toolsai
	extends CModule
{
	public $MODULE_ID = 'shef.toolsai';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_SORT;
	public $MODULE_GROUP_RIGHTS = 'N';

	public $PARTNER_NAME;
	public $PARTNER_URI;
	
	/** @var string  */
	public $PHP_MIN_VER = '8.2.0';
	/** @var string  */
	public $NEED_MAIN_VERSION = '22.600.300';
	/**
	 * ai — движки Копилота, crm — звонки и сделки. Без них модулю нечего
	 * делать, но в requireModules их нет: autoload.php поднимал бы их на
	 * каждой загрузке модуля.
	 *
	 * @var array
	 */
	public $NEED_MODULES = [
		'ai',
		'crm',
	];
	/**
	 * shef.options 3.0.0 — страница настроек на ShOptionsConfig без indexDoc;
	 * shef.problems 2.0.0 — логгер проблем и каталог логов вне корня сайта.
	 *
	 * @var array
	 */
	public $NEED_MODULES_BY_VERSION = [
		'shef.options' => '3.0.0',
		'shef.problems' => '2.0.0',
	];

	/**
	 * Группы блокировок агентов — те же, что Constants::LOCK_GROUP_DEAL_HEALTH
	 * и LOCK_GROUP_CHAT_ASSESSMENT (1.6.0). Повторены литералом: при удалении
	 * автозагрузка модуля уже не работает (навык shef-new-agent, п. 4).
	 */
	private const LOCK_GROUP = 'shef.toolsai.dealhealth';
	private const LOCK_GROUP_CHAT = 'shef.toolsai.chatassessment';

	/**
	 * Флажок «AI_IGNORE_BAAS включили мы». Снимаем при удалении только его:
	 * если обход включил кто-то до нас, это его решение.
	 */
	private const OPTION_BAAS_SET = 'SYS_baasset';
	
	/** @var \CMain  */
	private $application;

	/** Отчёт Main\Setup — показывается в форме после установки. */
	private array $setupReport = [];
	
	public function __construct()
	{
		$arModuleVersion = [];
		
		require __DIR__.'/version.php';

		$this->MODULE_VERSION = $arModuleVersion["VERSION"];
		$this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
		
		$this->MODULE_NAME = Loc::getMessage('shef.toolsai_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('shef.toolsai_MODULE_DESC');
		
		$sort = 'MODULE_SORT';
		$this->{$sort} = (int)'1010';

		$this->PARTNER_NAME = Loc::getMessage('shef.toolsai_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('shef.toolsai_PARTNER_URI');
		
		$this->application = $GLOBALS['APPLICATION'];
	}
	
	// region DB && Register Module ////
	/**
	 * Регистрация модуля и таблицы.
	 *
	 * @memo Need call first
	 */
	public function InstallDB(array $arParams = []): bool
	{
		RegisterModule($this->MODULE_ID);
		
		\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
		
		\Shef\ToolsAi\Quota\Model\UsageTable::init();
		\Shef\ToolsAi\Deal\Model\DealCheckTable::init();
		\Shef\ToolsAi\Deal\Model\DealProfileTable::init();
		\Shef\ToolsAi\Chat\Model\ChatAssessmentTable::init();
		
		return true;
	}
	
	/**
	 * Снятие регистрации.
	 *
	 * @param array $arParams ключ savedata = 'Y' оставляет настройки модуля и
	 *                        таблицы: журнал расхода — финансовая история
	 * @memo Need call last
	 */
	public function UnInstallDB(array $arParams = []): bool
	{
		$isSaveData = ($arParams['savedata'] ?? 'N') === 'Y';
		
		try
		{
			\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
			
			if(!$isSaveData)
			{
				\Shef\ToolsAi\Quota\Model\UsageTable::drop();
				\Shef\ToolsAi\Deal\Model\DealCheckTable::drop();
				\Shef\ToolsAi\Deal\Model\DealProfileTable::drop();
				\Shef\ToolsAi\Chat\Model\ChatAssessmentTable::drop();
			}
		}
		catch(\Throwable $throwable)
		{
		
		}
		
		/**
		 * Настройки уходят вместе с модулем. Ключ savedata — уговор ядра:
		 * установщик с формой удаления кладёт сюда ответ на «сохранить
		 * данные?». Формы у модуля нет, поэтому умолчание — чистить.
		 */
		if(!$isSaveData)
		{
			Config\Option::delete($this->MODULE_ID);
		}
		
		UnRegisterModule($this->MODULE_ID);
		
		$GLOBALS['CACHE_MANAGER']->CleanAll();
		
		return true;
	}
	// endregion ////

	// region Движки, BaaS, агент ////
	/**
	 * Подготовка (Main\Setup::prepare()): токен и заглушки. Обход BaaS,
	 * движки и агент установщик НЕ включает — решение владельца
	 * (2026-10-02): установка и включение раздельны, включает
	 * администратор кнопкой «Проверить и включить» на странице расхода.
	 */
	public function InstallEngine(): bool
	{
		try
		{
			\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
			
			$setup = new \Shef\ToolsAi\Main\Setup(new \Shef\ToolsAi\Config());
			$this->setupReport = $setup->prepare(
				(string)Application::getDocumentRoot(),
				$this->getModuleDir()
			);
		}
		catch(\Throwable $throwable)
		{
			$this->setupReport['setup'] = ['ok' => false, 'message' => $throwable->getMessage()];
		}
		
		return true;
	}
	
	/**
	 * Снять движки, агент, блокировки и — если включали мы — обход BaaS.
	 *
	 * Движки снимаются ядром (Manager::unRegister), на классы модуля не
	 * опираемся: коды повторены литералами, как и группа блокировок.
	 */
	public function UnInstallEngine(): bool
	{
		\CAgent::RemoveModuleAgents($this->MODULE_ID);
		
		if(
			\Bitrix\Main\Loader::includeModule('ai')
			&& class_exists(\Bitrix\AI\ThirdParty\Manager::class)
		)
		{
			foreach(['audio', 'text'] as $category)
			{
				try
				{
					\Bitrix\AI\ThirdParty\Manager::unRegister(['code' => 'sheftoolsai_'.$category]);
				}
				catch(\Throwable $throwable)
				{
				
				}
			}
		}
		
		if(
			Config\Option::get($this->MODULE_ID, self::OPTION_BAAS_SET, 'N') === 'Y'
			&& \Bitrix\Main\Loader::includeModule('crm')
			&& class_exists(\Bitrix\Crm\Integration\AI\BaasManager::class)
		)
		{
			\Bitrix\Crm\Integration\AI\BaasManager::setIgnored(false);
			// Снято — пометка больше ничего не значит. С savedata = Y
			// настройки переживают удаление, и устаревшая пометка сняла бы
			// после переустановки обход, включённый уже не модулем.
			Config\Option::set($this->MODULE_ID, self::OPTION_BAAS_SET, 'N');
		}
		
		$this->unselectEngines();
		
		// shef.options — чужой модуль, он остаётся; подключить явно.
		if(\Bitrix\Main\Loader::includeModule('shef.options'))
		{
			foreach([self::LOCK_GROUP, self::LOCK_GROUP_CHAT] as $group)
			{
				\Shef\Options\Main\TempFile\Pid::removeByGroup($group, 0);
				\Bitrix\Main\IO\Directory::deleteDirectory(
					\Shef\Options\Main\TempFile\Pid::getBasePath($group)
				);
			}
		}
		
		return true;
	}
	/**
	 * Снять наш движок с настройки ИИ, если там всё ещё наш код: иначе после
	 * удаления CRM искал бы удалённый движок строго по коду, без фолбэка, и
	 * распознавание на других движках встало бы. Выбирал модуль — вернуть,
	 * что стояло до него; выбирал администратор руками — очистить. Чужой
	 * код не трогаем. Классы модуля не нужны: флажки и коды — литералами
	 * (Setup::OPTION_SELECTED_PREFIX, OPTION_PREVIOUS_PREFIX).
	 */
	private function unselectEngines(): void
	{
		try
		{
			if(
				!\Bitrix\Main\Loader::includeModule('ai')
				|| !\Bitrix\Main\Loader::includeModule('crm')
				|| !class_exists(\Bitrix\AI\Tuning\Manager::class)
				|| !class_exists(\Bitrix\Crm\Integration\AI\EventHandler::class)
			)
			{
				return;
			}
			
			$settings = [
				'audio' => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
				'text' => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
			];
			
			$manager = new \Bitrix\AI\Tuning\Manager();
			$changed = false;
			
			foreach($settings as $category => $code)
			{
				$item = $manager->getItem($code);
				if($item === null || (string)$item->getValue() !== 'sheftoolsai_'.$category)
				{
					continue;
				}
				
				$previous = Config\Option::get($this->MODULE_ID, 'SYS_selected_'.$category, 'N') === 'Y'
					? (string)Config\Option::get($this->MODULE_ID, 'SYS_previous_'.$category, '')
					: '';
				$item->setValue(str_starts_with($previous, 'sheftoolsai_') ? '' : $previous);
				$changed = true;
			}
			
			if($changed)
			{
				$manager->save();
			}
		}
		catch(\Throwable $throwable)
		{
		
		}
	}
	// endregion ////
	
	// region Files ////
	/**
	 * Каталог модуля — там, где он стоит на самом деле: /bitrix/modules или
	 * /local/modules. Кит клал модуль в /local/modules, линейка — в
	 * /bitrix/modules; работать обязан и там, и там.
	 */
	private function getModuleDir(): string
	{
		return dirname(__DIR__);
	}
	
	/**
	 * Заглушки публичных страниц. Не копируются, а пишутся: путь в них
	 * зависит от того, где стоит модуль. Классы подключаются явно.
	 *
	 * @return \Shef\ToolsAi\Main\PublicPage[]
	 */
	private function getPublicPages(): array
	{
		require_once $this->getModuleDir().'/lib/main/constants.php';
		require_once $this->getModuleDir().'/lib/main/publicpage.php';
		
		return \Shef\ToolsAi\Main\PublicPage::getList();
	}
	
	public function InstallFiles(array $arParams = []): bool
	{
		$docRoot = (string)Application::getDocumentRoot();
		
		foreach($this->getPublicPages() as $page)
		{
			$page->install($docRoot, $this->getModuleDir());
		}
		
		return true;
	}
	
	public function UnInstallFiles(): bool
	{
		$response = $this->checkChildModules();

		if(!$response->isSuccess())
		{
			$this->application->ThrowException(
				join(PHP_EOL.'<br>',
					array_merge(
						[Loc::getMessage('SH_PROBLEM_UNINSTALL_MODULE')],
						$response->getErrorMessages()
					)
				)
			);
			
			return false;
		}
		
		// Только свои заглушки: проект мог положить на их место свой файл.
		$docRoot = (string)Application::getDocumentRoot();
		foreach($this->getPublicPages() as $page)
		{
			$page->uninstall($docRoot, $this->getModuleDir());
		}

		return true;
	}
	// endregion ////
	
	// region Install.Public ////
	public function DoInstall(): void
	{
		/**
		 * Модуль поставляется в UTF-8 и перекодировкой при установке не занимается.
		 * На проекте в CP1251 файлы модуля остались бы в UTF-8, и весь русский
		 * текст превратился бы в мусор уже после установки — молча.
		 * Поэтому отказываемся ставиться сразу, а не разбираемся потом.
		 */
		if(!Application::isUtfMode())
		{
			$this->ShowForm(
				'ERROR',
				Loc::getMessage('SH_NEED_UTF8')
			);
		}
		
		$phpVer = phpversion();
		
		if(version_compare($phpVer, $this->PHP_MIN_VER, '<'))
		{
			$this->ShowForm(
				'ERROR',
				Loc::getMessage('SH_NEED_PHP_VER', [
					'#CURRENT#' => $phpVer,
					'#NEED#' => $this->PHP_MIN_VER,
				])
			);
		}
		
		if(
			is_array($this->NEED_MODULES)
			&& !empty($this->NEED_MODULES)
		){
			foreach($this->NEED_MODULES as $module)
			{
				if(!ModuleManager::isModuleInstalled($module))
				{
					$this->ShowForm(
						'ERROR',
						Loc::getMessage('SH_NEED_MODULES', [
							'#NEED#' => $module
						])
					);
				}
			}
		}
		
		if(
			!empty($this->NEED_MODULES_BY_VERSION)
			&& is_array($this->NEED_MODULES_BY_VERSION)
		){
			foreach($this->NEED_MODULES_BY_VERSION as $module => $ver)
			{
				if(
					!ModuleManager::isModuleInstalled($module)
					|| version_compare(
						(string)ModuleManager::getVersion($module),
						$ver
					) < 0
				)
				{
					$this->ShowForm(
						'ERROR',
						Loc::getMessage('SH_NEED_MODULES_BY_VERSION', [
							'#URL#' => (strpos($module, '.') === false
								? 'https://www.1c-bitrix.ru/products/cms/versions.php?module='.$module
								: 'https://marketplace.1c-bitrix.ru/solutions/'.$module.'/'
							),
							'#NEED#' => $module,
							'#VER#' => $ver
						])
					);
				}
			}
		}

		if(
			mb_strlen($this->NEED_MAIN_VERSION) <= 0
			|| version_compare(SM_VERSION, $this->NEED_MAIN_VERSION) >= 0
		)
		{
			$this->InstallDB();
			$this->InstallFiles();
			$this->InstallEngine();

			$this->ShowForm(
				'OK',
				Loc::getMessage('SH_MOD_INST_OK').$this->renderSetupReport()
			);
		}
		else
		{
			$this->ShowForm(
				'ERROR',
				Loc::getMessage('SH_NEED_RIGHT_VER', [
					'#NEED#' => $this->NEED_MAIN_VERSION
				])
			);
		}
	}

	public function DoUninstall(): void
	{
		$response = $this->UnInstallFiles();
		if(!$response)
		{
			/** @var \CApplicationException $problem */
			$problem = $this->application->GetException();
			$this->ShowForm(
				'ERROR',
				(
					$problem instanceof \CApplicationException
					? $problem->GetString()
					: 'Error Uninstall'
				)
			);
		}
		$this->UnInstallEngine();
		$this->UnInstallDB();
	}

	private function renderSetupReport(): string
	{
		if($this->setupReport === [])
		{
			return '';
		}

		$rows = [];
		foreach($this->setupReport as $step => $row)
		{
			$rows[] = sprintf(
				'%s %s — %s',
				$row['ok'] ? '✔' : '✖',
				htmlspecialcharsbx((string)$step),
				htmlspecialcharsbx((string)$row['message'])
			);
		}

		return '<br><br>'.implode('<br>', $rows).'<br><br>'.Loc::getMessage('SH_TOOLSAI_SETUP_HINT');
	}

	private function ShowForm(
		string $type,
		string $message,
		string $buttonName = ''
	): void
	{
		/** @memo need for show title at prolog */
		$keys = array_keys($GLOBALS);
		for($i = 0; $i < count($keys); $i++)
		{
			if(
				$keys[$i] != 'i'
				&& $keys[$i] != 'GLOBALS'
				&& $keys[$i] != 'strTitle'
				&& $keys[$i] != 'filepath'
			)
			{
				global ${$keys[$i]};
			}
		}
		global $APPLICATION, $adminPage, $adminMenu, $adminChain, $USER;
		
		$this->application->SetTitle(
			Loc::getMessage('shef.toolsai_MODULE_NAME')
		);

		include(Application::getDocumentRoot().'/bitrix/modules/main/include/prolog_admin_after.php');

		\CAdminMessage::ShowMessage([
			'MESSAGE' => $message,
			'TYPE' => $type,
			'HTML' => true
		]);
		?>
		<form action="<?=$this->application->GetCurPage()?>" method="get">
			<p>
				<input type="hidden" name="lang" value="<?=LANG?>">
				<input type="submit" value="<?=($buttonName <> '' ? $buttonName : Loc::getMessage('SH_MOD_BACK'))?>">
			</p>
		</form>
		<?php
		include(Application::getDocumentRoot().'/bitrix/modules/main/include/epilog_admin.php');
		die();
	}
	// endregion /////
	
	// region Tools ////
	private function checkChildModules(): Result
	{
		$result = new Result();
		foreach(ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			if($this->MODULE_ID === $moduleId)
			{
				continue;
			}
			
			$requireModules = Config\Configuration::getInstance($moduleId)->get('requireModules');
			if(empty($requireModules) || !is_array($requireModules))
			{
				continue;
			}
			
			if(in_array($this->MODULE_ID, $requireModules))
			{
				$result->addError(new Error(Loc::getMessage('SH_NEED_UNINSTALL_MODULE_BEFORE', [
					'#TARGET#' => $moduleId
				])));
			}
		}
		
		return $result;
	}
	// endregion /////
}
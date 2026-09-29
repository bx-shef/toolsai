<?php declare(strict_types=1);

namespace Shef\ToolsAi\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Engine\Registrar;
use Shef\ToolsAi\Security\Token;

/**
 * Идемпотентные шаги включения. Прогонять после установки и после КАЖДОГО
 * обновления платформы (docs/03-upgrade-watch.md): опция crm::AI_IGNORE_BAAS
 * может сброситься при обновлении crm, а адрес движка — устареть при смене
 * внешнего адреса портала.
 *
 * Зовут: установщик, кнопка на странице расхода, cli/setup.php.
 */
final class Setup
{
	public const AGENT_NAME = '\\Shef\\ToolsAi\\Agent\\DealHealthAgent::run();';
	public const AGENT_INTERVAL = 86400;

	/** Флажок «выбор движка в настройках ИИ сделал модуль» — повторён в install/index.php. */
	public const OPTION_SELECTED_PREFIX = 'SYS_selected_';

	public function __construct(private readonly Config $config)
	{
	}

	/**
	 * Все шаги подряд.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function run(string $documentRoot, string $moduleDir): array
	{
		$report = [];

		$token = $this->ensureToken();
		$report['token'] = ['ok' => $token !== '', 'message' => $token !== '' ? 'есть' : 'не удалось сохранить'];

		foreach(PublicPage::getList() as $page)
		{
			$ok = $page->install($documentRoot, $moduleDir);
			$report['page '.$page->file] = [
				'ok' => $ok,
				'message' => $ok ? 'на месте' : 'не записана: чужой файл на этом месте или нет прав на каталог',
			];
		}

		$baas = $this->ensureBaasIgnored();
		$report['crm::AI_IGNORE_BAAS'] = [
			'ok' => $baas === true,
			'message' => match($baas)
			{
				true => 'Y — автозапуск не ждёт пакетов BaaS',
				false => 'не включилась',
				null => 'модуля crm нет',
			},
		];

		$engines = $this->ensureEngines();
		foreach($engines as $category => $row)
		{
			$report['engine '.$category] = $row;
		}

		// Выбор движка в настройках ИИ — только показать: писать туда модуль
		// вправе лишь по кнопке на странице настроек (selectEngines()).
		if(!isset($engines['*']))
		{
			// Настройки ИИ — чужая подсистема и API без теста: её сбой не
			// должен оборвать прогон до регистрации агента.
			try
			{
				$selected = $this->checkEngineSelection();
			}
			catch(\Throwable $throwable)
			{
				$selected = ['*' => ['ok' => false, 'message' => 'настройки ИИ не прочитались: '.$throwable->getMessage()]];
			}

			foreach($selected as $category => $row)
			{
				$report['selected '.$category] = $row;
			}
		}

		$agent = $this->ensureAgent();
		$report['agent'] = ['ok' => $agent, 'message' => $agent ? 'зарегистрирован' : 'не зарегистрирован'];

		return $report;
	}

	/** Токен эндпоинта. Генерируется один раз, дальше только читается. */
	public function ensureToken(): string
	{
		$token = $this->config->getToken();
		if($token !== '')
		{
			return $token;
		}

		Option::set(Constants::MODULE_ID, Config::OPTION_TOKEN, Token::generate());

		return $this->config->getToken();
	}

	/**
	 * Сменить токен эндпоинта и перерегистрировать движки с новым адресом.
	 *
	 * Токен едет в адресе, а адрес оседает в access-логах веб-сервера и в
	 * b_ai_engine. Утёк — старый адрес перестаёт работать сразу после
	 * перерегистрации: задания, отправленные ядром до неё, получат 403.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function rotateToken(): array
	{
		$old = $this->config->getToken();
		Option::set(Constants::MODULE_ID, Config::OPTION_TOKEN, Token::generate());

		$engines = $this->ensureEngines();
		$failed = array_filter($engines, static fn(array $row): bool => !$row['ok']);

		// Движки не перерегистрировались — они остались со старым адресом, и
		// с новым токеном эндпоинт отвечал бы им 403 на каждый запрос.
		// Вернуть старый токен (и старый адрес тем движкам, что успели
		// обновиться): рабочий старый токен лучше мёртвого нового.
		if($failed !== [] && $old !== '')
		{
			Option::set(Constants::MODULE_ID, Config::OPTION_TOKEN, $old);
			$this->ensureEngines();

			$report = ['token' => ['ok' => false, 'message' => 'не сменён: движки не перерегистрировались, оставлен прежний']];
		}
		else
		{
			$report = ['token' => ['ok' => $this->config->getToken() !== '', 'message' => 'новый токен']];
		}

		foreach($engines as $category => $row)
		{
			$report['engine '.$category] = $row;
		}

		return $report;
	}

	/**
	 * Обход проверки пакетов BaaS.
	 *
	 * Без этого AutoLauncher::isEnabled() (autolauncher.php:38) всегда false,
	 * и автозапуск распознавания не срабатывает. Метод штатный, с докблоком
	 * «It can be used if Baas is unavailable for some reason»
	 * (crm/lib/integration/ai/baasmanager.php:104-121).
	 *
	 * @return bool|null null — модуля crm нет
	 */
	public function ensureBaasIgnored(): ?bool
	{
		if(!Loader::includeModule('crm') || !class_exists(\Bitrix\Crm\Integration\AI\BaasManager::class))
		{
			return null;
		}

		if(\Bitrix\Crm\Integration\AI\BaasManager::isIgnored())
		{
			return true;
		}

		\Bitrix\Crm\Integration\AI\BaasManager::setIgnored(true);

		return \Bitrix\Crm\Integration\AI\BaasManager::isIgnored();
	}

	/**
	 * Движки audio и text.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function ensureEngines(): array
	{
		$url = $this->config->getCompletionsUrl();
		if($url === '')
		{
			return [
				'*' => [
					'ok' => false,
					'message' => 'не задан внешний адрес портала: настройка «Внешний адрес» или ai::public_url',
				],
			];
		}

		$names = [
			Constants::CATEGORY_AUDIO => 'Shef ToolsAI — распознавание речи',
			Constants::CATEGORY_TEXT => 'Shef ToolsAI — текст',
		];

		// Оба в одном прогоне: третьесторонний audio-движок виден CRM,
		// только пока есть хоть один text-движок (ThirdParty::hasQuality(),
		// ai/lib/Engine/ThirdParty.php:297-310). Без text audio выпадает из
		// списка в настройках ИИ, и распознавание встаёт без ошибки.
		$registrar = new Registrar();
		$report = [];

		foreach($names as $category => $name)
		{
			$result = $registrar->ensure($category, $name, $url);
			$report[$category] = $result->isSuccess()
				? ['ok' => true, 'message' => (string)($result->getData()['action'] ?? 'ok')]
				: ['ok' => false, 'message' => implode('; ', $result->getErrorMessages())];
		}

		if($report[Constants::CATEGORY_AUDIO]['ok'] && !$report[Constants::CATEGORY_TEXT]['ok'])
		{
			$report[Constants::CATEGORY_AUDIO] = [
				'ok' => false,
				'message' => $report[Constants::CATEGORY_AUDIO]['message'].', но без движка text CRM его не видит (ThirdParty::hasQuality)',
			];
		}

		return $report;
	}

	/**
	 * Движок, выбранный в настройках ИИ (/settings/configs/?page=ai).
	 *
	 * CRM берёт движок СТРОГО по коду из этой настройки
	 * (abstractoperation.php:684-697, Engine::getByCode без фолбэка). Там
	 * может лежать код облачного движка, которого на коробке нет, — тогда
	 * операция не находит движок, хотя наш зарегистрирован, и распознавание
	 * молча не идёт (docs/00-research.md, гейт 10).
	 *
	 * Решение владельца (2026-09-29): в настройки ИИ портала модуль пишет
	 * ТОЛЬКО по явному действию администратора на странице настроек модуля —
	 * selectEngines(). Установщик и «Проверить и включить» выбор только
	 * показывают.
	 *
	 * @return array<string, string|null> категория => код выбранного движка; null — настройки нет; [] — нет модулей ai или crm
	 */
	public function getEngineSelection(): array
	{
		if(!static::canSelect())
		{
			return [];
		}

		$result = [];
		foreach(static::getSelectionSettings() as $category => $code)
		{
			$item = (new \Bitrix\AI\Tuning\Manager())->getItem($code);
			$raw = $item?->getValue();
			$result[$category] = $item === null ? null : (is_scalar($raw) ? (string)$raw : '');
		}

		return $result;
	}

	/**
	 * Выбран ли наш движок — для отчёта. Ничего не пишет.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function checkEngineSelection(): array
	{
		if(!static::canSelect())
		{
			return ['*' => ['ok' => false, 'message' => 'нет модулей ai или crm']];
		}

		$report = [];
		foreach($this->getEngineSelection() as $category => $value)
		{
			$own = Constants::getEngineCode($category);
			$report[$category] = match(true)
			{
				$value === null => ['ok' => false, 'message' => 'настройка не найдена: группа настроек Копилота не загрузилась'],
				$value === $own => ['ok' => true, 'message' => 'выбран наш'],
				default => [
					'ok' => false,
					'message' => ($value === '' ? 'не выбран' : 'выбран «'.$value.'»')
						.' — нажмите «Выбрать движок модуля» на странице настроек модуля',
				],
			};
		}

		return $report;
	}

	/**
	 * Выбрать движки модуля в настройках ИИ — действие администратора со
	 * страницы настроек модуля. Перезаписывает и чужой выбор: это и есть
	 * явное решение, которого нельзя принимать установщику.
	 *
	 * Выбираются только зарегистрированные движки, и audio — только вместе с
	 * text: код движка, которого нет, CRM ищет без фолбэка, а audio без text
	 * CRM не видит (ThirdParty::hasQuality()).
	 *
	 * Что выбрал модуль, помечается (SYS_selected_<категория>): при удалении
	 * модуля установщик очищает только такую настройку и только если там всё
	 * ещё наш код — иначе CRM искал бы удалённый движок.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function selectEngines(): array
	{
		if(!static::canSelect())
		{
			return ['*' => ['ok' => false, 'message' => 'нет модулей ai или crm']];
		}

		$registrar = new Registrar();
		if(!$registrar->isRegistered(Constants::CATEGORY_TEXT))
		{
			return ['*' => ['ok' => false, 'message' => 'движки не зарегистрированы — сначала «Проверить и включить»']];
		}

		$manager = new \Bitrix\AI\Tuning\Manager();
		$report = [];
		$changed = false;

		foreach(static::getSelectionSettings() as $category => $code)
		{
			if(!$registrar->isRegistered($category))
			{
				$report[$category] = ['ok' => false, 'message' => 'движок не зарегистрирован'];
				continue;
			}

			$item = $manager->getItem($code);
			if($item === null)
			{
				$report[$category] = ['ok' => false, 'message' => 'настройка '.$code.' не найдена'];
				continue;
			}

			$own = Constants::getEngineCode($category);
			$raw = $item->getValue();
			$before = is_scalar($raw) ? (string)$raw : '';

			if($before === $own)
			{
				$report[$category] = ['ok' => true, 'message' => 'выбран наш'];
				continue;
			}

			$item->setValue($own);
			Option::set(Constants::MODULE_ID, static::OPTION_SELECTED_PREFIX.$category, 'Y');
			$changed = true;
			$report[$category] = ['ok' => true, 'message' => $before === '' ? 'выбран наш' : 'выбран наш (было «'.$before.'»)'];
		}

		if($changed)
		{
			$manager->save();
		}

		return $report;
	}

	private static function canSelect(): bool
	{
		return Loader::includeModule('ai')
			&& Loader::includeModule('crm')
			&& class_exists(\Bitrix\AI\Tuning\Manager::class)
			&& class_exists(\Bitrix\Crm\Integration\AI\EventHandler::class);
	}

	/**
	 * @return array<string, string> категория => код настройки ИИ
	 */
	private static function getSelectionSettings(): array
	{
		return [
			Constants::CATEGORY_AUDIO => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
			Constants::CATEGORY_TEXT => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
		];
	}

	/**
	 * Агент анализа сделок. Регистрируется всегда, работает — только когда
	 * анализ включён в настройках: так включение не требует переустановки.
	 */
	public function ensureAgent(): bool
	{
		$existing = \CAgent::GetList([], ['NAME' => static::AGENT_NAME, 'MODULE_ID' => Constants::MODULE_ID])->Fetch();
		if($existing)
		{
			return true;
		}

		return (bool)\CAgent::AddAgent(
			static::AGENT_NAME,
			Constants::MODULE_ID,
			'N',
			static::AGENT_INTERVAL
		);
	}
}

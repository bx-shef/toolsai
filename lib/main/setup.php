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
 * run() зовут кнопка на странице расхода и cli/setup.php; установщик —
 * только prepare().
 */
final class Setup
{
	public const AGENT_NAME = '\\Shef\\ToolsAi\\Agent\\DealHealthAgent::run();';
	/** Агент оценки чатов (1.6.0): интервал — тот же DEAL_interval. */
	public const CHAT_AGENT_NAME = '\\Shef\\ToolsAi\\Agent\\ChatAssessmentAgent::run();';

	/** Названия движков модуля в списках настроек ИИ — по ним их узнаёт администратор. */
	public const ENGINE_NAMES = [
		Constants::CATEGORY_AUDIO => 'Shef ToolsAI — распознавание речи',
		Constants::CATEGORY_TEXT => 'Shef ToolsAI — текст',
	];

	/**
	 * Другие сценарии CRM со своим выбором движка: модуль их не выбирает и не
	 * обслуживает, отчёт только показывает, что там стоит. Константа ядра —
	 * по имени: набор зависит от версии crm, нет константы — нет сценария.
	 */
	public const OTHER_SCENARIOS = [
		'call_assessment' => ['SETTINGS_CALL_ASSESSMENT_ENGINE_CODE', 'оценка звонка по скрипту'],
		'repeat_sale' => ['SETTINGS_REPEAT_SALE_ENGINE_CODE', 'повторные продажи'],
		'analyze_communication' => ['SETTINGS_ANALYZE_COMMUNICATION_ENGINE_CODE', 'автоматические дела и антиспам'],
	];
	/**
	 * Интервал агента до 1.4.0 — раз в сутки. Теперь из настройки
	 * DEAL_interval (Config::getAgentInterval(), по умолчанию час).
	 */
	public const AGENT_INTERVAL = 86400;

	/** Флажок «выбор движка в настройках ИИ сделал модуль» — повторён в install/index.php. */
	public const OPTION_SELECTED_PREFIX = 'SYS_selected_';
	/** Обход BaaS включил модуль — снять при удалении (install/index.php, литералом). */
	public const OPTION_BAAS_SET = 'SYS_baasset';
	/** Что стояло в настройке ИИ до выбора модулем — вернуть при удалении. */
	public const OPTION_PREVIOUS_PREFIX = 'SYS_previous_';

	/**
	 * @param \Closure(string): (string[]|false)|null $resolve хост => IP; для тестов
	 * @param \Closure(string): int|null $probe адрес => HTTP-статус GET, как у ядра; для тестов
	 */
	public function __construct(
		private readonly Config $config,
		private readonly ?\Closure $resolve = null,
		private readonly ?\Closure $probe = null
	)
	{
	}

	/**
	 * Подготовка — то, что делает установщик: токен и заглушки. Портал
	 * этим не меняется: ни обхода BaaS, ни движков, ни агента.
	 *
	 * Решение владельца (2026-10-02): установка и включение раздельны —
	 * включает администратор кнопкой «Проверить и включить» (run()).
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function prepare(string $documentRoot, string $moduleDir): array
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

		return $report;
	}

	/**
	 * Включение — все шаги подряд: подготовка, обход BaaS, движки, агент и
	 * отчёт о выборе движка. Зовут кнопка «Проверить и включить» и
	 * cli/setup.php, не установщик.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function run(string $documentRoot, string $moduleDir): array
	{
		$report = $this->prepare($documentRoot, $moduleDir);

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

		// Заглушку эндпоинта записать не удалось (на её месте чужой файл) —
		// движки не регистрируем: ответ 200 чужого файла прошёл бы проверку
		// ядра, и задания ушли бы не туда (приёмка, bx-shef/toolsai#3).
		$endpointReady = $report['page '.Constants::ENDPOINT_FILE]['ok'] ?? false;
		$engines = $endpointReady
			? $this->ensureEngines()
			: ['*' => ['ok' => false, 'message' => 'заглушка эндпоинта не записана — движки не регистрируются']];
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

		// Переход на профили анализа сделок (1.1.0): таблицы и перенос
		// старых настроек. Идемпотентно; сбой не мешает агенту.
		try
		{
			$report['deal profiles'] = \Shef\ToolsAi\Deal\ProfileMigration::run($this->config);
		}
		catch(\Throwable $throwable)
		{
			$report['deal profiles'] = ['ok' => false, 'message' => $throwable->getMessage()];
		}

		// Таблица оценок чатов (1.6.0): обновление модуля без установщика.
		try
		{
			\Shef\ToolsAi\Chat\Model\ChatAssessmentTable::init();
			$report['chat table'] = ['ok' => true, 'message' => 'на месте'];
		}
		catch(\Throwable $throwable)
		{
			$report['chat table'] = ['ok' => false, 'message' => $throwable->getMessage()];
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
		$pending = array_filter($engines, static fn(array $row): bool => $row['pending'] ?? false);

		// Движок снят со старого адреса и ждёт второго шага — старого адреса
		// у него больше нет, откат токена его не вернёт. Новый токен остаётся,
		// регистрацию завершит следующее «Проверить и включить».
		if($pending !== [])
		{
			$report = ['token' => ['ok' => false, 'message' => 'новый токен; движки перерегистрируются вторым шагом — нажмите «Проверить и включить»']];
		}
		// Движки не перерегистрировались — они остались со старым адресом, и
		// с новым токеном эндпоинт отвечал бы им 403 на каждый запрос.
		// Вернуть старый токен (и старый адрес тем движкам, что успели
		// обновиться): рабочий старый токен лучше мёртвого нового.
		elseif($failed !== [] && $old !== '')
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
		$ignored = \Bitrix\Crm\Integration\AI\BaasManager::isIgnored();
		if($ignored)
		{
			// Включили мы — значит, при удалении и снимаем мы.
			Option::set(Constants::MODULE_ID, static::OPTION_BAAS_SET, 'Y');
		}

		return $ignored;
	}

	/**
	 * Движки audio и text.
	 *
	 * @return array<string, array{ok: bool, message: string, pending?: bool}> pending — снят со старого адреса, регистрируется следующим прогоном
	 */
	public function ensureEngines(): array
	{
		$url = $this->config->getCompletionsUrl();
		$rejected = $this->config->getRejectedPublicUrl();
		if($url === '' && $rejected !== '')
		{
			return [
				'*' => [
					'ok' => false,
					'message' => $rejected.' не разобран: нужен адрес со схемой, без параметров и пробелов, например https://crm.example.by',
				],
			];
		}
		if($url === '')
		{
			return [
				'*' => [
					'ok' => false,
					'message' => 'не задан внешний адрес портала: настройка «Внешний адрес» или ai::public_url',
				],
			];
		}

		$names = static::ENGINE_NAMES;

		// Оба в одном прогоне: третьесторонний audio-движок виден CRM,
		// только пока есть хоть один text-движок (ThirdParty::hasQuality(),
		// ai/lib/Engine/ThirdParty.php:297-310). Без text audio выпадает из
		// списка в настройках ИИ, и распознавание встаёт без ошибки.
		$registrar = new Registrar();
		$report = [];
		$current = [];
		foreach($names as $category => $name)
		{
			$current[$category] = $registrar->getUrl($category);
			if($current[$category] === $url)
			{
				$report[$category] = ['ok' => true, 'message' => 'unchanged'];
			}
		}

		// Смена адреса — только штатно (решение владельца 2026-10-03):
		// Manager::register() существующий движок не обновляет, значит
		// снять и зарегистрировать. Шаг 1 — снять ВСЕ движки со старым
		// адресом, шаг 2 — регистрировать: тогда валидатор ядра, который
		// грузит список движков в процесс один раз (Engine::loadThirdParty(),
		// static $loaded), загрузит его уже без них. Если список успели
		// загрузить раньше в этом же процессе — регистрация упрётся в
		// «уже существует»: такой движок снят и регистрируется следующим
		// нажатием, в новом процессе (приёмка, bx-shef/toolsai#3, 8.2–8.3).
		$moving = array_keys(array_filter($current, static fn(?string $old): bool => $old !== null && $old !== $url));
		if($moving !== [])
		{
			// Не снимать движок ради адреса, на котором ядро его всё равно
			// не зарегистрирует: та же проверка, что у ядра, заранее.
			$status = $this->probe !== null ? ($this->probe)($url) : $registrar->probe($url);
			if($status !== 200)
			{
				foreach($moving as $category)
				{
					$report[$category] = [
						'ok' => false,
						'message' => 'новый адрес отвечает '.$status.', а ядру нужен 200 — движок оставлен на прежнем адресе',
					];
				}
			}
			else
			{
				foreach($moving as $category)
				{
					$registrar->unregister($category);
				}
			}
		}

		foreach($names as $category => $name)
		{
			if(isset($report[$category]))
			{
				continue;
			}

			$wasMoved = in_array($category, $moving, true);
			$result = $registrar->register($category, $name, $url);
			if($result->isSuccess())
			{
				$report[$category] = ['ok' => true, 'message' => $wasMoved ? 'updated' : 'registered'];
				continue;
			}

			$report[$category] = [
				'ok' => false,
				'pending' => $wasMoved && $registrar->getUrl($category) === null,
				'message' => $wasMoved && $registrar->getUrl($category) === null
					? 'снят со старого адреса, на новом зарегистрируется следующим нажатием «Проверить и включить» (ядро держит список движков до конца запроса; не помогло и со второго раза — сбросьте кеш портала): '.implode('; ', $result->getErrorMessages())
					: implode('; ', $result->getErrorMessages()),
			];
		}

		// DNS — только при отказе: успешный прогон не ждёт резолвера.
		$failed = array_keys(array_filter($report, static fn(array $row): bool => !$row['ok']));
		$hint = $failed !== [] ? $this->getPrivateHostHint($url) : '';
		foreach($hint !== '' ? $failed : [] as $category)
		{
			$report[$category]['message'] .= ' — '.$hint;
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
	 * Подсказка к отказу в регистрации: адрес движка ведёт во внутреннюю сеть.
	 *
	 * Ядро ai ходит на completions_url с HttpClient::setPrivateIp(false) — и
	 * при регистрации (ThirdPartyRegisterService.php:159), и на каждое
	 * задание (ThirdParty.php:220): адрес обязан резолвиться в публичный IP.
	 * Иначе ядро отвечает общим «должен быть валидный URL и отвечать 200», и
	 * причину не видно (приёмка 2026-10-02, bx-shef/toolsai#3). Только
	 * подсказка, не запрет: решает ядро, у него своя проверка.
	 */
	private function getPrivateHostHint(string $url): string
	{
		$host = (string)parse_url($url, PHP_URL_HOST);
		if($host === '')
		{
			return '';
		}

		$ips = filter_var($host, FILTER_VALIDATE_IP) !== false
			? [$host]
			: ($this->resolve !== null ? ($this->resolve)($host) : @gethostbynamel($host));
		if(!is_array($ips) || $ips === [])
		{
			return '';
		}

		$private = array_filter(
			$ips,
			static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
		);
		if($private === [])
		{
			return '';
		}

		return 'адрес '.$host.' ведёт во внутреннюю сеть ('.implode(', ', $private).'): ядро ai шлёт запросы движку только на публичные адреса (HttpClient::setPrivateIp(false)), нужен внешний адрес, который с сервера резолвится в публичный IP';
	}

	/**
	 * Движок, выбранный в настройках ИИ (/configs/?page=ai).
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
	 * @return array<string, array{ok: bool, message: string, info?: bool}>
	 */
	public function checkEngineSelection(): array
	{
		if(!static::canSelect())
		{
			return ['*' => ['ok' => false, 'message' => 'нет модулей ai или crm']];
		}

		$report = [];
		$selection = $this->getEngineSelection();
		// Хотя бы одна категория на движке модуля — вторая может быть штатной
		// намеренно (текст модуля, распознавание BitrixAudio,
		// bx-shef/toolsai#12): это не сбой, и совет «Выбрать движок модуля»
		// тут вреден — заменит рабочий штатный движок.
		$anyOwn = array_filter(
			$selection,
			static fn(?string $value, string $category): bool => $value === Constants::getEngineCode($category),
			ARRAY_FILTER_USE_BOTH
		) !== [];
		foreach($selection as $category => $value)
		{
			$own = Constants::getEngineCode($category);
			$report[$category] = match(true)
			{
				$value === null => ['ok' => false, 'message' => 'настройка не найдена: группа настроек Копилота не загрузилась'],
				$value === $own => ['ok' => true, 'message' => 'выбран '.static::describeOwn($category)],
				$anyOwn && $value !== '' => ['ok' => true, 'message' => 'выбран штатный «'.$value.'» — эту категорию модуль не обслуживает'],
				default => [
					'ok' => false,
					'message' => ($value === '' ? 'не выбран' : 'выбран «'.$value.'»')
						.' — нажмите «Выбрать движок модуля» на странице настроек модуля',
				],
			};
		}

		foreach($this->getOtherSelection() as $key => [$label, $value])
		{
			$report[$key] = [
				'ok' => true,
				'info' => true,
				'message' => $label.': '.match(true)
				{
					$value === '' => 'движок не выбран',
					$value === Constants::getEngineCode(Constants::CATEGORY_TEXT) => 'выбран '.static::describeOwn(Constants::CATEGORY_TEXT),
					default => '«'.$value.'» — не через модуль',
				},
			];
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
	 * Что выбрал модуль, помечается (SYS_selected_<категория>), прежнее
	 * значение запоминается (SYS_previous_<категория>): при удалении модуля
	 * установщик возвращает его, если в настройке всё ещё наш код, — иначе
	 * CRM искал бы удалённый движок.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function selectEngines(?array $categories = null): array
	{
		$categories = array_values(array_intersect(
			[Constants::CATEGORY_TEXT, Constants::CATEGORY_AUDIO],
			$categories ?? [Constants::CATEGORY_TEXT, Constants::CATEGORY_AUDIO]
		));
		if($categories === [])
		{
			return ['*' => ['ok' => false, 'message' => 'не выбрано ни одной категории']];
		}

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

		// Категории можно выбирать по отдельности (bx-shef/toolsai#12: текст
		// наш, распознавание — штатный движок). audio CRM видит, только пока
		// ЗАРЕГИСТРИРОВАН text-движок (ThirdParty::hasQuality()) — это
		// проверено выше; выбирать text для этого не обязательно. Если text
		// выбирается вместе с audio и не вышел — audio не трогаем: настройки
		// Копилота, похоже, не загрузились.
		$settings = static::getSelectionSettings();
		$settings = array_intersect_key(
			[Constants::CATEGORY_TEXT => $settings[Constants::CATEGORY_TEXT]] + $settings,
			array_flip($categories)
		);

		foreach($settings as $category => $code)
		{
			if($category !== Constants::CATEGORY_TEXT && isset($settings[Constants::CATEGORY_TEXT]) && !($report[Constants::CATEGORY_TEXT]['ok'] ?? false))
			{
				$report[$category] = ['ok' => false, 'message' => 'без text не выбирается'];
				continue;
			}

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
				$report[$category] = ['ok' => true, 'message' => 'выбран '.static::describeOwn($category)];
				continue;
			}

			$item->setValue($own);
			Option::set(Constants::MODULE_ID, static::OPTION_PREVIOUS_PREFIX.$category, $before);
			Option::set(Constants::MODULE_ID, static::OPTION_SELECTED_PREFIX.$category, 'Y');
			$changed = true;
			$report[$category] = ['ok' => true, 'message' => 'выбран '.static::describeOwn($category).($before === '' ? '' : ' (было «'.$before.'»)')];
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
	/** «движок модуля «Shef ToolsAI — текст» (sheftoolsai_text)» — для отчётов. */
	public static function describeOwn(string $category): string
	{
		return 'движок модуля «'.(static::ENGINE_NAMES[$category] ?? $category).'» ('.Constants::getEngineCode($category).')';
	}

	/**
	 * Что выбрано в других сценариях CRM — только чтение.
	 *
	 * @return array<string, array{0: string, 1: string}> ключ => [подпись, код движка]
	 */
	public function getOtherSelection(): array
	{
		if(!static::canSelect())
		{
			return [];
		}

		$result = [];
		foreach(static::OTHER_SCENARIOS as $key => [$constant, $label])
		{
			$name = \Bitrix\Crm\Integration\AI\EventHandler::class.'::'.$constant;
			$item = defined($name) ? (new \Bitrix\AI\Tuning\Manager())->getItem((string)constant($name)) : null;
			if($item === null)
			{
				continue;
			}
			$raw = $item->getValue();
			$result[$key] = [$label, is_scalar($raw) ? (string)$raw : ''];
		}

		return $result;
	}

	private static function getSelectionSettings(): array
	{
		return [
			Constants::CATEGORY_AUDIO => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
			Constants::CATEGORY_TEXT => \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
		];
	}

	/**
	 * Агенты анализа сделок и оценки чатов (1.6.0). Регистрируются всегда,
	 * работают — только когда включены в настройках (DEAL_enabled,
	 * CHAT_enabled): так включение не требует переустановки.
	 *
	 * Интервал у обоих — из DEAL_interval (1.4.0). Агент уже есть с другим
	 * интервалом — CAgent::Update() его интервала, и если следующий запуск
	 * назначен позже, чем через новый интервал (был раз в сутки), — он
	 * переносится ближе. Повторный вызов ничего не меняет.
	 */
	public function ensureAgent(): bool
	{
		$ok = true;
		foreach([static::AGENT_NAME, static::CHAT_AGENT_NAME] as $name)
		{
			$ok = $this->ensureOneAgent($name) && $ok;
		}

		return $ok;
	}

	private function ensureOneAgent(string $name): bool
	{
		$interval = $this->config->getAgentInterval();
		$existing = \CAgent::GetList([], ['NAME' => $name, 'MODULE_ID' => Constants::MODULE_ID])->Fetch();
		if($existing)
		{
			$nextExec = isset($existing['NEXT_EXEC']) && function_exists('MakeTimeStamp') ? (int)MakeTimeStamp((string)$existing['NEXT_EXEC']) : null;
			$changes = static::planAgentUpdate(
				isset($existing['AGENT_INTERVAL']) ? (int)$existing['AGENT_INTERVAL'] : null,
				$nextExec ?: null,
				$interval,
				time()
			);
			if($changes === [])
			{
				return true;
			}
			if(isset($changes['NEXT_EXEC']))
			{
				$changes['NEXT_EXEC'] = ConvertTimeStamp($changes['NEXT_EXEC'], 'FULL');
			}

			return (bool)\CAgent::Update((int)$existing['ID'], $changes);
		}

		return (bool)\CAgent::AddAgent(
			$name,
			Constants::MODULE_ID,
			'N',
			$interval
		);
	}

	/**
	 * Что поменять у существующего агента. Чистая функция.
	 *
	 * @param int|null $current интервал агента сейчас, секунд
	 * @param int|null $nextExec следующий запуск (unix)
	 * @return array{AGENT_INTERVAL?: int, NEXT_EXEC?: int} пусто — менять нечего
	 */
	public static function planAgentUpdate(?int $current, ?int $nextExec, int $interval, int $now): array
	{
		$changes = [];
		if($current !== $interval)
		{
			$changes['AGENT_INTERVAL'] = $interval;
		}
		if($nextExec !== null && $nextExec > $now + $interval)
		{
			$changes['NEXT_EXEC'] = $now + $interval;
		}

		return $changes;
	}
}

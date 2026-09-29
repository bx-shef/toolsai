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

		foreach($this->ensureEngines() as $category => $row)
		{
			$report['engine '.$category] = $row;
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

		$registrar = new Registrar();
		$report = [];

		foreach($names as $category => $name)
		{
			$result = $registrar->ensure($category, $name, $url);
			$report[$category] = $result->isSuccess()
				? ['ok' => true, 'message' => (string)($result->getData()['action'] ?? 'ok')]
				: ['ok' => false, 'message' => implode('; ', $result->getErrorMessages())];
		}

		return $report;
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

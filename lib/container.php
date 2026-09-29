<?php declare(strict_types=1);

namespace Shef\ToolsAi;

use Psr\Log\LoggerInterface;
use Shef\ToolsAi\Completion\Callback;
use Shef\ToolsAi\Completion\Dispatcher;
use Shef\ToolsAi\Completion\Endpoint;
use Shef\ToolsAi\Deal\ContextBuilder;
use Shef\ToolsAi\Deal\Escalation;
use Shef\ToolsAi\Deal\HealthAnalyzer;
use Shef\ToolsAi\Http\BitrixTransport;
use Shef\ToolsAi\Http\TransportInterface;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\EchoProvider;
use Shef\ToolsAi\Provider\Llm\EchoLlm;
use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;
use Shef\ToolsAi\Provider\OpenAi;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Quota\Ledger;
use Shef\ToolsAi\Quota\Meter;
use Shef\ToolsAi\Security\CallbackGuard;

/**
 * Сборка зависимостей.
 *
 * Намеренно простая: модуль небольшой, полноценный DI был бы церемонией ради
 * церемонии. Провайдер выбирается настройкой при каждой сборке — смена
 * «заглушка -> OpenAI» не требует ни сброса кеша, ни перерегистрации движка.
 */
final class Container
{
	private static ?Config $config = null;
	private static ?TransportInterface $transport = null;

	public static function getConfig(): Config
	{
		return self::$config ??= new Config();
	}

	public static function getTransport(): TransportInterface
	{
		return self::$transport ??= new BitrixTransport();
	}

	public static function getMeter(): Meter
	{
		return new Meter(self::getConfig());
	}

	public static function getEndpoint(): Endpoint
	{
		$config = self::getConfig();

		return new Endpoint(
			$config->getToken(),
			new CallbackGuard($config->getAllowedCallbackHosts())
		);
	}

	public static function getDispatcher(): Dispatcher
	{
		return new Dispatcher(
			providers: [
				Constants::CATEGORY_AUDIO => self::getProvider(Constants::CATEGORY_AUDIO),
				Constants::CATEGORY_TEXT => self::getProvider(Constants::CATEGORY_TEXT),
			],
			quota: self::getMeter(),
			ledger: new Ledger(),
			callback: new Callback(self::getTransport()),
			logger: self::getLogger(Dispatcher::class),
		);
	}

	public static function getProvider(string $category): ProviderInterface
	{
		$config = self::getConfig();

		if($config->getProviderCode($category) !== Constants::PROVIDER_OPENAI)
		{
			return new EchoProvider();
		}

		$client = new OpenAi\Client($config, self::getTransport());

		return $category === Constants::CATEGORY_AUDIO
			? new OpenAi\AsrProvider($config, $client, self::getTransport(), new CallbackGuard($config->getAllowedCallbackHosts()))
			: new OpenAi\ChatProvider($config, new OpenAi\Llm($config, $client));
	}

	/**
	 * LLM для анализа сделок — тот же провайдер, что у категории text.
	 */
	public static function getLlm(): LlmProviderInterface
	{
		$config = self::getConfig();

		if($config->getProviderCode(Constants::CATEGORY_TEXT) !== Constants::PROVIDER_OPENAI)
		{
			return new EchoLlm();
		}

		return new OpenAi\Llm($config, new OpenAi\Client($config, self::getTransport()));
	}

	public static function getHealthAnalyzer(): HealthAnalyzer
	{
		return new HealthAnalyzer(new ContextBuilder(), self::getLlm());
	}

	public static function getEscalation(): Escalation
	{
		return new Escalation(self::getConfig());
	}

	/**
	 * Логгер проблем shef.problems: сбой ложится в файл каталога логов и в
	 * журнал событий с типом «Продажи и CRM» и ответственным из его настроек.
	 *
	 * Без shef.problems (сломан, снят раньше времени) — null: учёт расхода
	 * важнее лога, и падать из-за логгера модулю незачем.
	 */
	public static function getLogger(string $className): ?LoggerInterface
	{
		try
		{
			if(!\Bitrix\Main\Loader::includeModule('shef.problems'))
			{
				return null;
			}

			return \Shef\Problems\Factory\SystemLoggerFactory::build(
				logLevel: \Monolog\Level::Warning,
				auditType: \Shef\Problems\Main\Constants::AuditTypeSale,
				moduleId: Constants::MODULE_ID,
				className: $className,
				assigned: \Shef\Problems\Main\Constants::getSaleUserId(),
			);
		}
		catch(\Throwable)
		{
			return null;
		}
	}
}

<?php declare(strict_types=1);

/**
 * Включение (Main\Setup) и регистрация движков (Engine\Registrar).
 *
 * Шаги Setup прогоняются после каждого обновления платформы, поэтому
 * обязаны быть идемпотентными — это и держит тест:
 *
 * * токен генерируется один раз; повторный run() его не меняет, иначе
 *   каждое «Проверить и включить» ломало бы адрес движка;
 * * ротация меняет токен и перерегистрирует движки с новым адресом;
 * * без внешнего адреса движки не регистрируются, и отчёт говорит почему;
 * * движок с тем же адресом — unchanged, с другим — unregister + register
 *   (пара код+категория уникальна), нового — registered;
 * * обход BaaS: уже включён — не трогаем; выключен — включаем;
 * * агент регистрируется один раз.
 *
 * Ядро (ai, crm, CAgent) подменено заглушками ниже, классы модуля настоящие.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\Setup;

// region Заглушки ядра ////
eval(<<<'PHP'
namespace Bitrix\AI\ThirdParty
{
	class Manager
	{
		/** @var array<string, array> код => поля */
		public static array $engines = [];
		public static array $calls = [];
		/** Коды, регистрация которых падает. */
		public static array $fail = [];

		public static function register(array $fields): int
		{
			static::$calls[] = 'register '.$fields['code'];
			if(in_array($fields['code'], static::$fail, true))
			{
				throw new \RuntimeException('ENGINE_REGISTER_ERROR');
			}
			static::$engines[$fields['code']] = $fields;

			return count(static::$engines);
		}

		public static function unRegister(array $filter): bool
		{
			static::$calls[] = 'unregister '.$filter['code'];
			unset(static::$engines[$filter['code']]);

			return true;
		}
	}
}

namespace Bitrix\AI\Model
{
	class EngineTable
	{
		public static function getList(array $params): object
		{
			$code = $params['filter']['=CODE'];
			$row = isset(\Bitrix\AI\ThirdParty\Manager::$engines[$code])
				? ['ID' => 1, 'CODE' => $code, 'CATEGORY' => $params['filter']['=CATEGORY'], 'COMPLETIONS_URL' => \Bitrix\AI\ThirdParty\Manager::$engines[$code]['completions_url']]
				: false;

			return new class($row)
			{
				public function __construct(private readonly array|false $row) {}

				public function fetch(): array|false
				{
					return $this->row;
				}
			};
		}
	}
}

namespace Bitrix\AI\Tuning
{
	class Manager
	{
		/** @var array<string, string> код настройки => значение; нет ключа — нет настройки */
		public static array $values = [];
		public static int $saved = 0;
		public static bool $throw = false;

		public function getItem(string $code): ?object
		{
			if(static::$throw)
			{
				throw new \RuntimeException('tuning');
			}

			if(!array_key_exists($code, static::$values))
			{
				return null;
			}

			return new class($code)
			{
				public function __construct(private readonly string $code) {}

				public function getValue(): string
				{
					return Manager::$values[$this->code];
				}

				public function setValue(string $value): void
				{
					Manager::$values[$this->code] = $value;
				}
			};
		}

		public function save(): void
		{
			static::$saved++;
		}
	}
}

namespace Bitrix\Crm\Integration\AI
{
	class EventHandler
	{
		public const SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE = 'crm_copilot_fill_item_from_call_engine_audio';
		public const SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE = 'crm_copilot_fill_item_from_call_engine_text';
	}

	class BaasManager
	{
		public static bool $ignored = false;
		public static int $set = 0;

		public static function isIgnored(): bool
		{
			return static::$ignored;
		}

		public static function setIgnored(bool $value): void
		{
			static::$set++;
			static::$ignored = $value;
		}
	}
}

namespace
{
	class CAgent
	{
		public static array $agents = [];

		public static function GetList(array $order, array $filter): object
		{
			$found = in_array($filter['NAME'], static::$agents, true) ? ['ID' => 1] : false;

			return new class($found)
			{
				public function __construct(private readonly array|false $row) {}

				public function Fetch(): array|false
				{
					return $this->row;
				}
			};
		}

		public static function AddAgent(string $name, string $module, string $period, int $interval): int
		{
			static::$agents[] = $name;

			return count(static::$agents);
		}
	}
}
PHP);
// endregion ////

$portal = sys_get_temp_dir().'/shef-toolsai-setup-'.getmypid();
mkdir($portal.'/www/bitrix/admin', 0777, true);
mkdir($portal.'/www/bitrix/tools', 0777, true);

$setup = static fn(): Setup => new Setup(new Config());

Check::group('без внешнего адреса');

$report = $setup()->run($portal.'/www', $root);
$token = Option::get('shef.toolsai', 'SYS_token');

Check::same('токен сгенерирован', 1 === preg_match('/^[a-f0-9]{64}$/', (string)$token), true);
Check::same('заглушки на месте', [$report['page '.Constants::ENDPOINT_FILE]['ok'], $report['page '.Constants::QUOTA_FILE]['ok']], [true, true]);
Check::same('BaaS: выключенный — включён', [$report['crm::AI_IGNORE_BAAS']['ok'], \Bitrix\Crm\Integration\AI\BaasManager::$set], [true, 1]);
Check::same('движки не регистрируются', $report['engine *']['ok'], false);
Check::same('и отчёт говорит почему', str_contains($report['engine *']['message'], 'внешний адрес'), true);
Check::same('в ядро ничего не ушло', \Bitrix\AI\ThirdParty\Manager::$calls, []);
Check::same('агент зарегистрирован', \CAgent::$agents, [Setup::AGENT_NAME]);

Check::same('без движков выбирать нечего', array_filter(array_keys($report), static fn(string $key): bool => str_starts_with($key, 'selected')), []);

Check::group('с внешним адресом — регистрация');

\Bitrix\AI\Tuning\Manager::$values = [
	'crm_copilot_fill_item_from_call_engine_audio' => '',
	'crm_copilot_fill_item_from_call_engine_text' => 'ChatGPT',
];
Option::set('shef.toolsai', 'DEF_publicurl', 'https://crm.example.by');
$report = $setup()->run($portal.'/www', $root);

Check::same('audio — registered', $report['engine audio']['message'], 'registered');
Check::same('text — registered', $report['engine text']['message'], 'registered');
Check::same(
	'адрес — внешний + заглушка + токен',
	\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_audio']['completions_url'],
	'https://crm.example.by'.Constants::ENDPOINT_FILE.'?token='.$token
);
Check::same('audio: настройка была пуста — выбран наш', [$report['selected audio']['ok'], \Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_audio']], [true, 'sheftoolsai_audio']);
Check::same('text: выбран чужой — не перезаписан', \Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_text'], 'ChatGPT');
Check::same('и отчёт говорит, где выбрать наш', [$report['selected text']['ok'], str_contains($report['selected text']['message'], '/settings/configs/?page=ai')], [false, true]);
Check::same('настройки сохранены один раз', \Bitrix\AI\Tuning\Manager::$saved, 1);
Check::same('категория и код', [\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']['category'], \Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']['code']], ['text', 'sheftoolsai_text']);

Check::group('повторный прогон — идемпотентен');

\Bitrix\AI\ThirdParty\Manager::$calls = [];
$report = $setup()->run($portal.'/www', $root);

Check::same('токен тот же', Option::get('shef.toolsai', 'SYS_token'), $token);
Check::same('движки — unchanged', [$report['engine audio']['message'], $report['engine text']['message']], ['unchanged', 'unchanged']);
Check::same('в ядро ничего не ушло', \Bitrix\AI\ThirdParty\Manager::$calls, []);
Check::same('выбор наш — не сохраняется повторно', \Bitrix\AI\Tuning\Manager::$saved, 1);
Check::same('и отчёт — «выбран наш»', [$report['selected audio']['ok'], $report['selected audio']['message']], [true, 'выбран наш']);
Check::same('BaaS уже включён — не трогаем', \Bitrix\Crm\Integration\AI\BaasManager::$set, 1);
Check::same('агент один', \CAgent::$agents, [Setup::AGENT_NAME]);

Check::group('смена адреса и ротация токена');

Option::set('shef.toolsai', 'DEF_publicurl', 'https://new.example.by');
$report = $setup()->run($portal.'/www', $root);
Check::same('адрес сменился — updated', $report['engine audio']['message'], 'updated');
Check::same('через unregister + register', \Bitrix\AI\ThirdParty\Manager::$calls, [
	'unregister sheftoolsai_audio', 'register sheftoolsai_audio',
	'unregister sheftoolsai_text', 'register sheftoolsai_text',
]);

$report = $setup()->rotateToken();
$newToken = Option::get('shef.toolsai', 'SYS_token');
Check::same('ротация — токен новый', $newToken !== $token && 1 === preg_match('/^[a-f0-9]{64}$/', (string)$newToken), true);
Check::same('движки перерегистрированы', $report['engine audio']['message'], 'updated');
Check::same('с новым токеном в адресе', str_ends_with(\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_audio']['completions_url'], '?token='.$newToken), true);

Check::group('ротация, когда движки не перерегистрировались');

$before = Option::get('shef.toolsai', 'SYS_token');
Option::set('shef.toolsai', 'DEF_publicurl', '');
$report = $setup()->rotateToken();
Check::same('токен не сменён', Option::get('shef.toolsai', 'SYS_token'), $before);
Check::same('отчёт говорит об этом', $report['token']['ok'], false);
Option::set('shef.toolsai', 'DEF_publicurl', 'https://new.example.by');

Check::group('выбор — только зарегистрированных движков');

\Bitrix\AI\ThirdParty\Manager::$engines = [];
\Bitrix\AI\ThirdParty\Manager::$fail = ['sheftoolsai_text'];
\Bitrix\AI\Tuning\Manager::$values = [
	'crm_copilot_fill_item_from_call_engine_audio' => '',
	'crm_copilot_fill_item_from_call_engine_text' => '',
];
$report = $setup()->run($portal.'/www', $root);
Check::same(
	'text не зарегистрирован — его настройка осталась пустой, CRM возьмёт движок по умолчанию',
	\Bitrix\AI\Tuning\Manager::$values,
	['crm_copilot_fill_item_from_call_engine_audio' => '', 'crm_copilot_fill_item_from_call_engine_text' => '']
);
Check::same('без text и audio не выбирается', array_key_exists('selected audio', $report), false);
\Bitrix\AI\ThirdParty\Manager::$fail = [];

Check::group('сбой настроек ИИ не обрывает прогон');

\Bitrix\AI\Tuning\Manager::$throw = true;
\CAgent::$agents = [];
$report = $setup()->run($portal.'/www', $root);
Check::same('отчёт о сбое', [$report['selected *']['ok'] ?? null, str_contains($report['selected *']['message'] ?? '', 'не прочитались')], [false, true]);
Check::same('агент всё равно зарегистрирован', \CAgent::$agents, [Setup::AGENT_NAME]);
\Bitrix\AI\Tuning\Manager::$throw = false;

Check::group('audio без text не виден CRM');

\Bitrix\AI\ThirdParty\Manager::$engines = [];
\Bitrix\AI\ThirdParty\Manager::$fail = ['sheftoolsai_text'];
$report = $setup()->ensureEngines();
Check::same('audio зарегистрирован, но отчёт — не OK', [$report['audio']['ok'], str_contains($report['audio']['message'], 'hasQuality')], [false, true]);
Check::same('text — ошибка', $report['text']['ok'], false);
\Bitrix\AI\ThirdParty\Manager::$fail = [];

// region Уборка ////
exec('rm -rf '.escapeshellarg($portal));
// endregion ////

Check::finish();

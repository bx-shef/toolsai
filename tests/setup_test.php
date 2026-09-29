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

		public static function register(array $fields): int
		{
			static::$calls[] = 'register '.$fields['code'];
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

namespace Bitrix\Crm\Integration\AI
{
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

Check::group('с внешним адресом — регистрация');

Option::set('shef.toolsai', 'DEF_publicurl', 'https://crm.example.by');
$report = $setup()->run($portal.'/www', $root);

Check::same('audio — registered', $report['engine audio']['message'], 'registered');
Check::same('text — registered', $report['engine text']['message'], 'registered');
Check::same(
	'адрес — внешний + заглушка + токен',
	\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_audio']['completions_url'],
	'https://crm.example.by'.Constants::ENDPOINT_FILE.'?token='.$token
);
Check::same('категория и код', [\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']['category'], \Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']['code']], ['text', 'sheftoolsai_text']);

Check::group('повторный прогон — идемпотентен');

\Bitrix\AI\ThirdParty\Manager::$calls = [];
$report = $setup()->run($portal.'/www', $root);

Check::same('токен тот же', Option::get('shef.toolsai', 'SYS_token'), $token);
Check::same('движки — unchanged', [$report['engine audio']['message'], $report['engine text']['message']], ['unchanged', 'unchanged']);
Check::same('в ядро ничего не ушло', \Bitrix\AI\ThirdParty\Manager::$calls, []);
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

// region Уборка ////
exec('rm -rf '.escapeshellarg($portal));
// endregion ////

Check::finish();

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
 * * prepare() (установщик) — только токен и заглушки: обход BaaS, движки
 *   и агент включает run() по кнопке (решение владельца);
 * * обход BaaS, включённый модулем, помечается для удаления (SYS_baasset);
 * * агент регистрируется один раз;
 * * выбор движка в настройках ИИ портала run() только показывает, пишет
 *   его selectEngines() — кнопка на странице настроек модуля (решение
 *   владельца): заменяет и чужой выбор, но только зарегистрированными
 *   движками и audio — только вместе с text.
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

// Резолвер-заглушка: тест не ходит в DNS.
$setup = static fn(): Setup => new Setup(new Config(), static fn(string $host): array => ['93.184.216.34']);

Check::group('подготовка (установщик) портал не меняет');

$report = $setup()->prepare($portal.'/www', $root);
Check::same('только токен и заглушки', array_keys($report), ['token', 'page '.Constants::ENDPOINT_FILE, 'page '.Constants::QUOTA_FILE]);
Check::same('токен и заглушки на месте', array_column($report, 'ok'), [true, true, true]);
Check::same(
	'обход BaaS не тронут, в ядро ничего не ушло, агента нет',
	[\Bitrix\Crm\Integration\AI\BaasManager::$set, \Bitrix\AI\ThirdParty\Manager::$calls, \CAgent::$agents, Option::get('shef.toolsai', 'SYS_baasset')],
	[0, [], [], '']
);
$prepared = Option::get('shef.toolsai', 'SYS_token');

Check::group('без внешнего адреса');

$report = $setup()->run($portal.'/www', $root);
Check::same('включение не меняет токен подготовки', Option::get('shef.toolsai', 'SYS_token'), $prepared);
Check::same('обход BaaS включил модуль — помечено для удаления', Option::get('shef.toolsai', 'SYS_baasset'), 'Y');
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
Check::same(
	'выбор в настройках ИИ run() только показывает — ничего не записано',
	[\Bitrix\AI\Tuning\Manager::$values, \Bitrix\AI\Tuning\Manager::$saved],
	[['crm_copilot_fill_item_from_call_engine_audio' => '', 'crm_copilot_fill_item_from_call_engine_text' => 'ChatGPT'], 0]
);
Check::same('audio пуст — отчёт FAIL «не выбран»', [$report['selected audio']['ok'], str_starts_with($report['selected audio']['message'], 'не выбран')], [false, true]);
Check::same('text чужой — отчёт называет его и кнопку', [$report['selected text']['ok'], str_contains($report['selected text']['message'], '«ChatGPT»'), str_contains($report['selected text']['message'], 'Выбрать движок модуля')], [false, true, true]);
Check::same('флажков «выбрал модуль» нет', [Option::get('shef.toolsai', 'SYS_selected_audio'), Option::get('shef.toolsai', 'SYS_selected_text')], ['', '']);
Check::same('категория и код', [\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']['category'], \Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']['code']], ['text', 'sheftoolsai_text']);

Check::group('повторный прогон — идемпотентен');

\Bitrix\AI\ThirdParty\Manager::$calls = [];
$report = $setup()->run($portal.'/www', $root);

Check::same('токен тот же', Option::get('shef.toolsai', 'SYS_token'), $token);
Check::same('движки — unchanged', [$report['engine audio']['message'], $report['engine text']['message']], ['unchanged', 'unchanged']);
Check::same('в ядро ничего не ушло', \Bitrix\AI\ThirdParty\Manager::$calls, []);
Check::same('настройки ИИ по-прежнему не тронуты', \Bitrix\AI\Tuning\Manager::$saved, 0);
Check::same('BaaS уже включён — не трогаем', \Bitrix\Crm\Integration\AI\BaasManager::$set, 1);

Option::set('shef.toolsai', 'SYS_baasset', '');
$setup()->ensureBaasIgnored();
Check::same('включён не нами — пометки нет, удаление его не снимет', Option::get('shef.toolsai', 'SYS_baasset'), '');
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

Check::group('выбор движка — только по кнопке на странице настроек');

$report = $setup()->selectEngines();
Check::same('пустой и чужой — оба заменены нашими', \Bitrix\AI\Tuning\Manager::$values, [
	'crm_copilot_fill_item_from_call_engine_audio' => 'sheftoolsai_audio',
	'crm_copilot_fill_item_from_call_engine_text' => 'sheftoolsai_text',
]);
Check::same('отчёт говорит, что было', [$report['audio']['message'], $report['text']['message']], ['выбран наш', 'выбран наш (было «ChatGPT»)']);
Check::same('сохранено один раз', \Bitrix\AI\Tuning\Manager::$saved, 1);
Check::same('флажки «выбрал модуль» стоят', [Option::get('shef.toolsai', 'SYS_selected_audio'), Option::get('shef.toolsai', 'SYS_selected_text')], ['Y', 'Y']);
Check::same('прежние значения запомнены — вернуть при удалении', [Option::get('shef.toolsai', 'SYS_previous_audio'), Option::get('shef.toolsai', 'SYS_previous_text')], ['', 'ChatGPT']);

$report = $setup()->selectEngines();
Check::same('повторно — уже наш, не сохраняется', [$report['audio']['message'], \Bitrix\AI\Tuning\Manager::$saved], ['выбран наш', 1]);

$report = $setup()->run($portal.'/www', $root);
Check::same('и run() теперь видит наш', [$report['selected audio'], $report['selected text']['ok']], [['ok' => true, 'message' => 'выбран наш'], true]);

Check::group('выбор — только зарегистрированных движков');

Option::set('shef.toolsai', 'SYS_selected_audio', '');
Option::set('shef.toolsai', 'SYS_selected_text', '');
\Bitrix\AI\Tuning\Manager::$values = [
	'crm_copilot_fill_item_from_call_engine_audio' => '',
	'crm_copilot_fill_item_from_call_engine_text' => '',
];
$saved = \Bitrix\AI\Tuning\Manager::$saved;

$registered = \Bitrix\AI\ThirdParty\Manager::$engines;
unset(\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_text']);
$report = $setup()->selectEngines();
Check::same(
	'text не зарегистрирован — не выбрано ничего: код движка, которого нет, CRM ищет без фолбэка',
	[\Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_audio'], \Bitrix\AI\Tuning\Manager::$saved],
	['', $saved]
);
Check::same('и отчёт отсылает к «Проверить и включить»', [$report['*']['ok'], str_contains($report['*']['message'], 'Проверить и включить')], [false, true]);

\Bitrix\AI\ThirdParty\Manager::$engines = $registered;
unset(\Bitrix\AI\ThirdParty\Manager::$engines['sheftoolsai_audio']);
$report = $setup()->selectEngines();
Check::same('audio не зарегистрирован — выбран только text', \Bitrix\AI\Tuning\Manager::$values, [
	'crm_copilot_fill_item_from_call_engine_audio' => '',
	'crm_copilot_fill_item_from_call_engine_text' => 'sheftoolsai_text',
]);
Check::same('отчёт по audio — FAIL, флажка нет', [$report['audio']['ok'], Option::get('shef.toolsai', 'SYS_selected_audio')], [false, '']);
\Bitrix\AI\ThirdParty\Manager::$engines = $registered;

$values = \Bitrix\AI\Tuning\Manager::$values;
unset(\Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_text']);
$report = $setup()->selectEngines();
Check::same('настройки text нет — audio тоже не выбран', [$report['text']['ok'], $report['audio']['message'], \Bitrix\AI\Tuning\Manager::$values['crm_copilot_fill_item_from_call_engine_audio']], [false, 'без text не выбирается', '']);
\Bitrix\AI\Tuning\Manager::$values = $values;

\Bitrix\Main\Loader::$missing = ['crm'];
Check::same('без crm — отказ, а не fatal', $setup()->selectEngines(), ['*' => ['ok' => false, 'message' => 'нет модулей ai или crm']]);
Check::same('и выбор не читается', $setup()->getEngineSelection(), []);
\Bitrix\Main\Loader::$missing = [];

Check::group('сбой настроек ИИ не обрывает прогон');

\Bitrix\AI\Tuning\Manager::$throw = true;
\CAgent::$agents = [];
$report = $setup()->run($portal.'/www', $root);
Check::same('отчёт о сбое', [$report['selected *']['ok'] ?? null, str_contains($report['selected *']['message'] ?? '', 'не прочитались')], [false, true]);
Check::same('агент всё равно зарегистрирован', \CAgent::$agents, [Setup::AGENT_NAME]);
\Bitrix\AI\Tuning\Manager::$throw = false;

Check::group('внешний адрес без схемы — не «не задан»');

Option::set('shef.toolsai', 'DEF_publicurl', 'crm.example.by');
$report = $setup()->ensureEngines();
Check::same('отчёт называет адрес и формат', [$report['*']['ok'], str_contains($report['*']['message'], 'внешний адрес в настройках модуля «crm.example.by» не разобран')], [false, true]);
Option::set('shef.toolsai', 'DEF_publicurl', 'https://new.example.by');

Check::group('отказ регистрации на внутреннем адресе — подсказка');

\Bitrix\AI\ThirdParty\Manager::$engines = [];
\Bitrix\AI\ThirdParty\Manager::$fail = ['sheftoolsai_audio', 'sheftoolsai_text'];
$report = (new Setup(new Config(), static fn(string $host): array => ['172.18.0.5']))->ensureEngines();
Check::same(
	'адрес ведёт в приватную сеть — причина в отчёте',
	[$report['text']['ok'], str_contains($report['text']['message'], 'внутреннюю сеть (172.18.0.5)')],
	[false, true]
);
$report = (new Setup(new Config(), static fn(string $host): array => ['93.184.216.34']))->ensureEngines();
Check::same('публичный адрес — без подсказки', str_contains($report['text']['message'], 'внутреннюю сеть'), false);
\Bitrix\AI\ThirdParty\Manager::$fail = [];
$resolved = 0;
$report = (new Setup(new Config(), static function(string $host) use (&$resolved): array { $resolved++; return ['172.18.0.5']; }))->ensureEngines();
Check::same('успех — без подсказки и без DNS: решает ядро', [$report['text']['ok'], $report['text']['message'], $resolved], [true, 'registered', 0]);

Check::group('на месте заглушки эндпоинта чужой файл — движки не регистрируются');

$endpointFile = $portal.'/www'.Constants::ENDPOINT_FILE;
$ownEndpoint = (string)file_get_contents($endpointFile);
file_put_contents($endpointFile, '<?php // свой эндпоинт проекта');
\Bitrix\AI\ThirdParty\Manager::$engines = [];
\Bitrix\AI\ThirdParty\Manager::$calls = [];
$report = $setup()->run($portal.'/www', $root);
Check::same(
	'чужой файл не тронут, в ядро ничего не ушло, отчёт говорит почему',
	[(string)file_get_contents($endpointFile), \Bitrix\AI\ThirdParty\Manager::$calls, $report['engine *']['ok'], str_contains($report['engine *']['message'], 'заглушка эндпоинта не записана')],
	['<?php // свой эндпоинт проекта', [], false, true]
);
file_put_contents($endpointFile, $ownEndpoint);

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

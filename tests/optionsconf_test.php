<?php declare(strict_types=1);

/**
 * Страница настроек: options_conf.php собирается против API shef.options 3.x.
 *
 * Что держит:
 *
 * * options_conf.php зовёт ShOptionsConfig так, как его понимает
 *   shef.options 3.x (без indexDoc — в shef.problems 1.1.7 на этом падала
 *   страница настроек «Unknown named parameter»);
 * * у каждой подписи есть перевод: setName() и setTitle() принимают строку,
 *   и пропущенный ключ языкового файла — TypeError, то есть
 *   неоткрывающаяся страница;
 * * коды вкладок и опций — те, что читает Shef\ToolsAi\Config: переименуете
 *   опцию на странице и забудете в коде — модуль молча читает умолчание;
 * * в списке провайдеров — ровно известные коды;
 * * токен эндпоинта на странице не показывается;
 * * выбор движка в настройках ИИ показан, а записать его можно только
 *   администратору со sessid (решение владельца: настройки ИИ портала
 *   модуль меняет только со своей страницы настроек).
 *
 * API shef.options подменяет tests/stub/options.php, файлы модуля настоящие.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/options.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Shef\Options\Main\Options;
use Shef\ToolsAi\Main\Constants;

define('LANGUAGE_ID', 'ru');

// region Заглушки ядра для выбора движка ////
eval(<<<'PHP'
namespace Bitrix\AI\Tuning
{
	class Manager
	{
		public static array $values = [
			'crm_copilot_fill_item_from_call_engine_audio' => 'sheftoolsai_audio',
			'crm_copilot_fill_item_from_call_engine_text' => '<b>ChatGPT</b>',
		];

		public function getItem(string $code): ?object
		{
			return new class(static::$values[$code])
			{
				public function __construct(private readonly string $value) {}

				public function getValue(): string
				{
					return $this->value;
				}
			};
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
}

namespace
{
	class CUser
	{
		public function __construct(private readonly bool $admin) {}

		public function IsAdmin(): bool
		{
			return $this->admin;
		}
	}

	class RedirectStub extends \RuntimeException {}

	function bitrix_sessid_get(): string
	{
		return 'sessid=abc';
	}

	function check_bitrix_sessid(): bool
	{
		return ($_GET['sessid'] ?? '') === 'abc';
	}

	function LocalRedirect(string $url): never
	{
		throw new RedirectStub($url);
	}
}
PHP);
// endregion ////
Loc::loadLangFile($root.'/lang/ru/options.php');

$token = str_repeat('ef', 32);
Option::set('shef.toolsai', 'SYS_token', $token);
Option::set('shef.toolsai', 'DEF_publicurl', 'https://crm.example.by');

Check::group('options_conf.php собирается');

$tabs = require $root.'/options_conf.php';

Check::same('вернул список вкладок', is_array($tabs), true);
Check::same('вкладки', array_map(static fn(Options\Tab $tab): string => $tab->getCode(), $tabs), ['DEF', 'API', 'DEAL']);

$names = [];
$codes = [];
$untitled = [];
$descriptions = '';
foreach($tabs as $tab)
{
	$names[] = $tab->getName();
	foreach($tab->getOptionList() as $option)
	{
		$codes[] = $tab->getCode().'_'.$option->getCode();
		$descriptions .= $option->getDescription();
		if(!$option instanceof Options\RowInfo && $option->getTitle() === '')
		{
			$untitled[] = $tab->getCode().'_'.$option->getCode();
		}
	}
}

Check::same('у вкладок есть названия', in_array('', $names, true), false);
Check::same('у каждой опции есть подпись', $untitled, []);

Check::group('коды опций — те, что читает Config');

$config = (string)file_get_contents($root.'/lib/config.php');
preg_match_all("/'((?:DEF|API|DEAL)_[a-z]+)'/", $config, $match);
$read = array_values(array_unique($match[1]));
sort($read);

$onPage = array_values(array_filter($codes, static fn(string $code): bool => !str_ends_with($code, '_Engine') && !str_ends_with($code, '_Selection')));
sort($onPage);

Check::same('всё, что читает Config, есть на странице, и наоборот', $onPage, $read);

require $root.'/default_option.php';
$unknownDefaults = array_diff(array_keys($shef_toolsai_default_option), $read);
Check::same('умолчания — только для настроек, которые читаются', array_values($unknownDefaults), []);

Check::group('провайдеры и токен');

foreach($tabs[0]->getOptionList() as $option)
{
	if($option instanceof Options\Enum)
	{
		Check::same('список провайдеров '.$option->getCode(), array_keys($option->getList()), Constants::getProviderList());
	}
}

Check::same('адрес эндпоинта показан', str_contains($descriptions, 'https://crm.example.by'.Constants::ENDPOINT_FILE), true);
Check::same('токена на странице нет', str_contains($descriptions, $token), false);

Check::group('выбор движка в настройках ИИ');

$selectionRow = null;
foreach($tabs[0]->getOptionList() as $option)
{
	if($option->getCode() === 'Selection')
	{
		$selectionRow = $option->getDescription();
	}
}
Check::same('строка выбора есть', is_string($selectionRow), true);
Check::same('показан текущий выбор, чужой — экранирован', [str_contains($selectionRow, 'sheftoolsai_audio'), str_contains($selectionRow, '&lt;b&gt;ChatGPT&lt;/b&gt;')], [true, true]);
Check::same('ссылка на выбор — со sessid', str_contains($selectionRow, 'shef_toolsai_select=Y&sessid=abc'), true);

$run = static function(array $get, bool $admin) use ($root): ?string
{
	$_GET = $get;
	$GLOBALS['USER'] = new CUser($admin);
	try
	{
		require $root.'/options_conf.php';
	}
	catch(RedirectStub $redirect)
	{
		return $redirect->getMessage();
	}
	finally
	{
		$_GET = [];
		unset($GLOBALS['USER']);
	}

	return null;
};

Check::same('не администратор — действия нет', $run(['shef_toolsai_select' => 'Y', 'sessid' => 'abc'], false), null);
Check::same('без sessid — действия нет', $run(['shef_toolsai_select' => 'Y'], true), null);
$redirect = $run(['shef_toolsai_select' => 'Y', 'sessid' => 'abc'], true);
Check::same(
	'администратор со sessid — выбор и редирект без повторяемого действия',
	[is_string($redirect), str_contains((string)$redirect, 'shef_toolsai_selected='), str_contains((string)$redirect, 'shef_toolsai_select=Y')],
	[true, true, false]
);
Check::same('сбой настроек ИИ — страница жива, редирект с fail', str_ends_with((string)$redirect, 'shef_toolsai_selected=fail'), true);

Check::finish();

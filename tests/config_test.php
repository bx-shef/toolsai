<?php declare(strict_types=1);

/**
 * Настройки: строгий разбор и то, что из них выводится.
 *
 * Что держит:
 *
 * * деньги разбираются строкой, без float: '0,1' — ровно 100000 микро, а
 *   '5 62' и '1e3' — умолчание, а не половина значения;
 * * ID пользователя — целое > 0 без ведущего нуля: '05', '', 'abc' дают 0;
 * * флажок — только 'Y': строка 'N' при (bool) была бы true;
 * * множественный выбор: пусто и мусор — разные ответы ([] и null);
 * * адрес эндпоинта собирается только при внешнем адресе И токене, токен —
 *   в адресе: ядро своих заголовков не шлёт;
 * * хосты колбэка — из внешнего адреса и ai::public_url, с портом.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Config;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\OptionParser;

$config = static function(array $options, array $ai = []): Config
{
	return new Config(static fn(string $module, string $name): mixed => $module === 'ai'
		? ($ai[$name] ?? '')
		: ($options[$name] ?? ''));
};

Check::group('деньги — в микро-единицах, строкой');

Check::same('500', OptionParser::micro('500'), 500_000_000);
Check::same('12,50', OptionParser::micro('12,50'), 12_500_000);
Check::same('0.1 — без ошибки float', OptionParser::micro('0.1'), 100_000);
Check::same('0.000001 — одна микро-единица', OptionParser::micro('0.000001'), 1);
Check::same('пробел и неразрывный пробел в тысячах', OptionParser::micro("1 000\u{00A0}000"), 1_000_000_000_000);
Check::same('пусто — ноль', OptionParser::micro(''), 0);
Check::same('«5 62» — это 562, а не 5', OptionParser::micro('5 62'), 562_000_000);
Check::same('1e3 — мусор', OptionParser::micro('1e3', -1), -1);
Check::same('отрицательное — мусор', OptionParser::micro('-5', -1), -1);
Check::same('семь знаков после запятой — мусор', OptionParser::micro('1.0000001', -1), -1);
Check::same('массив — мусор', OptionParser::micro(['5'], -1), -1);

Check::group('ID — строго');

Check::same("'15'", OptionParser::id('15'), 15);
Check::same('15', OptionParser::id(15), 15);
Check::same("'05' — ведущий ноль", OptionParser::id('05'), 0);
Check::same("'' — не задано", OptionParser::id(''), 0);
Check::same("'5 62'", OptionParser::id('5 62'), 0);
Check::same('true', OptionParser::id(true), 0);
Check::same('[562]', OptionParser::id([562]), 0);
Check::same('-3', OptionParser::id(-3), 0);

Check::group('целое в границах');

Check::same("'20'", OptionParser::int('20', 7, 1, 500), 20);
Check::same("'0' при min 1 — умолчание", OptionParser::int('0', 7, 1, 500), 7);
Check::same("'abc' — умолчание", OptionParser::int('abc', 7), 7);
Check::same("'' — умолчание", OptionParser::int('', 7), 7);

Check::group('флажок и список');

Check::same("'Y'", OptionParser::flag('Y'), true);
Check::same("'N'", OptionParser::flag('N'), false);
Check::same("'y'", OptionParser::flag('y'), false);
Check::same('пусто — []', OptionParser::idList(''), []);
Check::same('одиночное', OptionParser::idList('3'), [3]);
Check::same('направление по умолчанию 0', OptionParser::idList('0'), [0]);
Check::same('сериализованный список', OptionParser::idList(serialize(['0', '2', '2'])), [0, 2]);
Check::same('экранированный сериализованный (так хранит страница)', OptionParser::idList(htmlspecialchars(serialize(['1']))), [1]);
Check::same('мусор в списке — null, а не «все»', OptionParser::idList(serialize(['1', 'x'])), null);
Check::same('битая сериализация — null', OptionParser::idList('a:1:{'), null);
Check::same('объект в сериализации не поднимается', OptionParser::idList('a:1:{i:0;O:8:"stdClass":0:{}}'), null);

Check::group('адрес');

Check::same('слэш в конце снимается', OptionParser::url('https://crm.example.by/'), 'https://crm.example.by');
Check::same('с портом', OptionParser::url('http://localhost:8080'), 'http://localhost:8080');
Check::same('без схемы — пусто', OptionParser::url('crm.example.by'), '');
Check::same('javascript: — пусто', OptionParser::url('javascript:alert(1)'), '');
Check::same('с query — пусто', OptionParser::url('https://a.by/?x=1'), '');

Check::group('адрес эндпоинта');

$token = str_repeat('ab', 32);

Check::same('без внешнего адреса — пусто', $config(['SYS_token' => $token])->getCompletionsUrl(), '');
Check::same('без токена — пусто', $config(['DEF_publicurl' => 'https://crm.example.by'])->getCompletionsUrl(), '');
Check::same('кривой токен — как нет токена', $config(['DEF_publicurl' => 'https://crm.example.by', 'SYS_token' => 'short'])->getCompletionsUrl(), '');
Check::same(
	'внешний адрес + заглушка + токен',
	$config(['DEF_publicurl' => 'https://crm.example.by/', 'SYS_token' => $token])->getCompletionsUrl(),
	'https://crm.example.by'.Constants::ENDPOINT_FILE.'?token='.$token
);
Check::same(
	'своего адреса нет — ai::public_url',
	$config(['SYS_token' => $token], ['public_url' => 'https://ai.example.by'])->getCompletionsUrl(),
	'https://ai.example.by'.Constants::ENDPOINT_FILE.'?token='.$token
);

Check::group('хосты колбэка');

Check::same(
	'свой адрес и ai::public_url, строчными, с портом',
	$config(['DEF_publicurl' => 'https://CRM.example.by'], ['public_url' => 'http://10.0.0.5:8080'])->getAllowedCallbackHosts(),
	['crm.example.by', '10.0.0.5:8080']
);
Check::same('одинаковые не дублируются', $config(['DEF_publicurl' => 'https://a.by'], ['public_url' => 'https://a.by'])->getAllowedCallbackHosts(), ['a.by']);
Check::same('ничего не задано — пусто', $config([])->getAllowedCallbackHosts(), []);

Check::group('провайдеры и умолчания');

Check::same('по умолчанию — заглушка', $config([])->getProviderCode('audio'), 'echo');
Check::same('openai для audio', $config(['DEF_provideraudio' => 'openai'])->getProviderCode('audio'), 'openai');
Check::same('text читает свою настройку', $config(['DEF_provideraudio' => 'openai'])->getProviderCode('text'), 'echo');
Check::same('неизвестный код — заглушка, а не падение', $config(['DEF_providertext' => 'gpt'])->getProviderCode('text'), 'echo');
Check::same('адрес API по умолчанию', $config([])->getBaseUrl(), 'https://api.openai.com/v1');
Check::same('модель по умолчанию', $config([])->getLlmModel(), 'gpt-4o-mini');
Check::same('квота', $config(['DEF_quota' => '500'])->getMonthlyQuotaMicro(), 500_000_000);
Check::same('порог выше 100 — умолчание', $config(['DEAL_threshold' => '150'])->getEscalationThreshold(), 70);
Check::same('анализ выключен по умолчанию', $config([])->isDealHealthEnabled(), false);
Check::same('направления не выбраны — []', $config([])->getDealCategories(), []);

Check::finish();

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
Check::same('12 знаков целой и 6 дробной — ровно, без float', OptionParser::micro('123456789012.123456'), 123456789012123456);
Check::same('13 знаков целой — мусор', OptionParser::micro('1234567890123', -1), -1);
Check::same('целое числом', OptionParser::micro(5), 5_000_000);
Check::same('отрицательное числом — умолчание', OptionParser::micro(-5, -1), -1);

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

Check::group('дополнительные параметры модели текста (bx-shef/toolsai#12)');

Check::same('пусто — ничего', [$config([])->getLlmExtra(), $config([])->isLlmExtraBroken()], [[], false]);
Check::same(
	'JSON-объект — как есть, без модели, сообщений и формата',
	$config(['API_llmextra' => '{"thinking":{"type":"disabled"},"model":"x","messages":[],"response_format":{}}'])->getLlmExtra(),
	['thinking' => ['type' => 'disabled']]
);
Check::same('не JSON — пусто и сломано', [$config(['API_llmextra' => '{thinking'])->getLlmExtra(), $config(['API_llmextra' => '{thinking'])->isLlmExtraBroken()], [[], true]);
Check::same('список — сломано', $config(['API_llmextra' => '[1]'])->isLlmExtraBroken(), true);
Check::same('только защищённые ключи — не сломано, просто пусто', $config(['API_llmextra' => '{"model":"x"}'])->isLlmExtraBroken(), false);

Check::group('точки доступа направлений: свой адрес, ключ, таймаут — с откатом (1.5.0)');

$common = [
	'API_baseurl' => 'https://api.openai.com/v1/',
	'API_apikey' => ' sk-common ',
	'API_timeout' => '90',
	'API_asrmodel' => 'whisper-1',
	'API_llmmodel' => 'gpt-test',
	'API_asrprice' => '0,6',
	'API_llmpricein' => '0.15',
	'API_llmpriceout' => '0.6',
];
$view = static fn(\Shef\ToolsAi\Provider\OpenAi\ApiEndpoint $e): array => [$e->baseUrl, $e->apiKey, $e->timeout, $e->model, $e->priceInMicro, $e->priceOutMicro];

// Обратная совместимость: у существующей установки новых настроек нет.
Check::same('пусто — распознавание на общем', $view($config($common)->getAsrEndpoint()), ['https://api.openai.com/v1', 'sk-common', 90, 'whisper-1', 600_000, 0]);
Check::same('пусто — текст на общем', $view($config($common)->getTextEndpoint()), ['https://api.openai.com/v1', 'sk-common', 90, 'gpt-test', 150_000, 600_000]);
Check::same('пусто — сделки на общем', $view($config($common)->getDealEndpoint()), ['https://api.openai.com/v1', 'sk-common', 90, 'gpt-test', 150_000, 600_000]);
Check::same('ничего не задано — умолчания модуля', $view($config([])->getTextEndpoint()), ['https://api.openai.com/v1', '', 120, 'gpt-4o-mini', 0, 0]);

// Локальный whisper в Docker + DeepSeek: пример из docs/04-runbook.md.
$split = array_replace($common, [
	'API_asrbaseurl' => 'http://127.0.0.1:8000/v1',
	'API_asrtimeout' => '600',
	'API_asrprice' => '0',
	'API_llmbaseurl' => 'https://api.deepseek.com/v1',
	'API_llmapikey' => 'sk-deepseek',
	'API_llmmodel' => 'deepseek-chat',
]);
Check::same(
	'свой адрес распознавания без ключа — общий ключ туда не уходит',
	$view($config($split)->getAsrEndpoint()),
	['http://127.0.0.1:8000/v1', '', 600, 'whisper-1', 0, 0]
);
Check::same('свой ключ распознавания — свой', $config($split + ['API_asrapikey' => 'local-key'])->getAsrEndpoint()->apiKey, 'local-key');
Check::same('свой ключ при общем адресе — свой', array_slice($view($config($common + ['API_asrapikey' => 'sk-asr'])->getAsrEndpoint()), 0, 2), ['https://api.openai.com/v1', 'sk-asr']);
Check::same('свой адрес, совпавший с общим, — общий ключ', $config($common + ['API_asrbaseurl' => 'https://api.openai.com/v1'])->getAsrEndpoint()->apiKey, 'sk-common');
Check::same('текст — DeepSeek со своим ключом', $view($config($split)->getTextEndpoint()), ['https://api.deepseek.com/v1', 'sk-deepseek', 90, 'deepseek-chat', 150_000, 600_000]);
Check::same('сделки пусто — как у текста, не как общий', $view($config($split)->getDealEndpoint()), ['https://api.deepseek.com/v1', 'sk-deepseek', 90, 'deepseek-chat', 150_000, 600_000]);
Check::same(
	'сделки со своим — свой адрес, ключ, модель, цены, таймаут',
	$view($config($split + ['API_dealbaseurl' => 'https://llm.example.by/v1', 'API_dealapikey' => 'sk-deal', 'API_dealmodel' => 'big', 'API_dealpricein' => '1', 'API_dealpriceout' => '2', 'API_dealtimeout' => '300'])->getDealEndpoint()),
	['https://llm.example.by/v1', 'sk-deal', 300, 'big', 1_000_000, 2_000_000]
);
Check::same('сделки: свой адрес без ключа — ключ текста туда не уходит', $config($split + ['API_dealbaseurl' => 'https://llm.example.by/v1'])->getDealEndpoint()->apiKey, '');
Check::same('сделки: цена 0 — это 0, а не цена текста', $config($split + ['API_dealpricein' => '0'])->getDealEndpoint()->priceInMicro, 0);
Check::same('сделки: текст пуст — общий', $config($common + ['API_dealmodel' => 'x'])->getDealEndpoint()->baseUrl, 'https://api.openai.com/v1');
Check::same('таймаут 0 и мусор — общий', [$config($split + ['API_llmtimeout' => '0'])->getTextEndpoint()->timeout, $config($common + ['API_asrtimeout' => 'abc'])->getAsrEndpoint()->timeout], [90, 90]);
Check::same('по направлению', [$config($split)->getEndpoint('audio')->baseUrl, $config($split)->getEndpoint('text')->baseUrl, $config($split)->getEndpoint('deal')->baseUrl], ['http://127.0.0.1:8000/v1', 'https://api.deepseek.com/v1', 'https://api.deepseek.com/v1']);
Check::same('кривой свой адрес — назван кодом, без значения', $config(['API_asrbaseurl' => '127.0.0.1:8000/v1?key=sk-x', 'API_baseurl' => 'https://ok.by'])->getRejectedApiUrls(), ['API_asrbaseurl']);
Check::same('адрес для показа — без логина и пароля', (new \Shef\ToolsAi\Provider\OpenAi\ApiEndpoint('https://u:p@h.by/v1', 'k', 5, 'm'))->getDisplayUrl(), 'https://***@h.by/v1');

Check::finish();

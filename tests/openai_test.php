<?php declare(strict_types=1);

/**
 * OpenAI-совместимые провайдеры: что уходит и как разбирается ответ.
 *
 * Сети нет — транспорт подставной, классы модуля настоящие.
 *
 * Что держит:
 *
 * * ключ — только заголовком Authorization; пустой ключ — без заголовка
 *   (свой сервер в закрытой сети);
 * * multipart собирается правильно: граница, поля, файл, кавычки в имени
 *   не ломают заголовок;
 * * распознавание: запись скачивается с лимитом 25 МБ, уходит с моделью и
 *   языком, расход — по длительности из verbose_json;
 * * запись не скачалась — понятная ошибка с подсказкой про public_url;
 * * коды ошибок провайдера: 401 -> provider_auth, 429 -> rate_limit,
 *   5xx и нет соединения -> unavailable; ключ в текст ошибки не попадает;
 * * стоимость LLM — токены × цена за миллион, в микро-единицах ровно;
 * * JSON из ответа модели — и голый, и в ```json```;
 * * completeJson: провайдер отверг json_schema (4xx) — один повтор с
 *   json_object, дальше сразу так; 401/429/5xx — без повтора; ответ
 *   сверяется со схемой, не сошлось — provider_bad_response.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Provider\OpenAi\AsrProvider;
use Shef\ToolsAi\Provider\OpenAi\ChatProvider;
use Shef\ToolsAi\Provider\OpenAi\Client;
use Shef\ToolsAi\Provider\OpenAi\Llm;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Security\CallbackGuard;

$config = static fn(array $options): Config => new Config(static fn(string $module, string $name): mixed => $options[$name] ?? '');

$options = [
	'API_baseurl' => 'http://mock:8000/v1/',
	'API_apikey' => 'sk-secret-key',
	'API_asrmodel' => 'whisper-1',
	'API_llmmodel' => 'gpt-test',
	'API_asrprice' => '0,6',
	'API_llmpricein' => '0.15',
	'API_llmpriceout' => '0.6',
];

$errorOf = static function(callable $call): ?ProviderException
{
	try
	{
		$call();
	}
	catch(ProviderException $exception)
	{
		return $exception;
	}

	return null;
};

$portal = new CallbackGuard(['crm.example.by']);

Check::group('multipart');

$body = Client::buildMultipart('B', ['model' => 'whisper-1'], 'file', 'a"b.mp3', 'audio/mpeg', 'DATA');
Check::same('тело', $body, "--B\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\nwhisper-1\r\n"
	."--B\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a%22b.mp3\"\r\nContent-Type: audio/mpeg\r\n\r\nDATA\r\n--B--\r\n");

Check::group('распознавание');

$transport = new FakeTransport();
$transport->responses = [
	new Response(200, 'ID3-mp3-bytes'),
	new Response(200, (string)json_encode(['text' => ' Менеджер: Добрый день. ', 'duration' => 125.4])),
];
$asr = new AsrProvider($config($options), new Client($config($options), $transport), $transport, $portal);
$result = $asr->run(Request::fromArray(makeCoreRequest()));

Check::same('текст без краевых пробелов', $result->text, 'Менеджер: Добрый день.');
Check::same('единицы — секунды вверх', $result->units, 126);
Check::same('стоимость: 125.4 с × 0.6 за минуту', $result->costMicro, 1_254_000);
Check::same('запись скачивается GET', $transport->sent[0]['method'], 'GET');
Check::same('с лимитом 25 МБ', $transport->sent[0]['maxBytes'], 25 * 1024 * 1024);
Check::same('запись с портала — приватные адреса разрешены', $transport->sent[0]['allowPrivate'], true);
Check::same('скачивание — без ключа провайдера', $transport->sent[0]['headers'], []);
Check::same('адрес API без двойного слэша', $transport->sent[1]['url'], 'http://mock:8000/v1/audio/transcriptions');
Check::same('ключ — заголовком', $transport->sent[1]['headers']['Authorization'] ?? null, 'Bearer sk-secret-key');
Check::same('multipart', str_starts_with($transport->sent[1]['headers']['Content-Type'] ?? '', 'multipart/form-data; boundary='), true);
Check::same('модель в теле', str_contains($transport->sent[1]['body'], "name=\"model\"\r\n\r\nwhisper-1\r\n"), true);
Check::same('язык в теле', str_contains($transport->sent[1]['body'], "name=\"language\"\r\n\r\nru\r\n"), true);
Check::same('файл в теле', str_contains($transport->sent[1]['body'], "filename=\"record.mp3\"\r\nContent-Type: audio/mpeg\r\n\r\nID3-mp3-bytes"), true);
Check::same('оценка до запроса — час записи', $asr->estimateCostMicro(Request::fromArray(makeCoreRequest())), 36_000_000);

$transport = new FakeTransport();
$transport->responses = [new Response(403, 'Forbidden')];
$asr = new AsrProvider($config($options), new Client($config($options), $transport), $transport, $portal);
$error = $errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest())));
Check::same('запись не скачалась — file_download', $error?->errorCode, 'file_download');
Check::same('подсказка про public_url', str_contains((string)$error?->getMessage(), 'public_url'), true);
Check::same('адреса записи в ошибке нет', str_contains((string)$error?->getMessage(), 'crm_show_file'), false);

$transport = new FakeTransport();
$transport->responses = [new Response(200, 'x'), new Response(200, '{"text":""}')];
$asr = new AsrProvider($config($options), new Client($config($options), $transport), $transport, $portal);
Check::same('пустой текст — ошибка, не успех', $errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest())))?->errorCode, 'empty_result');

Check::same(
	'нет адреса записи — no_file',
	$errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest(['prompt' => []]))))?->errorCode,
	'no_file'
);

Check::group('скачивание записи — под SSRF-защитой');

$asrWith = static function(array $responses) use ($config, $options, $portal): array
{
	$transport = new FakeTransport();
	$transport->responses = $responses;

	return [new AsrProvider($config($options), new Client($config($options), $transport), $transport, $portal), $transport];
};
$ok = [new Response(200, 'ID3'), new Response(200, '{"text":"ok","duration":1}')];

[$asr, $transport] = $asrWith($ok);
$asr->run(Request::fromArray(makeCoreRequest(['prompt' => ['file' => 'https://storage.example.com/rec.mp3', 'fileExtension' => 'mp3']])));
Check::same('чужой хост (облако) — приватные адреса запрещены', $transport->sent[0]['allowPrivate'], false);

[$asr, $transport] = $asrWith([new Response(302, '', '', 'https://storage.example.com/signed/rec.mp3'), ...$ok]);
$asr->run(Request::fromArray(makeCoreRequest()));
Check::same('редирект с портала в облако пройден', $transport->sent[1]['url'], 'https://storage.example.com/signed/rec.mp3');
Check::same('и проверен заново: облаку приватные адреса не положены', [$transport->sent[0]['allowPrivate'], $transport->sent[1]['allowPrivate']], [true, false]);

[$asr, $transport] = $asrWith([new Response(302, '', '', 'http://10.0.0.5/secret'), new Response(0, '', 'private IP blocked')]);
$error = $errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest())));
Check::same('редирект во внутреннюю сеть — без права на приватные адреса', $transport->sent[1]['allowPrivate'] ?? null, false);
Check::same('и заканчивается ошибкой', $error?->errorCode, 'file_download');

[$asr, $transport] = $asrWith([new Response(302, '', '', '/bitrix/tools/crm_show_file.php?fileId=2'), ...$ok]);
$asr->run(Request::fromArray(makeCoreRequest()));
Check::same('относительный редирект — к хосту исходного адреса', $transport->sent[1]['url'], 'https://crm.example.by/bitrix/tools/crm_show_file.php?fileId=2');

[$asr, $transport] = $asrWith(array_fill(0, 5, new Response(302, '', '', 'https://crm.example.by/loop')));
Check::same('бесконечный редирект — ошибка, а не цикл', $errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest())))?->errorCode, 'file_download');
Check::same('попыток не больше 1 + 3 редиректа', count($transport->sent), 4);

[$asr, $transport] = $asrWith([]);
Check::same(
	'схема не http(s) — до сети не доходит',
	[$errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest(['prompt' => ['file' => 'file:///etc/passwd']]))))?->errorCode, count($transport->sent)],
	['file_download', 0]
);

[$asr, $transport] = $asrWith([new Response(200, 'обрезок', 'body length limit exceeded')]);
Check::same('ошибка при статусе 200 (обрезанное тело) — не запись', $errorOf(static fn() => $asr->run(Request::fromArray(makeCoreRequest())))?->errorCode, 'file_download');

Check::group('ошибки провайдера');

$cases = [
	'401' => [new Response(401, '{"error":{"message":"Incorrect API key sk-secret-key"}}'), 'provider_auth'],
	'429' => [new Response(429, '{"error":{"message":"Rate limit"}}'), 'provider_rate_limit'],
	'502' => [new Response(502, '<html>Bad gateway</html>'), 'provider_unavailable'],
	'нет соединения' => [new Response(0, '', 'Connection refused'), 'provider_unavailable'],
	'400' => [new Response(400, '{"error":{"message":"bad"}}'), 'provider_error'],
	'200 не JSON' => [new Response(200, 'OK'), 'provider_bad_response'],
];
foreach($cases as $name => [$response, $code])
{
	$transport = new FakeTransport();
	$transport->responses = [$response];
	$client = new Client($config($options), $transport);
	$error = $errorOf(static fn() => $client->postJson('chat/completions', []));
	Check::same($name.' -> '.$code, $error?->errorCode, $code);
}

$transport = new FakeTransport();
$transport->responses = [new Response(401, '{"error":{"message":"Incorrect API key sk-secret-key"}}')];
$error = $errorOf(static fn() => (new Client($config($options), $transport))->postJson('x', []));
Check::same('ключ, повторённый провайдером, в ошибку не попадает', str_contains((string)$error?->getMessage(), 'sk-secret-key'), false);

Check::group('LLM');

$llm = new Llm($config($options), new Client($config($options), new FakeTransport()));
Check::same('1000 вх. × 0.15 + 500 вых. × 0.6 за миллион', $llm->getCostMicro(1000, 500), 450);
Check::same('дробная цена — вверх', $llm->getCostMicro(1, 0), 1);
Check::same('без цены — ноль', (new Llm($config([]), new Client($config([]), new FakeTransport())))->getCostMicro(1000, 1000), 0);

Check::same('голый JSON', Llm::extractJson('{"risk": 5}'), ['risk' => 5]);
Check::same('JSON в обёртке', Llm::extractJson("```json\n{\"risk\": 5}\n```"), ['risk' => 5]);
Check::same('не JSON — null', Llm::extractJson('Риск высокий'), null);

$transport = new FakeTransport();
$transport->responses = [new Response(200, (string)json_encode([
	'choices' => [['message' => ['content' => 'Резюме: клиент готов.']]],
	'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80],
]))];
$chat = new ChatProvider($config($options), new Llm($config($options), new Client($config($options), $transport)));
$result = $chat->run(Request::fromArray(makeCoreRequest(['category' => 'text', 'payload_prompt_text' => 'Сделай резюме'])));
$sent = json_decode($transport->sent[0]['body'], true);

Check::same('текст', $result->text, 'Резюме: клиент готов.');
Check::same('единицы — токены', $result->units, 1280);
Check::same('модель', $sent['model'] ?? null, 'gpt-test');
Check::same('сообщения', $sent['messages'] ?? null, [['role' => 'user', 'content' => 'Сделай резюме']]);

$transport = new FakeTransport();
$transport->responses = [new Response(200, (string)json_encode([
	'choices' => [['message' => ['content' => '{"risk":80,"needSenior":true,"why":"молчит","nextStep":"позвонить"}']]],
]))];
$json = (new Llm($config($options), new Client($config($options), $transport)))->completeJson('sys', 'user', ['type' => 'object']);
$sent = json_decode($transport->sent[0]['body'], true);
Check::same('JSON разобран', $json->json['risk'] ?? null, 80);
Check::same('схема ушла в response_format', $sent['response_format']['type'] ?? null, 'json_schema');

$transport = new FakeTransport();
$transport->responses = [new Response(200, '{"choices":[{"message":{"content":"не json"}}]}')];
Check::same(
	'не JSON при completeJson — ошибка',
	$errorOf(static fn() => (new Llm($config($options), new Client($config($options), $transport)))->completeJson('s', 'u', []))?->errorCode,
	'provider_bad_response'
);

Check::group('completeJson: провайдер без json_schema (DeepSeek, bx-shef/toolsai#12)');

$schema = Shef\ToolsAi\Deal\HealthAnalyzer::SCHEMA;
$good = (string)json_encode(['choices' => [['message' => ['content' => '{"risk":80,"needSenior":true,"why":"молчит","nextStep":"позвонить"}']]]]);
$transport = new FakeTransport();
$transport->responses = [
	new Response(400, '{"error":{"message":"This response_format type is unavailable now"}}'),
	new Response(200, $good),
	new Response(200, $good),
];
$llm = new Llm($config($options), new Client($config($options), $transport));
$json = $llm->completeJson('Оцени сделку.', 'факты', $schema);
$first = json_decode($transport->sent[0]['body'], true);
$retry = json_decode($transport->sent[1]['body'], true);
Check::same(
	'отказ на json_schema — один повтор с json_object, схема в системном сообщении',
	[$first['response_format']['type'], $retry['response_format']['type'], str_contains($retry['messages'][0]['content'], '"needSenior"'), $json->json['risk']],
	['json_schema', 'json_object', true, 80]
);
$llm->completeJson('Оцени сделку.', 'факты', $schema);
Check::same(
	'следующий запрос — сразу json_object, без лишнего отказа',
	[count($transport->sent), json_decode($transport->sent[2]['body'], true)['response_format']['type']],
	[3, 'json_object']
);

foreach([401 => 'provider_auth', 429 => 'provider_rate_limit', 500 => 'provider_unavailable'] as $status => $code)
{
	$transport = new FakeTransport();
	$transport->responses = [new Response($status, '{"error":{"message":"x"}}')];
	Check::same(
		$status.' — без повтора, это не формат',
		[$errorOf(static fn() => (new Llm($config($options), new Client($config($options), $transport)))->completeJson('s', 'u', $schema))?->errorCode, count($transport->sent)],
		[$code, 1]
	);
}

foreach([
	'нет ключа' => '{"risk":80,"needSenior":true,"why":"молчит"}',
	'тип не тот' => '{"risk":"80","needSenior":true,"why":"молчит","nextStep":"позвонить"}',
	'список вместо объекта' => '[1,2]',
] as $what => $content)
{
	$transport = new FakeTransport();
	$transport->responses = [new Response(200, (string)json_encode(['choices' => [['message' => ['content' => $content]]]]))];
	Check::same(
		'ответ не по схеме ('.$what.') — ошибка, а не молчаливый успех',
		$errorOf(static fn() => (new Llm($config($options), new Client($config($options), $transport)))->completeJson('s', 'u', $schema))?->errorCode,
		'provider_bad_response'
	);
}
$transport = new FakeTransport();
$transport->responses = [new Response(200, (string)json_encode([
	'choices' => [['message' => ['content' => '{"risk":"80"}']]],
	'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 10],
]))];
$spent = $errorOf(static fn() => (new Llm($config($options), new Client($config($options), $transport)))->completeJson('s', 'u', $schema));
Check::same('негодный ответ оплачен — расход едет с ошибкой', [$spent?->spentUnits, $spent?->spentMicro], [1010, 156]);
Check::same('80.0 — целое (json_object так отвечает)', Llm::validate(['risk' => 80.0, 'needSenior' => true, 'why' => '', 'nextStep' => ''], $schema), null);
Check::same('80.5 — не целое', Llm::validate(['risk' => 80.5, 'needSenior' => true, 'why' => '', 'nextStep' => ''], $schema) !== null, true);
$transport = new FakeTransport();
$transport->responses = [new Response(200, '{"choices":[{"message":{"content":"ok"}}]}')];
(new Llm($config($options + ['API_llmextra' => '{"thinking":{"type":"disabled"},"model":"evil"}']), new Client($config($options), $transport)))->complete([['role' => 'user', 'content' => 'x']]);
$sent = json_decode($transport->sent[0]['body'], true);
Check::same('доп. параметры — в запросе, модель не перекрыта', [$sent['thinking'] ?? null, $sent['model']], [['type' => 'disabled'], 'gpt-test']);
Check::same('лишний ключ — не ошибка', Llm::validate(['risk' => 1, 'needSenior' => false, 'why' => '', 'nextStep' => '', 'x' => 1], $schema), null);

$transport = new FakeTransport();
Check::same(
	'пустой промпт — до сети не доходит',
	[$errorOf(static fn() => (new Llm($config($options), new Client($config($options), $transport)))->complete([]))?->errorCode, count($transport->sent)],
	['empty_prompt', 0]
);

Check::group('ключ');

$transport = new FakeTransport();
(new Client($config(['API_apikey' => '']), $transport))->postJson('x', []);
Check::same('пустой ключ — без заголовка', array_key_exists('Authorization', $transport->sent[0]['headers']), false);

Check::finish();

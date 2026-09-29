<?php declare(strict_types=1);

/**
 * Колбэк ядру: форма тела и повторы.
 *
 * Что держит:
 *
 * * успех — {"result": ["текст"]}: ровно то, что разбирает
 *   ThirdParty::getResultFromRaw(); ошибка — {"error", "error_code"};
 * * пустой текст уходит ошибкой empty_result, а не пустым успехом;
 * * принят — только 200;
 * * нет соединения, 429 и 5xx — повтор (результат уже оплачен), 4xx — нет:
 *   чужой hash повтором не лечится;
 * * пустой адрес — false без запроса.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Completion\Callback;
use Shef\ToolsAi\Http\Response;

$slept = [];
$make = static function(array $responses) use (&$slept): array
{
	$slept = [];
	$transport = new FakeTransport();
	$transport->responses = $responses;

	return [new Callback($transport, static function(int $seconds) use (&$slept): void { $slept[] = $seconds; }), $transport];
};

Check::group('форма тела');

[$callback, $transport] = $make([]);
Check::same('успех принят', $callback->success('https://p/ok', 'https://p/err', 'текст'), true);
Check::same('тело успеха', $transport->sent[0]['body'], '{"result":["текст"]}');
Check::same('на адрес успеха', $transport->sent[0]['url'], 'https://p/ok');

[$callback, $transport] = $make([]);
$callback->success('https://p/ok', 'https://p/err', "  \n ");
Check::same('пустой текст — на адрес ошибки', $transport->sent[0]['url'], 'https://p/err');
Check::same('с кодом empty_result', json_decode($transport->sent[0]['body'], true)['error_code'] ?? null, 'empty_result');

[$callback, $transport] = $make([]);
$callback->error('https://p/err', 'упало', 'provider_auth');
Check::same('тело ошибки', json_decode($transport->sent[0]['body'], true), ['error' => 'упало', 'error_code' => 'provider_auth']);

[$callback, $transport] = $make([]);
Check::same('пустой адрес — false', $callback->error('', 'x'), false);
Check::same('и без запроса', $transport->sent, []);

Check::group('повторы');

[$callback, $transport] = $make([new Response(502, ''), new Response(200, '')]);
Check::same('502, потом 200 — принят', $callback->success('https://p/ok', 'https://p/err', 't'), true);
Check::same('две попытки, пауза 1 с', [count($transport->sent), $slept], [2, [1]]);

[$callback, $transport] = $make([new Response(0, '', 'refused'), new Response(429, ''), new Response(503, '')]);
Check::same('все попытки провалены — false', $callback->success('https://p/ok', 'https://p/err', 't'), false);
Check::same('паузы 1 и 3 с', $slept, Callback::RETRY_DELAYS);

[$callback, $transport] = $make([new Response(400, '')]);
Check::same('400 — false сразу', $callback->success('https://p/ok', 'https://p/err', 't'), false);
Check::same('без повтора', count($transport->sent), 1);

[$callback, $transport] = $make([new Response(202, '')]);
Check::same('принят только 200, не любой 2xx', $callback->success('https://p/ok', 'https://p/err', 't'), false);

Check::finish();

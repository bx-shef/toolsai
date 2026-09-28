<?php declare(strict_types=1);

/**
 * Диспетчер: маршрут до провайдера, учёт расхода и колбэк.
 *
 * Что держит:
 *
 * * успех: запись журнала закрыта как SUCCESS с фактическим расходом, и
 *   ядру ушло ровно {"result": ["текст"]} на callbackUrl;
 * * пустой текст — не успех: пустая транскрипция обрывает цепочку ядра
 *   молча, поэтому ядру уходит ошибка empty_result;
 * * сбой провайдера — ERROR в журнале и колбэк ошибки с его кодом;
 * * квота исчерпана — провайдер НЕ вызывается, в журнале QUOTA_EXCEEDED;
 * * повтор того же задания — ни провайдера, ни второго колбэка;
 * * расход пишется ДО колбэка: колбэк не дошёл — деньги всё равно видны.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Completion\Callback;
use Shef\ToolsAi\Completion\Dispatcher;
use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Provider\Result;

/**
 * @return array{0: Dispatcher, 1: FakeTransport, 2: FakeLedger, 3: FakeLogger}
 */
$make = static function(FakeProvider $provider, ?FakeQuota $quota = null): array
{
	$transport = new FakeTransport();
	$ledger = new FakeLedger();
	$logger = new FakeLogger();

	$quota ??= new FakeQuota();
	$quota->ledger = $ledger;

	return [
		new Dispatcher(['audio' => $provider], $quota, $ledger, new Callback($transport), $logger),
		$transport,
		$ledger,
		$logger,
	];
};

$request = Request::fromArray(makeCoreRequest());

Check::group('успех');

$provider = new FakeProvider(new Result('Менеджер: Добрый день.', 42, 4_200));
[$dispatcher, $transport, $ledger] = $make($provider);
$dispatcher->dispatch($request);

Check::same('провайдер вызван один раз', $provider->calls, 1);
Check::same('журнал: SUCCESS', $ledger->rows[1]['status'], 'SUCCESS');
Check::same('журнал: единицы', $ledger->rows[1]['units'], 42);
Check::same('журнал: стоимость', $ledger->rows[1]['costMicro'], 4_200);
Check::same('журнал: код движка', $ledger->rows[1]['engineCode'], 'sheftoolsai_audio');
Check::same('журнал: хэш задания', $ledger->rows[1]['jobHash'], 'abc123');
Check::same('колбэк — на callbackUrl', $transport->sent[0]['url'], $request->callbackUrl);
Check::same('колбэк — форма, которую разбирает getResultFromRaw()', $transport->sent[0]['body'], '{"result":["Менеджер: Добрый день."]}');
Check::same('колбэк — JSON', $transport->sent[0]['headers'], ['Content-Type' => 'application/json']);

Check::group('повтор того же задания');

$dispatcher->dispatch($request);
Check::same('провайдер второй раз не вызван', $provider->calls, 1);
Check::same('второго колбэка нет', count($transport->sent), 1);
Check::same('вторая запись журнала не появилась', count($ledger->rows), 1);

Check::group('без хэша задания — каждый раз заново');

$noHash = Request::fromArray(makeCoreRequest(['callbackUrl' => 'https://crm.example.by/cb']));
[$dispatcher, $transport, $ledger] = $make($provider2 = new FakeProvider());
$dispatcher->dispatch($noHash);
$dispatcher->dispatch($noHash);
Check::same('два вызова', $provider2->calls, 2);

Check::group('пустой результат');

[$dispatcher, $transport, $ledger] = $make(new FakeProvider(new Result('   ')));
$dispatcher->dispatch($request);
Check::same('ушла ошибка, а не пустой успех', $transport->sent[0]['url'], $request->errorCallbackUrl);
Check::same('код empty_result', json_decode($transport->sent[0]['body'], true)['error_code'] ?? null, 'empty_result');

Check::group('сбой провайдера');

[$dispatcher, $transport, $ledger, $logger] = $make(new FakeProvider(throw: new ProviderException('Провайдер ответил 429', 'provider_rate_limit')));
$dispatcher->dispatch($request);
Check::same('журнал: ERROR', $ledger->rows[1]['status'], 'ERROR');
Check::same('журнал: текст ошибки', $ledger->rows[1]['error'], 'Провайдер ответил 429');
Check::same('колбэк ошибки', $transport->sent[0]['url'], $request->errorCallbackUrl);
Check::same('код провайдера дошёл до ядра', json_decode($transport->sent[0]['body'], true), ['error' => 'Провайдер ответил 429', 'error_code' => 'provider_rate_limit']);
Check::same('сбой в логе проблем', $logger->records[0]['level'] ?? null, 'error');

[$dispatcher, $transport] = $make(new FakeProvider(throw: new RuntimeException('что-то')));
$dispatcher->dispatch($request);
Check::same('чужое исключение — provider_error', json_decode($transport->sent[0]['body'], true)['error_code'] ?? null, 'provider_error');

Check::group('квота');

$provider = new FakeProvider(estimate: 50);
[$dispatcher, $transport, $ledger] = $make($provider, new FakeQuota(limitMicro: 100, spentMicro: 60));
$dispatcher->dispatch($request);
Check::same('провайдер не вызван', $provider->calls, 0);
Check::same('60 потрачено + оценка 50 > 100 — отказ', $ledger->rows[1]['status'], 'QUOTA_EXCEEDED');
Check::same('журнал: стоимость обнулена', $ledger->rows[1]['costMicro'], 0);
Check::same('ядру — quota_exceeded', json_decode($transport->sent[0]['body'], true)['error_code'] ?? null, 'quota_exceeded');

$provider = new FakeProvider(estimate: 50);
[$dispatcher] = $make($provider, new FakeQuota(limitMicro: 100, spentMicro: 50));
$dispatcher->dispatch($request);
Check::same('50 + оценка 50 = ровно квота — ещё можно', $provider->calls, 1);

$provider = new FakeProvider(estimate: 0);
[$dispatcher] = $make($provider, new FakeQuota(limitMicro: 100, spentMicro: 101));
$dispatcher->dispatch($request);
Check::same('уже перерасход — даже бесплатный запрос не идёт', $provider->calls, 0);

Check::group('нет провайдера для категории');

[$dispatcher, $transport, $ledger] = $make(new FakeProvider());
$dispatcher->dispatch(Request::fromArray(makeCoreRequest(['category' => 'text'])));
Check::same('ядру — no_provider', json_decode($transport->sent[0]['body'], true)['error_code'] ?? null, 'no_provider');
Check::same('журнал не тронут', $ledger->rows, []);

Check::group('колбэк не дошёл');

$provider = new FakeProvider(new Result('текст', 1, 7));
[$dispatcher, $transport, $ledger, $logger] = $make($provider);
$transport->responses = [new Response(0, '', 'Connection refused')];
$dispatcher->dispatch($request);
Check::same('расход всё равно записан', [$ledger->rows[1]['status'], $ledger->rows[1]['costMicro']], ['SUCCESS', 7]);
Check::same('в логе — подсказка про public_url', str_contains($logger->records[0]['message'] ?? '', 'public_url'), true);

Check::finish();

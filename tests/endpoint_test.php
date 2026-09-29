<?php declare(strict_types=1);

/**
 * Эндпоинт: статусы, на которых держится контракт с ядром.
 *
 * Что держит:
 *
 * * GET — ровно 200: ядро проверяет адрес при регистрации движка;
 * * POST — ровно 202, не 200: ThirdParty::HTTP_STATUS_OK = 202, сравнение
 *   строгое, и 200 ядро сочло бы сбоем;
 * * без токена, с чужим токеном, с пустым настроенным токеном — 403;
 * * колбэк на чужой хост — отказ: иначе украденный токен превращал бы модуль
 *   в отправщик POST куда угодно;
 * * мусор и неподдерживаемая категория — 4xx, задание в фон не уходит.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Completion\Endpoint;
use Shef\ToolsAi\Security\CallbackGuard;

$token = str_repeat('cd', 32);
$endpoint = new Endpoint($token, new CallbackGuard(['crm.example.by']));
$body = (string)json_encode(makeCoreRequest());
$query = ['token' => $token];

Check::group('GET и прочие методы');

Check::same('GET — 200 без токена', $endpoint->handle('GET', [], '')->status, 200);
Check::same('GET — тело', $endpoint->handle('get', [], '')->body, ['status' => 'ok']);
Check::same('HEAD — 200', $endpoint->handle('HEAD', [], '')->status, 200);
Check::same('PUT — 405', $endpoint->handle('PUT', $query, $body)->status, 405);

Check::group('токен');

Check::same('без токена — 403', $endpoint->handle('POST', [], $body)->status, 403);
Check::same('чужой токен — 403', $endpoint->handle('POST', ['token' => str_repeat('00', 32)], $body)->status, 403);
Check::same('токен массивом — 403, без warning', $endpoint->handle('POST', ['token' => [$token]], $body)->status, 403);
Check::same(
	'токен не настроен — закрыт, а не открыт',
	(new Endpoint('', new CallbackGuard(['crm.example.by'])))->handle('POST', ['token' => ''], $body)->status,
	403
);

Check::group('тело');

Check::same('не JSON — 400', $endpoint->handle('POST', $query, '{')->status, 400);
Check::same('JSON-строка — 400', $endpoint->handle('POST', $query, '"x"')->status, 400);
Check::same('без колбэка — 400', $endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['callbackUrl' => ''])))->status, 400);
Check::same('категория image — 400', $endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['category' => 'image'])))->status, 400);
Check::same('больше лимита — 413', $endpoint->handle('POST', $query, str_repeat(' ', Endpoint::MAX_BODY_BYTES + 1))->status, 413);

Check::same(
	'без хэша задания — 400: нет ни дедупликации, ни защиты от повторов',
	$endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['callbackUrl' => 'https://crm.example.by/cb'])))->body,
	['error' => 'no_hash']
);

Check::group('колбэк только на портал');

$foreign = $endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest([
	'callbackUrl' => 'http://169.254.169.254/latest/meta-data?hash=abc',
])));
Check::same('чужой хост в callbackUrl — 400', $foreign->status, 400);
Check::same('в фон не ушло', $foreign->job, null);

Check::same(
	'чужой хост в errorCallbackUrl — 400',
	$endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['errorCallbackUrl' => 'https://evil.example/x'])))->status,
	400
);
Check::same(
	'user@ в адресе — 400',
	$endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['callbackUrl' => 'https://crm.example.by@evil.example/x'])))->status,
	400
);
Check::same(
	'схема file: — 400',
	$endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['callbackUrl' => 'file:///etc/passwd'])))->status,
	400
);
$guard = new CallbackGuard(['crm.example.by']);
Check::same('user:pass@ на разрешённом хосте — отказ', $guard->isAllowed('https://user:pw@crm.example.by/cb'), false);
Check::same('ftp на разрешённом хосте — отказ', $guard->isAllowed('ftp://crm.example.by/cb'), false);
Check::same('gopher на разрешённом хосте — отказ', $guard->isAllowed('gopher://crm.example.by/cb'), false);
Check::same('чужой порт — отказ', $guard->isAllowed('https://crm.example.by:8443/cb'), false);
Check::same('регистр хоста и схемы не важен', $guard->isAllowed('HTTPS://CRM.Example.BY/cb'), true);
Check::same('порт — часть хоста', (new CallbackGuard(['portal:8080']))->isAllowed('http://portal:8080/cb'), true);
Check::same(
	'хосты не заданы — никуда',
	(new CallbackGuard([]))->isAllowed('https://crm.example.by/x'),
	false
);

Check::group('приём');

$accepted = $endpoint->handle('POST', $query, $body);
Check::same('202, не 200', $accepted->status, 202);
Check::same('тело', $accepted->body, ['accepted' => true]);
Check::same('задание ушло в фон', $accepted->job?->category, 'audio');
Check::same('хэш задания разобран', $accepted->job?->getJobHash(), 'abc123');
Check::same(
	'категория text тоже принимается',
	$endpoint->handle('POST', $query, (string)json_encode(makeCoreRequest(['category' => 'text'])))->status,
	202
);

Check::finish();

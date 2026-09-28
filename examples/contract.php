<?php declare(strict_types=1);

/**
 * Контракт движка целиком — без портала и без денег.
 *
 * ЦЕЛЬ
 *   Пройти путь, который на портале проходит каждый звонок: ядро проверяет
 *   адрес GET-ом, шлёт POST с заданием, эндпоинт отвечает 202, диспетчер
 *   зовёт провайдера и отдаёт результат колбэком. Провайдер — заглушка
 *   EchoProvider, колбэк уходит в транспорт-свидетель, а не в сеть.
 *
 * ГДЕ ПРИМЕНЯТЬ
 *   Перед тем как включать платного провайдера: здесь видно, какие статусы
 *   ждёт ядро и в какой форме уходит результат. Если на стенде что-то не
 *   сходится, сравнивайте с этим выводом.
 *
 * ЧТО ДОЛЖНО ПОЛУЧИТЬСЯ
 *   Все строки «ok», последняя — «ГОТОВО: contract», код возврата 0.
 *   По сути:
 *     * GET — 200 (так ядро проверяет адрес при регистрации движка);
 *     * POST без токена — 403, с токеном — 202, не 200;
 *     * колбэк — {"result": ["…текст…"]} на callbackUrl из запроса;
 *     * повтор того же задания провайдера второй раз не зовёт.
 *
 * ЗАПУСК
 *   php examples/contract.php
 *   DOCUMENT_ROOT=/var/www/portal php examples/contract.php
 *
 * НА ПОРТАЛЕ ОТЛИЧАЕТСЯ
 *   Ничем: и журнал, и транспорт здесь в памяти, в базу портала и в сеть
 *   пример не пишет.
 */

require_once __DIR__.'/_bootstrap.php';

use Shef\ToolsAi\Completion\Callback;
use Shef\ToolsAi\Completion\Dispatcher;
use Shef\ToolsAi\Completion\Endpoint;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Http\TransportInterface;
use Shef\ToolsAi\Provider\EchoProvider;
use Shef\ToolsAi\Quota\Balance;
use Shef\ToolsAi\Quota\LedgerInterface;
use Shef\ToolsAi\Quota\QuotaInterface;
use Shef\ToolsAi\Security\CallbackGuard;
use Shef\ToolsAi\Security\Token;

title('Контракт движка: GET 200, POST 202, колбэк');

/** Транспорт-свидетель: запоминает колбэки, в сеть не ходит. */
$transport = new class implements TransportInterface
{
	public array $posts = [];

	public function post(string $url, string $body, array $headers, int $timeout): Response
	{
		$this->posts[] = ['url' => $url, 'body' => $body];

		return new Response(200, '{"result":true}');
	}

	public function get(string $url, array $headers, int $timeout, int $maxBytes): Response
	{
		return new Response(200, '');
	}
};

/** Журнал в памяти: повтор задания по хэшу — null. */
$ledger = new class implements LedgerInterface
{
	public array $rows = [];

	public function start(string $engineCode, string $category, string $providerCode, ?string $jobHash, int $estimateMicro = 0): ?int
	{
		if($jobHash !== null && in_array($jobHash, array_column($this->rows, 'hash'), true))
		{
			return null;
		}

		$this->rows[] = ['hash' => $jobHash, 'status' => 'PROCESSING'];

		return count($this->rows);
	}

	public function finish(int $id, string $status, int $units = 0, int $costMicro = 0, ?string $error = null): void
	{
		$this->rows[$id - 1]['status'] = $status;
	}
};

$quota = new class implements QuotaInterface
{
	public function getMonthly(): Balance
	{
		return new Balance(0, 0, new DateTimeImmutable('first day of this month'));
	}
};

$token = Token::generate();
$endpoint = new Endpoint($token, new CallbackGuard(['crm.example.by']));
$dispatcher = new Dispatcher(['audio' => new EchoProvider()], $quota, $ledger, new Callback($transport));

$job = [
	'category' => 'audio',
	'payload_provider' => 'audio',
	'prompt' => ['file' => 'https://crm.example.by/bitrix/tools/crm_show_file.php?fileId=1', 'fileExtension' => 'mp3'],
	'payload_markers' => ['language' => 'ru', 'type' => 'audio/mpeg'],
	'callbackUrl' => 'https://crm.example.by/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackSuccess&hash=job1',
	'errorCallbackUrl' => 'https://crm.example.by/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackError&hash=job1',
];

step('Регистрация движка: ядро делает GET на адрес');
check('GET', $endpoint->handle('GET', [], '')->status, 200);

step('Задание от ядра');
check('POST без токена', $endpoint->handle('POST', [], (string)json_encode($job))->status, 403);

$response = $endpoint->handle('POST', ['token' => $token], (string)json_encode($job));
check('POST с токеном — 202, не 200', $response->status, 202);
note('Ответ ушёл, соединение закрыто — дальше работа в фоне.');

step('Фон: провайдер и колбэк');
$dispatcher->dispatch($response->job);
$callback = json_decode($transport->posts[0]['body'] ?? '{}', true);
check('колбэк — на callbackUrl из запроса', $transport->posts[0]['url'] ?? null, $job['callbackUrl']);
check('результат — строкой в result[0]', str_starts_with((string)($callback['result'][0] ?? ''), EchoProvider::MARK), true);
check('журнал: успех', $ledger->rows[0]['status'], 'SUCCESS');
note('Текст: '.strtok((string)($callback['result'][0] ?? ''), "\n"));

step('Повтор того же задания');
$dispatcher->dispatch($response->job);
check('второго колбэка нет', count($transport->posts), 1);

done('contract');

<?php declare(strict_types=1);

/**
 * Подставные реализации интерфейсов модуля: транспорт, журнал, квота,
 * провайдер, логгер. Классы модуля, которые их принимают, — настоящие.
 */

use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Http\TransportInterface;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Provider\Result;
use Shef\ToolsAi\Quota\Balance;
use Shef\ToolsAi\Quota\LedgerInterface;
use Shef\ToolsAi\Quota\QuotaInterface;

/** Запоминает, что ушло, и отдаёт заранее заданные ответы по очереди. */
final class FakeTransport implements TransportInterface
{
	/** @var array<int, array{method: string, url: string, body: string, headers: array, timeout: int, maxBytes: int, allowPrivate: bool}> */
	public array $sent = [];

	/** @var Response[] */
	public array $responses = [];

	public function post(string $url, string $body, array $headers, int $timeout): Response
	{
		$this->sent[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers, 'timeout' => $timeout, 'maxBytes' => 0, 'allowPrivate' => true];

		return array_shift($this->responses) ?? new Response(200, '{}');
	}

	public function get(string $url, array $headers, int $timeout, int $maxBytes, bool $allowPrivate = false): Response
	{
		$this->sent[] = ['method' => 'GET', 'url' => $url, 'body' => '', 'headers' => $headers, 'timeout' => $timeout, 'maxBytes' => $maxBytes, 'allowPrivate' => $allowPrivate];

		return array_shift($this->responses) ?? new Response(200, '');
	}
}

class FakeLedger implements LedgerInterface
{
	/** @var array<int, array> */
	public array $rows = [];

	public function start(string $engineCode, string $category, string $providerCode, ?string $jobHash, int $estimateMicro = 0): ?int
	{
		foreach($this->rows as $row)
		{
			if($jobHash !== null && $row['jobHash'] === $jobHash)
			{
				return null;
			}
		}

		$id = count($this->rows) + 1;
		$this->rows[$id] = [
			'engineCode' => $engineCode,
			'category' => $category,
			'provider' => $providerCode,
			'jobHash' => $jobHash,
			'status' => 'PROCESSING',
			'units' => 0,
			'costMicro' => $estimateMicro,
			'error' => null,
		];

		return $id;
	}

	public function finish(int $id, string $status, int $units = 0, int $costMicro = 0, ?string $error = null): void
	{
		$this->rows[$id] = array_merge($this->rows[$id], [
			'status' => $status,
			'units' => $units,
			'costMicro' => $costMicro,
			'error' => $error,
		]);
	}
}

/**
 * Квота поверх журнала — как настоящий Meter: в расход идут SUCCESS и
 * PROCESSING, то есть и оценка запроса, который сейчас у провайдера.
 */
final class FakeQuota implements QuotaInterface
{
	public function __construct(
		public int $limitMicro = 0,
		public int $spentMicro = 0,
		public ?FakeLedger $ledger = null,
	)
	{
	}

	public function getMonthly(): Balance
	{
		$spent = $this->spentMicro;
		foreach($this->ledger?->rows ?? [] as $row)
		{
			if(in_array($row['status'], ['SUCCESS', 'PROCESSING'], true))
			{
				$spent += $row['costMicro'];
			}
		}

		return new Balance($this->limitMicro, $spent, new DateTimeImmutable('2026-09-01'));
	}
}

final class FakeProvider implements ProviderInterface
{
	public int $calls = 0;

	public function __construct(
		private readonly ?Result $result = null,
		private readonly ?Throwable $throw = null,
		private readonly int $estimate = 0,
	)
	{
	}

	public function getCode(): string
	{
		return 'fake';
	}

	public function estimateCostMicro(Request $request): int
	{
		return $this->estimate;
	}

	public function run(Request $request): Result
	{
		$this->calls++;
		if($this->throw !== null)
		{
			throw $this->throw;
		}

		return $this->result ?? new Result('текст');
	}
}

final class FakeLogger implements Psr\Log\LoggerInterface
{
	/** @var array<int, array{level: string, message: string}> */
	public array $records = [];

	public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
	public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
	public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
	public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
	public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
	public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
	public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
	public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }

	public function log($level, string|\Stringable $message, array $context = []): void
	{
		$this->records[] = ['level' => (string)$level, 'message' => (string)$message];
	}
}

/** Запрос ядра в том виде, в каком его шлёт ThirdParty::completions(). */
function makeCoreRequest(array $override = []): array
{
	return array_replace([
		'prompt' => [
			'file' => 'https://crm.example.by/bitrix/tools/crm_show_file.php?fileId=965723',
			'fields' => [],
			'fileExtension' => 'mp3',
		],
		'payload_raw' => null,
		'payload_provider' => 'audio',
		'payload_role' => null,
		'payload_prompt_text' => null,
		'context' => [],
		'payload_markers' => ['language' => 'ru', 'type' => 'audio/mpeg'],
		'auth' => null,
		'category' => 'audio',
		'ttl' => 300,
		'callbackUrl' => 'https://crm.example.by/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackSuccess&hash=abc123',
		'errorCallbackUrl' => 'https://crm.example.by/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackError&hash=abc123',
	], $override);
}

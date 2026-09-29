<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAi\Quota\Model\UsageTable;

/**
 * Запись расхода. Единственная точка, где пополняется журнал.
 */
final class Ledger implements LedgerInterface
{
	public function start(string $engineCode, string $category, string $providerCode, ?string $jobHash, int $estimateMicro = 0): ?int
	{
		try
		{
			$result = UsageTable::add([
				'CREATED_AT' => new DateTime(),
				'ENGINE_CODE' => $engineCode,
				'CATEGORY' => $category,
				'PROVIDER_CODE' => $providerCode,
				'JOB_HASH' => $jobHash,
				'COST_MICRO' => max(0, $estimateMicro),
				'STATUS' => UsageTable::STATUS_PROCESSING,
			]);
		}
		catch(SqlQueryException $exception)
		{
			// Уникальный индекс по JOB_HASH: задание уже было.
			if($jobHash !== null && $this->exists($jobHash))
			{
				return null;
			}

			throw $exception;
		}

		return $result->isSuccess() ? (int)$result->getId() : null;
	}

	public function finish(int $id, string $status, int $units = 0, int $costMicro = 0, ?string $error = null): void
	{
		UsageTable::update($id, [
			'FINISHED_AT' => new DateTime(),
			'STATUS' => $status,
			'UNITS' => $units,
			'COST_MICRO' => $costMicro,
			'ERROR' => $error !== null ? mb_substr($error, 0, 2000) : null,
		]);
	}

	private function exists(string $jobHash): bool
	{
		return (bool)UsageTable::getList([
			'select' => ['ID'],
			'filter' => ['=JOB_HASH' => $jobHash],
			'limit' => 1,
		])->fetch();
	}
}

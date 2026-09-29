<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAi\Quota\Model\UsageTable;

/**
 * Запись расхода. Единственная точка, где пополняется журнал.
 */
final class Ledger implements LedgerInterface
{
	/**
	 * Через сколько секунд запись «в работе» считается брошенной. С запасом
	 * больше самого долгого живого прогона: таймаут провайдера до 1800 с
	 * (Config::getTimeout()), скачивание записи до 120 с, повторы колбэка.
	 * Меньше — медленный живой прогон отдали бы повтору, и провайдеру
	 * заплатили бы дважды.
	 */
	public const STALE_AFTER = 3600;

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
			$existing = $jobHash !== null ? $this->find($jobHash) : null;
			if($existing === null)
			{
				throw $exception;
			}

			return $this->takeOverStale($existing, $estimateMicro);
		}

		// Не «дубликат», а сбой записи: молча пропустить задание нельзя.
		if(!$result->isSuccess())
		{
			throw new \RuntimeException('Журнал расхода не записан: '.implode('; ', $result->getErrorMessages()));
		}

		return (int)$result->getId();
	}

	/**
	 * Запись «в работе», брошенная умершим процессом (таймаут PHP,
	 * перезапуск apache), — повтор задания забирает её себе. Иначе повтор
	 * пропускался бы навсегда, а ядро так и не получило бы колбэк.
	 *
	 * Забирает условным UPDATE: из двух одновременных повторов пройдёт один.
	 */
	private function takeOverStale(array $row, int $estimateMicro): ?int
	{
		if($row['STATUS'] !== UsageTable::STATUS_PROCESSING)
		{
			return null;
		}

		$created = $row['CREATED_AT'] instanceof DateTime ? $row['CREATED_AT']->getTimestamp() : time();
		if($created > time() - static::STALE_AFTER)
		{
			return null;
		}

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$connection->queryExecute(sprintf(
			"UPDATE %s SET CREATED_AT = %s, COST_MICRO = %d WHERE ID = %d AND STATUS = '%s' AND CREATED_AT = %s",
			UsageTable::getTableName(),
			$helper->getCurrentDateTimeFunction(),
			max(0, $estimateMicro),
			(int)$row['ID'],
			$helper->forSql(UsageTable::STATUS_PROCESSING),
			$helper->convertToDbDateTime($row['CREATED_AT'])
		));

		return $connection->getAffectedRowsCount() === 1 ? (int)$row['ID'] : null;
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

	private function find(string $jobHash): ?array
	{
		$row = UsageTable::getList([
			'select' => ['ID', 'STATUS', 'CREATED_AT'],
			'filter' => ['=JOB_HASH' => $jobHash],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}
}

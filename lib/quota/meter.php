<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Quota\Model\UsageTable;

/**
 * Суммы из журнала расхода.
 *
 * В расход идут успешные записи и записи «в работе»: запрос, который прямо
 * сейчас у провайдера, уже тратит деньги, и параллельный запрос не должен
 * считать их свободными. Сумма «в работе» — оценка до ответа; после ответа
 * её заменяет фактическая.
 *
 * Запись «в работе» старше PROCESSING_TTL в расход не идёт: так остаётся
 * запись процесса, убитого посреди запроса (таймаут PHP, перезапуск
 * php-fpm), и без срока она держала бы квоту до конца месяца.
 */
final class Meter implements QuotaInterface
{
	public const PROCESSING_TTL = 7200;

	public function __construct(private readonly Config $config)
	{
	}

	public function getMonthly(): Balance
	{
		$from = Balance::getMonthStart(new \DateTimeImmutable());

		$row = UsageTable::getList([
			'select' => ['SUM_COST'],
			'filter' => [
				'>=CREATED_AT' => DateTime::createFromTimestamp($from->getTimestamp()),
				[
					'LOGIC' => 'OR',
					['=STATUS' => UsageTable::STATUS_SUCCESS],
					[
						'=STATUS' => UsageTable::STATUS_PROCESSING,
						'>=CREATED_AT' => DateTime::createFromTimestamp(time() - static::PROCESSING_TTL),
					],
				],
			],
			'runtime' => [
				new ExpressionField('SUM_COST', 'SUM(%s)', 'COST_MICRO'),
			],
		])->fetch();

		return new Balance(
			$this->config->getMonthlyQuotaMicro(),
			(int)($row['SUM_COST'] ?? 0),
			$from
		);
	}

	/**
	 * Разбивка по категориям и статусам.
	 *
	 * @return array<string, array{units: int, costMicro: int, count: int}>
	 */
	public function getBreakdown(\DateTimeInterface $from): array
	{
		$rows = UsageTable::getList([
			'select' => ['CATEGORY', 'STATUS', 'SUM_UNITS', 'SUM_COST', 'CNT'],
			'filter' => ['>=CREATED_AT' => DateTime::createFromTimestamp($from->getTimestamp())],
			'group' => ['CATEGORY', 'STATUS'],
			'order' => ['CATEGORY' => 'ASC', 'STATUS' => 'ASC'],
			'runtime' => [
				new ExpressionField('SUM_UNITS', 'SUM(%s)', 'UNITS'),
				new ExpressionField('SUM_COST', 'SUM(%s)', 'COST_MICRO'),
				new ExpressionField('CNT', 'COUNT(%s)', 'ID'),
			],
		])->fetchAll();

		$result = [];
		foreach($rows as $row)
		{
			$result[$row['CATEGORY'].'/'.$row['STATUS']] = [
				'units' => (int)$row['SUM_UNITS'],
				'costMicro' => (int)$row['SUM_COST'],
				'count' => (int)$row['CNT'],
			];
		}

		return $result;
	}

	/**
	 * Последние записи журнала — для страницы расхода.
	 */
	public function getLast(int $limit): array
	{
		return UsageTable::getList([
			'select' => ['ID', 'CREATED_AT', 'CATEGORY', 'PROVIDER_CODE', 'STATUS', 'UNITS', 'COST_MICRO', 'ERROR'],
			'order' => ['ID' => 'DESC'],
			'limit' => $limit,
		])->fetchAll();
	}
}

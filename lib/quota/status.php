<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

/**
 * Статусы записи журнала расхода.
 *
 * Отдельно от таблицы: диспетчер и тесты знают статусы, но не ORM.
 */
final class Status
{
	public const PROCESSING = 'PROCESSING';
	public const SUCCESS = 'SUCCESS';
	public const ERROR = 'ERROR';
	public const QUOTA = 'QUOTA_EXCEEDED';

	/**
	 * @return string[]
	 */
	public static function getList(): array
	{
		return [
			static::PROCESSING,
			static::SUCCESS,
			static::ERROR,
			static::QUOTA,
		];
	}
}

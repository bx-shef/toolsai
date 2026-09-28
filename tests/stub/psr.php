<?php

/**
 * Интерфейсы PSR-3 — то, что Monolog ждёт от psr/log.
 *
 * На портале их даёт ядро (main/vendor) или Composer проекта, в модуле своей
 * копии psr/log нет и не нужно. Тестам без портала их взять неоткуда, поэтому
 * здесь — ровно сигнатуры psr/log 3.0, без логики. Логики в PSR-3 и нет:
 * интерфейс, список уровней и исключение.
 */

namespace Psr\Log
{
	if(!interface_exists(LoggerInterface::class))
	{
		class InvalidArgumentException extends \InvalidArgumentException {}

		class LogLevel
		{
			const EMERGENCY = 'emergency';
			const ALERT = 'alert';
			const CRITICAL = 'critical';
			const ERROR = 'error';
			const WARNING = 'warning';
			const NOTICE = 'notice';
			const INFO = 'info';
			const DEBUG = 'debug';
		}

		interface LoggerInterface
		{
			public function emergency(string|\Stringable $message, array $context = []): void;
			public function alert(string|\Stringable $message, array $context = []): void;
			public function critical(string|\Stringable $message, array $context = []): void;
			public function error(string|\Stringable $message, array $context = []): void;
			public function warning(string|\Stringable $message, array $context = []): void;
			public function notice(string|\Stringable $message, array $context = []): void;
			public function info(string|\Stringable $message, array $context = []): void;
			public function debug(string|\Stringable $message, array $context = []): void;
			public function log($level, string|\Stringable $message, array $context = []): void;
		}
	}
}

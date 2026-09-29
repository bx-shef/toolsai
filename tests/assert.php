<?php declare(strict_types=1);

/**
 * Крошечная обвязка для тестов: ни зависимостей, ни фреймворка.
 *
 * Тесты гоняются как обычные скрипты — `php tests/<имя>_test.php`, — и их же
 * зовёт build.sh, а значит и CI. Ненулевой код возврата означает провал.
 *
 * Warning и notice превращаются в провал. PHP 8 их не роняет, а печатает и
 * идёт дальше: обращение к несуществующему ключу массива, деление на ноль,
 * неявное приведение — всё это прошло бы мимо теста, хотя на портале
 * выглядело бы мусором в логе и неверным поведением.
 */

final class Check
{
	/** @var string[] */
	private static array $errors = [];
	private static int $count = 0;

	public static function boot(): void
	{
		set_error_handler(static function(int $level, string $message, string $file, int $line): bool
		{
			// Заглушённое «@» — не провал: обработчик зовётся и для него, а
			// error_reporting() в этот момент не содержит уровня ошибки. Так
			// делает и ядро. Нашлось на Monolog: @fileinode() на файле, который
			// только что переименовали, — это его штатная проверка.
			if(!(error_reporting() & $level))
			{
				return false;
			}

			throw new ErrorException($message, 0, $level, $file, $line);
		});
	}

	public static function group(string $title): void
	{
		echo PHP_EOL, $title, PHP_EOL;
	}

	/**
	 * Строгое сравнение, без приведения типов: '0' и 0 — разные вещи, и
	 * именно на таком приведении обычно и разъезжается разбор настроек.
	 */
	public static function same(string $what, mixed $actual, mixed $expected): void
	{
		static::$count++;

		if($actual === $expected)
		{
			printf("  OK   %s = %s%s", $what, static::show($actual), PHP_EOL);
			return;
		}

		static::fail(sprintf(
			'%s: получено %s, ожидалось %s',
			$what,
			static::show($actual),
			static::show($expected)
		));
	}

	public static function throws(string $what, string $exceptionClass, callable $call): void
	{
		static::$count++;

		try
		{
			$call();
		}
		catch(Throwable $throwable)
		{
			if($throwable instanceof $exceptionClass)
			{
				printf("  OK   %s бросает %s%s", $what, $exceptionClass, PHP_EOL);
				return;
			}

			static::fail(sprintf(
				'%s: брошено %s, ожидалось %s',
				$what,
				get_class($throwable),
				$exceptionClass
			));

			return;
		}

		static::fail(sprintf('%s: исключение %s не брошено', $what, $exceptionClass));
	}

	private static function fail(string $message): void
	{
		static::$errors[] = $message;
		printf("  FAIL %s%s", $message, PHP_EOL);
	}

	private static function show(mixed $value): string
	{
		if(is_object($value))
		{
			return get_class($value).' '.json_encode($value, JSON_UNESCAPED_UNICODE);
		}

		return var_export($value, true);
	}

	public static function finish(): never
	{
		echo PHP_EOL;

		if(!empty(static::$errors))
		{
			printf('Провалено %d из %d:%s', count(static::$errors), static::$count, PHP_EOL);
			foreach(static::$errors as $error)
			{
				echo '  * ', $error, PHP_EOL;
			}

			exit(1);
		}

		printf('Проверок пройдено: %d%s', static::$count, PHP_EOL);
		exit(0);
	}
}

Check::boot();

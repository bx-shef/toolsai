<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider;

/**
 * Сбой провайдера. Код уходит ядру в error_code колбэка ошибки.
 *
 * Текст — для человека и для журнала: ключей и тел ответов с персональными
 * данными в нём быть не должно.
 */
class ProviderException extends \RuntimeException
{
	public function __construct(
		string $message,
		public readonly string $errorCode = 'provider_error',
		?\Throwable $previous = null,
	)
	{
		parent::__construct($message, 0, $previous);
	}
}

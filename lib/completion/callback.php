<?php declare(strict_types=1);

namespace Shef\ToolsAi\Completion;

use Shef\ToolsAi\Http\TransportInterface;

/**
 * Асинхронный ответ Битриксу по завершении работы провайдера.
 *
 * Принимающая сторона — \Bitrix\AI\Controller\Integration\Thirdparty
 * (ai/lib/controller/integration/thirdparty.php):
 *   callbackSuccessAction -> QueueJob::execute($result->getData())
 *   callbackErrorAction   -> QueueJob::fail($result->getData())
 *
 * URL колбэков НЕ СТРОИМ САМИ — берём из входящего запроса. Маршруты зашиты
 * в QueueJob.php:39,42 и при обновлении могут переехать; пока мы берём их у
 * ядра, переименование нас не касается. См. docs/03-upgrade-watch.md, п. 3.
 */
final class Callback
{
	private const TIMEOUT = 20;

	public function __construct(private readonly TransportInterface $transport)
	{
	}

	/**
	 * Успешный результат.
	 *
	 * Форма {"result": ["текст"]} разбирается в ThirdParty::getResultFromRaw()
	 * (ThirdParty.php:159-179): берётся result[0] для всех категорий, кроме image.
	 *
	 * ПУСТОЙ ТЕКСТ ОТПРАВЛЯТЬ НЕЛЬЗЯ: canProceedToNextStep()
	 * (transcribecallrecording.php:83-93) проверяет !empty($payload->transcription)
	 * и при пустом значении обрывает цепочку МОЛЧА. Явная ошибка лучше.
	 */
	public function success(string $callbackUrl, string $errorCallbackUrl, string $text): bool
	{
		if(trim($text) === '')
		{
			return $this->error($errorCallbackUrl, 'Провайдер вернул пустой результат', 'empty_result');
		}

		return $this->send($callbackUrl, ['result' => [$text]]);
	}

	public function error(string $errorCallbackUrl, string $message, string $code = 'provider_error'): bool
	{
		return $this->send($errorCallbackUrl, [
			'error' => $message,
			'error_code' => $code,
		]);
	}

	private function send(string $url, array $body): bool
	{
		if($url === '')
		{
			return false;
		}

		$response = $this->transport->post(
			$url,
			(string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			['Content-Type' => 'application/json'],
			self::TIMEOUT
		);

		return $response->status === 200;
	}
}

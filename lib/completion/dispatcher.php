<?php declare(strict_types=1);

namespace Shef\ToolsAi\Completion;

use Psr\Log\LoggerInterface;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Quota\LedgerInterface;
use Shef\ToolsAi\Quota\QuotaInterface;
use Shef\ToolsAi\Quota\Status;

/**
 * Маршрутизация запроса на провайдера + учёт расхода.
 *
 * Вызывается УЖЕ ПОСЛЕ того, как эндпоинт ответил Битриксу 202: таймаут
 * запроса — 5 секунд (ThirdParty::HTTP_TIMEOUT), а поход к ASR/LLM в них не
 * укладывается никогда.
 *
 * Здесь же единственное место, где считается расход. Битрикс для нашего
 * движка не считает НИЧЕГО: ThirdParty::checkLimits() (ThirdParty.php:289)
 * отдаёт false для любого кода, кроме itsolutionru.gptconnector, и блок
 * лимитера в Engine::completions() (Engine.php:852-870) пропускается целиком.
 *
 * Порядок — не вкусовщина:
 *
 * 1. журнал (start) — ДО провайдера: повтор того же задания отсекается
 *    уникальным индексом, а не проверкой «было ли», которую два повтора
 *    проходят одновременно;
 * 2. квота — до провайдера, по оценке;
 * 3. расход (finish) — ДО колбэка: колбэк может не дойти, а деньги у
 *    провайдера уже потрачены и должны быть видны в отчёте.
 */
final class Dispatcher
{
	/**
	 * @param array<string, ProviderInterface> $providers ключ — категория движка
	 */
	public function __construct(
		private readonly array $providers,
		private readonly QuotaInterface $quota,
		private readonly LedgerInterface $ledger,
		private readonly Callback $callback,
		private readonly ?LoggerInterface $logger = null,
	)
	{
	}

	public function dispatch(Request $request): void
	{
		if(!$request->isValid())
		{
			// Отвечать некуда — только в лог.
			$this->logger?->error('Запрос без категории или адреса колбэка');

			return;
		}

		$provider = $this->providers[$request->category] ?? null;
		if($provider === null)
		{
			$this->callback->error(
				$request->errorCallbackUrl,
				'Нет провайдера для категории '.$request->category,
				'no_provider'
			);

			return;
		}

		$estimate = max(0, $provider->estimateCostMicro($request));

		$id = $this->ledger->start(
			Constants::getEngineCode($request->category),
			$request->category,
			$provider->getCode(),
			$request->getJobHash(),
			$estimate
		);

		if($id === null)
		{
			// Повторная доставка того же задания — квоту второй раз не тратим
			// и колбэк второй раз не шлём: первый прогон его уже отправил.
			$this->logger?->info('Задание уже обработано, повтор пропущен', ['hash' => $request->getJobHash()]);

			return;
		}

		// Квота проверяется после start(): своя оценка уже в сумме, поэтому
		// вопрос не «хватит ли на оценку», а «не вышли ли за квоту с ней».
		if($this->quota->getMonthly()->isExceeded())
		{
			$this->ledger->finish($id, Status::QUOTA, error: 'Месячная квота исчерпана');
			$this->callback->error($request->errorCallbackUrl, 'Месячная квота на ИИ исчерпана', 'quota_exceeded');

			return;
		}

		try
		{
			$result = $provider->run($request);
		}
		catch(\Throwable $throwable)
		{
			$code = $throwable instanceof ProviderException ? $throwable->errorCode : 'provider_error';

			$this->ledger->finish($id, Status::ERROR, error: $throwable->getMessage());
			$this->logger?->error($throwable, ['category' => $request->category, 'hash' => $request->getJobHash()]);
			$this->callback->error($request->errorCallbackUrl, $throwable->getMessage(), $code);

			return;
		}

		$this->ledger->finish($id, Status::SUCCESS, $result->units, $result->costMicro);

		if(!$this->callback->success($request->callbackUrl, $request->errorCallbackUrl, $result->text))
		{
			$this->logger?->error('Колбэк не принят порталом — проверьте ai::public_url и внешний адрес в настройках', [
				'category' => $request->category,
				'hash' => $request->getJobHash(),
			]);
		}
	}
}

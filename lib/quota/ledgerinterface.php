<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

/**
 * Журнал расхода.
 *
 * Запись идёт в два шага — start() до обращения к провайдеру и finish()
 * после, — и это ради идемпотентности: start() с хэшем задания, который уже
 * есть в журнале, возвращает null, и второй раз провайдеру за то же задание
 * мы не платим. Проверка «есть ли хэш» и вставка отдельными запросами
 * пропустили бы два одновременных повтора; уникальный индекс — нет.
 */
interface LedgerInterface
{
	/**
	 * @param int $estimateMicro оценка стоимости: пока запрос у провайдера,
	 *        она уже занимает квоту
	 * @return int|null ID записи; null — задание с этим хэшем уже обработано
	 *         или обрабатывается живым процессом. Запись «в работе», брошенная
	 *         умершим процессом, отдаётся заново.
	 * @throws \RuntimeException журнал не записан — это не «дубликат»
	 */
	public function start(string $engineCode, string $category, string $providerCode, ?string $jobHash, int $estimateMicro = 0): ?int;

	public function finish(int $id, string $status, int $units = 0, int $costMicro = 0, ?string $error = null): void;
}

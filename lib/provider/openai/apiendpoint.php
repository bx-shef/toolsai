<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

/**
 * Точка доступа к OpenAI-совместимому API для одного направления:
 * распознавание (audio), текст Копилота (text), анализ сделок (deal).
 *
 * Собирает Config (getAsrEndpoint(), getTextEndpoint(), getDealEndpoint())
 * с откатом на общие настройки; Client берёт адрес, ключ и таймаут только
 * отсюда. Так распознавание может идти на свой whisper в Docker, а текст — на
 * DeepSeek (с 1.5.0).
 *
 * Цены: для текста и сделок — за миллион входных и выходных токенов; для
 * распознавания priceInMicro — цена минуты, priceOutMicro не используется.
 */
final class ApiEndpoint
{
	public function __construct(
		public readonly string $baseUrl,
		public readonly string $apiKey,
		public readonly int $timeout,
		public readonly string $model,
		public readonly int $priceInMicro = 0,
		public readonly int $priceOutMicro = 0,
	)
	{
	}

	/**
	 * Адрес для показа на странице и в отчёте: без ключа (его здесь и нет)
	 * и без логина-пароля в адресе (https://user:pass@host).
	 */
	public function getDisplayUrl(): string
	{
		return (string)preg_replace('~//[^/@]+@~', '//***@', $this->baseUrl);
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Http\TransportInterface;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Provider\Result;

/**
 * Распознавание речи через /audio/transcriptions (Whisper и совместимые).
 *
 * Запись доступна по URL из prompt.file. Он строится в
 * TranscribeCallRecording::getFileInfo() (transcribecallrecording.php:144-189)
 * и при локальном хранении файла дополняется хостом из ai::public_url.
 * Неверный public_url — недостижимый URL: ошибка здесь, а не тишина.
 */
final class AsrProvider implements ProviderInterface
{
	/**
	 * Верхняя граница размера записи: столько же пропускает
	 * SuitableAudiosChecker ядра (25 МБ), и таков же лимит OpenAI на файл.
	 */
	public const MAX_BYTES = 25 * 1024 * 1024;

	/** Оценка до скачивания: верхняя граница длительности из SuitableAudiosChecker. */
	private const ESTIMATE_SECONDS = 3600;

	private const DOWNLOAD_TIMEOUT = 120;

	public function __construct(
		private readonly Config $config,
		private readonly Client $client,
		private readonly TransportInterface $transport,
	)
	{
	}

	public function getCode(): string
	{
		return Constants::PROVIDER_OPENAI;
	}

	public function estimateCostMicro(Request $request): int
	{
		return $this->getCostMicro(self::ESTIMATE_SECONDS);
	}

	public function getCostMicro(float $seconds): int
	{
		return (int)ceil($seconds * $this->config->getAsrPricePerMinuteMicro() / 60);
	}

	public function run(Request $request): Result
	{
		$url = $request->getAudioUrl();
		if($url === null)
		{
			throw new ProviderException('В запросе нет адреса записи', 'no_file');
		}

		$download = $this->transport->get($url, [], self::DOWNLOAD_TIMEOUT, self::MAX_BYTES);
		if($download->status !== 200 || $download->body === '')
		{
			// Адрес в текст ошибки не кладём: в нём параметры доступа к файлу.
			throw new ProviderException(
				sprintf(
					'Не удалось скачать запись: HTTP %d%s. Проверьте ai::public_url — от него строится адрес записи',
					$download->status,
					$download->error !== '' ? ', '.$download->error : ''
				),
				'file_download'
			);
		}

		$extension = $request->getAudioExtension();

		$data = $this->client->postFile(
			'audio/transcriptions',
			[
				'model' => $this->config->getAsrModel(),
				'language' => $request->getLanguage(),
				// verbose_json отдаёт длительность — по ней считается расход.
				'response_format' => 'verbose_json',
			],
			'file',
			'record.'.$extension,
			$request->getAudioMimeType() ?? 'audio/'.($extension === 'mp3' ? 'mpeg' : $extension),
			$download->body
		);

		$text = is_string($data['text'] ?? null) ? trim($data['text']) : '';
		if($text === '')
		{
			throw new ProviderException('Распознавание вернуло пустой текст', 'empty_result');
		}

		$seconds = is_numeric($data['duration'] ?? null) ? (float)$data['duration'] : 0.0;

		return new Result($text, (int)ceil($seconds), $this->getCostMicro($seconds));
	}
}

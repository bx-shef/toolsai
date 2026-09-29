<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\OpenAi;

use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Http\TransportInterface;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Provider\ProviderInterface;
use Shef\ToolsAi\Provider\Result;
use Shef\ToolsAi\Security\CallbackGuard;

/**
 * Распознавание речи через /audio/transcriptions (Whisper и совместимые).
 *
 * Запись доступна по URL из prompt.file. Он строится в
 * TranscribeCallRecording::getFileInfo() (transcribecallrecording.php:144-189)
 * и при локальном хранении файла дополняется хостом из ai::public_url.
 * Неверный public_url — недостижимый URL: ошибка здесь, а не тишина.
 *
 * Адрес записи приходит в теле запроса, поэтому скачивание — под SSRF-защитой:
 * приватные адреса разрешены только для хоста портала (файл лежит в его
 * /upload), любой другой хост — облачное хранилище — качается с защитой ядра
 * от приватных адресов. Редиректы проходятся вручную, и каждый шаг
 * проверяется заново: crm_show_file может отдать редирект в облако, а
 * редирект с разрешённого хоста мог бы увести во внутреннюю сеть.
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

	private const REDIRECT_MAX = 3;

	public function __construct(
		private readonly Config $config,
		private readonly Client $client,
		private readonly TransportInterface $transport,
		private readonly CallbackGuard $portal,
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

		$download = $this->download($url);

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

	/**
	 * @throws ProviderException
	 */
	private function download(string $url): Response
	{
		for($hop = 0; $hop <= self::REDIRECT_MAX; $hop++)
		{
			$scheme = parse_url($url, PHP_URL_SCHEME);
			if(!is_string($scheme) || !in_array(mb_strtolower($scheme), ['http', 'https'], true))
			{
				throw new ProviderException('Адрес записи не http(s)', 'file_download');
			}

			$response = $this->transport->get($url, [], self::DOWNLOAD_TIMEOUT, self::MAX_BYTES, $this->portal->isAllowed($url));

			if($response->isRedirect())
			{
				$url = static::resolveLocation($url, $response->location);
				continue;
			}

			// Адрес в текст ошибки не кладём: в нём параметры доступа к файлу.
			if($response->status !== 200 || $response->error !== '' || $response->body === '')
			{
				throw new ProviderException(
					sprintf(
						'Не удалось скачать запись: HTTP %d%s. Проверьте ai::public_url — от него строится адрес записи',
						$response->status,
						$response->error !== '' ? ', '.$response->error : ''
					),
					'file_download'
				);
			}

			// Тело длиннее лимита — не запись, а обрезок: распознавать его нельзя.
			if(strlen($response->body) > self::MAX_BYTES)
			{
				throw new ProviderException('Запись больше 25 МБ', 'file_download');
			}

			return $response;
		}

		throw new ProviderException('Слишком много редиректов при скачивании записи', 'file_download');
	}

	/**
	 * Абсолютный адрес из Location: абсолютный как есть, от корня — к хосту
	 * исходного адреса. Относительный без слэша ядро не отдаёт; считаем его от
	 * корня.
	 */
	public static function resolveLocation(string $from, string $location): string
	{
		if(1 === preg_match('~^https?://~i', $location))
		{
			return $location;
		}

		$scheme = (string)parse_url($from, PHP_URL_SCHEME);
		$host = (string)parse_url($from, PHP_URL_HOST);
		$port = parse_url($from, PHP_URL_PORT);

		if(str_starts_with($location, '//'))
		{
			return $scheme.':'.$location;
		}

		return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '').'/'.ltrim($location, '/');
	}
}

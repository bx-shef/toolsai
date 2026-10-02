<?php declare(strict_types=1);

namespace Shef\ToolsAi;

use Bitrix\Main\Config\Option;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\OptionParser;

/**
 * Настройки модуля.
 *
 * Имя настройки — <код вкладки>_<код опции> (так их хранит страница настроек
 * shef.options): DEF_quota, API_apikey. Коды вкладок менять нельзя —
 * сохранённые значения останутся под старыми именами.
 *
 * Все значения разбираются строго (Main\OptionParser): опечатка в квоте или
 * в ID старшего не превращается молча в другое число.
 *
 * Чтение — через $reader, чтобы логику можно было проверить без портала.
 */
class Config
{
	public const MODULE_ID = Constants::MODULE_ID;

	public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
	public const DEFAULT_ASR_MODEL = 'whisper-1';
	public const DEFAULT_LLM_MODEL = 'gpt-4o-mini';
	public const DEFAULT_TIMEOUT = 120;
	public const DEFAULT_THRESHOLD = 70;
	public const DEFAULT_MAX_PER_RUN = 20;
	public const DEFAULT_REANALYZE_DAYS = 7;
	public const DEFAULT_IDLE_DAYS = 3;

	/** Токен эндпоинта: не на странице настроек, генерируется Setup. */
	public const OPTION_TOKEN = 'SYS_token';

	/** @var callable(string $module, string $name): mixed */
	private $reader;

	public function __construct(?callable $reader = null)
	{
		$this->reader = $reader ?? static fn(string $module, string $name): mixed => Option::get($module, $name, '');
	}

	private function get(string $name): mixed
	{
		return ($this->reader)(static::MODULE_ID, $name);
	}

	// region Движок ////
	/**
	 * Внешний адрес портала: на него Битрикс шлёт запросы движка, по нему же
	 * строятся колбэк и ссылка на запись звонка.
	 *
	 * Своя настройка, иначе ai::public_url. Пусто — движок не регистрируется:
	 * UrlManager::getHostUrl() за прокси даёт внутренний адрес, и колбэк
	 * уходит в никуда (docs/00-research.md, раздел 6).
	 */
	public function getPublicUrl(): string
	{
		$own = OptionParser::url($this->get('DEF_publicurl'));
		if($own !== '')
		{
			return $own;
		}

		return $this->getAiPublicUrl();
	}

	/**
	 * Внешний адрес задан, но не разобрался (например, без схемы:
	 * «crm.example.by»): «откуда «значение»». Пусто — адрес не задан или в
	 * порядке. Нужен, чтобы отчёт не говорил «не задан», когда он задан с
	 * ошибкой, и называл настройку, где её искать.
	 */
	public function getRejectedPublicUrl(): string
	{
		$sources = [
			'внешний адрес в настройках модуля' => $this->get('DEF_publicurl'),
			'ai::public_url' => ($this->reader)('ai', 'public_url'),
		];
		foreach($sources as $source => $raw)
		{
			if(is_string($raw) && trim($raw) !== '' && OptionParser::url($raw) === '')
			{
				return $source.' «'.trim($raw).'»';
			}
			if(is_string($raw) && OptionParser::url($raw) !== '')
			{
				return '';
			}
		}

		return '';
	}

	public function getAiPublicUrl(): string
	{
		return OptionParser::url(($this->reader)('ai', 'public_url'));
	}

	public function getToken(): string
	{
		$token = $this->get(static::OPTION_TOKEN);

		return is_string($token) && 1 === preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
	}

	/**
	 * Адрес эндпоинта вместе с токеном.
	 *
	 * Токен — в адресе, а не в заголовке, и это не небрежность: запрос шлёт
	 * ядро (ThirdParty::completions()), своих заголовков оно не добавляет, а
	 * completions_url — единственное, что задаём мы. Подпись заголовком из
	 * кита (X-Shef-Signature) до эндпоинта не доехала бы ни разу.
	 *
	 * @return string '' — не хватает внешнего адреса или токена
	 */
	public function getCompletionsUrl(): string
	{
		$publicUrl = $this->getPublicUrl();
		$token = $this->getToken();

		if($publicUrl === '' || $token === '')
		{
			return '';
		}

		return $publicUrl.Constants::ENDPOINT_FILE.'?'.Constants::TOKEN_PARAM.'='.$token;
	}

	/**
	 * Хосты, на которые эндпоинт согласен слать колбэк.
	 *
	 * URL колбэка приходит в теле запроса. Без сверки украденный токен
	 * превращал бы модуль в отправщик POST на любой адрес.
	 *
	 * @return string[] хост[:порт] строчными
	 */
	public function getAllowedCallbackHosts(): array
	{
		$hosts = [];
		foreach([$this->getPublicUrl(), $this->getAiPublicUrl()] as $url)
		{
			$host = static::hostOf($url);
			if($host !== '')
			{
				$hosts[] = $host;
			}
		}

		return array_values(array_unique($hosts));
	}

	public static function hostOf(string $url): string
	{
		$host = parse_url($url, PHP_URL_HOST);
		if(!is_string($host) || $host === '')
		{
			return '';
		}

		$port = parse_url($url, PHP_URL_PORT);

		return mb_strtolower($host).(is_int($port) ? ':'.$port : '');
	}

	public function getProviderCode(string $category): string
	{
		$code = $this->get($category === Constants::CATEGORY_AUDIO ? 'DEF_provideraudio' : 'DEF_providertext');

		return in_array($code, Constants::getProviderList(), true) ? $code : Constants::PROVIDER_ECHO;
	}

	/** Месячная квота в микро-единицах валюты. 0 — без ограничения. */
	public function getMonthlyQuotaMicro(): int
	{
		return OptionParser::micro($this->get('DEF_quota'));
	}
	// endregion ////

	// region OpenAI-совместимое API ////
	public function getBaseUrl(): string
	{
		return OptionParser::url($this->get('API_baseurl')) ?: static::DEFAULT_BASE_URL;
	}

	/** Ключ API. Хранится только в настройках, в лог не пишется. */
	public function getApiKey(): string
	{
		$key = $this->get('API_apikey');

		return is_string($key) ? trim($key) : '';
	}

	public function getAsrModel(): string
	{
		$model = trim((string)$this->get('API_asrmodel'));

		return $model !== '' ? $model : static::DEFAULT_ASR_MODEL;
	}

	public function getLlmModel(): string
	{
		$model = trim((string)$this->get('API_llmmodel'));

		return $model !== '' ? $model : static::DEFAULT_LLM_MODEL;
	}

	/** Цена минуты распознавания, микро-единицы. */
	public function getAsrPricePerMinuteMicro(): int
	{
		return OptionParser::micro($this->get('API_asrprice'));
	}

	/** Цена миллиона входных токенов, микро-единицы. */
	public function getLlmPriceInMicro(): int
	{
		return OptionParser::micro($this->get('API_llmpricein'));
	}

	/** Цена миллиона выходных токенов, микро-единицы. */
	public function getLlmPriceOutMicro(): int
	{
		return OptionParser::micro($this->get('API_llmpriceout'));
	}

	public function getTimeout(): int
	{
		return OptionParser::int($this->get('API_timeout'), static::DEFAULT_TIMEOUT, 5, 1800);
	}
	// endregion ////

	// region Анализ сделок ////
	public function isDealHealthEnabled(): bool
	{
		return OptionParser::flag($this->get('DEAL_enabled'));
	}

	/**
	 * Направления сделок для анализа.
	 *
	 * @return int[]|null null — настройка испорчена: агент не работает, а не
	 *                    берёт «все направления»
	 */
	public function getDealCategories(): ?array
	{
		return OptionParser::idList($this->get('DEAL_categories'));
	}

	/** Порог риска 0-100, с которого зовём старшего. */
	public function getEscalationThreshold(): int
	{
		return OptionParser::int($this->get('DEAL_threshold'), static::DEFAULT_THRESHOLD, 0, 100);
	}

	/** Кому ставить дело эскалации. 0 — ответственному за сделку не ставим, только таймлайн. */
	public function getSeniorUserId(): int
	{
		return OptionParser::id($this->get('DEAL_senior'));
	}

	public function getMaxPerRun(): int
	{
		return OptionParser::int($this->get('DEAL_maxperrun'), static::DEFAULT_MAX_PER_RUN, 1, 500);
	}

	public function getReanalyzeDays(): int
	{
		return OptionParser::int($this->get('DEAL_reanalyzedays'), static::DEFAULT_REANALYZE_DAYS, 1, 365);
	}

	/** Сколько дней без дел считать «работа не идёт». */
	public function getIdleDays(): int
	{
		return OptionParser::int($this->get('DEAL_idledays'), static::DEFAULT_IDLE_DAYS, 0, 365);
	}
	// endregion ////
}

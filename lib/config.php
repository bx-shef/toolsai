<?php declare(strict_types=1);

namespace Shef\ToolsAi;

use Bitrix\Main\Config\Option;
use Shef\ToolsAi\Main\Constants;
use Shef\ToolsAi\Main\OptionParser;
use Shef\ToolsAi\Provider\OpenAi\ApiEndpoint;

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
	public const DEFAULT_MAX_PER_RUN = 50;
	/** Интервал агента анализа сделок, минут (DEAL_interval). С 1.4.0. */
	public const DEFAULT_AGENT_INTERVAL_MINUTES = 60;
	public const AGENT_INTERVAL_MIN = 10;
	public const AGENT_INTERVAL_MAX = 10080;
	public const DEFAULT_REANALYZE_DAYS = 7;
	public const DEFAULT_IDLE_DAYS = 3;
	/** Оценка чатов (1.6.0): оценок за прогон и окно «закрыт за последние N дней». */
	public const DEFAULT_CHAT_MAX_PER_RUN = 20;
	public const DEFAULT_CHAT_DAYS = 3;

	/**
	 * Настройки анализа сделок до 1.1.0. Теперь это поля профилей
	 * (Deal\Profile); Config читает их только для переноса
	 * (Deal\ProfileMigration::plan()), на странице настроек их нет.
	 */
	public const LEGACY_DEAL_OPTIONS = ['DEAL_categories', 'DEAL_threshold', 'DEAL_senior', 'DEAL_reanalyzedays', 'DEAL_idledays'];

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

	/** Поля, которые дополнительные параметры не перекрывают: их задаёт модуль. */
	public const LLM_PROTECTED_KEYS = ['model', 'messages', 'response_format', 'stream', 'n'];

	/**
	 * Дополнительные параметры /chat/completions — JSON-объект из настроек:
	 * выключить рассуждения ({"thinking":{"type":"disabled"}}), задать
	 * reasoning_effort, temperature. Провайдеры называют это по-разному,
	 * поэтому — как есть, без знания модуля (bx-shef/toolsai#12). Не JSON-
	 * объект — пусто; модель, сообщения и формат ответа задаёт модуль.
	 */
	/**
	 * Свои промпты для резюме, заполнения полей и оценки звонка (Completion\CopilotPrompt)
	 * вместо промпта ядра. По умолчанию выключено: ядро присылает готовый
	 * промпт Копилота (prompt), и он первый кандидат; свои — если на
	 * портале ответы по промпту ядра не годятся (bx-shef/toolsai#11).
	 */
	public function isOwnPromptsEnabled(): bool
	{
		return OptionParser::flag($this->get('API_ownprompts'));
	}

	public function getLlmExtra(): array
	{
		$raw = trim((string)$this->get('API_llmextra'));
		if($raw === '')
		{
			return [];
		}

		$data = json_decode($raw, true);
		if(!is_array($data) || ($data !== [] && array_is_list($data)))
		{
			return [];
		}

		return array_diff_key($data, array_flip(static::LLM_PROTECTED_KEYS));
	}

	/** Дополнительные параметры заданы, но не разобрались (не JSON-объект). */
	public function isLlmExtraBroken(): bool
	{
		$raw = trim((string)$this->get('API_llmextra'));
		$data = $raw === '' ? [] : json_decode($raw, true);

		return !is_array($data) || ($data !== [] && array_is_list($data));
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
		return $this->parseTimeout($this->get('API_timeout'), static::DEFAULT_TIMEOUT);
	}

	private function parseTimeout(mixed $raw, int $default): int
	{
		return OptionParser::int($raw, $default, 5, 1800);
	}
	// endregion ////

	// region Точки доступа по направлениям (1.5.0) ////
	/**
	 * Направления, у которых своя точка доступа: распознавание, текст
	 * Копилота, анализ сделок. Коды audio и text совпадают с категориями
	 * движка.
	 */
	public const DIRECTION_AUDIO = Constants::CATEGORY_AUDIO;
	public const DIRECTION_TEXT = Constants::CATEGORY_TEXT;
	public const DIRECTION_DEAL = 'deal';

	/** @return string[] */
	public static function getDirectionList(): array
	{
		return [static::DIRECTION_AUDIO, static::DIRECTION_TEXT, static::DIRECTION_DEAL];
	}

	public function getEndpoint(string $direction): ApiEndpoint
	{
		return match($direction)
		{
			static::DIRECTION_AUDIO => $this->getAsrEndpoint(),
			static::DIRECTION_DEAL => $this->getDealEndpoint(),
			default => $this->getTextEndpoint(),
		};
	}

	/** Общая точка: API_baseurl, API_apikey, API_timeout — откат для всех направлений. */
	private function getCommonEndpoint(): ApiEndpoint
	{
		return new ApiEndpoint($this->getBaseUrl(), $this->getApiKey(), $this->getTimeout(), '');
	}

	/**
	 * Распознавание: свои адрес, ключ, таймаут (API_asrbaseurl, API_asrapikey,
	 * API_asrtimeout), пусто — общие. Модель и цена минуты — как были
	 * (API_asrmodel, API_asrprice).
	 */
	public function getAsrEndpoint(): ApiEndpoint
	{
		$common = $this->getCommonEndpoint();
		[$url, $key] = $this->resolveAccess($this->get('API_asrbaseurl'), $this->get('API_asrapikey'), $common);

		return new ApiEndpoint(
			$url,
			$key,
			$this->parseTimeout($this->get('API_asrtimeout'), $common->timeout),
			$this->getAsrModel(),
			$this->getAsrPricePerMinuteMicro(),
		);
	}

	/**
	 * Текст Копилота (резюме, поля, оценка, дела после разговора): свои адрес,
	 * ключ, таймаут (API_llmbaseurl, API_llmapikey, API_llmtimeout), пусто —
	 * общие. Модель и цены — API_llmmodel, API_llmpricein/out.
	 */
	public function getTextEndpoint(): ApiEndpoint
	{
		$common = $this->getCommonEndpoint();
		[$url, $key] = $this->resolveAccess($this->get('API_llmbaseurl'), $this->get('API_llmapikey'), $common);

		return new ApiEndpoint(
			$url,
			$key,
			$this->parseTimeout($this->get('API_llmtimeout'), $common->timeout),
			$this->getLlmModel(),
			$this->getLlmPriceInMicro(),
			$this->getLlmPriceOutMicro(),
		);
	}

	/**
	 * Анализ сделок: свои адрес, ключ, таймаут, модель, цены (API_deal*);
	 * пусто — как у текста (а у текста пусто — общие).
	 */
	public function getDealEndpoint(): ApiEndpoint
	{
		$text = $this->getTextEndpoint();
		[$url, $key] = $this->resolveAccess($this->get('API_dealbaseurl'), $this->get('API_dealapikey'), $text);
		$model = trim((string)$this->get('API_dealmodel'));
		$priceIn = $this->get('API_dealpricein');
		$priceOut = $this->get('API_dealpriceout');

		return new ApiEndpoint(
			$url,
			$key,
			$this->parseTimeout($this->get('API_dealtimeout'), $text->timeout),
			$model !== '' ? $model : $text->model,
			static::isBlank($priceIn) ? $text->priceInMicro : OptionParser::micro($priceIn),
			static::isBlank($priceOut) ? $text->priceOutMicro : OptionParser::micro($priceOut),
		);
	}

	/**
	 * Адрес и ключ направления с откатом на родителя.
	 *
	 * Адрес: свой, пусто — родителя. Ключ: свой; пусто — ключ родителя, но
	 * только если и адрес родительский. Общий ключ (например, OpenAI) не
	 * должен уехать на чужой сервер, когда администратор задал направлению
	 * свой адрес и не задал ключ: свой whisper в Docker ключа не требует, и
	 * запрос туда уходит без заголовка Authorization.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolveAccess(mixed $rawUrl, mixed $rawKey, ApiEndpoint $parent): array
	{
		$url = OptionParser::url($rawUrl) ?: $parent->baseUrl;
		$key = is_string($rawKey) ? trim($rawKey) : '';
		if($key === '' && $url === $parent->baseUrl)
		{
			$key = $parent->apiKey;
		}

		return [$url, $key];
	}

	private static function isBlank(mixed $value): bool
	{
		return $value === null || (is_string($value) && trim($value) === '');
	}

	/**
	 * Адреса API, которые заданы, но не разобрались (без схемы, с ?query):
	 * коды настроек. Такое направление молча ушло бы на общий адрес — отчёт
	 * и страница расхода говорят об этом вслух. Значение не отдаём: в
	 * «адресе» с ?query бывает и ключ.
	 *
	 * @return string[]
	 */
	public function getRejectedApiUrls(): array
	{
		$rejected = [];
		foreach(['API_baseurl' => $this->get('API_baseurl'), 'API_asrbaseurl' => $this->get('API_asrbaseurl'), 'API_llmbaseurl' => $this->get('API_llmbaseurl'), 'API_dealbaseurl' => $this->get('API_dealbaseurl')] as $code => $raw)
		{
			if(is_string($raw) && trim($raw) !== '' && OptionParser::url($raw) === '')
			{
				$rejected[] = $code;
			}
		}

		return $rejected;
	}
	// endregion ////

	// region Анализ сделок ////
	public function isDealHealthEnabled(): bool
	{
		return OptionParser::flag($this->get('DEAL_enabled'));
	}

	/**
	 * Направления сделок для анализа. Устарело (1.1.0): только для переноса
	 * в профили.
	 *
	 * @return int[]|null null — настройка испорчена: агент не работает, а не
	 *                    берёт «все направления»
	 */
	public function getDealCategories(): ?array
	{
		return OptionParser::idList($this->get('DEAL_categories'));
	}

	/** Порог риска 0-100, с которого зовём старшего. Устарело: для переноса. */
	public function getEscalationThreshold(): int
	{
		return OptionParser::int($this->get('DEAL_threshold'), static::DEFAULT_THRESHOLD, 0, 100);
	}

	/** Кому ставить дело эскалации. 0 — только таймлайн. Устарело: для переноса. */
	public function getSeniorUserId(): int
	{
		return OptionParser::id($this->get('DEAL_senior'));
	}

	public function getMaxPerRun(): int
	{
		return OptionParser::int($this->get('DEAL_maxperrun'), static::DEFAULT_MAX_PER_RUN, 1, 500);
	}

	/** Устарело (1.1.0): для переноса в профили. */
	/** Интервал агента анализа сделок в секундах: DEAL_interval — минуты, 10..10080 (неделя). */
	public function getAgentInterval(): int
	{
		return 60 * OptionParser::int($this->get('DEAL_interval'), static::DEFAULT_AGENT_INTERVAL_MINUTES, static::AGENT_INTERVAL_MIN, static::AGENT_INTERVAL_MAX);
	}

	public function getReanalyzeDays(): int
	{
		return OptionParser::int($this->get('DEAL_reanalyzedays'), static::DEFAULT_REANALYZE_DAYS, 1, 365);
	}

	/** Сколько дней без дел считать «работа не идёт». Устарело: для переноса. */
	public function getIdleDays(): int
	{
		return OptionParser::int($this->get('DEAL_idledays'), static::DEFAULT_IDLE_DAYS, 0, 365);
	}
	// endregion ////

	// region Оценка чатов (1.6.0) ////
	/** Своя оценка переписки по скрипту (Agent\ChatAssessmentAgent). По умолчанию выключена. */
	public function isChatAssessmentEnabled(): bool
	{
		return OptionParser::flag($this->get('CHAT_enabled'));
	}

	/** Оценок за прогон агента, не больше: 1..200, по умолчанию 20. */
	public function getChatMaxPerRun(): int
	{
		return OptionParser::int($this->get('CHAT_maxperrun'), static::DEFAULT_CHAT_MAX_PER_RUN, 1, 200);
	}

	/** Берём диалоги, закрытые за последние N дней: 1..60, по умолчанию 3. */
	public function getChatDays(): int
	{
		return OptionParser::int($this->get('CHAT_days'), static::DEFAULT_CHAT_DAYS, 1, 60);
	}

	/**
	 * ID скрипта речевой аналитики (b_crm_copilot_call_assessment) для всех
	 * чатов. 0 — подбирать как звонку (Chat\DialogSource::pickScript()).
	 */
	public function getChatScriptId(): int
	{
		return OptionParser::id($this->get('CHAT_script'));
	}
	// endregion ////

}

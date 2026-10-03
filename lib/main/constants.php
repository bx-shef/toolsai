<?php declare(strict_types=1);

namespace Shef\ToolsAi\Main;

/**
 * Имена, которые не должны расходиться между файлами модуля.
 *
 * Класс самодостаточен — без ядра и без модуля: его подключают установщик и
 * тесты, где на автозагрузку полагаться нельзя.
 */
class Constants
{
	public const MODULE_ID = 'shef.toolsai';

	// region Публичные страницы ////
	/**
	 * Каталог модуля браузеру недоступен (в поставке nginx закрывает
	 * /bitrix/modules), поэтому страницы открываются заглушками в одну строку:
	 * require на файл модуля. Заглушки пишет Main\PublicPage — туда, где
	 * модуль стоит на самом деле.
	 *
	 * Эндпоинт — в /bitrix/tools, а не в /local/ai, как было в ките: /bitrix/tools
	 * публичен на любом портале (там же лежит crm_show_file.php, по которому
	 * движок забирает запись звонка), а /local модуль не создаёт.
	 */
	public const ENDPOINT_FILE = '/bitrix/tools/shef_toolsai_completions.php';
	public const ENDPOINT_MODULE_PAGE = '/endpoint/completions.php';

	public const QUOTA_FILE = '/bitrix/admin/shef_toolsai_quota.php';
	public const QUOTA_MODULE_PAGE = '/admin/quota.php';

	public const DEAL_PROFILES_FILE = '/bitrix/admin/shef_toolsai_deal_profiles.php';
	public const DEAL_PROFILES_MODULE_PAGE = '/admin/dealprofiles.php';

	public const STATS_FILE = '/bitrix/admin/shef_toolsai_stats.php';
	public const STATS_MODULE_PAGE = '/admin/stats.php';
	// endregion ////

	/**
	 * Префикс кода движка в b_ai_engine.
	 *
	 * Код НЕ должен совпадать с 'itsolutionru.gptconnector' — только для него
	 * ThirdParty::checkLimits() (ai/lib/Engine/ThirdParty.php:289-292) включает
	 * штатный лимитер. Формат кода валидируется как [A-Za-z0-9-_]
	 * (ThirdPartyRegisterService::validateCodeFormat), поэтому без точки.
	 */
	public const ENGINE_CODE_PREFIX = 'sheftoolsai_';

	public const CATEGORY_AUDIO = 'audio';
	public const CATEGORY_TEXT = 'text';

	/** Группа блокировок агента анализа сделок — повторена в install/index.php. */
	public const LOCK_GROUP_DEAL_HEALTH = 'shef.toolsai.dealhealth';

	/** Параметр адреса эндпоинта с токеном. */
	public const TOKEN_PARAM = 'token';

	/**
	 * Коды провайдеров в настройках.
	 *
	 * echo — заглушка без денег, openai — любое OpenAI-совместимое API
	 * (OpenAI, свой whisper-сервер, vLLM, LocalAI, прокси).
	 */
	public const PROVIDER_ECHO = 'echo';
	public const PROVIDER_OPENAI = 'openai';

	/**
	 * @return string[]
	 */
	public static function getCategoryList(): array
	{
		return [
			static::CATEGORY_AUDIO,
			static::CATEGORY_TEXT,
		];
	}

	/**
	 * @return string[]
	 */
	public static function getProviderList(): array
	{
		return [
			static::PROVIDER_ECHO,
			static::PROVIDER_OPENAI,
		];
	}

	public static function getEngineCode(string $category): string
	{
		return static::ENGINE_CODE_PREFIX.$category;
	}

	public static function isOwnEngineCode(string $code): bool
	{
		return str_starts_with($code, static::ENGINE_CODE_PREFIX);
	}
}

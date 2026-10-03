<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Bitrix\Main\Config\Option;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Deal\Model\DealCheckTable;
use Shef\ToolsAi\Deal\Model\DealProfileTable;
use Shef\ToolsAi\Main\Constants;

/**
 * Переход на профили (версия 1.1.0): таблицы и перенос старых настроек.
 *
 * Механизма updater у модуля нет — он ставится Composer'ом или из git, и
 * обновление — это новые файлы без вызова установщика. Поэтому переход
 * идемпотентный и зовётся там, где модуль и так бывает после обновления:
 *   - кнопка «Проверить и включить» (Main\Setup::prepare());
 *   - агент анализа перед прогоном и cli/deal-health.php;
 *   - страница профилей при открытии.
 *
 * Что делает:
 *   1) создаёт таблицу профилей, дописывает столбцы в таблицу проверок;
 *   2) один раз переносит DEAL_categories / threshold / senior /
 *      reanalyzedays / idledays в профили — по одному на направление,
 *      LOW_BORDER = HIGH_BORDER = порог, промпт пустой (общий). Отметка —
 *      SYS_profilesmigrated. Профили уже есть (завели руками) — не переносим.
 *
 * Старые настройки не стираются: откат на 1.0.x работает как раньше.
 */
final class ProfileMigration
{
	public const OPTION_DONE = 'SYS_profilesmigrated';

	/** Чтобы не ходить в базу на каждом вызове в одном процессе. */
	private static bool $done = false;

	/**
	 * Профили из старых настроек. Чистая функция — по Config.
	 *
	 * Направления не выбраны или настройка испорчена — пусто: «все
	 * направления» по ошибке — это счёт за всю базу.
	 *
	 * @return array<int, array<string, mixed>> поля DealProfileTable
	 */
	public static function plan(Config $config): array
	{
		$categories = $config->getDealCategories();
		if($categories === null || $categories === [])
		{
			return [];
		}

		$threshold = $config->getEscalationThreshold();
		$rows = [];
		foreach(array_values(array_unique($categories)) as $categoryId)
		{
			$rows[] = [
				'TITLE' => 'Направление '.$categoryId.' (перенесено из настроек)',
				'IS_ENABLED' => 'Y',
				'SORT' => Profile::DEFAULT_SORT,
				'CATEGORY_ID' => $categoryId,
				'CLIENT_TYPES' => '',
				'PROMPT' => '',
				'IDLE_DAYS' => $config->getIdleDays(),
				'REANALYZE_DAYS' => $config->getReanalyzeDays(),
				'LOW_BORDER' => $threshold,
				'HIGH_BORDER' => $threshold,
				'SENIOR_ID' => $config->getSeniorUserId(),
			];
		}

		return $rows;
	}

	/**
	 * Таблицы и перенос. Повторный вызов ничего не меняет.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function run(Config $config): array
	{
		if(self::$done)
		{
			return ['ok' => true, 'message' => 'уже выполнено'];
		}

		DealCheckTable::init();
		DealProfileTable::init();

		if(Option::get(Constants::MODULE_ID, static::OPTION_DONE, 'N') === 'Y')
		{
			self::$done = true;

			return ['ok' => true, 'message' => 'таблицы на месте, настройки перенесены раньше'];
		}

		$created = 0;
		$plan = [];
		$exists = DealProfileTable::getList(['select' => ['ID'], 'limit' => 1])->fetch();
		if(!$exists)
		{
			$plan = static::plan($config);
			foreach($plan as $row)
			{
				if(DealProfileTable::add($row)->isSuccess())
				{
					$created++;
				}
			}
		}

		// Не все записались — отметку не ставим, но и повтор не задвоит:
		// профили уже есть, и следующий вызов перенос пропустит.
		if($created < count($plan))
		{
			return ['ok' => false, 'message' => sprintf('перенесено профилей: %d из %d', $created, count($plan))];
		}

		Option::set(Constants::MODULE_ID, static::OPTION_DONE, 'Y');
		self::$done = true;

		return ['ok' => true, 'message' => $exists ? 'профили уже были — настройки не переносились' : 'перенесено профилей: '.$created];
	}
}

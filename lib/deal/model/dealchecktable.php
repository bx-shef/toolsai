<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Последняя проверка каждой сделки.
 *
 * Держит два ограничителя расхода: повторный анализ не чаще раза в N дней и
 * эскалация одной сделки не каждый прогон. По строке на сделку.
 */
class DealCheckTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsai_deal_check';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('DEAL_ID'))->configureRequired(),
			(new Fields\DatetimeField('CHECKED_AT'))->configureRequired(),
			(new Fields\IntegerField('RISK'))->configureDefaultValue(0),
			(new Fields\BooleanField('NEED_SENIOR'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			(new Fields\BooleanField('SKIPPED'))->configureValues('N', 'Y')->configureDefaultValue('N'),
			(new Fields\StringField('WHY'))->configureSize(500)->configureNullable(),
			(new Fields\StringField('NEXT_STEP'))->configureSize(300)->configureNullable(),
			(new Fields\DatetimeField('ESCALATED_AT'))->configureNullable(),
			// Когда ставили дело менеджеру (шкала профиля, Deal\RiskScale):
			// повтор не чаще REANALYZE_DAYS профиля. С версии 1.1.0.
			(new Fields\DatetimeField('MANAGER_TODO_AT'))->configureNullable(),
			// Каким профилем проверяли. 0 — профиля не нашлось. С версии 1.1.0.
			(new Fields\IntegerField('PROFILE_ID'))->configureDefaultValue(0),
		];
	}

	public static function init(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			static::upgrade();

			return;
		}

		$connection->queryExecute(sprintf(
			'CREATE TABLE %s (
				ID INT(11) NOT NULL AUTO_INCREMENT,
				DEAL_ID INT(11) NOT NULL,
				CHECKED_AT DATETIME NOT NULL,
				RISK INT(11) NOT NULL DEFAULT 0,
				NEED_SENIOR CHAR(1) NOT NULL DEFAULT \'N\',
				SKIPPED CHAR(1) NOT NULL DEFAULT \'N\',
				WHY VARCHAR(500) NULL,
				NEXT_STEP VARCHAR(300) NULL,
				ESCALATED_AT DATETIME NULL,
				MANAGER_TODO_AT DATETIME NULL,
				PROFILE_ID INT(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (ID),
				UNIQUE KEY UX_SHEF_TOOLSAI_DEAL (DEAL_ID),
				KEY IX_SHEF_TOOLSAI_CHECKED (CHECKED_AT)
			)',
			static::getTableName()
		));
	}

	/**
	 * Таблица из версии до 1.1.0 — дописать новые столбцы. Идемпотентно:
	 * столбец уже есть — не трогаем. Зовут установщик (InstallDB), кнопка
	 * «Проверить и включить» и агент перед прогоном (Deal\ProfileMigration).
	 */
	public static function upgrade(): void
	{
		$connection = Application::getConnection();
		$existing = array_change_key_case((array)$connection->getTableFields(static::getTableName()), CASE_UPPER);

		$columns = [
			'MANAGER_TODO_AT' => 'DATETIME NULL',
			'PROFILE_ID' => 'INT(11) NOT NULL DEFAULT 0',
		];
		foreach($columns as $name => $definition)
		{
			if(!array_key_exists($name, $existing))
			{
				$connection->queryExecute(sprintf('ALTER TABLE %s ADD COLUMN %s %s', static::getTableName(), $name, $definition));
			}
		}
	}

	public static function drop(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			$connection->queryExecute('DROP TABLE '.static::getTableName());
		}
	}

	public static function getByDeal(int $dealId): ?array
	{
		$row = static::getList([
			'filter' => ['=DEAL_ID' => $dealId],
			'limit' => 1,
		])->fetch();

		return is_array($row) ? $row : null;
	}

	/**
	 * Записать проверку: одна строка на сделку, повтор обновляет её.
	 */
	public static function save(int $dealId, array $fields): void
	{
		$row = static::getByDeal($dealId);
		if($row === null)
		{
			static::add(['DEAL_ID' => $dealId] + $fields);

			return;
		}

		static::update((int)$row['ID'], $fields);
	}
}

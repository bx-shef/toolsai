<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Shef\ToolsAi\Deal\Profile;

/**
 * Профили анализа сделок (Deal\Profile): направление, типы клиента, промпт,
 * шкала, старший. Редактируются на странице «ИИ: профили анализа сделок»
 * (admin/dealprofiles.php).
 *
 * CLIENT_TYPES — коды Deal\ClientType через запятую, пусто — любой клиент.
 * PROMPT пустой — общий промпт HealthAnalyzer::getSystemPrompt().
 */
class DealProfileTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'shef_toolsai_deal_profile';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\StringField('TITLE'))->configureRequired()->configureSize(255),
			(new Fields\BooleanField('IS_ENABLED'))->configureValues('N', 'Y')->configureDefaultValue('Y'),
			(new Fields\IntegerField('SORT'))->configureDefaultValue(Profile::DEFAULT_SORT),
			(new Fields\IntegerField('CATEGORY_ID'))->configureDefaultValue(0),
			(new Fields\StringField('CLIENT_TYPES'))->configureSize(50)->configureDefaultValue(''),
			(new Fields\TextField('PROMPT'))->configureNullable(),
			(new Fields\IntegerField('IDLE_DAYS'))->configureDefaultValue(Profile::DEFAULT_IDLE_DAYS),
			(new Fields\IntegerField('REANALYZE_DAYS'))->configureDefaultValue(Profile::DEFAULT_REANALYZE_DAYS),
			(new Fields\IntegerField('LOW_BORDER'))->configureDefaultValue(Profile::DEFAULT_LOW_BORDER),
			(new Fields\IntegerField('HIGH_BORDER'))->configureDefaultValue(Profile::DEFAULT_HIGH_BORDER),
			(new Fields\IntegerField('SENIOR_ID'))->configureDefaultValue(0),
		];
	}

	public static function init(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			return;
		}

		$connection->queryExecute(sprintf(
			'CREATE TABLE %s (
				ID INT(11) NOT NULL AUTO_INCREMENT,
				TITLE VARCHAR(255) NOT NULL,
				IS_ENABLED CHAR(1) NOT NULL DEFAULT \'Y\',
				SORT INT(11) NOT NULL DEFAULT %d,
				CATEGORY_ID INT(11) NOT NULL DEFAULT 0,
				CLIENT_TYPES VARCHAR(50) NOT NULL DEFAULT \'\',
				PROMPT TEXT NULL,
				IDLE_DAYS INT(11) NOT NULL DEFAULT %d,
				REANALYZE_DAYS INT(11) NOT NULL DEFAULT %d,
				LOW_BORDER INT(11) NOT NULL DEFAULT %d,
				HIGH_BORDER INT(11) NOT NULL DEFAULT %d,
				SENIOR_ID INT(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (ID),
				KEY IX_SHEF_TOOLSAI_PROFILE_CAT (CATEGORY_ID, IS_ENABLED)
			)',
			static::getTableName(),
			Profile::DEFAULT_SORT,
			Profile::DEFAULT_IDLE_DAYS,
			Profile::DEFAULT_REANALYZE_DAYS,
			Profile::DEFAULT_LOW_BORDER,
			Profile::DEFAULT_HIGH_BORDER
		));
	}

	public static function drop(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			$connection->queryExecute('DROP TABLE '.static::getTableName());
		}
	}

	/**
	 * Все профили, разобранные.
	 *
	 * @return Profile[]
	 */
	public static function getProfiles(bool $onlyEnabled = false): array
	{
		$rows = static::getList([
			'filter' => $onlyEnabled ? ['=IS_ENABLED' => 'Y'] : [],
			'order' => ['CATEGORY_ID' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
		])->fetchAll();

		return array_map(static fn(array $row): Profile => Profile::fromRow($row), $rows);
	}
}

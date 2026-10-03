<?php declare(strict_types=1);

namespace Shef\ToolsAi\Chat\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Оценки переписки по скрипту (Agent\ChatAssessmentAgent), с 1.6.0.
 *
 * Строка на дело открытой линии (ACTIVITY_ID уникален): есть строка —
 * чат уже разобран, второй раз за него не платим. STATUS:
 *
 * * DONE — оценён, SCORE (null — ни один пункт не оценён), CRITERIA —
 *   {"criteria": [...]} как у Stats\ScoreResult, SUMMARY — итог модели;
 * * SKIPPED — без модели: нет реплик менеджера или клиента, нет скрипта,
 *   у скрипта нет критериев; причина — REASON;
 * * ERROR — модель ответила негодно (оплачено, в журнале расхода), REASON.
 *
 * Сбой провайдера (нет связи, лимит, ключ) строку не пишет: чат снова
 * кандидат в следующий прогон.
 */
class ChatAssessmentTable extends DataManager
{
	public const STATUS_DONE = 'DONE';
	public const STATUS_SKIPPED = 'SKIPPED';
	public const STATUS_ERROR = 'ERROR';

	public static function getTableName(): string
	{
		return 'shef_toolsai_chat_assessment';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('ACTIVITY_ID'))->configureRequired(),
			(new Fields\IntegerField('SESSION_ID'))->configureDefaultValue(0),
			(new Fields\IntegerField('CHAT_ID'))->configureDefaultValue(0),
			(new Fields\IntegerField('OWNER_TYPE_ID'))->configureDefaultValue(0),
			(new Fields\IntegerField('OWNER_ID'))->configureDefaultValue(0),
			(new Fields\IntegerField('ASSESSMENT_ID'))->configureDefaultValue(0),
			(new Fields\StringField('STATUS'))->configureSize(16)->configureRequired(),
			(new Fields\IntegerField('SCORE'))->configureNullable(),
			(new Fields\TextField('CRITERIA'))->configureNullable(),
			(new Fields\TextField('SUMMARY'))->configureNullable(),
			(new Fields\StringField('REASON'))->configureSize(500)->configureNullable(),
			(new Fields\IntegerField('RESPONSIBLE_ID'))->configureDefaultValue(0),
			(new Fields\DatetimeField('CREATED_AT'))->configureRequired(),
		];
	}

	/**
	 * Столбцы кроме ID и ACTIVITY_ID: по ним и создаётся таблица, и
	 * дописывается недостающее (upgrade()).
	 */
	private const COLUMNS = [
		'SESSION_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'CHAT_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'OWNER_TYPE_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'OWNER_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'ASSESSMENT_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'STATUS' => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
		'SCORE' => 'INT(11) NULL',
		'CRITERIA' => 'MEDIUMTEXT NULL',
		'SUMMARY' => 'TEXT NULL',
		'REASON' => 'VARCHAR(500) NULL',
		'RESPONSIBLE_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'CREATED_AT' => 'DATETIME NULL',
	];

	/**
	 * Создать таблицу, если её нет; есть — дописать недостающие столбцы.
	 * Идемпотентно: зовут установщик, «Проверить и включить» и агент перед
	 * прогоном (обновление модуля без установщика).
	 */
	public static function init(): void
	{
		$connection = Application::getConnection();
		if($connection->isTableExists(static::getTableName()))
		{
			static::upgrade();

			return;
		}

		$columns = [];
		foreach(self::COLUMNS as $name => $definition)
		{
			$columns[] = $name.' '.$definition.',';
		}

		$connection->queryExecute(sprintf(
			'CREATE TABLE %s (
				ID INT(11) NOT NULL AUTO_INCREMENT,
				ACTIVITY_ID INT(11) NOT NULL,
				%s
				PRIMARY KEY (ID),
				UNIQUE KEY UX_SHEF_TOOLSAI_CHAT_ACT (ACTIVITY_ID),
				KEY IX_SHEF_TOOLSAI_CHAT_CREATED (CREATED_AT)
			)',
			static::getTableName(),
			implode("\n\t\t\t\t", $columns)
		));
	}

	/**
	 * Таблица есть, а столбца нет (таблицу создала другая версия модуля) —
	 * дописать. Столбец уже есть — не трогаем.
	 */
	public static function upgrade(): void
	{
		$connection = Application::getConnection();
		$existing = array_change_key_case((array)$connection->getTableFields(static::getTableName()), CASE_UPPER);

		foreach(self::COLUMNS as $name => $definition)
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

	/**
	 * Записать результат. Строка на дело: повтор (ручной прогон по списку)
	 * обновляет её.
	 */
	public static function save(int $activityId, array $fields): void
	{
		$row = static::getList([
			'select' => ['ID'],
			'filter' => ['=ACTIVITY_ID' => $activityId],
			'limit' => 1,
		])->fetch();
		if(!is_array($row))
		{
			static::add(['ACTIVITY_ID' => $activityId] + $fields);

			return;
		}

		static::update((int)$row['ID'], $fields);
	}
}

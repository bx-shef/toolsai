<?php declare(strict_types=1);

namespace Shef\ToolsAi\Email\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Письма, которые разобрал модуль (Agent\EmailAgent), с 1.7.0.
 *
 * Строка на дело-письмо CRM (ACTIVITY_ID уникален). MODE — тип обработки:
 *
 * * SUMMARY — входящее разобрано моделью: IS_CLIENT, SUMMARY, RESULT (ответ
 *   модели после нормализации), TODO_COUNT — поставлено дел;
 * * REVIEW — исходящее оценено: SCORE (null — ни один пункт не оценён),
 *   RESULT — {"criteria": [...], ...} как у Stats\ScoreResult;
 * * WATCH — входящее только под контролем скорости ответа, моделью не
 *   разбиралось (выключено или не дошла очередь).
 *
 * ANALYZED_AT — когда разобрано моделью или пропущено без неё (по нему
 * считает страница статистики).
 *
 * STATUS: DONE, SKIPPED (без модели: служебное письмо, пустое, нет сделки),
 * ERROR (модель ответила негодно — оплачено), пусто — моделью не
 * обрабатывалось (строка контроля скорости). Пустой STATUS — входящее снова
 * кандидат на разбор.
 *
 * Контроль скорости ответа (входящие): REPLIED_AT и REPLY_SECONDS — первый
 * ответ (исходящее письмо или звонок по сделке) и сколько ждал клиент;
 * REPLY_TODO_AT — поставлено дело менеджеру, SENIOR_TODO_AT — старшему.
 * Отметка гасит повтор.
 *
 * Сбой провайдера (нет связи, лимит, ключ) строку не пишет: письмо снова
 * кандидат в следующий прогон.
 */
class EmailTable extends DataManager
{
	public const MODE_SUMMARY = 'SUMMARY';
	public const MODE_REVIEW = 'REVIEW';
	public const MODE_WATCH = 'WATCH';

	public const STATUS_DONE = 'DONE';
	public const STATUS_SKIPPED = 'SKIPPED';
	public const STATUS_ERROR = 'ERROR';

	public static function getTableName(): string
	{
		return 'shef_toolsai_email';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('ACTIVITY_ID'))->configureRequired(),
			(new Fields\IntegerField('OWNER_TYPE_ID'))->configureDefaultValue(0),
			(new Fields\IntegerField('OWNER_ID'))->configureDefaultValue(0),
			(new Fields\IntegerField('DIRECTION'))->configureDefaultValue(0),
			(new Fields\IntegerField('RESPONSIBLE_ID'))->configureDefaultValue(0),
			(new Fields\DatetimeField('MAIL_AT'))->configureNullable(),
			(new Fields\StringField('MODE'))->configureSize(16)->configureDefaultValue(''),
			(new Fields\StringField('STATUS'))->configureSize(16)->configureDefaultValue(''),
			(new Fields\StringField('IS_CLIENT'))->configureSize(1)->configureNullable(),
			(new Fields\TextField('SUMMARY'))->configureNullable(),
			(new Fields\TextField('RESULT'))->configureNullable(),
			(new Fields\IntegerField('SCORE'))->configureNullable(),
			(new Fields\IntegerField('TODO_COUNT'))->configureDefaultValue(0),
			(new Fields\StringField('REASON'))->configureSize(500)->configureNullable(),
			(new Fields\DatetimeField('ANALYZED_AT'))->configureNullable(),
			(new Fields\DatetimeField('REPLIED_AT'))->configureNullable(),
			(new Fields\IntegerField('REPLY_SECONDS'))->configureNullable(),
			(new Fields\DatetimeField('REPLY_TODO_AT'))->configureNullable(),
			(new Fields\DatetimeField('SENIOR_TODO_AT'))->configureNullable(),
			(new Fields\DatetimeField('CREATED_AT'))->configureRequired(),
		];
	}

	/** Столбцы кроме ID и ACTIVITY_ID: по ним создаётся таблица и дописывается недостающее. */
	private const COLUMNS = [
		'OWNER_TYPE_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'OWNER_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'DIRECTION' => 'INT(11) NOT NULL DEFAULT 0',
		'RESPONSIBLE_ID' => 'INT(11) NOT NULL DEFAULT 0',
		'MAIL_AT' => 'DATETIME NULL',
		'MODE' => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
		'STATUS' => 'VARCHAR(16) NOT NULL DEFAULT \'\'',
		'IS_CLIENT' => 'CHAR(1) NULL',
		'SUMMARY' => 'TEXT NULL',
		'RESULT' => 'MEDIUMTEXT NULL',
		'SCORE' => 'INT(11) NULL',
		'TODO_COUNT' => 'INT(11) NOT NULL DEFAULT 0',
		'REASON' => 'VARCHAR(500) NULL',
		'ANALYZED_AT' => 'DATETIME NULL',
		'REPLIED_AT' => 'DATETIME NULL',
		'REPLY_SECONDS' => 'INT(11) NULL',
		'REPLY_TODO_AT' => 'DATETIME NULL',
		'SENIOR_TODO_AT' => 'DATETIME NULL',
		'CREATED_AT' => 'DATETIME NULL',
	];

	/**
	 * Создать таблицу, если её нет; есть — дописать недостающие столбцы.
	 * Идемпотентно: зовут установщик, «Проверить и включить» и агент.
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
				UNIQUE KEY UX_SHEF_TOOLSAI_EMAIL_ACT (ACTIVITY_ID),
				KEY IX_SHEF_TOOLSAI_EMAIL_OWNER (OWNER_TYPE_ID, OWNER_ID),
				KEY IX_SHEF_TOOLSAI_EMAIL_CREATED (CREATED_AT)
			)',
			static::getTableName(),
			implode("\n\t\t\t\t", $columns)
		));
	}

	/** Таблица есть, а столбца нет — дописать. Есть — не трогаем. */
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
	 * Записать по делу: строки нет — добавить ($base + $fields, CREATED_AT —
	 * сейчас), есть — обновить только $fields.
	 */
	public static function save(int $activityId, array $fields, array $base = []): void
	{
		$row = static::getList([
			'select' => ['ID'],
			'filter' => ['=ACTIVITY_ID' => $activityId],
			'limit' => 1,
		])->fetch();
		if(!is_array($row))
		{
			static::add(['ACTIVITY_ID' => $activityId] + $fields + $base + ['CREATED_AT' => new \Bitrix\Main\Type\DateTime()]);

			return;
		}

		static::update((int)$row['ID'], $fields);
	}
}

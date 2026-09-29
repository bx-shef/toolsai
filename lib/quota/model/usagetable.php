<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Shef\ToolsAi\Quota\Status;

/**
 * Журнал расхода ИИ — наш собственный.
 *
 * Битрикс для третьесторонних движков не пишет НИЧЕГО: ThirdParty::checkLimits()
 * отдаёт false для любого кода, кроме одного приложения Маркета, и блок
 * лимитера в Engine::completions() пропускается целиком (docs/00-research.md,
 * раздел 4). b_ai_usage остаётся пустой, единственный источник правды о
 * расходе — эта таблица.
 *
 * Считаем не «запросы по 1», как ядро (ai/lib/Payload/Payload.php:15), а
 * реальные единицы: секунды аудио или токены. Деньги — в микро-единицах
 * валюты (1/1_000_000), чтобы не ловить ошибки округления на копейках.
 *
 * Таблица — финансовая история: при удалении модуля уходит только без
 * savedata = Y, как и настройки.
 */
class UsageTable extends DataManager
{
	public const STATUS_PROCESSING = Status::PROCESSING;
	public const STATUS_SUCCESS = Status::SUCCESS;
	public const STATUS_ERROR = Status::ERROR;
	public const STATUS_QUOTA = Status::QUOTA;

	public static function getTableName(): string
	{
		return 'shef_toolsai_usage';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\DatetimeField('CREATED_AT'))->configureRequired(),
			(new Fields\DatetimeField('FINISHED_AT'))->configureNullable(),
			(new Fields\StringField('ENGINE_CODE'))->configureSize(100),
			(new Fields\StringField('CATEGORY'))->configureSize(32),
			(new Fields\StringField('PROVIDER_CODE'))->configureSize(32),
			// Секунды аудио для распознавания, токены для LLM.
			(new Fields\IntegerField('UNITS'))->configureDefaultValue(0),
			// Стоимость в 1/1_000_000 единицы валюты. В базе BIGINT.
			(new Fields\IntegerField('COST_MICRO'))->configureDefaultValue(0),
			// Хэш QueueJob — ключ идемпотентности, уникальный индекс.
			(new Fields\StringField('JOB_HASH'))->configureSize(64)->configureNullable(),
			(new Fields\EnumField('STATUS'))
				->configureValues(Status::getList())
				->configureDefaultValue(static::STATUS_PROCESSING),
			(new Fields\TextField('ERROR'))->configureNullable(),
		];
	}

	/**
	 * Создать таблицу, если её нет. Схема — MySQL, как у коробки Битрикс24.
	 */
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
				CREATED_AT DATETIME NOT NULL,
				FINISHED_AT DATETIME NULL,
				ENGINE_CODE VARCHAR(100) NULL,
				CATEGORY VARCHAR(32) NULL,
				PROVIDER_CODE VARCHAR(32) NULL,
				UNITS INT(11) NOT NULL DEFAULT 0,
				COST_MICRO BIGINT NOT NULL DEFAULT 0,
				JOB_HASH VARCHAR(64) NULL,
				STATUS VARCHAR(20) NOT NULL DEFAULT \'%s\',
				ERROR TEXT NULL,
				PRIMARY KEY (ID),
				UNIQUE KEY UX_SHEF_TOOLSAI_JOB (JOB_HASH),
				KEY IX_SHEF_TOOLSAI_PERIOD (CREATED_AT, STATUS),
				KEY IX_SHEF_TOOLSAI_CATEGORY (CATEGORY, CREATED_AT)
			)',
			static::getTableName(),
			static::STATUS_PROCESSING
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
}

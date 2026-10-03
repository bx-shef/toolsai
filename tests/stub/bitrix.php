<?php

/**
 * Заглушки ядра Битрикса для тестов.
 *
 * Ровно столько, сколько нужно, чтобы подключить настоящие классы модуля.
 * Логику модуля здесь не повторяем — иначе тест проверял бы заглушку, а не
 * код. Основа — заглушки shef.options (tests/stub/bitrix.php там), сверху
 * то, что зовёт этот модуль: журнал событий, текущий пользователь,
 * расширения, события, менеджеры модулей и событий.
 *
 * Блочный синтаксис namespace: в одном файле нужно объявить классы сразу в
 * нескольких пространствах имён.
 */

namespace Bitrix\Main
{
	if(!class_exists(SystemException::class))
	{
		class SystemException extends \Exception {}
		class ArgumentException extends SystemException {}
		class ArgumentNullException extends ArgumentException {}
		class ObjectException extends SystemException {}
	}
}

namespace Bitrix\Main\Type
{
	if(!class_exists(Date::class))
	{
		class Date
		{
			public function __construct(
				public readonly string $value = '',
				public readonly string $format = ''
			) {}

			public function toString(): string
			{
				return $this->value;
			}

			public function format(string $format): string
			{
				return $this->value;
			}

			/** Что прибавляли — в том порядке, как ядро: меняет сам объект. */
			public array $added = [];

			public function add(string $interval): static
			{
				$this->added[] = $interval;
				return $this;
			}
		}

		class DateTime extends Date {}
	}
}

namespace Bitrix\Main\Localization
{
	if(!class_exists(Loc::class))
	{
		/**
		 * Сообщения языковых файлов. Тестам важно не что вернётся, а что
		 * вызов не падает: проверять перевод здесь нечего, он уедет на портал.
		 */
		class Loc
		{
			/**
			 * Сообщения, которые тест загрузил сам. Пусто — getMessage()
			 * отдаёт код, как раньше: большинству тестов перевод не важен.
			 * Загружены — отдаёт перевод с подстановкой, а незнакомый код —
			 * null, как ядро: тест, которому важны подписи, увидит пропуск.
			 *
			 * @var array<string, string>
			 */
			public static array $messages = [];

			public static function loadMessages(string $file): void {}

			/** Подгрузить настоящий языковой файл модуля. */
			public static function loadLangFile(string $path): void
			{
				$MESS = [];
				include $path;
				static::$messages = array_merge(static::$messages, $MESS);
			}

			public static function getMessage(string $code, ?array $replace = null, ?string $language = null): ?string
			{
				if(empty(static::$messages))
				{
					return $code;
				}

				if(!isset(static::$messages[$code]))
				{
					return null;
				}

				return strtr(static::$messages[$code], $replace ?? []);
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Loader::class))
	{
		class LoaderException extends SystemException {}

		class Loader
		{
			public const MODULE_NOT_FOUND = 0;
			public const MODULE_INSTALLED = 1;
			public const MODULE_DEMO = 2;
			public const MODULE_DEMO_EXPIRED = 3;

			/**
			 * Куда Loader::getLocal() «нашёл» файл. Пусто — не нашёл, как на
			 * портале без модуля.
			 *
			 * @var array<string, string>
			 */
			public static array $local = [];

			/** @var array<string, string> что зарегистрировал autoload.php */
			public static array $namespaces = [];

			/**
			 * Модули, которых «нет на портале»: includeModule() отвечает false.
			 *
			 * @var string[]
			 */
			public static array $missing = [];

			public static function includeModule(string $moduleName): bool
			{
				return !in_array($moduleName, static::$missing, true);
			}

			/**
			 * Что делает подключение модуля — например, shef.options объявляет
			 * свои _log(). Задаёт тест, которому это важно.
			 *
			 * @var array<string, callable>
			 */
			public static array $onInclude = [];

			public static function includeSharewareModule(string $moduleName): int
			{
				if(isset(static::$onInclude[$moduleName]))
				{
					(static::$onInclude[$moduleName])();
				}

				return static::MODULE_INSTALLED;
			}

			public static function getDocumentRoot(): string
			{
				return Application::getDocumentRoot();
			}

			public static function getLocal(string $path, ?string $root = null): string|false
			{
				return static::$local[$path] ?? false;
			}

			public static function registerNamespace(string $namespace, string $path): void
			{
				static::$namespaces[$namespace] = $path;
			}

			public static function registerAutoLoadClasses(?string $moduleName, array $classes): void
			{
			}
		}
	}
}

namespace Bitrix\Main\Config
{
	if(!class_exists(Option::class))
	{
		/**
		 * Настройки модуля. Важно не как они хранятся, а что приходит обратно:
		 * значение опции — всегда строка из формы, и разбирать её приходится
		 * коду модуля.
		 */
		class Option
		{
			/** @var array<string, array<string, mixed>> */
			public static array $values = [];

			public static function set(string $moduleId, string $name, mixed $value): void
			{
				static::$values[$moduleId][$name] = $value;
			}

			public static function forget(string $moduleId, string $name): void
			{
				unset(static::$values[$moduleId][$name]);
			}

			public static function get(string $moduleId, string $name, mixed $default = '', mixed $siteId = false): mixed
			{
				return static::$values[$moduleId][$name] ?? $default;
			}

			/** Настройки одного модуля целиком — то, что зовёт установщик. */
			public static function delete(string $moduleId, array $filter = []): void
			{
				unset(static::$values[$moduleId]);
			}
		}
	}

	if(!class_exists(Configuration::class))
	{
		/**
		 * Содержимое .settings.php. Отдаёт не весь ключ, а его value — ровно
		 * так это читает autoload.php модуля. Наполняет массив тот тест,
		 * которому он нужен: заглушка не знает, где лежит файл.
		 */
		class Configuration
		{
			/** @var array<string, array> */
			public static array $settings = [];

			public static function getInstance(?string $moduleId = null): static
			{
				return new static();
			}

			/**
			 * Настройки ядра — /bitrix/.settings.php и .settings_extra.php.
			 * Пусто, пока тест не положит своё: Composer на стенде нет,
			 * каталог логов — по умолчанию.
			 *
			 * @var array<string, mixed>
			 */
			public static array $values = [];

			public static function getValue(string $name): mixed
			{
				return static::$values[$name] ?? null;
			}

			public function get(string $key): mixed
			{
				return static::$settings[$key]['value'] ?? null;
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Error::class))
	{
		/**
		 * Result и Error — то, чем модуль возвращает «получилось / не
		 * получилось» вместе с данными. Поведение простое, но важное:
		 * isSuccess() определяется НАЛИЧИЕМ ошибок, а не отдельным флагом.
		 */
		class Error
		{
			public function __construct(
				private readonly string $message = '',
				private readonly int|string $code = 0,
				private readonly mixed $customData = null
			) {}

			public function getMessage(): string
			{
				return $this->message;
			}

			public function getCode(): int|string
			{
				return $this->code;
			}

			public function getCustomData(): mixed
			{
				return $this->customData;
			}

			public function __toString(): string
			{
				return $this->message;
			}

			public function jsonSerialize(): array
			{
				return [
					'message' => $this->getMessage(),
					'code' => $this->getCode(),
					'customData' => $this->getCustomData(),
				];
			}
		}

		/**
		 * Коллекция ошибок — ровно те методы, которыми по ней ходит
		 * BitrixResultStrategy: перемотка, текущий, количество.
		 */
		class ErrorCollection
		{
			private int $position = 0;

			/** @param Error[] $errors */
			public function __construct(private array $errors = []) {}

			public function rewind(): void
			{
				$this->position = 0;
			}

			public function current(): ?Error
			{
				return $this->errors[$this->position] ?? null;
			}

			public function count(): int
			{
				return count($this->errors);
			}
		}

		class Result
		{
			/** @var Error[] */
			protected array $errors = [];
			protected array $data = [];

			public function isSuccess(): bool
			{
				return empty($this->errors);
			}

			public function addError(Error $error): static
			{
				$this->errors[] = $error;
				return $this;
			}

			/** @param Error[] $errors */
			public function addErrors(array $errors): static
			{
				foreach($errors as $error)
				{
					$this->errors[] = $error;
				}

				return $this;
			}

			/** @return Error[] */
			public function getErrors(): array
			{
				return $this->errors;
			}

			/** @return string[] */
			public function getErrorMessages(): array
			{
				return array_map(
					static fn(Error $error): string => $error->getMessage(),
					$this->errors
				);
			}

			/**
			 * Данные кладутся как есть: ссылка внутри массива остаётся
			 * ссылкой, и это не мелочь — Pid::removeByGroup() наполняет
			 * список УЖЕ ПОСЛЕ вызова setData().
			 */
			public function setData(array $data): static
			{
				$this->data = $data;
				return $this;
			}

			public function getData(): array
			{
				return $this->data;
			}

			public function getErrorCollection(): ErrorCollection
			{
				return new ErrorCollection(array_values($this->errors));
			}
		}
	}
}

namespace Bitrix\Main\IO
{
	if(!class_exists(Path::class))
	{
		/**
		 * Файловый слой ядра ровно в том объёме, в котором его зовут классы
		 * модуля: путь, файл, каталог. Работает с настоящей файловой системой —
		 * иначе проверять было бы нечего.
		 */
		class Path
		{
			public static function combine(string ...$parts): string
			{
				return implode('/', array_map(
					static fn(string $part): string => trim($part, '/'),
					array_filter($parts, static fn(string $part): bool => '' !== $part)
				));
			}
		}

		class Directory
		{
			/** Как в ядре: каталог целиком, со всем содержимым. */
			public static function deleteDirectory(string $path): void
			{
				if(!is_dir($path))
				{
					return;
				}

				foreach(scandir($path) ?: [] as $entry)
				{
					if('.' === $entry || '..' === $entry)
					{
						continue;
					}

					is_dir($path.'/'.$entry)
						? static::deleteDirectory($path.'/'.$entry)
						: unlink($path.'/'.$entry);
				}

				rmdir($path);
			}

			public function __construct(private readonly string $path) {}

			public function getPath(): string
			{
				return $this->path;
			}

			public function isExists(): bool
			{
				return is_dir($this->path);
			}
		}

		class File
		{
			public function __construct(private readonly string $path) {}

			public function getPath(): string
			{
				return $this->path;
			}

			public function getName(): string
			{
				return basename($this->path);
			}

			public function getExtension(): string
			{
				return pathinfo($this->path, PATHINFO_EXTENSION);
			}

			public function getDirectory(): Directory
			{
				return new Directory(dirname($this->path));
			}

			public function isExists(): bool
			{
				return is_file($this->path);
			}

			public function getContents(): string
			{
				return (string)file_get_contents($this->path);
			}

			public function putContents(mixed $data): int|false
			{
				return file_put_contents($this->path, (string)$data);
			}

			public static function deleteFile(string $path): bool
			{
				return is_file($path) && unlink($path);
			}

			public const REWRITE = 0;
			public const APPEND = 1;

			/** Как в ядре: каталог создаётся, если его нет. */
			public static function putFileContents(string $path, mixed $data, int $flags = self::REWRITE): bool
			{
				if(!is_dir(dirname($path)))
				{
					mkdir(dirname($path), 0777, true);
				}

				return false !== file_put_contents(
					$path,
					(string)$data,
					$flags === self::APPEND ? FILE_APPEND : 0
				);
			}

			public function delete(): bool
			{
				return is_file($this->path) && unlink($this->path);
			}
		}
	}
}

namespace Bitrix\Main\Security
{
	if(!class_exists(Random::class))
	{
		class Random
		{
			public static function getString(int $length = 32): string
			{
				return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Application::class))
	{
		class Application
		{
			public static string $documentRoot = '';

			public static ?object $connection = null;

			public static function getDocumentRoot(): string
			{
				return static::$documentRoot;
			}

			/**
			 * Соединение с базой: запоминает запросы, таблицы «есть» по
			 * списку. Настоящей базы у тестов нет и не нужно.
			 */
			public static function getConnection(): object
			{
				return static::$connection ??= new class
				{
					/** @var string[] */
					public array $tables = [];

					/** @var string[] */
					public array $queries = [];

					/** @var array<string, string[]> столбцы таблицы; нет записи — все текущие */
					public array $fields = [];

					public function getTableFields(string $table): array
					{
						return array_flip($this->fields[$table] ?? ['ID', 'MANAGER_TODO_AT', 'PROFILE_ID', 'OVERDUE_NOTIFIED_AT', 'ACTIVE_DAYS', 'ACTIVITY_ID', 'SESSION_ID', 'CHAT_ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'ASSESSMENT_ID', 'STATUS', 'SCORE', 'CRITERIA', 'SUMMARY', 'REASON', 'RESPONSIBLE_ID', 'CREATED_AT', 'DIRECTION', 'MAIL_AT', 'MODE', 'IS_CLIENT', 'RESULT', 'TODO_COUNT', 'ANALYZED_AT', 'REPLIED_AT', 'REPLY_SECONDS', 'REPLY_TODO_AT', 'SENIOR_TODO_AT']);
					}

					public function isTableExists(string $table): bool
					{
						return in_array($table, $this->tables, true);
					}

					public function queryExecute(string $sql): void
					{
						$this->queries[] = preg_replace('/\s+/', ' ', trim($sql));
					}
				};
			}
		}
	}
}

namespace Bitrix\Main\Type\Contract
{
	if(!interface_exists(Arrayable::class))
	{
		interface Arrayable
		{
			public function toArray(): array;
		}

		interface Jsonable
		{
			public function toJson(int $options = 0);
		}
	}
}

namespace
{
	if(!defined('BX_DIR_PERMISSIONS'))
	{
		define('BX_DIR_PERMISSIONS', 0777);
	}

	if(!defined('BX_UTF_PCRE_MODIFIER'))
	{
		// В UTF-режиме Битрикс подставляет 'u'.
		define('BX_UTF_PCRE_MODIFIER', 'u');
	}

	if(!class_exists('CUtil'))
	{
		/**
		 * Транслитерация ядра. Важно не как она переводит, а с чем её зовут.
		 */
		class CUtil
		{
			public static array $lastCall = [];

			public static function translit(string $value, string $lang, array $params): string
			{
				static::$lastCall = ['value' => $value, 'lang' => $lang, 'params' => $params];
				return $value;
			}
		}
	}
}


namespace Bitrix\Main\Engine
{
	if(!class_exists(CurrentUser::class))
	{
		/** Текущий пользователь; администратор ли он — решает тест. */
		class CurrentUser
		{
			public static bool $isAdmin = false;

			public static function get(): static
			{
				return new static();
			}

			public function isAdmin(): bool
			{
				return static::$isAdmin;
			}
		}
	}
}

namespace Bitrix\Main\UI
{
	if(!class_exists(Extension::class))
	{
		/** Подключение расширений: запоминаем, что просили. */
		class Extension
		{
			/** @var string[] */
			public static array $loaded = [];

			public static function load(string|array $extensions): void
			{
				foreach((array)$extensions as $extension)
				{
					static::$loaded[] = $extension;
				}
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(EventResult::class))
	{
		class Event
		{
			public function __construct(
				public readonly string $moduleId = '',
				public readonly string $type = '',
				public readonly array $parameters = []
			) {}

			public function send(): void {}
		}

		class EventResult
		{
			public const UNDEFINED = 0;
			public const SUCCESS = 1;
			public const ERROR = 2;

			public function __construct(
				private readonly int $type,
				private readonly mixed $parameters = null,
				private readonly ?string $moduleId = null
			) {}

			public function getType(): int
			{
				return $this->type;
			}

			public function getParameters(): mixed
			{
				return $this->parameters;
			}

			public function getModuleId(): ?string
			{
				return $this->moduleId;
			}
		}

		/** Регистрация обработчиков: запоминаем, что сняли. */
		class EventManager
		{
			/** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> */
			public static array $unregistered = [];

			/** @var list<array> */
			public static array $registered = [];

			public static function getInstance(): static
			{
				return new static();
			}

			public function findEventHandlers(string $moduleId, string $eventType): array
			{
				return [];
			}

			public function registerEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				static::$registered[] = [$fromModule, $event, $toModule, $class, $method];
			}

			public function registerEventHandlerCompatible(string $fromModule, string $event, string $toModule, string $class, string $method, int $sort = 100): void
			{
				static::$registered[] = [$fromModule, $event, $toModule, $class, $method];
			}

			public function unRegisterEventHandler(string $fromModule, string $event, string $toModule, string $class, string $method): void
			{
				static::$unregistered[] = [$fromModule, $event, $toModule, $class, $method];
			}
		}

		class ModuleManager
		{
			/** @var string[] */
			public static array $installed = [];

			public static function isModuleInstalled(string $moduleId): bool
			{
				return in_array($moduleId, static::$installed, true);
			}

			public static function getInstalledModules(): array
			{
				return array_fill_keys(static::$installed, []);
			}
		}
	}
}

namespace Bitrix\Main
{
	if(!class_exists(Context::class))
	{
		/**
		 * Контекст запроса. В административной части сайта у него нет —
		 * ровно так это выглядит на портале, и admin/menu.php обязан это
		 * пережить.
		 */
		class Context
		{
			public static ?string $language = 'ru';
			public static ?string $site = null;
			public static bool $isAdminSection = false;

			public static function getCurrent(): static
			{
				return new static();
			}

			public function getLanguage(): ?string
			{
				return static::$language;
			}

			public function getSite(): ?string
			{
				return static::$site;
			}

			public function getRequest(): object
			{
				return new class
				{
					public function isAdminSection(): bool
					{
						return Context::$isAdminSection;
					}
				};
			}
		}
	}
}

namespace Bitrix\Main\DI
{
	if(!class_exists(ServiceLocator::class))
	{
		/**
		 * Сервисы модуля: ядро берёт их из ключа services в .settings.php
		 * модуля. Здесь — оттуда же (Configuration::$settings), и так же:
		 * constructor вызывается, className создаётся, объект один на запрос.
		 */
		class ServiceLocator
		{
			/** @var array<string, object> */
			private static array $built = [];

			public static function getInstance(): static
			{
				return new static();
			}

			private function describe(string $id): ?array
			{
				$services = \Bitrix\Main\Config\Configuration::getInstance()->get('services');

				return is_array($services) && is_array($services[$id] ?? null) ? $services[$id] : null;
			}

			public function has(string $id): bool
			{
				return null !== $this->describe($id);
			}

			public function get(string $id): mixed
			{
				if(isset(static::$built[$id]))
				{
					return static::$built[$id];
				}

				$service = $this->describe($id);
				if(null === $service)
				{
					throw new \Bitrix\Main\SystemException('Service '.$id.' not found');
				}

				return static::$built[$id] = isset($service['constructor'])
					? ($service['constructor'])()
					: new $service['className']();
			}
		}
	}
}

namespace
{
	if(!class_exists('CEventLog'))
	{
		/** Журнал событий: запоминаем записи вместо таблицы b_event_log. */
		class CEventLog
		{
			/** @var list<array{SEVERITY: string, AUDIT_TYPE_ID: string, MODULE_ID: string, ITEM_ID: mixed, DESCRIPTION: string}> */
			public static array $records = [];

			public static function Log($severity, $auditTypeId, $moduleId, $itemId, $description = false, $siteId = false): void
			{
				static::$records[] = [
					'SEVERITY' => (string)$severity,
					'AUDIT_TYPE_ID' => (string)$auditTypeId,
					'MODULE_ID' => (string)$moduleId,
					'ITEM_ID' => $itemId,
					'DESCRIPTION' => (string)$description,
				];
			}
		}
	}

	if(!function_exists('htmlspecialcharsbx'))
	{
		function htmlspecialcharsbx($string, $flags = ENT_COMPAT, $doubleEncode = true)
		{
			return htmlspecialchars((string)$string, $flags, 'UTF-8', $doubleEncode);
		}
	}
}

namespace Bitrix\Main\ORM\Data
{
	if(!class_exists(DataManager::class))
	{
		/**
		 * Базовый класс таблиц ORM. Тестам нужен только для загрузки класса
		 * таблицы модуля: create/drop идут своим SQL через соединение.
		 */
		abstract class DataManager
		{
			public static function getTableName(): string
			{
				return '';
			}
		}
	}
}

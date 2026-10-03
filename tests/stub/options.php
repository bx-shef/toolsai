<?php

/**
 * Заглушка API страницы настроек shef.options 3.x — ровно то, что зовёт
 * options_conf.php этого модуля.
 *
 * Сигнатуры повторяют shef.options 3.x (optionsconfig.php,
 * lib/main/options/*.php), логики нет: опции только запоминают, что им
 * передали. Ради сигнатур всё и затеяно — в 1.1.7 options_conf.php звал
 * ShOptionsConfig::getInstance(indexDoc: ...), в 3.x такого параметра нет, и
 * страница настроек падала «Unknown named parameter». Здесь это Error.
 *
 * Поменяется API в shef.options — эта заглушка обязана поменяться вместе с
 * ним, иначе тест проверяет прошлое.
 */

namespace Shef\Options\Main\Options
{
	enum TypeUIAlert: string
	{
		case Error = 'ui-alert-danger';
		case Note = 'ui-alert-default';
		case Warning = 'ui-alert-warning';
	}

	abstract class AOption
	{
		protected string $title = '';
		protected string $description = '';

		public function __construct(protected readonly string $code) {}

		public function getCode(): string
		{
			return $this->code;
		}

		public function setTitle(string $value): static
		{
			$this->title = $value;
			return $this;
		}

		public function getTitle(): string
		{
			return $this->title;
		}

		public function setDescription(string $value): static
		{
			$this->description = $value;
			return $this;
		}

		public function getDescription(): string
		{
			return $this->description;
		}
	}

	class RowInfo extends AOption
	{
		public ?TypeUIAlert $type = null;

		public function setType(TypeUIAlert $value): static
		{
			$this->type = $value;
			return $this;
		}
	}

	class Enum extends AOption
	{
		public int $showRows = 0;
		public array $list = [];

		public function setList(array $value): static
		{
			$this->list = $value;
			return $this;
		}

		public function getList(): array
		{
			return $this->list;
		}

		public function setShowRows(int $value): static
		{
			$this->showRows = $value;
			return $this;
		}
	}

	class Users extends Enum
	{
		public array $filter = [];

		public function initSimpleUserList(array $filter, ?array $select = null, ?array $order = null): static
		{
			$this->filter = $filter;
			return $this;
		}
	}

	class EnumCrmDealCategory extends Enum
	{
		public array $listFilter = [];

		public function initSimpleList(array $filter): static
		{
			$this->listFilter = $filter;
			return $this;
		}
	}

	class Text extends AOption {}

	class TextArea extends AOption
	{
		public int $rows = 5;
		public int $cols = 15;

		public function setRows(int $value): static
		{
			$this->rows = $value;
			return $this;
		}

		public function setCols(int $value): static
		{
			$this->cols = $value;
			return $this;
		}
	}

	class Checkbox extends AOption {}

	class NumberInt extends AOption {}

	class Tab
	{
		protected string $name = '';
		protected string $title = '';
		/** @var AOption[] */
		protected array $options = [];

		public function __construct(protected readonly string $code) {}

		public function getCode(): string
		{
			return $this->code;
		}

		public function setName(string $value): static
		{
			$this->name = $value;
			return $this;
		}

		public function getName(): string
		{
			return $this->name;
		}

		public function setTitle(string $value): static
		{
			$this->title = $value;
			return $this;
		}

		public function addOption(AOption $value): static
		{
			$this->options[] = $value;
			return $this;
		}

		/** @return AOption[] */
		public function getOptionList(): array
		{
			return $this->options;
		}
	}
}

namespace Shef\Options\Main
{
	class Constants
	{
		/** Служебный пользователь — настройка shef.options. */
		public static function getSystemUserId(): int
		{
			return 1;
		}
	}
}

namespace
{
	use Bitrix\Main\Result;
	use Shef\Options\Main\Options;

	class ShOptionsConfig
	{
		/** @var Options\Tab[] */
		private array $tabs = [];

		protected function __construct(public readonly string $moduleId) {}

		public static function getInstance(string $moduleId): Result
		{
			return (new Result())->setData(['OPTIONS' => new static($moduleId)]);
		}

		public function addTab(null|Options\Tab $tab): static
		{
			if(null !== $tab)
			{
				$this->tabs[$tab->getCode()] = $tab;
			}

			return $this;
		}

		/** @return Options\Tab[] */
		public function get(): array
		{
			return array_values($this->tabs);
		}
	}
}

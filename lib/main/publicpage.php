<?php declare(strict_types=1);

namespace Shef\ToolsAi\Main;

/**
 * Заглушки публичных страниц модуля: эндпоинт движка в /bitrix/tools,
 * страницы расхода и профилей анализа сделок в /bitrix/admin.
 *
 * Каталог модуля браузеру недоступен, поэтому снаружи лежит файл в одну
 * строку, как принято в Битриксе:
 *
 *   <?php require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/shef.toolsai/admin/quota.php');
 *
 * Путь в нём — туда, где модуль стоит НА САМОМ ДЕЛЕ: /bitrix/modules или
 * /local/modules. Готовый файл с зашитым путём модулю из другого каталога
 * дал бы белую страницу, поэтому заглушку не копируют, а пишут. Модуль вне
 * корня сайта — путь абсолютный. Канон — Main\AdminPage в shef.problems.
 *
 * Удаляется заглушка только СВОЯ — та, что ведёт в эту страницу модуля
 * shef.toolsai. Проект мог положить на это место свой файл, и ни установка,
 * ни удаление не вправе его трогать.
 *
 * Класс самодостаточен — без ядра и модуля: установщик подключает его явным
 * require_once, на автозагрузку в установщике полагаться нельзя.
 */
class PublicPage
{
	public function __construct(
		/** От корня сайта: /bitrix/admin/shef_toolsai_quota.php */
		public readonly string $file,
		/** От каталога модуля: /admin/quota.php */
		public readonly string $modulePage,
	)
	{
	}

	/**
	 * @return static[]
	 */
	public static function getList(): array
	{
		return [
			new static(Constants::ENDPOINT_FILE, Constants::ENDPOINT_MODULE_PAGE),
			new static(Constants::QUOTA_FILE, Constants::QUOTA_MODULE_PAGE),
			new static(Constants::DEAL_PROFILES_FILE, Constants::DEAL_PROFILES_MODULE_PAGE),
		];
	}

	public function getTarget(string $documentRoot): string
	{
		return rtrim(str_replace('\\', '/', $documentRoot), '/').$this->file;
	}

	/**
	 * Содержимое заглушки для модуля, лежащего в $moduleDir.
	 */
	public function getContent(string $documentRoot, string $moduleDir): string
	{
		$documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
		$page = rtrim(str_replace('\\', '/', $moduleDir), '/').$this->modulePage;

		if($documentRoot !== '' && str_starts_with($page, $documentRoot.'/'))
		{
			return sprintf(
				"<?php require(\$_SERVER['DOCUMENT_ROOT'].'%s');\n",
				substr($page, strlen($documentRoot))
			);
		}

		return sprintf("<?php require('%s');\n", $page);
	}

	/**
	 * Своя заглушка: та, что модуль написал бы сейчас из $moduleDir (каталог
	 * может называться не shef.toolsai — клон репозитория), либо require на
	 * .../shef.toolsai<страница модуля> от корня сайта или абсолютным путём.
	 */
	public function isOwn(string $content, string $documentRoot = '', string $moduleDir = ''): bool
	{
		if($moduleDir !== '' && $content === $this->getContent($documentRoot, $moduleDir))
		{
			return true;
		}

		$pattern = sprintf(
			'#^<\?php require\((?:\$_SERVER\[\'DOCUMENT_ROOT\'\]\.)?\'[^\']*/%s%s\'\);\s*$#',
			preg_quote(Constants::MODULE_ID, '#'),
			preg_quote($this->modulePage, '#')
		);

		return 1 === preg_match($pattern, $content);
	}

	/**
	 * Положить заглушку. Нет файла — пишет. Своя с другим путём (модуль
	 * переехал) — переписывает. Чужая — не трогает.
	 *
	 * @return bool заглушка на месте и ведёт в этот модуль
	 */
	public function install(string $documentRoot, string $moduleDir): bool
	{
		$target = $this->getTarget($documentRoot);
		$content = $this->getContent($documentRoot, $moduleDir);

		if(is_file($target))
		{
			$current = (string)file_get_contents($target);
			if($current === $content)
			{
				return true;
			}

			if(!$this->isOwn($current, $documentRoot, $moduleDir))
			{
				return false;
			}
		}

		if(!is_dir(dirname($target)) || !is_writable(dirname($target)))
		{
			return false;
		}

		return false !== file_put_contents($target, $content);
	}

	/**
	 * Убрать заглушку — только свою.
	 *
	 * @return bool своей заглушки больше нет
	 */
	public function uninstall(string $documentRoot, string $moduleDir = ''): bool
	{
		$target = $this->getTarget($documentRoot);

		if(!is_file($target))
		{
			return true;
		}

		if(!$this->isOwn((string)file_get_contents($target), $documentRoot, $moduleDir))
		{
			return false;
		}

		return unlink($target);
	}
}

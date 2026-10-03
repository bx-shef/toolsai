<?php declare(strict_types=1);

/**
 * Профили анализа сделок: разбор, подбор к сделке, шкала, перенос старых
 * настроек, таблицы.
 *
 * Что держит:
 *
 * * тип клиента — коды речевой аналитики, перевод из enum ядра как у
 *   AssessmentClientTypeResolver;
 * * профиль: границы 0-100, HIGH не ниже LOW, форма не превращает «70%» в
 *   другое число молча;
 * * подбор: только включённые и своего направления; конкретный тип главнее
 *   «любого»; потом SORT, ID; нет профиля — null;
 * * шкала: < LOW — ничего; >= LOW — дело менеджеру только без
 *   запланированных дел; >= HIGH и needSenior — комментарий и дело
 *   старшему; менеджер = старший — одно дело; повторы не чаще срока;
 * * перенос: по профилю на направление, LOW = HIGH = порог, промпт пустой;
 *   нет направлений — ничего;
 * * таблицы: профили создаются, в таблицу проверок дописываются столбцы.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Deal\ClientType;
use Shef\ToolsAi\Deal\Model\DealCheckTable;
use Shef\ToolsAi\Deal\Model\DealProfileTable;
use Shef\ToolsAi\Deal\Profile;
use Shef\ToolsAi\Deal\ProfileMigration;
use Shef\ToolsAi\Deal\ProfilePicker;
use Shef\ToolsAi\Deal\RiskScale;
use Shef\ToolsAi\Deal\Verdict;

Check::group('тип клиента');

Check::same('по кому: компания главнее контакта', ClientType::pickClient(5, 7), [ClientType::CLIENT_COMPANY, 5]);
Check::same('по кому: нет компании — контакт', ClientType::pickClient(0, 7), [ClientType::CLIENT_CONTACT, 7]);
Check::same('по кому: нет обоих — не определён', ClientType::pickClient(0, 0), null);

Check::same('коды как у речевой аналитики', ClientType::getAll(), [1, 2, 3, 4]);
Check::same('New -> NEW', ClientType::fromCoreName('New'), ClientType::NEW);
Check::same('Existing -> IN_WORK', ClientType::fromCoreName('Existing'), ClientType::IN_WORK);
Check::same('PreviouslyContacted -> REPEATED_APPROACH', ClientType::fromCoreName('PreviouslyContacted'), ClientType::REPEATED_APPROACH);
Check::same('WithSale -> RETURN_CUSTOMER', ClientType::fromCoreName('WithSale'), ClientType::RETURN_CUSTOMER);
Check::same('Unrecognised -> null', ClientType::fromCoreName('Unrecognised'), null);
Check::same('список: мусор и повторы отброшены, по порядку', ClientType::parseList('3, 1,x,9,3'), [1, 3]);
Check::same('список из массива формы', ClientType::parseList(['4', '2']), [2, 4]);
Check::same('пусто — любой', ClientType::parseList(''), []);
Check::same('в строку для таблицы', ClientType::toList([3, 1]), '1,3');

Check::group('профиль из строки');

$p = Profile::fromRow(['ID' => '5', 'TITLE' => ' Опт ', 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => '2', 'CLIENT_TYPES' => '2,4', 'LOW_BORDER' => '150', 'HIGH_BORDER' => '-3']);
Check::same('ID и направление числом', [$p->id, $p->categoryId], [5, 2]);
Check::same('название без краевых пробелов', $p->title, 'Опт');
Check::same('LOW подрезан до 100', $p->lowBorder, 100);
Check::same('HIGH мусором — умолчание, но не ниже LOW', $p->highBorder, 100);
Check::same('типы клиента', $p->clientTypes, [2, 4]);
Check::same('выключен по умолчанию', Profile::fromRow([])->enabled, false);
Check::same('умолчания шкалы', [Profile::fromRow([])->lowBorder, Profile::fromRow([])->highBorder, Profile::fromRow([])->idleDays, Profile::fromRow([])->reanalyzeDays], [50, 70, 3, 7]);
Check::same('промпт обрезан', mb_strlen(Profile::fromRow(['PROMPT' => str_repeat('я', 9000)])->prompt), Profile::PROMPT_MAX);

Check::group('профиль из формы');

[$fields, $errors] = Profile::fromInput(['TITLE' => 'Опт', 'IS_ENABLED' => 'Y', 'CATEGORY_ID' => '2', 'CLIENT_TYPES' => ['3', '1'], 'LOW_BORDER' => '40', 'HIGH_BORDER' => '80', 'SENIOR_ID' => '7', 'PROMPT' => ' свой ']);
Check::same('без ошибок', $errors, []);
Check::same('поля', [$fields['IS_ENABLED'], $fields['CATEGORY_ID'], $fields['CLIENT_TYPES'], $fields['LOW_BORDER'], $fields['HIGH_BORDER'], $fields['SENIOR_ID'], $fields['PROMPT']], ['Y', 2, '1,3', 40, 80, 7, 'свой']);
[, $errors] = Profile::fromInput(['TITLE' => '', 'LOW_BORDER' => '70%', 'HIGH_BORDER' => '101']);
Check::same('пустое название, «70%», 101 — ошибки', $errors, ['TITLE', 'LOW_BORDER', 'HIGH_BORDER']);
[, $errors] = Profile::fromInput(['TITLE' => 'x', 'LOW_BORDER' => '80', 'HIGH_BORDER' => '60']);
Check::same('HIGH ниже LOW — ошибка', $errors, ['HIGH_BORDER']);
[$fields] = Profile::fromInput(['TITLE' => 'x']);
Check::same('флажок не отмечен — выключен', $fields['IS_ENABLED'], 'N');

Check::group('подбор профиля');

$make = static fn(int $id, int $category, string $types = '', int $sort = 100, bool $enabled = true): Profile => Profile::fromRow([
	'ID' => $id, 'TITLE' => 'p'.$id, 'IS_ENABLED' => $enabled ? 'Y' : 'N', 'CATEGORY_ID' => $category, 'CLIENT_TYPES' => $types, 'SORT' => $sort,
]);
$profiles = [
	$make(1, 0),
	$make(2, 0, '1'),
	$make(3, 0, '1', 50),
	$make(4, 5, '', 10, false),
	$make(5, 5, '', 20),
	$make(6, 5, '', 20),
	$make(7, 9, '4'),
];
$pickId = static fn(int $category, ?int $type): ?int => ProfilePicker::pick($profiles, $category, $type)?->id;

Check::same('тип совпал — профиль с типом главнее «любого»', $pickId(0, ClientType::NEW), 3);
Check::same('среди профилей с типом — меньший SORT', $pickId(0, ClientType::NEW), 3);
Check::same('тип не совпал — «любой»', $pickId(0, ClientType::IN_WORK), 1);
Check::same('тип не определился — «любой»', $pickId(0, null), 1);
Check::same('выключенный пропущен, при равном SORT — меньший ID', $pickId(5, null), 5);
Check::same('только профиль с типом, тип другой — нет профиля', $pickId(9, ClientType::NEW), null);
Check::same('тип не определился, профиль с типом — нет профиля', $pickId(9, null), null);
Check::same('направление без профилей — нет', $pickId(42, ClientType::NEW), null);
Check::same('пустой список — нет', ProfilePicker::pick([], 0, null), null);

Check::same(
	'направления кандидатов: включённые, самый короткий повтор',
	ProfilePicker::getCategoryReanalyzeDays([
		Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3, 'REANALYZE_DAYS' => 7]),
		Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3, 'REANALYZE_DAYS' => 2]),
		Profile::fromRow(['IS_ENABLED' => 'N', 'CATEGORY_ID' => 4, 'REANALYZE_DAYS' => 1]),
		Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 0, 'REANALYZE_DAYS' => 5]),
	]),
	[0 => 5, 3 => 2]
);

$monthly = Profile::fromRow(['IS_ENABLED' => 'Y', 'CATEGORY_ID' => 3, 'REANALYZE_DAYS' => 30]);
$now = 1_800_000_000;
Check::same(
	'срок повтора — по профилю сделки, а не по самому короткому в направлении',
	[
		ProfilePicker::isDue($monthly, null, $now),
		ProfilePicker::isDue($monthly, $now - 86400, $now),
		ProfilePicker::isDue($monthly, $now - 29 * 86400, $now),
		ProfilePicker::isDue($monthly, $now - 30 * 86400, $now),
	],
	[true, false, false, true]
);

Check::group('шкала');

$scale = Profile::fromRow(['IS_ENABLED' => 'Y', 'LOW_BORDER' => 40, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7]);
$v = static fn(int $risk, bool $senior = true): Verdict => Verdict::fromArray(['risk' => $risk, 'needSenior' => $senior]);
$d = static fn(Verdict $verdict, int $manager = 3, int $open = 0, bool $managerRecent = false, bool $seniorRecent = false, ?Profile $profile = null): array => (array)RiskScale::decide($verdict, $profile ?? $scale, $manager, $open, $managerRecent, $seniorRecent);
$none = ['managerTodo' => false, 'comment' => false, 'seniorTodo' => false, 'seniorMissing' => false, 'seniorOverdueTodo' => false];

Check::same('ниже LOW — ничего', $d($v(39)), $none);
Check::same('ровно LOW, дел нет — дело менеджеру', $d($v(40)), ['managerTodo' => true] + $none);
Check::same('LOW, но есть запланированное дело — ничего', $d($v(60), 3, 1), $none);
Check::same('LOW, ответственного нет — ничего', $d($v(60), 0), $none);
Check::same('HIGH и needSenior, дел нет — менеджер, комментарий, старший', $d($v(70)), ['managerTodo' => true, 'comment' => true, 'seniorTodo' => true, 'seniorMissing' => false, 'seniorOverdueTodo' => false]);
Check::same('HIGH и needSenior, дела есть — только старший', $d($v(90), 3, 2), ['managerTodo' => false, 'comment' => true, 'seniorTodo' => true, 'seniorMissing' => false, 'seniorOverdueTodo' => false]);
Check::same('HIGH без needSenior — уровень менеджера', $d($v(90, false)), ['managerTodo' => true] + $none);
Check::same('менеджер и есть старший — одно дело', $d($v(90), 7), ['managerTodo' => false, 'comment' => true, 'seniorTodo' => true, 'seniorMissing' => false, 'seniorOverdueTodo' => false]);
Check::same('менеджер = старший, но эскалация недавно — дело менеджеру', $d($v(90), 7, 0, false, true), ['managerTodo' => true] + $none);
Check::same('дело менеджеру недавно — не повторяем', $d($v(60), 3, 0, true), $none);
Check::same('эскалация недавно — не повторяем', $d($v(90), 3, 1, false, true), $none);
Check::same('старший не задан — комментарий и пометка', $d($v(90), 3, 1, false, false, Profile::fromRow(['LOW_BORDER' => 40, 'HIGH_BORDER' => 70])), ['managerTodo' => false, 'comment' => true, 'seniorTodo' => false, 'seniorMissing' => true, 'seniorOverdueTodo' => false]);
Check::same('пропущенная сделка — никогда', $d(Verdict::skipped('в работе')), $none);
Check::same('LOW = HIGH (после переноса): ниже — ничего', $d($v(69), 3, 0, false, false, Profile::fromRow(['LOW_BORDER' => 70, 'HIGH_BORDER' => 70, 'SENIOR_ID' => 7])), $none);

Check::group('перенос старых настроек');

$legacy = static fn(array $values): Config => new Config(static fn(string $module, string $name): string => $values[$name] ?? '');
$plan = ProfileMigration::plan($legacy(['DEAL_categories' => 'a:2:{i:0;s:1:"0";i:1;s:1:"3";}', 'DEAL_threshold' => '65', 'DEAL_senior' => '7', 'DEAL_reanalyzedays' => '5', 'DEAL_idledays' => '2']));
Check::same('по профилю на направление', array_column($plan, 'CATEGORY_ID'), [0, 3]);
Check::same('LOW = HIGH = порог', [$plan[0]['LOW_BORDER'], $plan[0]['HIGH_BORDER']], [65, 65]);
Check::same('старший, сроки, промпт пустой, включён', [$plan[1]['SENIOR_ID'], $plan[1]['REANALYZE_DAYS'], $plan[1]['IDLE_DAYS'], $plan[1]['PROMPT'], $plan[1]['CLIENT_TYPES'], $plan[1]['IS_ENABLED']], [7, 5, 2, '', '', 'Y']);
Check::same('строки годятся профилю как есть', Profile::fromRow($plan[1])->highBorder, 65);
Check::same('направлений нет — ничего', ProfileMigration::plan($legacy([])), []);
Check::same('порог по умолчанию — 70', ProfileMigration::plan($legacy(['DEAL_categories' => '4']))[0]['LOW_BORDER'] ?? null, 70);

Check::group('таблицы');

$connection = Application::getConnection();
$connection->tables = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('профили: создаётся таблица', str_contains($connection->queries[0] ?? '', 'CREATE TABLE shef_toolsai_deal_profile'), true);
Check::same('профили: промпт — TEXT, типы — строка', str_contains($connection->queries[0] ?? '', 'PROMPT TEXT NULL') && str_contains($connection->queries[0] ?? '', "CLIENT_TYPES VARCHAR(50) NOT NULL DEFAULT ''"), true);

$connection->tables = ['shef_toolsai_deal_check'];
$connection->fields = ['shef_toolsai_deal_check' => ['ID', 'DEAL_ID', 'ESCALATED_AT']];
$connection->queries = [];
DealCheckTable::init();
Check::same('проверки до 1.1.0: дописаны столбцы', $connection->queries, [
	'ALTER TABLE shef_toolsai_deal_check ADD COLUMN MANAGER_TODO_AT DATETIME NULL',
	'ALTER TABLE shef_toolsai_deal_check ADD COLUMN PROFILE_ID INT(11) NOT NULL DEFAULT 0',
	'ALTER TABLE shef_toolsai_deal_check ADD COLUMN OVERDUE_NOTIFIED_AT DATETIME NULL',
]);
$connection->fields = [];
$connection->queries = [];
DealCheckTable::init();
Check::same('столбцы уже есть — ничего', $connection->queries, []);

$connection->tables = ['shef_toolsai_deal_profile'];
$connection->fields = ['shef_toolsai_deal_profile' => ['ID', 'TITLE', 'SENIOR_ID']];
$connection->queries = [];
DealProfileTable::init();
Check::same('профили до 1.4.0: дописан ACTIVE_DAYS', $connection->queries, ['ALTER TABLE shef_toolsai_deal_profile ADD COLUMN ACTIVE_DAYS INT(11) NOT NULL DEFAULT 60']);
$connection->fields = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('ACTIVE_DAYS уже есть — ничего', $connection->queries, []);
$connection->tables = [];
$connection->queries = [];
DealProfileTable::init();
Check::same('новая таблица — с ACTIVE_DAYS', str_contains($connection->queries[0] ?? '', 'ACTIVE_DAYS INT(11) NOT NULL DEFAULT 60'), true);
$connection->tables = [];

Check::group('живые сделки: ACTIVE_DAYS');

Check::same('по умолчанию 60', Profile::fromRow([])->activeDays, 60);
Check::same('0 — без ограничения', Profile::fromRow(['ACTIVE_DAYS' => '0'])->activeDays, 0);
Check::same('форма: 90', Profile::fromInput(['TITLE' => 'x', 'ACTIVE_DAYS' => '90'])[0]['ACTIVE_DAYS'], 90);
Check::same('форма: мусор — ошибка', Profile::fromInput(['TITLE' => 'x', 'ACTIVE_DAYS' => '-5'])[1], ['ACTIVE_DAYS']);
$p = static fn(int $cat, int $days, string $on = 'Y'): Profile => Profile::fromRow(['CATEGORY_ID' => $cat, 'ACTIVE_DAYS' => $days, 'IS_ENABLED' => $on]);
Check::same(
	'выборка: самый мягкий срок направления, 0 побеждает, выключенные не в счёт',
	ProfilePicker::getCategoryActiveDays([$p(1, 30), $p(1, 90), $p(2, 60), $p(2, 0), $p(3, 10), $p(3, 500, 'N')]),
	[1 => 90, 2 => 0, 3 => 10]
);

Check::group('шкала: просрочка');

$o = static fn(Verdict $verdict, int $manager = 3, int $open = 0, int $overdue = 2, bool $overdueRecent = false, ?Profile $profile = null, bool $managerRecent = false): array => (array)RiskScale::decide($verdict, $profile ?? $scale, $manager, $open, $managerRecent, false, $overdue, $overdueRecent);
Check::same('LOW, просрочено 2, запланированных нет — менеджеру и старшему', $o($v(50)), array_replace($none, ['managerTodo' => true, 'seniorOverdueTodo' => true]));
Check::same('просрочек нет — только менеджеру', $o($v(50), 3, 0, 0), array_replace($none, ['managerTodo' => true]));
Check::same('есть запланированное — никому', $o($v(50), 3, 1), $none);
Check::same('ниже LOW — никому, даже с просрочкой', $o($v(10)), $none);
Check::same('старшему о просрочке писали недавно — только менеджеру', $o($v(50), 3, 0, 2, true), array_replace($none, ['managerTodo' => true]));
Check::same('дело менеджеру недавно — и старшему нет', $o($v(50), 3, 0, 2, false, null, true), $none);
Check::same('старший не задан — только менеджеру', $o($v(50), 3, 0, 2, false, Profile::fromRow(['LOW_BORDER' => 40, 'HIGH_BORDER' => 70])), array_replace($none, ['managerTodo' => true]));
Check::same('старший и есть менеджер — одно дело', $o($v(50), 7), array_replace($none, ['managerTodo' => true]));
Check::same('эскалация старшему уже идёт — второго дела нет', $o($v(90)), array_replace($none, ['managerTodo' => true, 'comment' => true, 'seniorTodo' => true]));
Check::same('мёртвая сделка (риск 100, без needSenior) с просрочкой — менеджеру и старшему', $o($v(100, false)), array_replace($none, ['managerTodo' => true, 'seniorOverdueTodo' => true]));

Check::finish();

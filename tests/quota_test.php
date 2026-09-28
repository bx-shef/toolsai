<?php declare(strict_types=1);

/**
 * Остаток квоты: граничные значения.
 *
 * Что держит:
 *
 * * квота 0 и меньше — безлимит, который никогда не блокирует;
 * * перерасход не становится отрицательным остатком;
 * * «ровно на остаток» — хватает: последний разрешённый запрос месяца;
 * * начало периода — первое число месяца, 00:00.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Quota\Balance;

$from = new DateTimeImmutable('2026-09-01');

Check::group('безлимит');

$unlimited = new Balance(0, 999_000_000, $from);
Check::same('квота 0 — безлимит', $unlimited->isUnlimited(), true);
Check::same('не блокирует даже огромный запрос', $unlimited->canSpend(PHP_INT_MAX), true);
Check::same('процент 0', $unlimited->getPercent(), 0.0);
Check::same('отрицательная квота — тоже безлимит', (new Balance(-1, 0, $from))->canSpend(1), true);

Check::group('обычная квота');

$balance = new Balance(100, 90, $from);
Check::same('остаток', $balance->getLeftMicro(), 10);
Check::same('ровно на остаток — хватает', $balance->canSpend(10), true);
Check::same('на единицу больше — нет', $balance->canSpend(11), false);
Check::same('процент', $balance->getPercent(), 90.0);
Check::same('отрицательная оценка считается нулём', $balance->canSpend(-5), true);

Check::group('перерасход');

$over = new Balance(100, 130, $from);
Check::same('остаток не отрицательный', $over->getLeftMicro(), 0);
Check::same('процент больше 100', $over->getPercent(), 130.0);
Check::same('нулевой запрос — нельзя', (new Balance(100, 100, $from))->canSpend(1), false);
Check::same('ноль при нулевом остатке — можно', (new Balance(100, 100, $from))->canSpend(0), true);

Check::group('выход за квоту');

Check::same('потрачено ровно квоту — не выход', (new Balance(100, 100, $from))->isExceeded(), false);
Check::same('на единицу больше — выход', (new Balance(100, 101, $from))->isExceeded(), true);
Check::same('безлимит не выходит никогда', (new Balance(0, PHP_INT_MAX, $from))->isExceeded(), false);

Check::group('начало месяца');

Check::same(
	'середина месяца',
	Balance::getMonthStart(new DateTimeImmutable('2026-09-28 17:45:12'))->format('Y-m-d H:i:s'),
	'2026-09-01 00:00:00'
);
Check::same(
	'первое число, полночь',
	Balance::getMonthStart(new DateTimeImmutable('2026-10-01 00:00:00'))->format('Y-m-d H:i:s'),
	'2026-10-01 00:00:00'
);

Check::finish();

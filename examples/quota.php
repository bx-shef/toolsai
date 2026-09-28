<?php declare(strict_types=1);

/**
 * Квота: как настройка превращается в «сколько осталось».
 *
 * ЦЕЛЬ
 *   Показать, как модуль читает квоту и цены из настроек и считает остаток:
 *   деньги — в микро-единицах (1/1_000_000), строкой, без float; мусор в
 *   настройке — не половина значения, а умолчание.
 *
 * ГДЕ ПРИМЕНЯТЬ
 *   Когда задаёте квоту и цены провайдера на странице настроек и хотите
 *   понимать, что именно модуль из них прочитает. И в своём модуле — когда
 *   надо проверить квоту до вызова LLM (навык shef-new-ai-provider).
 *
 * ЧТО ДОЛЖНО ПОЛУЧИТЬСЯ
 *   Все строки «ok», последняя — «ГОТОВО: quota», код возврата 0.
 *   По сути:
 *     * «500» — это 500 000 000 микро, «12,50» — 12 500 000;
 *     * квота 0 — без ограничения;
 *     * потрачено ровно квоту — ещё не перерасход, на микро больше — уже да.
 *
 * ЗАПУСК
 *   php examples/quota.php
 *   DOCUMENT_ROOT=/var/www/portal php examples/quota.php
 *
 * НА ПОРТАЛЕ ОТЛИЧАЕТСЯ
 *   Последний шаг печатает настоящий остаток портала из журнала расхода;
 *   его число не проверяется — оно своё на каждом портале.
 */

require_once __DIR__.'/_bootstrap.php';

use Shef\ToolsAi\Main\OptionParser;
use Shef\ToolsAi\Quota\Balance;

title('Квота: из настройки в остаток');

step('Настройка -> микро-единицы');
check('«500»', OptionParser::micro('500'), 500_000_000);
check('«12,50»', OptionParser::micro('12,50'), 12_500_000);
check('«0.1» — ровно, без ошибки float', OptionParser::micro('0.1'), 100_000);
check('«1e3» — мусор, умолчание', OptionParser::micro('1e3'), 0);

$from = new DateTimeImmutable('first day of this month 00:00');

step('Остаток');
check('квота 0 — без ограничения', (new Balance(0, 10_000_000, $from))->isUnlimited(), true);

$balance = new Balance(OptionParser::micro('500'), OptionParser::micro('499,999999'), $from);
check('остаток — одна микро-единица', $balance->getLeftMicro(), 1);
check('ещё не перерасход', $balance->isExceeded(), false);
check('на микро больше — перерасход', (new Balance(500_000_000, 500_000_001, $from))->isExceeded(), true);

if($exampleMode === 'портал')
{
	step('Остаток этого портала');
	$real = \Shef\ToolsAi\Container::getMeter()->getMonthly();
	note(sprintf(
		'с %s потрачено %s, квота %s',
		$real->periodFrom->format('d.m.Y'),
		number_format($real->spentMicro / 1_000_000, 2, '.', ' '),
		$real->isUnlimited() ? 'не задана' : number_format($real->limitMicro / 1_000_000, 2, '.', ' ')
	));
}

done('quota');

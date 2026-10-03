<?php declare(strict_types=1);

/**
 * Страница «ИИ: статистика» — чистая логика.
 *
 * Что держит:
 *
 * * период: строго Y-m-d, несуществующий день и мусор — по умолчанию (с
 *   начала месяца по сегодня), «с» позже «по» — меняются местами, «по»
 *   включительно (в SQL — < следующего дня);
 * * RESULT оценки звонка: criteria в корне (так пишет ядро —
 *   Json::encode(ScoreCallPayload)) или в call_review; status только bool;
 *   мусор — пусто;
 * * провалы: один критерий в звонке — один раз, доля от оценённых звонков
 *   менеджера, порядок топа, лимит;
 * * строка топа: число не теряется рядом с «×».
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Stats\FailureCounter;
use Shef\ToolsAi\Stats\Period;
use Shef\ToolsAi\Stats\ScoreResult;

$now = new DateTimeImmutable('2026-10-03 15:20:00');

Check::group('период');

$period = Period::fromRequest(null, null, $now);
Check::same('по умолчанию — с начала месяца', $period->getFromValue(), '2026-10-01');
Check::same('по умолчанию — по сегодня', $period->getToValue(), '2026-10-03');
Check::same('граница SQL — начало следующего дня', $period->till->format('Y-m-d H:i:s'), '2026-10-04 00:00:00');
Check::same('начало — полночь', $period->from->format('H:i:s'), '00:00:00');

$period = Period::fromRequest('2026-09-01', '2026-09-30', $now);
Check::same('свой период', [$period->getFromValue(), $period->getToValue()], ['2026-09-01', '2026-09-30']);

$period = Period::fromRequest('2026-09-30', '2026-09-01', $now);
Check::same('«с» позже «по» — местами', [$period->getFromValue(), $period->getToValue()], ['2026-09-01', '2026-09-30']);

foreach(['2026-02-30', '2026-9-01', '01.09.2026', '2026-09-01 00:00', "2026-09-01\n", ' 2026-09-01', '', 'x', ['2026-09-01'], 20260901] as $bad)
{
	Check::same('мусор — null: '.json_encode($bad), Period::parseDate($bad), null);
}
Check::same('мусор в «с» — начало месяца', Period::fromRequest('2026-02-30', '2026-10-02', $now)->getFromValue(), '2026-10-01');
Check::same('мусор в «по» — сегодня', Period::fromRequest('2026-09-15', '<script>', $now)->getToValue(), '2026-10-03');

Check::group('RESULT оценки звонка');

$core = json_encode([
	'criteria' => [
		['criterion' => 'Поздоровался', 'status' => true, 'explanation' => '…'],
		['criterion' => 'Назвал цену', 'status' => false],
		['criterion' => 'Не оценён', 'status' => null],
		['criterion' => '', 'status' => false],
		['criterion' => 'Строка вместо bool', 'status' => 'false'],
		'мусор',
	],
	'overallSummary' => 'итог',
], JSON_UNESCAPED_UNICODE);
Check::same('как пишет ядро — criteria в корне', ScoreResult::parseCriteria($core), [
	['criterion' => 'Поздоровался', 'status' => true],
	['criterion' => 'Назвал цену', 'status' => false],
]);
Check::same(
	'сырой ответ модели — call_review.criteria',
	ScoreResult::parseCriteria('{"call_review":{"criteria":[{"criterion":"  Договорился\n о звонке ","status":false}]}}'),
	[['criterion' => 'Договорился о звонке', 'status' => false]]
);
foreach(['', '{', 'null', '"x"', '{"criteria":"x"}', '{"recommendations":"только советы"}'] as $bad)
{
	Check::same('мусор — пусто: '.$bad, ScoreResult::parseCriteria($bad), []);
}
Check::same('не строка — пусто', ScoreResult::parseCriteria(null), []);

Check::group('провалы');

$counter = new FailureCounter();
$crit = static fn(array $map): array => array_map(static fn(string $name, bool $status): array => ['criterion' => $name, 'status' => $status], array_keys($map), $map);

$counter->add(7, $crit(['Цена' => false, 'Следующий шаг' => false, 'Привет' => true]));
$counter->add(7, $crit(['Цена' => false, 'Следующий шаг' => true]));
$counter->add(7, [['criterion' => 'Цена', 'status' => false], ['criterion' => 'Цена', 'status' => false]]);
$counter->add(7, $crit(['Привет' => true]));
$counter->add(9, $crit(['Следующий шаг' => false]));
$counter->add(9, []);

Check::same('оценённых звонков — пустой не считается', $counter->getAssessed(), 5);
Check::same('менеджеры', $counter->getUserIds(), [7, 9]);
Check::same('у 7 оценено', $counter->getAssessedByUser(7), 4);
Check::same('топ по всем', $counter->getTop(10), [
	['criterion' => 'Цена', 'count' => 3, 'percent' => 60],
	['criterion' => 'Следующий шаг', 'count' => 2, 'percent' => 40],
]);
Check::same('топ менеджера 7 — повтор в звонке один раз, доля от его звонков', $counter->getTopByUser(7, 5), [
	['criterion' => 'Цена', 'count' => 3, 'percent' => 75],
	['criterion' => 'Следующий шаг', 'count' => 1, 'percent' => 25],
]);
Check::same('лимит', count($counter->getTop(1)), 1);
Check::same('нет такого менеджера — пусто', $counter->getTopByUser(1, 5), []);

$tie = new FailureCounter();
$tie->add(1, $crit(['Б' => false, 'А' => false]));
Check::same('равные — по названию', array_column($tie->getTop(10), 'criterion'), ['А', 'Б']);

Check::group('строка топа');

Check::same('число рядом с × не пропадает', FailureCounter::formatRow(['criterion' => 'Цена', 'count' => 3, 'percent' => 60]), 'Цена — 3× (60%)');

Check::finish();

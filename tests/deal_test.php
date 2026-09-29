<?php declare(strict_types=1);

/**
 * Анализ сделки: фильтр до модели, разбор вердикта, эскалация.
 *
 * Что держит:
 *
 * * дешёвый фильтр: сделка «в работе» и сделка без фактов до модели не
 *   доходят — это главная экономия квоты;
 * * вердикт: риск подрезается в 0-100, needSenior — только настоящий true
 *   (строка 'false' при (bool) была бы true), длинный текст обрезается;
 * * эскалация — только при needSenior И риске не ниже порога;
 * * заглушка LLM отдаёт вердикт по схеме — на стенде эскалацию можно
 *   пройти целиком без денег;
 * * откаты по стадиям считаются по сортировке, а не по количеству смен.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Deal\ContextBuilder;
use Shef\ToolsAi\Deal\DealFacts;
use Shef\ToolsAi\Deal\Escalation;
use Shef\ToolsAi\Deal\FactsSourceInterface;
use Shef\ToolsAi\Deal\HealthAnalyzer;
use Shef\ToolsAi\Deal\Verdict;
use Shef\ToolsAi\Provider\Llm\EchoLlm;

$facts = static fn(int $idle, int $calls = 2, array $notes = [], int $rollbacks = 0): DealFacts => new DealFacts(
	dealId: 15,
	title: 'Поставка станков',
	stage: 'Переговоры',
	opportunity: 125000.5,
	currency: 'BYN',
	daysSinceCreated: 40,
	daysSinceLastActivity: $idle,
	stageRollbacks: $rollbacks,
	callsTotal: $calls,
	callsIncoming: 1,
	outgoingWithoutAnswer: 1,
	recentNotes: $notes,
);

Check::group('фильтр до модели');

Check::same('дела были вчера — в работе', $facts(1)->isWorthAnalyzing(3), false);
Check::same('ровно порог — уже анализируем', $facts(3)->isWorthAnalyzing(3), true);
Check::same('ни звонков, ни дел, ни откатов — не о чем рассуждать', $facts(30, 0)->isWorthAnalyzing(3), false);
Check::same('без звонков, но с откатом — анализируем', $facts(30, 0, [], 1)->isWorthAnalyzing(3), true);
Check::same('без звонков, но с записями — анализируем', $facts(30, 0, ['01.09.2026, Встреча: обсудили цену'])->isWorthAnalyzing(3), true);

Check::group('промпт — сводка, а не транскрипт');

$text = $facts(10, 2, ['01.09.2026, Звонок: просил скидку'])->toPromptText();
Check::same('сумма с разрядами', str_contains($text, 'Сумма: 125 000.50 BYN'), true);
Check::same('дни без активности', str_contains($text, 'Дней без активности: 10'), true);
Check::same('записи пронумерованы', str_contains($text, '1) 01.09.2026, Звонок: просил скидку'), true);

Check::group('вердикт');

$verdict = Verdict::fromArray(['risk' => 150, 'needSenior' => true, 'why' => str_repeat('я', 600), 'nextStep' => ' позвонить ']);
Check::same('риск подрезан до 100', $verdict->risk, 100);
Check::same('why обрезан до 500', mb_strlen($verdict->why), 500);
Check::same('nextStep без краевых пробелов', $verdict->nextStep, 'позвонить');
Check::same('риск ниже нуля — 0', Verdict::fromArray(['risk' => -5])->risk, 0);
Check::same('риск строкой-числом', Verdict::fromArray(['risk' => '75.6'])->risk, 76);
Check::same('риск мусором — 0', Verdict::fromArray(['risk' => 'высокий'])->risk, 0);
Check::same("needSenior 'false' — не true", Verdict::fromArray(['needSenior' => 'false'])->needSenior, false);
Check::same('needSenior 1 — не true', Verdict::fromArray(['needSenior' => 1])->needSenior, false);
Check::same('пустой ответ не роняет', Verdict::fromArray([])->risk, 0);

Check::group('когда звать старшего');

Check::same('риск 80, нужен, порог 70', Verdict::fromArray(['risk' => 80, 'needSenior' => true])->shouldEscalate(70), true);
Check::same('риск ровно порог', Verdict::fromArray(['risk' => 70, 'needSenior' => true])->shouldEscalate(70), true);
Check::same('риск 90, но «не нужен»', Verdict::fromArray(['risk' => 90, 'needSenior' => false])->shouldEscalate(70), false);
Check::same('нужен, но риск 50', Verdict::fromArray(['risk' => 50, 'needSenior' => true])->shouldEscalate(70), false);
Check::same('пропущенная сделка — никогда', Verdict::skipped('в работе')->shouldEscalate(0), false);

Check::group('анализатор');

$source = new class($facts) implements FactsSourceInterface
{
	public array $data = [];

	public function __construct(private readonly Closure $make) {}

	public function build(int $dealId): DealFacts
	{
		return ($this->make)(...$this->data[$dealId]);
	}
};
$source->data = [1 => [1], 2 => [20]];

$analyzer = new HealthAnalyzer($source, new EchoLlm());
$skipped = $analyzer->analyze(1, 3);
Check::same('сделка в работе — пропуск', $skipped->verdict->skipped, true);
Check::same('модель не звали', $skipped->llm, null);

$stale = $analyzer->analyze(2, 3);
Check::same('20 дней без дел — модель позвали', $stale->llm !== null, true);
Check::same('заглушка: риск по дням', $stale->verdict->risk, 95);
Check::same('заглушка: звать старшего', $stale->verdict->needSenior, true);

$schema = HealthAnalyzer::SCHEMA;
Check::same('схема ответа строгая', [$schema['additionalProperties'], $schema['required']], [false, ['risk', 'needSenior', 'why', 'nextStep']]);

Check::group('текст эскалации');

Check::same(
	'риск, почему, что сделать',
	Escalation::buildText(Verdict::fromArray(['risk' => 80, 'needSenior' => true, 'why' => 'молчит', 'nextStep' => 'позвонить'])),
	"ИИ-анализ сделки: риск потери 80%, нужен старший.\nПочему: молчит\nЧто сделать: позвонить"
);

Check::group('откаты по стадиям');

Check::same('вперёд — ноль', ContextBuilder::countBackward([10, 20, 30]), 0);
Check::same('назад один раз', ContextBuilder::countBackward([10, 30, 20, 40]), 1);
Check::same('туда-обратно дважды', ContextBuilder::countBackward([10, 20, 10, 20, 10]), 2);
Check::same('пустая история', ContextBuilder::countBackward([]), 0);

Check::finish();

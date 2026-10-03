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
 * * эскалация — только при needSenior И риске не ниже порога; шаги —
 *   по решению шкалы профиля (подробно — profile_test.php);
 * * промпт профиля: пустой — общий, свой — целиком плюс требование JSON;
 * * заглушка LLM отдаёт вердикт по схеме — на стенде эскалацию можно
 *   пройти целиком без денег;
 * * откаты по стадиям считаются по сортировке, а не по количеству смен.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Deal\CallInsights;
use Shef\ToolsAi\Deal\ContextBuilder;
use Shef\ToolsAi\Deal\DealFacts;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Deal\Escalation;
use Shef\ToolsAi\Deal\FactsSourceInterface;
use Shef\ToolsAi\Deal\HealthAnalyzer;
use Shef\ToolsAi\Deal\Profile;
use Shef\ToolsAi\Deal\RiskScale;
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
Check::same('строка о запланированных делах', str_contains($text, 'Запланированных дел (не завершены, срок не прошёл): 0'), true);
Check::same('строка о просроченных делах', str_contains($text, 'Просроченных дел (не завершены, срок прошёл): 0'), true);
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

$profile = Profile::fromRow(['ID' => 4, 'TITLE' => 'Опт', 'IS_ENABLED' => 'Y', 'IDLE_DAYS' => '3']);
$analyzer = new HealthAnalyzer($source, new EchoLlm());
$skipped = $analyzer->analyze(1, static fn(): Profile => $profile);
Check::same('сделка в работе — пропуск', $skipped->verdict->skipped, true);
Check::same('модель не звали', $skipped->llm, null);
Check::same('профиль в итоге', $skipped->profile?->id, 4);

$noProfile = $analyzer->analyze(2, static fn(): ?Profile => null);
Check::same('нет профиля — пропуск', [$noProfile->verdict->skipped, $noProfile->verdict->skipReason], [true, 'Нет подходящего профиля анализа']);
Check::same('нет профиля — модель не звали', $noProfile->llm, null);
Check::same('нет профиля — факты всё равно есть', $noProfile->facts?->dealId, 15);

$stale = $analyzer->analyze(2, static fn(): Profile => $profile);
Check::same('20 дней без дел — модель позвали', $stale->llm !== null, true);
Check::same('заглушка: риск по дням', $stale->verdict->risk, 95);
Check::same('заглушка: звать старшего', $stale->verdict->needSenior, true);

$long = $analyzer->analyze(2, static fn(): Profile => Profile::fromRow(['IS_ENABLED' => 'Y', 'IDLE_DAYS' => '30']));
Check::same('IDLE_DAYS профиля: 20 дней при пороге 30 — в работе', $long->llm, null);

Check::group('промпт профиля');

Check::same('пустой — общий', HealthAnalyzer::buildSystemPrompt('  '), HealthAnalyzer::getSystemPrompt());
Check::same('свой — целиком и с требованием JSON', HealthAnalyzer::buildSystemPrompt('Ты — РОП оптового отдела.'), "Ты — РОП оптового отдела.\n\n".HealthAnalyzer::JSON_RULE);

$llm = new class implements \Shef\ToolsAi\Provider\Llm\LlmProviderInterface
{
	public string $system = '';
	private EchoLlm $echo;

	public function __construct() { $this->echo = new EchoLlm(); }
	public function getCode(): string { return 'spy'; }
	public function complete(array $messages): \Shef\ToolsAi\Provider\Llm\LlmResult { return $this->echo->complete($messages); }

	public function completeJson(string $system, string $user, array $schema): \Shef\ToolsAi\Provider\Llm\LlmResult
	{
		$this->system = $system;

		return $this->echo->completeJson($system, $user, $schema);
	}
};
(new HealthAnalyzer($source, $llm))->analyze(2, static fn(): Profile => Profile::fromRow(['IS_ENABLED' => 'Y', 'PROMPT' => 'Свой промпт']));
Check::same('модель получила промпт профиля', str_starts_with($llm->system, 'Свой промпт'), true);

$schema = HealthAnalyzer::SCHEMA;
Check::same('схема ответа строгая', [$schema['additionalProperties'], $schema['required']], [false, ['risk', 'needSenior', 'why', 'nextStep']]);

Check::group('текст эскалации');

Check::same(
	'риск, почему, что сделать',
	Escalation::buildText(Verdict::fromArray(['risk' => 80, 'needSenior' => true, 'why' => 'молчит', 'nextStep' => 'позвонить'])),
	"ИИ-анализ сделки: риск потери 80%, нужен старший.\nПочему: молчит\nЧто сделать: позвонить"
);

Check::group('дело старшему: конструктор ToDo из crm 26.800');

// В crm 26.800 конструктор дела — (ItemIdentifier, ActivityProvider): с одним
// аргументом ArgumentCountError, и дело не ставилось (приёмка, bx-shef/toolsai#3).
eval(<<<'PHP'
namespace Bitrix\Crm
{
	class ItemIdentifier
	{
		public function __construct(public readonly int $entityTypeId, public readonly int $entityId) {}
	}
}

namespace Bitrix\Crm\Activity\Provider\ToDo
{
	class ToDo {}
}

namespace Bitrix\Crm\Activity\Entity
{
	class ToDo
	{
		public static array $saved = [];
		private array $fields = [];

		public function __construct(
			private readonly \Bitrix\Crm\ItemIdentifier $owner,
			private readonly \Bitrix\Crm\Activity\Provider\ToDo\ToDo $provider
		) {}

		public function setDescription(string $value): static { $this->fields['description'] = $value; return $this; }
		public function setResponsibleId(int $value): static { $this->fields['responsible'] = $value; return $this; }
		public function setDeadline(object $value): static { return $this; }

		public function save(): \Bitrix\Main\Result
		{
			static::$saved[] = [$this->owner->entityId, $this->fields['responsible']];

			return new \Bitrix\Main\Result();
		}
	}
}
PHP);
if(!class_exists('CCrmOwnerType'))
{
	eval('class CCrmOwnerType { public const Deal = 2; }');
}

$addTodo = new ReflectionMethod(Escalation::class, 'addTodo');
Check::same('дело поставлено', $addTodo->invoke(new Escalation(), 15, 7, 'текст'), true);
Check::same('на сделку и старшему', \Bitrix\Crm\Activity\Entity\ToDo::$saved, [[15, 7]]);

Check::group('эскалация: старший не задан — отчёт говорит об этом');

eval(<<<'PHP'
namespace Bitrix\Crm\Timeline
{
	class CommentEntry
	{
		public static function create(array $fields): int
		{
			return 5;
		}
	}
}
PHP);

$verdict = Verdict::fromArray(['risk' => 95, 'needSenior' => true, 'why' => 'молчит', 'nextStep' => 'позвонить']);
$withSenior = static fn(string $senior): Profile => Profile::fromRow(['IS_ENABLED' => 'Y', 'LOW_BORDER' => '50', 'HIGH_BORDER' => '70', 'SENIOR_ID' => $senior]);
$apply = static fn(Profile $profile, int $manager, int $open): array => (new Escalation())->apply(15, $verdict, $profile, RiskScale::decide($verdict, $profile, $manager, $open), $manager);
\Bitrix\Crm\Activity\Entity\ToDo::$saved = [];
Check::same('старшего нет — комментарий и причина, без дела', $apply($withSenior(''), 3, 1), ['comment', 'старший не задан — дела нет']);
Check::same('старший есть — комментарий и дело', $apply($withSenior('7'), 3, 1), ['comment', 'todo']);
Check::same('нет запланированных дел — ещё и дело менеджеру', $apply($withSenior('7'), 3, 0), ['manager_todo', 'comment', 'todo']);
Check::same('дела ушли менеджеру 3 и старшему 7', \Bitrix\Crm\Activity\Entity\ToDo::$saved, [[15, 7], [15, 3], [15, 7]]);

Check::group('текст дела менеджеру');

Check::same(
	'риск, что сделать, почему',
	Escalation::buildManagerText(Verdict::fromArray(['risk' => 60, 'why' => 'молчит', 'nextStep' => 'позвонить'])),
	"ИИ-анализ сделки: нет запланированных дел, риск 60%.\nЧто сделать: позвонить\nПочему: молчит"
);

Check::group('откаты по стадиям');

Check::same('вперёд — ноль', ContextBuilder::countBackward([10, 20, 30]), 0);
Check::same('назад один раз', ContextBuilder::countBackward([10, 30, 20, 40]), 1);
Check::same('туда-обратно дважды', ContextBuilder::countBackward([10, 20, 10, 20, 10]), 2);
Check::same('пустая история', ContextBuilder::countBackward([]), 0);

Check::group('запланированные и просроченные дела (1.4.0)');

$now = 1_800_000_000;
Check::same(
	'по COMPLETED и DEADLINE',
	[
		DealFacts::activityState(true, $now - 86400, $now),
		DealFacts::activityState(false, null, $now),
		DealFacts::activityState(false, $now + 3600, $now),
		DealFacts::activityState(false, $now, $now),
		DealFacts::activityState(false, $now - 1, $now),
		DealFacts::activityState(false, mktime(23, 59, 59, 12, 31, 9999), $now),
	],
	['done', 'planned', 'planned', 'planned', 'overdue', 'planned']
);

$overdueFacts = new DealFacts(
	dealId: 15, title: 'x', stage: 'y', opportunity: 0.0, currency: 'BYN',
	daysSinceCreated: 700, daysSinceLastActivity: 600, stageRollbacks: 0,
	callsTotal: 1, callsIncoming: 0, outgoingWithoutAnswer: 1, recentNotes: [],
	assignedById: 3, openActivities: 0, overdueActivities: 2, oldestOverdueAt: mktime(12, 0, 0, 3, 5, 2025),
	callNotes: ['01.09.2026, входящий: резюме: просил скидку'],
);
$text = $overdueFacts->toPromptText();
Check::same('в сводке обе строки', [str_contains($text, 'Запланированных дел (не завершены, срок не прошёл): 0'), str_contains($text, 'Просроченных дел (не завершены, срок прошёл): 2, самое старое — с 05.03.2025')], [true, true]);
Check::same('в сводке звонки', str_contains($text, "Последние звонки — резюме и оценка Копилота (от свежего к старому):\n1) 01.09.2026, входящий: резюме: просил скидку"), true);

Check::group('живые сделки');

Check::same('600 дней без активности, срок 60 — мёртвая', $overdueFacts->isAlive(60), false);
Check::same('срок 0 — без ограничения', $overdueFacts->isAlive(0), true);
Check::same('ровно срок — живая', $overdueFacts->isAlive(600), true);
$llm = new EchoLlm();
$analysis = (new HealthAnalyzer(new class($overdueFacts) implements \Shef\ToolsAi\Deal\FactsSourceInterface {
	public function __construct(private readonly DealFacts $f) {}
	public function build(int $dealId): DealFacts { return $this->f; }
}, $llm))->analyze(15, static fn(): Profile => Profile::fromRow(['IS_ENABLED' => 'Y', 'IDLE_DAYS' => 3]));
Check::same('мёртвая — пропуск без модели', [$analysis->verdict->skipped, $analysis->llm, str_contains($analysis->verdict->skipReason, '600')], [true, null, true]);

Check::group('дело старшему о просрочке');

Check::same(
	'текст',
	Escalation::buildOverdueText('Иванов Пётр', 2, mktime(12, 0, 0, 3, 5, 2025), Verdict::fromArray(['risk' => 95, 'why' => 'молчит'])),
	"У менеджера Иванов Пётр просрочены дела по сделке: 2 шт., самое старое с 05.03.2025. Проконтролировать.\nИИ-анализ: риск потери 95%.\nПочему: молчит"
);
Check::same('без даты — без хвоста', str_starts_with(Escalation::buildOverdueText('', 1, null, Verdict::fromArray([])), 'У менеджера — просрочены дела по сделке: 1 шт. Проконтролировать.'), true);
\Bitrix\Crm\Activity\Entity\ToDo::$saved = [];
$dead = Verdict::fromArray(['risk' => 100, 'needSenior' => false, 'why' => 'мертва']);
$profile7 = Profile::fromRow(['IS_ENABLED' => 'Y', 'LOW_BORDER' => '50', 'HIGH_BORDER' => '70', 'SENIOR_ID' => '7']);
Check::same(
	'менеджеру и старшему',
	(new Escalation())->apply(15, $dead, $profile7, RiskScale::decide($dead, $profile7, 3, 0, false, false, 2), 3, $overdueFacts),
	['manager_todo', 'overdue_todo']
);
Check::same('дела ушли 3 и 7', \Bitrix\Crm\Activity\Entity\ToDo::$saved, [[15, 3], [15, 7]]);

Check::group('содержание звонков');

Check::same('резюме', CallInsights::parseSummary('{"summary":"  Клиент   просил скидку  "}'), 'Клиент просил скидку');
Check::same('резюме: мусор — пусто', [CallInsights::parseSummary('не json'), CallInsights::parseSummary(null), CallInsights::parseSummary('{"summary":5}')], ['', '', '']);
Check::same('резюме обрезается', mb_strlen(CallInsights::parseSummary(json_encode(['summary' => str_repeat('а', 1000)]))), CallInsights::SUMMARY_LENGTH);
Check::same('не клиент с причиной', CallInsights::parseNotClient('{"isClient":false,"reasonIfIsClientFalse":"спам","actions":[]}'), 'спам');
Check::same('клиент или нет признака — null', [CallInsights::parseNotClient('{"isClient":true}'), CallInsights::parseNotClient('{}')], [null, null]);
Check::same('невыполненные пункты', CallInsights::parseFailed('{"criteria":[{"criterion":"Приветствие","status":true},{"criterion":"Выявил потребность","status":false},{"criterion":"Не оценён","status":null}]}'), ['Выявил потребность']);
Check::same(
	'строка звонка',
	CallInsights::formatCall(mktime(12, 0, 0, 9, 1, 2026), 'входящий', 'просил скидку', 62, ['Выявил потребность', 'Назначил шаг'], null),
	'01.09.2026, входящий: резюме: просил скидку. оценка по скрипту: 62%, не выполнено: Выявил потребность; Назначил шаг'
);
Check::same('не клиент — первым', CallInsights::formatCall(mktime(12, 0, 0, 9, 1, 2026), 'исходящий', '', null, [], 'ошиблись номером'), '01.09.2026, исходящий: не клиент (ошиблись номером)');
Check::same('нечего сказать — пусто', CallInsights::formatCall(1, 'входящий', '', null, [], null), '');
Check::same('пунктов не больше пяти', substr_count(CallInsights::formatCall(1, 'в', '', 10, array_fill(0, 9, 'Ж'), null), 'Ж'), 5);

Check::finish();

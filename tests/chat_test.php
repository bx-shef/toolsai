<?php declare(strict_types=1);

/**
 * Чаты открытых линий (1.6.0, bx-shef/toolsai#34): чистая логика и запрос к
 * модели без сети.
 *
 * Что держит:
 *
 * * текст переписки: роль автора (клиент — коннектор, бот, автоответ по
 *   классу сообщения — даже от имени оператора, остальные — менеджер), BB-коды
 *   и HTML сняты, неизвестные квадратные скобки — нет, имя клиента не
 *   уходит, файлы и вложения отмечены, пустое — прочь; нечего оценивать без
 *   реплик менеджера или клиента (автоответ — не менеджер); длинный чат —
 *   начало и конец, середина пропущена с отметкой;
 * * оценка: критерии из «сути» скрипта, процент как у CRM (null не в счёт,
 *   ни одного оценённого — нет процента), невыполненные, текст
 *   комментария, запись критериев читается Stats\ScoreResult и считается
 *   FailureCounter;
 * * запрос к модели: json_object и потолок ответа, промпт про переписку,
 *   транскрипт — сообщением пользователя, ответ к форме CRM, ни одного
 *   критерия — provider_bad_response с расходом; заглушка — без сети;
 * * свои промпты Копилота: переписку из чата CRM узнаём по подписям реплик,
 *   звонок — нейтральная формулировка;
 * * сделка важнее лида; таблица оценок создаётся и дописывается
 *   идемпотентно; настройки вкладки «Чаты» разбираются строго.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Shef\ToolsAi\Chat\ChatScore;
use Shef\ToolsAi\Chat\ChatScorer;
use Shef\ToolsAi\Chat\DialogSource;
use Shef\ToolsAi\Chat\Model\ChatAssessmentTable;
use Shef\ToolsAi\Chat\Transcript;
use Shef\ToolsAi\Completion\CopilotPrompt;
use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Provider\Llm\EchoLlm;
use Shef\ToolsAi\Provider\OpenAi\ChatProvider;
use Shef\ToolsAi\Provider\OpenAi\Client;
use Shef\ToolsAi\Provider\OpenAi\Llm;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Stats\FailureCounter;
use Shef\ToolsAi\Stats\ScoreResult;

$config = static fn(array $options): Config => new Config(static fn(string $module, string $name): mixed => $options[$name] ?? '');
$options = [
	'API_baseurl' => 'http://mock:8000/v1/',
	'API_apikey' => 'sk-secret-key',
	'API_llmmodel' => 'gpt-test',
	'API_llmpricein' => '0.15',
	'API_llmpriceout' => '0.6',
];
$answer = static fn(string $content): Response => new Response(200, (string)json_encode([
	'choices' => [['message' => ['content' => $content]]],
	'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100],
]));

Check::group('роль автора');

Check::same('пользователь коннектора — клиент', Transcript::role('imconnector'), Transcript::ROLE_CLIENT);
Check::same('чат-бот — бот', Transcript::role('bot'), Transcript::ROLE_BOT);
Check::same('сотрудник — менеджер', Transcript::role(''), Transcript::ROLE_EMPLOYEE);
Check::same(
	'автоответ при закрытии подписан оператором, но по классу — автоответ',
	Transcript::role('', 'bx-messenger-content-item-ol-output bx-messenger-content-item-vote'),
	Transcript::ROLE_AUTO
);
Check::same('«диалог закрыт» (ol-end) — тоже автоответ', Transcript::role('', 'bx-messenger-content-item-ol-end'), Transcript::ROLE_AUTO);
Check::same('скрытое сообщение оператора (тихий режим) — скрытое', Transcript::role('', 'bx-messenger-content-item-system'), Transcript::ROLE_HIDDEN);
Check::same('другой класс сообщения — не автоответ', Transcript::role('', 'bx-messenger-content-item-vote'), Transcript::ROLE_EMPLOYEE);

Check::group('текст сообщения');

Check::same(
	'BB-коды мессенджера сняты, текст внутри остался',
	Transcript::cleanText('[USER=15]Иван Петров[/USER], [b]цена[/b] на [URL=https://shop.by/x]сапун[/URL][br]ок'),
	'Иван Петров, цена на сапун ок'
);
Check::same('неизвестные скобки — не разметка', Transcript::cleanText('Заказ [№ 15] готов'), 'Заказ [№ 15] готов');
Check::same('HTML-сущности раскрыты, теги и лишние пробелы прочь', Transcript::cleanText("&quot;Да&quot;  <i>конечно</i>\n\n  &amp; сразу"), '"Да" конечно & сразу');

Check::group('переписка для модели');

$messages = [
	['role' => Transcript::ROLE_BOT, 'author' => 'Бот линии', 'time' => '03.10 12:00', 'text' => 'Здравствуйте! Оператор скоро ответит.'],
	['role' => Transcript::ROLE_CLIENT, 'author' => 'Анна Клиентова', 'time' => '03.10 12:01', 'text' => 'Нужен сапун на [b]Husqvarna 135[/b]'],
	['role' => Transcript::ROLE_EMPLOYEE, 'author' => 'Иван Петров', 'time' => '03.10 12:02', 'text' => 'Добрый день, есть в наличии', 'files' => 2],
	['role' => Transcript::ROLE_CLIENT, 'author' => 'Анна Клиентова', 'time' => '', 'text' => '', 'files' => 1],
	['role' => Transcript::ROLE_CLIENT, 'author' => 'Анна Клиентова', 'time' => '03.10 12:05', 'text' => '   '],
	['role' => Transcript::ROLE_EMPLOYEE, 'author' => 'Иван Петров', 'time' => '03.10 12:06', 'text' => 'Карточка товара', 'attach' => true],
	['role' => Transcript::ROLE_HIDDEN, 'author' => 'Иван Петров', 'time' => '03.10 12:07', 'text' => 'клиент мутный, цену не давать'],
	['role' => Transcript::ROLE_AUTO, 'author' => 'Иван Петров', 'time' => '03.10 13:00', 'text' => 'Диалог закрыт. Оцените работу оператора.'],
];
$transcript = Transcript::build($messages);
Check::same('строки: время, роль, имя сотрудника; имени клиента нет; файлы отмечены; пустое и скрытое — прочь', $transcript->text, implode("\n", [
	'[03.10 12:00] Бот (Бот линии): Здравствуйте! Оператор скоро ответит.',
	'[03.10 12:01] Клиент: Нужен сапун на Husqvarna 135',
	'[03.10 12:02] Менеджер (Иван Петров): Добрый день, есть в наличии [файлов: 2]',
	'Клиент: [файл]',
	'[03.10 12:06] Менеджер (Иван Петров): Карточка товара [вложение]',
	'[03.10 13:00] Автоответ: Диалог закрыт. Оцените работу оператора.',
]));
Check::same('счёт реплик: клиент, менеджер, бот и автоответ', [$transcript->clientCount, $transcript->employeeCount, $transcript->botCount], [2, 2, 2]);
Check::same('есть что оценивать', $transcript->getSkipReason(), null);
Check::same('имя клиента в текст не попало', str_contains($transcript->text, 'Анна'), false);

$onlyAuto = Transcript::build([
	['role' => Transcript::ROLE_CLIENT, 'text' => 'Есть кто?'],
	['role' => Transcript::ROLE_AUTO, 'author' => 'Иван Петров', 'text' => 'Диалог закрыт'],
	['role' => Transcript::ROLE_BOT, 'text' => 'Нерабочее время'],
]);
Check::same('ответили только автоответ и бот — менеджера нет', $onlyAuto->getSkipReason(), 'нет реплик менеджера');
Check::same('клиент молчал', Transcript::build([['role' => Transcript::ROLE_EMPLOYEE, 'text' => 'Добрый день']])->getSkipReason(), 'нет реплик клиента');
Check::same('неизвестная роль — менеджер, а не потеря реплики', Transcript::build([['role' => 'x', 'text' => 'т']])->employeeCount, 1);

$long = [];
for($i = 1; $i <= 300; $i++)
{
	$long[] = ['role' => $i % 2 ? Transcript::ROLE_CLIENT : Transcript::ROLE_EMPLOYEE, 'text' => 'реплика '.$i.' '.str_repeat('ж', 40)];
}
$cut = Transcript::build($long, 2000);
$lines = explode("\n", $cut->text);
Check::same('длинный чат: начало и конец на месте', [str_contains($lines[0], 'реплика 1 '), str_contains(end($lines), 'реплика 300 ')], [true, true]);
Check::same('длинный чат: середина пропущена с отметкой', [$cut->skipped > 0, str_contains($cut->text, '… пропущено реплик: '.$cut->skipped.' …')], [true, true]);
Check::same('длинный чат: в потолке', mb_strlen($cut->text) <= 2000 + 50, true);
Check::same('потолок не меньше 1000: «0» не обнуляет текст', Transcript::build([['role' => Transcript::ROLE_CLIENT, 'text' => 'коротко']], 0)->text, 'Клиент: коротко');
$huge = Transcript::build([['role' => Transcript::ROLE_CLIENT, 'text' => str_repeat('ы', 5000)], ['role' => Transcript::ROLE_EMPLOYEE, 'text' => 'ok']], 1000);
Check::same('одна огромная реплика — её начало, а не пусто', [str_starts_with($huge->text, 'Клиент: ыы'), mb_strlen($huge->text) < 1200], [true, true]);

Check::group('оценка: критерии, процент, комментарий');

Check::same('критерии из сути скрипта: перевод строки любого вида, пустые и повторы — прочь', ChatScore::criteriaFromGist("Поздоровался\r\n\r\n  Выяснил   потребность \nПоздоровался\rНазвал цену"), ['Поздоровался', 'Выяснил потребность', 'Назвал цену']);
Check::same('суть не строка — пусто', ChatScore::criteriaFromGist(null), []);

$criteria = [
	['criterion' => 'Поздоровался', 'status' => true, 'explanation' => ''],
	['criterion' => 'Назвал цену', 'status' => false, 'explanation' => ''],
	['criterion' => 'Интонация', 'status' => null, 'explanation' => ''],
	['criterion' => 'Предложил доставку', 'status' => true, 'explanation' => ''],
];
Check::same('процент: выполненные от оценённых, null не в счёт', ChatScore::percent($criteria), 67);
Check::same('ни одного оценённого — процента нет, а не деление на ноль', ChatScore::percent([['criterion' => 'а', 'status' => null]]), null);
Check::same('всё провалено — 0, а не null', ChatScore::percent([['criterion' => 'а', 'status' => false]]), 0);
Check::same('невыполненные — по порядку, без повторов', ChatScore::failed([...$criteria, ['criterion' => 'Назвал цену', 'status' => false]]), ['Назвал цену']);

$scoring = ['call_review' => ['criteria' => $criteria], 'overall_summary' => 'Ответил быстро.', 'recommendations' => 'Называть цену сразу.'];
Check::same('комментарий в таймлайн', ChatScore::formatComment('Продажа запчастей', $scoring), implode("\n", [
	'Оценка переписки по скрипту «Продажа запчастей»: 67%.',
	'Не выполнено: Назвал цену.',
	'Итог: Ответил быстро.',
	'Что сделать иначе: Называть цену сразу.',
]));
Check::same(
	'всё выполнено и без итога',
	ChatScore::formatComment('', ['call_review' => ['criteria' => [['criterion' => 'а', 'status' => true, 'explanation' => '']]], 'overall_summary' => '', 'recommendations' => '']),
	"Оценка переписки по скрипту без названия: 100%.\nВсе оценённые пункты выполнены."
);
Check::same(
	'не оценена — так и сказано, без списка',
	ChatScore::formatComment('С', ['call_review' => ['criteria' => [['criterion' => 'а', 'status' => null, 'explanation' => '']]], 'overall_summary' => '', 'recommendations' => '']),
	'Оценка переписки по скрипту «С»: не оценена — ни один пункт нельзя проверить по переписке.'
);
Check::same('длинный комментарий обрезан', mb_strlen(ChatScore::formatComment('С', ['call_review' => ['criteria' => $criteria], 'overall_summary' => str_repeat('я', 5000), 'recommendations' => ''])), ChatScore::MAX_COMMENT_LENGTH);

$stored = ChatScore::toStored($scoring);
$counter = new FailureCounter();
$counter->add(7, ScoreResult::parseCriteria($stored));
$counter->add(7, ScoreResult::parseCriteria(ChatScore::toStored(['call_review' => ['criteria' => [['criterion' => 'Назвал цену', 'status' => true, 'explanation' => '']]]])));
Check::same('запись критериев читает страница статистики: только bool', ScoreResult::parseCriteria($stored), [
	['criterion' => 'Поздоровался', 'status' => true],
	['criterion' => 'Назвал цену', 'status' => false],
	['criterion' => 'Предложил доставку', 'status' => true],
]);
Check::same('«что не делают в чатах» — тот же FailureCounter', $counter->getTopByUser(7, 5), [['criterion' => 'Назвал цену', 'count' => 1, 'percent' => 50]]);

Check::group('запрос к модели');

$transport = new FakeTransport();
$transport->responses = [$answer('{"criteria": [{"criterion": "Поздоровался", "status": true, "explanation": "«Добрый день»"}, {"criterion": "Назвал цену", "status": "нет", "explanation": "не назвал"}, {"criterion": "", "status": true}], "overall_summary": "Норм", "recommendations": "Цена"}')];
$scorer = new ChatScorer(new Llm($config($options), new Client($config($options)->getTextEndpoint(), $transport)));
$result = $scorer->score($transcript->text, ['Поздоровался', 'Назвал цену'], ['manager_name' => 'Иван Петров', 'language' => 'Русский']);
$sent = json_decode($transport->sent[0]['body'], true);
Check::same(
	'json_object, потолок ответа, промпт про переписку и автоответы, имя менеджера в справке, переписка — сообщением пользователя',
	[
		$sent['response_format'] ?? null,
		$sent['max_tokens'] ?? null,
		str_contains($sent['messages'][0]['content'], 'переписку менеджера с клиентом в чате'),
		str_contains($sent['messages'][0]['content'], 'автоответы'),
		str_contains($sent['messages'][0]['content'], 'менеджеру не засчитывай'),
		str_contains($sent['messages'][0]['content'], '"Иван Петров"'),
		str_contains($sent['messages'][0]['content'], '["Поздоровался","Назвал цену"]'),
		$sent['messages'][1],
	],
	[['type' => 'json_object'], ChatScorer::MAX_TOKENS, true, true, true, true, true, ['role' => 'user', 'content' => $transcript->text]]
);
Check::same('ответ — к форме CRM: статус не bool — null, без названия — прочь', $result['scoring']['call_review']['criteria'], [
	['criterion' => 'Поздоровался', 'status' => true, 'explanation' => '«Добрый день»'],
	['criterion' => 'Назвал цену', 'status' => null, 'explanation' => 'не назвал'],
]);
Check::same('расход — токены и деньги ответа', [$result['result']->getTokens(), $result['result']->costMicro > 0], [1100, true]);
Check::same('оценка цены до запроса — не ноль', $scorer->estimateCostMicro($transcript->text, ['Поздоровался']) > 0, true);

$transport = new FakeTransport();
$transport->responses = [$answer('{"overall_summary": "Не понял"}')];
$scorer = new ChatScorer(new Llm($config($options), new Client($config($options)->getTextEndpoint(), $transport)));
$error = null;
try
{
	$scorer->score('Клиент: а', ['Поздоровался']);
}
catch(ProviderException $exception)
{
	$error = $exception;
}
Check::same('ни одного критерия — provider_bad_response с оплаченным расходом', [$error?->errorCode, $error?->spentUnits, ($error?->spentMicro ?? 0) > 0], ['provider_bad_response', 1100, true]);

$echo = new ChatScorer(new EchoLlm());
$stub = $echo->score('Клиент: а', ['Поздоровался', 'Назвал цену']);
Check::same('заглушка: без сети и денег, все пункты — с пометкой заглушки', [
	ChatScore::percent($stub['scoring']['call_review']['criteria']),
	$stub['scoring']['call_review']['criteria'][1]['explanation'],
	$stub['result']->costMicro,
	$echo->estimateCostMicro('Клиент: а', ['а']),
], [100, ChatScore::STUB_EXPLANATION, 0, 0]);

Check::group('свои промпты Копилота: чат или звонок');

$coreChat = 'Анна [2026-10-03T12:01:00+03:00]: Нужен сапун Иван Петров [2026-10-03T12:02:00+03:00]: Есть в наличии Иван Петров [2026-10-03T13:00:00+03:00]: Диалог закрыт';
Check::same('переписка CRM (getMessagesForCopilot) — чат', CopilotPrompt::isChat($coreChat), true);
Check::same('расшифровка звонка — не чат', CopilotPrompt::isChat('Менеджер: Добрый день. Клиент: Нужен сапун [вчера].'), false);
Check::same('одна подпись с датой — ещё не чат', CopilotPrompt::isChat('Клиент сказал [2026-10-03T12:01:00+03:00]: перезвоните'), false);
Check::same('не строка — не чат', CopilotPrompt::isChat(['x']), false);

$own = $options + ['API_ownprompts' => 'Y'];
$systemFor = static function(string $code, array $markers) use ($config, $own, $answer): string
{
	$transport = new FakeTransport();
	$transport->responses = [$answer($code === 'summarize_transcript' ? 'Резюме' : '{"is_client": true, "actions": []}')];
	(new ChatProvider($config($own), new Llm($config($own), new Client($config($own)->getTextEndpoint(), $transport))))->run(Request::fromArray(makeCoreRequest([
		'category' => 'text',
		'payload_provider' => 'prompt',
		'payload_raw' => $code,
		'payload_markers' => $markers + ['language' => 'Русский'],
	])));

	return (string)(json_decode($transport->sent[0]['body'], true)['messages'][0]['content'] ?? '');
};

$system = $systemFor('summarize_transcript', ['original_message' => $coreChat, 'manager_name' => 'Иван Петров']);
Check::same('резюме чата: про переписку, кто есть кто, автоответ — не слова менеджера', [
	str_contains($system, 'переписку менеджера с клиентом в чате'),
	str_contains($system, 'Как читать переписку'),
	str_contains($system, 'даже если подписаны его именем'),
	str_contains($system, 'телефон'),
], [true, true, true, false]);

$system = $systemFor('summarize_transcript', ['original_message' => 'Менеджер: Добрый день. Клиент: Нужно 20 стульев.']);
Check::same('резюме звонка: нейтрально, без правил переписки и без «телефонного»', [
	str_contains($system, 'расшифровку звонка или переписку в чате'),
	str_contains($system, 'Как читать переписку'),
	str_contains($system, 'телефон'),
], [true, false, false]);

$system = $systemFor('client_dialogue_action_extraction', ['dialogue' => $coreChat, 'employee_name' => 'Иван Петров']);
Check::same('дела по чату: про переписку, по автоответам дел не ставить', [str_contains($system, 'в чате (открытая линия)'), str_contains($system, 'дел по ним не ставь')], [true, true]);

$system = $systemFor('extract_form_fields', ['original_message' => '- клиент хочет сапун', 'fields' => ['Сумма' => 'double or null']]);
Check::same('поля: резюме звонка или переписки, без «телефонного»', [str_contains($system, 'звонка или переписки в чате'), str_contains($system, 'телефон')], [true, false]);

$call = implode("\n", array_column(CopilotPrompt::scoringMessages(['transcript' => 'Менеджер: да', 'criteria' => "А\nБ"], false), 'content'));
Check::same('оценка звонка: без правил переписки', [str_contains($call, 'Как читать переписку'), str_contains($call, 'расшифровку звонка или переписку')], [false, true]);

Check::group('сделка или лид');

Check::same('сделка важнее лида', DialogSource::pickOwner([
	['OWNER_TYPE_ID' => '1', 'OWNER_ID' => '900'],
	['OWNER_TYPE_ID' => '2', 'OWNER_ID' => '15'],
	['OWNER_TYPE_ID' => '3', 'OWNER_ID' => '77'],
]), [DialogSource::OWNER_DEAL, 15]);
Check::same('из двух сделок — новее', DialogSource::pickOwner([['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 15], ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 16]]), [DialogSource::OWNER_DEAL, 16]);
Check::same('только лид', DialogSource::pickOwner([['OWNER_TYPE_ID' => 1, 'OWNER_ID' => 5]]), [DialogSource::OWNER_LEAD, 5]);
Check::same('только контакт — некуда писать', DialogSource::pickOwner([['OWNER_TYPE_ID' => 3, 'OWNER_ID' => 5], ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => 0]]), null);

Check::group('таблица оценок');

$connection = Application::getConnection();
$connection->tables = [];
$connection->queries = [];
ChatAssessmentTable::init();
Check::same('создаётся с уникальным делом и сроком', [
	count($connection->queries),
	str_contains($connection->queries[0] ?? '', 'CREATE TABLE shef_toolsai_chat_assessment'),
	str_contains($connection->queries[0] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_CHAT_ACT (ACTIVITY_ID)'),
	str_contains($connection->queries[0] ?? '', 'CRITERIA MEDIUMTEXT NULL'),
	str_contains($connection->queries[0] ?? '', 'CREATED_AT DATETIME NULL, PRIMARY KEY'),
], [1, true, true, true, true]);

$connection->tables = ['shef_toolsai_chat_assessment'];
$connection->fields = ['shef_toolsai_chat_assessment' => ['ID', 'ACTIVITY_ID', 'SESSION_ID', 'CHAT_ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'ASSESSMENT_ID', 'STATUS', 'SCORE', 'CRITERIA', 'SUMMARY', 'RESPONSIBLE_ID', 'CREATED_AT']];
$connection->queries = [];
ChatAssessmentTable::init();
Check::same('таблица есть без столбца — дописан только он', $connection->queries, ['ALTER TABLE shef_toolsai_chat_assessment ADD COLUMN REASON VARCHAR(500) NULL']);
$connection->fields = [];
$connection->queries = [];
ChatAssessmentTable::init();
Check::same('всё на месте — ничего', $connection->queries, []);
$connection->tables = [];

Check::group('настройки вкладки «Чаты»');

$chat = $config([]);
Check::same('по умолчанию: выключено, 20 за прогон, 3 дня, скрипт подбирается', [$chat->isChatAssessmentEnabled(), $chat->getChatMaxPerRun(), $chat->getChatDays(), $chat->getChatScriptId()], [false, 20, 3, 0]);
$chat = $config(['CHAT_enabled' => 'Y', 'CHAT_maxperrun' => '500', 'CHAT_days' => '0', 'CHAT_script' => '12']);
Check::same('вне границ (за прогон до 200, дней от 1) — умолчание, как у интервала агента; ID скрипта', [$chat->isChatAssessmentEnabled(), $chat->getChatMaxPerRun(), $chat->getChatDays(), $chat->getChatScriptId()], [true, 20, 3, 12]);
$chat = $config(['CHAT_maxperrun' => '200', 'CHAT_days' => '60']);
Check::same('на границе — как задано', [$chat->getChatMaxPerRun(), $chat->getChatDays()], [200, 60]);
$chat = $config(['CHAT_maxperrun' => 'двадцать', 'CHAT_script' => '12abc']);
Check::same('мусор — умолчание, а не ноль и не «12»', [$chat->getChatMaxPerRun(), $chat->getChatScriptId()], [20, 0]);

Check::finish();

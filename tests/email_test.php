<?php declare(strict_types=1);

/**
 * Письма в CRM (1.7.0, bx-shef/toolsai#34, раздел «Письма»): чистая логика и
 * запросы к модели без сети.
 *
 * Что держит:
 *
 * * тело письма -> текст: HTML без разметки, стилей и сущностей; цитата
 *   прошлой переписки режется (blockquote, в том числе вложенный и
 *   незакрытый, блоки цитат Gmail/Outlook, «-----Original Message-----»,
 *   «On … wrote:», «… пишет:», шапка «От кого:/Кому:» как у Битрикса, адрес
 *   с двоеточием как у Яндекса, строки «>»), но письмо, которое начинается с
 *   заголовка цитаты, не обнуляется; подпись («-- », «Отправлено с iPhone»,
 *   «С уважением» после текста); потолок длины; текст и BB-код;
 * * служебные письма без модели: робот почты, no-reply, автоответ,
 *   недоставка; обычное письмо — нет;
 * * ответ модели на входящее: is_client строго (строка «true» — да, мусор —
 *   null), не клиент — без дел, дел не больше 3, без названия — из
 *   описания, срок — годный в будущем или +24 ч; критерии оценки — свои по
 *   строкам или встроенные;
 * * скорость ответа: первый ответ не раньше письма, решение по группе
 *   сделки (пороги, отметки гасят повтор, срок от самого старого), кто
 *   ждёт ответа в сводке сделки (входящий звонок — не ответ), длительность;
 * * тексты в CRM: резюме, оценка (процент как у звонка), дела менеджеру и
 *   старшему, строка сводки; критерии оценки письма читает статистика;
 * * запросы: json_object и потолок, письмо — сообщением пользователя,
 *   предыдущее письмо клиента в оценке; негодный ответ — provider_bad_response
 *   с расходом; заглушка — без сети;
 * * таблица писем идемпотентна; настройки вкладки «Письма»; сводка сделки.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Application;
use Shef\ToolsAi\Config;
use Shef\ToolsAi\Deal\DealFacts;
use Shef\ToolsAi\Email\EmailAnalyzer;
use Shef\ToolsAi\Email\EmailComment;
use Shef\ToolsAi\Email\EmailPrompt;
use Shef\ToolsAi\Email\EmailText;
use Shef\ToolsAi\Email\Model\EmailTable;
use Shef\ToolsAi\Email\ReplyClock;
use Shef\ToolsAi\Http\Response;
use Shef\ToolsAi\Provider\Llm\EchoLlm;
use Shef\ToolsAi\Provider\OpenAi\Client;
use Shef\ToolsAi\Provider\OpenAi\Llm;
use Shef\ToolsAi\Provider\ProviderException;
use Shef\ToolsAi\Stats\FailureCounter;
use Shef\ToolsAi\Stats\ScoreResult;

date_default_timezone_set('Europe/Minsk');

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

Check::group('HTML -> текст');

Check::same(
	'теги, стили, комментарии и сущности сняты; абзацы и <br> — строки',
	EmailText::prepare('<html><head><style>p{color:red}</style><title>t</title></head><body><!-- x --><p>Добрый&nbsp;день!</p><div>Нужно <b>20</b> стульев &amp; 2&nbsp;стола.<br>Срок &laquo;до пятницы&raquo;</div></body></html>', EmailText::TYPE_HTML),
	"Добрый день!\nНужно 20 стульев & 2 стола.\nСрок «до пятницы»"
);
Check::same(
	'таблица — ячейки через пробел',
	EmailText::prepare('<table><tr><td>Стул</td><td>20 шт</td></tr><tr><td>Стол</td><td>2 шт</td></tr></table>', EmailText::TYPE_HTML),
	"Стул 20 шт\nСтол 2 шт"
);

Check::group('цитата прошлой переписки');

Check::same(
	'blockquote (так цитирует Битрикс) — прочь вместе с шапкой «От кого / Кому»',
	EmailText::prepare('<p>Да, пришлите счёт.</p></br></br>От кого: Иван &lt;ivan@shop.by&gt;</br>Кому: anna@client.by</br>Дата: 03.10.2026</br>Тема: КП</br><blockquote style="margin:0">Высылаем КП на стулья</blockquote>', EmailText::TYPE_HTML),
	'Да, пришлите счёт.'
);
Check::same(
	'вложенный blockquote — тоже',
	EmailText::prepare('Ответ<blockquote>старое<blockquote>совсем старое</blockquote>ещё</blockquote>после', EmailText::TYPE_HTML),
	"Ответ\nпосле"
);
Check::same('незакрытый blockquote — всё после него цитата', EmailText::prepare('Ответ<blockquote>старое без конца', EmailText::TYPE_HTML), 'Ответ');
Check::same(
	'блок цитаты Gmail — всё после него прочь',
	EmailText::prepare('<div dir="ltr">Подойдёт, берём.</div><br><div class="gmail_quote"><div class="gmail_attr">пн, 3 окт. 2026 г. в 12:00, Иван:</div>Цена 100</div>', EmailText::TYPE_HTML),
	'Подойдёт, берём.'
);
Check::same(
	'Outlook: divRplyFwdMsg',
	EmailText::prepare('<p>Спасибо</p><hr><div id="divRplyFwdMsg"><b>From:</b> Ivan<br><b>Sent:</b> Monday</div><p>old</p>', EmailText::TYPE_HTML),
	'Спасибо'
);
Check::same(
	'«-----Original Message-----» в тексте',
	EmailText::prepare("Хорошо, ждём.\n\n-----Original Message-----\nFrom: ivan@shop.by\nЦена 100", EmailText::TYPE_PLAIN),
	'Хорошо, ждём.'
);
Check::same(
	'«On … wrote:»',
	EmailText::prepare("Ok, thanks\n\nOn Mon, 3 Oct 2026 at 12:00, Ivan <ivan@shop.by> wrote:\n> price 100", EmailText::TYPE_PLAIN),
	'Ok, thanks'
);
Check::same(
	'«… пишет:» с датой',
	EmailText::prepare("Беру два.\n\n3 окт. 2026 г., в 12:00, Иван Петров <ivan@shop.by> пишет:\nЕсть в наличии", EmailText::TYPE_PLAIN),
	'Беру два.'
);
Check::same(
	'Яндекс: «03.10.2026, 12:00, "Иван" <ivan@shop.by>:»',
	EmailText::prepare("Согласен.\n\n03.10.2026, 12:00, \"Иван\" <ivan@shop.by>:\nЦена 100", EmailText::TYPE_PLAIN),
	'Согласен.'
);
Check::same(
	'«От: / Отправлено:» — шапка Outlook на русском',
	EmailText::prepare("Принято\nОт: Иван Петров\nОтправлено: 3 октября 2026 г. 12:00\nКому: Анна\nТема: КП", EmailText::TYPE_PLAIN),
	'Принято'
);
Check::same('строки «>» — прочь, остальное на месте', EmailText::prepare("> старое\nНовое\n>> ещё старее\nи ещё", EmailText::TYPE_PLAIN), "Новое\nи ещё");
Check::same('«От:» без шапки следом — обычная строка', EmailText::prepare("Привет\nОт: склада ответа нет, жду\nСпасибо", EmailText::TYPE_PLAIN), "Привет\nОт: склада ответа нет, жду\nСпасибо");
Check::same('«пишет:» без даты и адреса — не заголовок', EmailText::cutQuote("Коллега пишет:\nнужно 5 штук"), "Коллега пишет:\nнужно 5 штук");
Check::same(
	'письмо целиком из цитаты (заголовок в первой строке) — не обнуляется',
	EmailText::prepare("-----Original Message-----\nЦена 100", EmailText::TYPE_PLAIN),
	"-----Original Message-----\nЦена 100"
);

Check::group('подпись и длина');

Check::same('«-- » — дальше подпись', EmailText::prepare("Пришлите счёт\n-- \nИван, +375 29 000", EmailText::TYPE_PLAIN), 'Пришлите счёт');
Check::same('«Отправлено с iPhone»', EmailText::prepare("Ок\n\nОтправлено с iPhone", EmailText::TYPE_PLAIN), 'Ок');
Check::same('«С уважением» после текста — дальше подпись', EmailText::prepare("Жду КП до пятницы.\nС уважением,\nАнна, ООО Ромашка", EmailText::TYPE_PLAIN), 'Жду КП до пятницы.');
Check::same('«С уважением» первой строкой — не режем в пустоту', EmailText::cutSignature("С уважением к вашему времени, коротко: нужен счёт"), 'С уважением к вашему времени, коротко: нужен счёт');
$long = EmailText::prepare(str_repeat('слово ', 3000), EmailText::TYPE_PLAIN, 1000);
Check::same('потолок длины: обрезано с «…»', [mb_strlen($long), str_ends_with($long, '…')], [1000, true]);
Check::same('потолок не меньше 100', mb_strlen(EmailText::limit(str_repeat('я', 500), 0)), 100);
Check::same('BB-код: теги прочь, цитата прочь', EmailText::prepare('[b]Нужно[/b] 5 шт[br][quote]старое[/quote]Спасибо', EmailText::TYPE_BBCODE), "Нужно 5 шт\n\nСпасибо");
Check::same('текст: переводы строк и пробелы нормализованы', EmailText::prepare("  Раз  \r\n\r\n\r\n\r\nДва\u{A0}три ", EmailText::TYPE_PLAIN), "Раз\n\nДва три");

Check::group('служебные письма без модели');

Check::same('MAILER-DAEMON', EmailText::serviceReason('Mail Delivery System <MAILER-DAEMON@mx.shop.by>', 'Undelivered Mail'), 'служебный адрес отправителя');
Check::same('no-reply', EmailText::serviceReason('noreply@service.by', 'Ваш заказ'), 'служебный адрес отправителя');
Check::same('автоответ по теме', EmailText::serviceReason('anna@client.by', 'Автоответ: Re: КП'), 'автоответ или уведомление почты');
Check::same('Out of Office', EmailText::serviceReason('Anna <anna@client.by>', 'Out of Office: КП'), 'автоответ или уведомление почты');
Check::same('«в отпуске» в теме', EmailText::serviceReason('anna@client.by', 'В отпуске до 10.10'), 'автоответ или уведомление почты');
Check::same('обычное письмо клиента — не служебное', EmailText::serviceReason('Анна <anna@client.by>', 'Re: КП на стулья'), null);
Check::same('reply в имени ящика — не no-reply', EmailText::serviceReason('replymanager@client.by', 'Заказ'), null);

Check::group('ответ модели на входящее');

$now = mktime(12, 0, 0, 10, 3, 2026);
$normalized = EmailPrompt::normalizeIncoming([
	'is_client' => 'true',
	'is_actionable' => true,
	'kind' => 'spam',
	'summary' => ' Просит КП ',
	'wants' => 'КП',
	'terms' => 'до пятницы',
	'todos' => [
		['title' => 'Выслать КП', 'description' => '20 стульев', 'deadline' => '2026-10-09T12:00:00'],
		['title' => '', 'description' => 'Уточнить срок поставки у склада', 'deadline' => '2026-10-01T12:00:00'],
		['title' => '', 'description' => ''],
		'мусор',
		['title' => 'Третье', 'deadline' => 'завтра'],
		['title' => 'Четвёртое лишнее'],
	],
], $now);
Check::same('строка «true» — клиент, kind у клиента — client', [$normalized['is_client'], $normalized['kind'], $normalized['summary']], [true, 'client', 'Просит КП']);
Check::same('дел не больше 3, пустые и мусор — прочь, название из описания', array_column($normalized['todos'], 'title'), ['Выслать КП', 'Уточнить срок поставки у склада', 'Третье']);
Check::same(
	'срок: годный — как есть; в прошлом и «завтра» — +24 ч',
	array_column($normalized['todos'], 'deadline'),
	[mktime(12, 0, 0, 10, 9, 2026), $now + 86400, $now + 86400]
);

$notClient = EmailPrompt::normalizeIncoming(['is_client' => false, 'kind' => 'autoreply', 'summary' => 'Автоответ: в отпуске', 'wants' => 'x', 'todos' => [['title' => 'Ответить']]], $now);
Check::same('не клиент — без дел, без «хочет», kind сохранён', [$notClient['is_client'], $notClient['is_actionable'], $notClient['todos'], $notClient['wants'], $notClient['kind']], [false, false, [], '', 'autoreply']);
Check::same('не клиент, kind мусор — other', EmailPrompt::normalizeIncoming(['is_client' => false, 'kind' => 'x'], $now)['kind'], 'other');
Check::same('«для сведения» (is_actionable false) — без дел', EmailPrompt::normalizeIncoming(['is_client' => true, 'is_actionable' => false, 'todos' => [['title' => 'a']]], $now)['todos'], []);
Check::same('is_client нет или мусор — null (ответ негодный)', [EmailPrompt::normalizeIncoming([], $now)['is_client'], EmailPrompt::normalizeIncoming(['is_client' => 'да'], $now)['is_client']], [null, null]);
Check::same('срок с поясом — тоже годный', EmailPrompt::deadlineTimestamp('2026-10-05T10:00:00+03:00', $now), mktime(10, 0, 0, 10, 5, 2026));

Check::group('критерии оценки исходящего');

Check::same('пусто — встроенные', EmailPrompt::parseCriteria(''), EmailPrompt::DEFAULT_CRITERIA);
Check::same('не строка — встроенные', EmailPrompt::parseCriteria(null), EmailPrompt::DEFAULT_CRITERIA);
Check::same('строка — критерий; нумерация и маркеры сняты; пустые и повторы прочь', EmailPrompt::parseCriteria("1. Поздоровался\r\n\r\n- Назвал  цену\n• Назвал цену\n2) Предложил доставку"), ['Поздоровался', 'Назвал цену', 'Предложил доставку']);
$many = implode("\n", array_map(static fn(int $i): string => 'Критерий '.$i, range(1, 30)));
Check::same('не больше 20', count(EmailPrompt::parseCriteria($many)), EmailPrompt::MAX_CRITERIA);

Check::group('скорость ответа');

Check::same('первый ответ не раньше письма', ReplyClock::firstReplyAfter(100, [50, 300, 200, 100]), 100);
Check::same('ответов после письма нет', ReplyClock::firstReplyAfter(100, [50, 99]), null);

$at = $now - 5 * 3600;
Check::same('ждёт 5 ч, порог 4 — дело менеджеру, старшему ещё нет', ReplyClock::decide([['at' => $at, 'managerNotified' => false, 'seniorNotified' => false]], $now, 4, 24), ['oldestAt' => $at, 'hours' => 5, 'manager' => true, 'senior' => false]);
Check::same('ровно порог — уже пора', ReplyClock::decide([['at' => $now - 4 * 3600, 'managerNotified' => false, 'seniorNotified' => false]], $now, 4, 24)['manager'], true);
Check::same('3 ч 59 мин — рано', ReplyClock::decide([['at' => $now - 4 * 3600 + 60, 'managerNotified' => false, 'seniorNotified' => false]], $now, 4, 24)['manager'], false);
Check::same(
	'группа сделки: срок от самого старого, отметка у любого письма гасит повтор',
	ReplyClock::decide([
		['at' => $now - 3600, 'managerNotified' => false, 'seniorNotified' => false],
		['at' => $now - 30 * 3600, 'managerNotified' => true, 'seniorNotified' => false],
	], $now, 4, 24),
	['oldestAt' => $now - 30 * 3600, 'hours' => 30, 'manager' => false, 'senior' => true]
);
Check::same('старшему уже ставили — не повторяем', ReplyClock::decide([['at' => $now - 50 * 3600, 'managerNotified' => true, 'seniorNotified' => true]], $now, 4, 24), ['oldestAt' => $now - 50 * 3600, 'hours' => 50, 'manager' => false, 'senior' => false]);
Check::same('ждущих нет — null', ReplyClock::decide([], $now, 4, 24), null);

$in = ReplyClock::DIRECTION_INCOMING;
$out = ReplyClock::DIRECTION_OUTGOING;
$email = ReplyClock::TYPE_EMAIL;
$callType = ReplyClock::TYPE_CALL;
Check::same('ответили после письма — не ждёт', ReplyClock::waitingSince([['type' => $email, 'direction' => $in, 'at' => 100], ['type' => $email, 'direction' => $out, 'at' => 200]]), null);
Check::same(
	'после последнего ответа два письма — ждёт с первого из них',
	ReplyClock::waitingSince([
		['type' => $email, 'direction' => $in, 'at' => 100],
		['type' => $callType, 'direction' => $out, 'at' => 200],
		['type' => $email, 'direction' => $in, 'at' => 400],
		['type' => $email, 'direction' => $in, 'at' => 300],
	]),
	300
);
Check::same('входящий звонок — не ответ', ReplyClock::waitingSince([['type' => $email, 'direction' => $in, 'at' => 100], ['type' => $callType, 'direction' => $in, 'at' => 200]]), 100);
Check::same('исходящий звонок — ответ', ReplyClock::isReply($callType, $out), true);
Check::same('дело «Сделать» (тип 6) — не ответ', ReplyClock::isReply(6, $out), false);
Check::same('писем клиента нет — не ждёт', ReplyClock::waitingSince([['type' => $callType, 'direction' => $in, 'at' => 100]]), null);
Check::same('часы — полные', ReplyClock::hours($now - 3 * 3600 - 3599, $now), 3);
Check::same('длительность', [ReplyClock::formatDuration(45 * 60), ReplyClock::formatDuration(2 * 3600 + 5 * 60), ReplyClock::formatDuration(76 * 3600), ReplyClock::formatDuration(-5)], ['45 мин', '2 ч 05 мин', '3 дн 4 ч', '0 мин']);

Check::group('тексты в CRM');

Check::same('резюме входящего в ленту', EmailComment::incoming('Re: КП', mktime(12, 30, 0, 10, 3, 2026), $normalized, ['Выслать КП', 'Уточнить срок']), implode("\n", [
	'ИИ: письмо клиента от 03.10.2026 12:30 «Re: КП».',
	'Что пишет: Просит КП',
	'Чего хочет: КП',
	'Сроки: до пятницы',
	'Дела менеджеру: «Выслать КП»; «Уточнить срок».',
]));
Check::same('без темы, без хотелок и сроков, без дел', EmailComment::incoming('', mktime(9, 0, 0, 10, 3, 2026), ['summary' => 'Спасибо, получили', 'wants' => '', 'terms' => '']), "ИИ: письмо клиента от 03.10.2026 09:00.\nЧто пишет: Спасибо, получили");

$scoring = EmailPrompt::normalizeReview([
	'criteria' => [
		['criterion' => 'Ответил на вопрос', 'status' => true, 'explanation' => 'да'],
		['criterion' => 'Назвал цену', 'status' => false, 'explanation' => 'нет цены'],
		['criterion' => 'Вежливо', 'status' => null, 'explanation' => ''],
	],
	'overall_summary' => 'Ответил, но без цены.',
	'recommendations' => 'Называть цену.',
]);
Check::same('оценка письма в ленту: процент как у звонка, null не в счёт', EmailComment::review('КП', $scoring), implode("\n", [
	'ИИ-оценка письма менеджера «КП»: 50%.',
	'Не выполнено: Назвал цену.',
	'Итог: Ответил, но без цены.',
	'Что сделать иначе: Называть цену.',
]));
Check::same('ни одного оценённого — «не оценено»', EmailComment::review('', EmailPrompt::normalizeReview(['criteria' => [['criterion' => 'а', 'status' => null]]])), 'ИИ-оценка письма менеджера: не оценено — ни один пункт нельзя проверить по письму.');
Check::same('длинный комментарий обрезан', mb_strlen(EmailComment::incoming('т', $now, ['summary' => str_repeat('я', 5000), 'wants' => '', 'terms' => ''])), EmailComment::MAX_LENGTH);

$stored = EmailComment::toStoredReview($scoring);
$counter = new FailureCounter();
$counter->add(7, ScoreResult::parseCriteria($stored));
Check::same('критерии письма читает статистика (только bool)', ScoreResult::parseCriteria($stored), [['criterion' => 'Ответил на вопрос', 'status' => true], ['criterion' => 'Назвал цену', 'status' => false]]);
Check::same('«что не делают в письмах» — FailureCounter', $counter->getTopByUser(7, 5), [['criterion' => 'Назвал цену', 'count' => 1, 'percent' => 100]]);

$mailAt = mktime(9, 15, 0, 10, 3, 2026);
Check::same('дело менеджеру', EmailComment::managerTodo('КП', $mailAt, $mailAt + 4 * 3600 + 20 * 60), [
	'subject' => 'Ответить клиенту на письмо от 03.10.2026 09:15',
	'description' => "Клиент ждёт ответа 4 ч 20 мин на письмо «КП». Ответьте письмом или позвоните.\nДело поставил ИИ-контроль скорости ответа: исходящего письма или звонка по сделке после письма клиента нет.",
]);
Check::same('дело старшему', EmailComment::seniorTodo('Иван Петров', 'КП', $mailAt, $mailAt + 26 * 3600, 'сделка «Стулья»'), [
	'subject' => 'Клиент без ответа 26 ч 00 мин: письмо от 03.10.2026 09:15',
	'description' => 'Менеджер Иван Петров не ответил на письмо клиента от 03.10.2026 09:15 «КП» (сделка «Стулья»). Проконтролировать ответ.',
]);
Check::same('строка сводки сделки', EmailComment::dealNote($mailAt, "Просит\n  КП"), '03.10.2026, письмо клиента: Просит КП');

Check::group('запрос на входящее');

$transport = new FakeTransport();
$transport->responses = [$answer('{"is_client": true, "is_actionable": true, "kind": "client", "summary": "Просит КП", "wants": "КП", "terms": "", "todos": [{"title": "Выслать КП", "description": "", "deadline": null}]}')];
$analyzer = new EmailAnalyzer(new Llm($config($options), new Client($config($options)->getTextEndpoint(), $transport)));
$messages = EmailPrompt::incomingMessages('Re: КП "срочно"', 'Пришлите КП на 20 стульев', ['manager_name' => 'Иван Петров', 'mail_date' => '03.10.2026 12:30', 'now' => '2026-10-03T12:00:00']);
$result = $analyzer->analyzeIncoming($messages, $now);
$sent = json_decode($transport->sent[0]['body'], true);
Check::same(
	'json_object, потолок, is_client и дела в инструкции, тема и имя — строкой JSON в справке, письмо — сообщением пользователя',
	[
		$sent['response_format'] ?? null,
		$sent['max_tokens'] ?? null,
		str_contains($sent['messages'][0]['content'], '"is_client"'),
		str_contains($sent['messages'][0]['content'], 'не больше 3 дел'),
		str_contains($sent['messages'][0]['content'], 'Тема письма: "Re: КП \"срочно\""'),
		str_contains($sent['messages'][0]['content'], 'Сейчас: "2026-10-03T12:00:00"'),
		$sent['messages'][1],
	],
	[['type' => 'json_object'], EmailAnalyzer::MAX_TOKENS_INCOMING, true, true, true, true, ['role' => 'user', 'content' => 'Пришлите КП на 20 стульев']]
);
Check::same('ответ разобран, срок null — +24 ч', [$result['answer']['is_client'], $result['answer']['todos'][0]['title'], $result['answer']['todos'][0]['deadline']], [true, 'Выслать КП', $now + 86400]);
Check::same('расход — токены и деньги ответа', [$result['result']->getTokens(), $result['result']->costMicro > 0], [1100, true]);
Check::same('оценка цены до запроса — не ноль', $analyzer->estimateCostMicro($messages) > 0, true);

$transport = new FakeTransport();
$transport->responses = [$answer('{"summary": "Не понял"}')];
$analyzer = new EmailAnalyzer(new Llm($config($options), new Client($config($options)->getTextEndpoint(), $transport)));
$error = null;
try
{
	$analyzer->analyzeIncoming($messages, $now);
}
catch(ProviderException $exception)
{
	$error = $exception;
}
Check::same('без is_client — provider_bad_response с оплаченным расходом', [$error?->errorCode, $error?->spentUnits, ($error?->spentMicro ?? 0) > 0], ['provider_bad_response', 1100, true]);

Check::group('запрос на оценку исходящего');

$transport = new FakeTransport();
$transport->responses = [$answer('{"criteria": [{"criterion": "Назвал цену", "status": false, "explanation": "нет"}], "overall_summary": "Без цены", "recommendations": "Цена"}')];
$analyzer = new EmailAnalyzer(new Llm($config($options), new Client($config($options)->getTextEndpoint(), $transport)));
$messages = EmailPrompt::reviewMessages('КП', 'Добрый день, высылаю КП во вложении.', 'Сколько стоит 20 стульев?', ['Назвал цену', 'Вежливо'], ['manager_name' => 'Иван Петров']);
$review = $analyzer->review($messages, ['Назвал цену', 'Вежливо']);
$sent = json_decode($transport->sent[0]['body'], true);
Check::same(
	'потолок, критерии строкой JSON и их число, письмо менеджера и предыдущее письмо клиента — сообщением пользователя',
	[
		$sent['max_tokens'] ?? null,
		str_contains($sent['messages'][0]['content'], '["Назвал цену","Вежливо"]'),
		str_contains($sent['messages'][0]['content'], 'ровно 2 элементов'),
		$sent['messages'][1]['content'],
	],
	[EmailAnalyzer::MAX_TOKENS_REVIEW, true, true, "ПИСЬМО МЕНЕДЖЕРА:\nДобрый день, высылаю КП во вложении.\n\nПРЕДЫДУЩЕЕ ПИСЬМО КЛИЕНТА:\nСколько стоит 20 стульев?"]
);
Check::same('оценка — к форме звонка', $review['scoring']['call_review']['criteria'], [['criterion' => 'Назвал цену', 'status' => false, 'explanation' => 'нет']]);
Check::same(
	'предыдущего письма нет — так и сказано',
	str_contains(EmailPrompt::reviewMessages('', 'т', '', ['а'])[1]['content'], '(нет — менеджер пишет первым)'),
	true
);

$transport = new FakeTransport();
$transport->responses = [$answer('{"overall_summary": "?"}')];
$analyzer = new EmailAnalyzer(new Llm($config($options), new Client($config($options)->getTextEndpoint(), $transport)));
$error = null;
try
{
	$analyzer->review($messages, ['Назвал цену']);
}
catch(ProviderException $exception)
{
	$error = $exception;
}
Check::same('ни одного критерия — provider_bad_response с расходом', [$error?->errorCode, $error?->spentUnits], ['provider_bad_response', 1100]);

$echo = new EmailAnalyzer(new EchoLlm());
$stubIn = $echo->analyzeIncoming($messages, $now);
$stubReview = $echo->review($messages, ['А', 'Б']);
Check::same('заглушка: без сети и денег, клиент без дел, все критерии — с пометкой', [
	$stubIn['answer']['is_client'],
	$stubIn['answer']['todos'],
	$stubIn['answer']['summary'],
	count($stubReview['scoring']['call_review']['criteria']),
	$stubReview['scoring']['call_review']['criteria'][1]['explanation'],
	$stubIn['result']->costMicro + $stubReview['result']->costMicro,
	$echo->estimateCostMicro($messages),
], [true, [], EmailPrompt::STUB_TEXT, 2, EmailPrompt::STUB_TEXT, 0, 0]);

Check::group('таблица писем');

$connection = Application::getConnection();
$connection->tables = [];
$connection->queries = [];
EmailTable::init();
Check::same('создаётся с уникальным делом, сделкой и отметками', [
	count($connection->queries),
	str_contains($connection->queries[0] ?? '', 'CREATE TABLE shef_toolsai_email'),
	str_contains($connection->queries[0] ?? '', 'UNIQUE KEY UX_SHEF_TOOLSAI_EMAIL_ACT (ACTIVITY_ID)'),
	str_contains($connection->queries[0] ?? '', 'KEY IX_SHEF_TOOLSAI_EMAIL_OWNER (OWNER_TYPE_ID, OWNER_ID)'),
	str_contains($connection->queries[0] ?? '', 'REPLY_TODO_AT DATETIME NULL'),
	str_contains($connection->queries[0] ?? '', 'SENIOR_TODO_AT DATETIME NULL'),
], [1, true, true, true, true, true]);

$connection->tables = ['shef_toolsai_email'];
$connection->fields = ['shef_toolsai_email' => ['ID', 'ACTIVITY_ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'DIRECTION', 'RESPONSIBLE_ID', 'MAIL_AT', 'MODE', 'STATUS', 'IS_CLIENT', 'SUMMARY', 'RESULT', 'SCORE', 'TODO_COUNT', 'REASON', 'ANALYZED_AT', 'REPLIED_AT', 'REPLY_SECONDS', 'REPLY_TODO_AT', 'CREATED_AT']];
$connection->queries = [];
EmailTable::init();
Check::same('нет столбца — дописан только он', $connection->queries, ['ALTER TABLE shef_toolsai_email ADD COLUMN SENIOR_TODO_AT DATETIME NULL']);
$connection->fields = [];
$connection->queries = [];
EmailTable::init();
Check::same('всё на месте — ничего', $connection->queries, []);
$connection->tables = [];

Check::group('настройки вкладки «Письма»');

$mail = $config([]);
Check::same(
	'по умолчанию: всё выключено, 20 за прогон, 3 дня, 4 и 24 ч, старшего нет, критерии встроенные',
	[$mail->isEmailEnabled(), $mail->isEmailSummaryEnabled(), $mail->getEmailMaxPerRun(), $mail->getEmailDays(), $mail->getEmailReplyHours(), $mail->getEmailEscalateHours(), $mail->getEmailSeniorId(), $mail->getEmailCriteria()],
	[false, false, 20, 3, 4, 24, 0, EmailPrompt::DEFAULT_CRITERIA]
);
$mail = $config(['EMAIL_reply' => 'Y', 'EMAIL_maxperrun' => '500', 'EMAIL_days' => '0', 'EMAIL_replyhours' => '0', 'EMAIL_escalatehours' => '1000', 'EMAIL_senior' => '12', 'EMAIL_criteria' => "Свой критерий\n"]);
Check::same(
	'одна функция — агенту есть что делать; вне границ — умолчание; свои критерии',
	[$mail->isEmailEnabled(), $mail->isEmailReplyControlEnabled(), $mail->isEmailReviewEnabled(), $mail->getEmailMaxPerRun(), $mail->getEmailDays(), $mail->getEmailReplyHours(), $mail->getEmailEscalateHours(), $mail->getEmailSeniorId(), $mail->getEmailCriteria()],
	[true, true, false, 20, 3, 4, 24, 12, ['Свой критерий']]
);
$mail = $config(['EMAIL_summary' => 'Y', 'EMAIL_todos' => 'Y', 'EMAIL_review' => 'Y', 'EMAIL_maxperrun' => '200', 'EMAIL_days' => '60', 'EMAIL_replyhours' => '168', 'EMAIL_escalatehours' => '720']);
Check::same('на границе — как задано', [$mail->isEmailSummaryEnabled(), $mail->isEmailTodosEnabled(), $mail->isEmailReviewEnabled(), $mail->getEmailMaxPerRun(), $mail->getEmailDays(), $mail->getEmailReplyHours(), $mail->getEmailEscalateHours()], [true, true, true, 200, 60, 168, 720]);
Check::same('мусор — умолчание, а не ноль', [$config(['EMAIL_replyhours' => 'четыре'])->getEmailReplyHours(), $config(['EMAIL_senior' => '12abc'])->getEmailSeniorId()], [4, 0]);

Check::group('сводка анализа сделки');

$facts = new DealFacts(
	dealId: 15, title: 'Стулья', stage: 'КП', opportunity: 0.0, currency: 'BYN',
	daysSinceCreated: 10, daysSinceLastActivity: 5, stageRollbacks: 0,
	callsTotal: 0, callsIncoming: 0, outgoingWithoutAnswer: 0, recentNotes: [],
	emailNotes: ['03.10.2026, письмо клиента: Просит КП', '01.10.2026, письмо клиента: Спрашивает цену'],
	emailWaitingHours: 6,
);
$text = $facts->toPromptText();
Check::same('в сводке: ждёт ответа и резюме писем', [
	str_contains($text, 'Клиент ждёт ответа на письмо: 6 ч'),
	str_contains($text, "Последние письма клиента — резюме ИИ (от свежего к старому):\n1) 03.10.2026, письмо клиента: Просит КП\n2) 01.10.2026"),
], [true, true]);
Check::same('только письма — есть о чём рассуждать', $facts->isWorthAnalyzing(3), true);
$quiet = new DealFacts(dealId: 1, title: 'x', stage: 'y', opportunity: 0.0, currency: 'BYN', daysSinceCreated: 10, daysSinceLastActivity: 5, stageRollbacks: 0, callsTotal: 0, callsIncoming: 0, outgoingWithoutAnswer: 0, recentNotes: []);
Check::same('без писем — строк о письмах нет', [str_contains($quiet->toPromptText(), 'письм'), $quiet->isWorthAnalyzing(3)], [false, false]);

Check::finish();

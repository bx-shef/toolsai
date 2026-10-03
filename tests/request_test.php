<?php declare(strict_types=1);

/**
 * Запрос ядра: терпимый разбор и то, что из него достаётся.
 *
 * Что держит:
 *
 * * отсутствующий или неожиданного типа ключ не роняет разбор и не даёт
 *   warning — ядро добавляет поля между версиями;
 * * хэш задания (ключ идемпотентности) — только из query колбэка и только
 *   безопасного вида;
 * * сообщения для chat completions: роль -> контекст -> промпт, без повтора
 *   и без пустых сообщений; ничего нет — сырые данные, а не пустота;
 * * запрос переживает переход в фон: toArray/fromArray без потерь.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/fakes.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Completion\Request;

Check::group('терпимость к формату');

$empty = Request::fromArray([]);
Check::same('пустой массив — категория пустая', $empty->category, '');
Check::same('пустой массив — невалиден', $empty->isValid(), false);
Check::same('ttl по умолчанию', $empty->ttl, 300);

$odd = Request::fromArray([
	'category' => ['audio'],
	'context' => 'строка',
	'payload_markers' => 5,
	'callbackUrl' => 42,
	'ttl' => 'abc',
]);
Check::same('категория массивом — пусто', $odd->category, '');
Check::same('контекст строкой — пусто', $odd->context, []);
Check::same('маркеры числом — пусто', $odd->markers, []);
Check::same('колбэк числом — пусто', $odd->callbackUrl, '');
Check::same('ttl мусором — умолчание', $odd->ttl, 300);
Check::same('лишний ключ ядра не мешает', Request::fromArray(makeCoreRequest(['new_key_2027' => [1]]))->isValid(), true);

Check::group('хэш задания');

$withHash = static fn(string $url): ?string => Request::fromArray(makeCoreRequest(['callbackUrl' => $url]))->getJobHash();

Check::same('обычный', $withHash('https://a.by/x?action=y&hash=abc123'), 'abc123');
Check::same('нет query', $withHash('https://a.by/x'), null);
Check::same('нет hash', $withHash('https://a.by/x?action=y'), null);
Check::same('пустой hash', $withHash('https://a.by/x?hash='), null);
Check::same('hash массивом', $withHash('https://a.by/x?hash[]=1'), null);
Check::same('hash с кавычкой', $withHash("https://a.by/x?hash=a'b"), null);

Check::group('аудио');

$audio = Request::fromArray(makeCoreRequest());
Check::same('адрес записи', $audio->getAudioUrl(), 'https://crm.example.by/bitrix/tools/crm_show_file.php?fileId=965723');
Check::same('расширение', $audio->getAudioExtension(), 'mp3');
Check::same('MIME', $audio->getAudioMimeType(), 'audio/mpeg');
Check::same('язык', $audio->getLanguage(), 'ru');

$bad = Request::fromArray(makeCoreRequest([
	'prompt' => ['file' => '', 'fileExtension' => '../x'],
	'payload_markers' => ['language' => 'russian', 'type' => '<script>'],
]));
Check::same('пустой адрес — null', $bad->getAudioUrl(), null);
Check::same('кривое расширение — mp3', $bad->getAudioExtension(), 'mp3');
Check::same('кривой MIME — null', $bad->getAudioMimeType(), null);
Check::same('кривой язык — ru', $bad->getLanguage(), 'ru');

Check::group('сообщения для chat completions');

$text = Request::fromArray(makeCoreRequest([
	'category' => 'text',
	'payload_provider' => 'prompt',
	'prompt' => null,
	'payload_role' => 'Ты — ассистент менеджера.',
	'payload_prompt_text' => 'Сделай резюме звонка.',
	'context' => [
		['role' => 'user', 'content' => 'Транскрипт: ...'],
		['role' => 'tool', 'content' => 'чужая роль'],
		['role' => 'assistant', 'content' => ''],
		'мусор',
	],
]));
Check::same('роль -> контекст -> промпт', $text->getChatMessages(), [
	['role' => 'system', 'content' => 'Ты — ассистент менеджера.'],
	['role' => 'user', 'content' => 'Транскрипт: ...'],
	['role' => 'user', 'content' => 'Сделай резюме звонка.'],
]);

$repeat = Request::fromArray(makeCoreRequest([
	'category' => 'text',
	'payload_prompt_text' => 'Один и тот же',
	'context' => [['role' => 'user', 'content' => 'Один и тот же']],
]));
Check::same('промпт не повторяется за контекстом', count($repeat->getChatMessages()), 1);

$raw = Request::fromArray(makeCoreRequest([
	'category' => 'text',
	'prompt' => null,
	'payload_raw' => ['text' => 'сырьё'],
]));
Check::same('ничего нет — сырые данные строкой', $raw->getChatMessages(), [
	['role' => 'user', 'content' => '{"text":"сырьё"}'],
]);

$promptString = Request::fromArray(makeCoreRequest(['category' => 'text', 'prompt' => 'строковый промпт']));
Check::same('prompt строкой — как текст промпта', $promptString->getChatMessages(), [
	['role' => 'user', 'content' => 'строковый промпт'],
]);

$rendered = Request::fromArray(makeCoreRequest([
	'category' => 'text',
	'payload_provider' => 'prompt',
	'prompt' => 'Сделай резюме звонка на языке ru. Транскрипт: Алло, добрый день',
	'payload_prompt_text' => 'Сделай резюме звонка на языке {language}. @switch(x) @case(1) Транскрипт: {original_message}',
]));
Check::same('готовый prompt важнее сырого шаблона payload_prompt_text', $rendered->getChatMessages(), [
	['role' => 'user', 'content' => 'Сделай резюме звонка на языке ru. Транскрипт: Алло, добрый день'],
]);

$emptyPrompt = Request::fromArray(makeCoreRequest([
	'category' => 'text',
	'prompt' => '   ',
	'payload_prompt_text' => 'шаблон',
]));
Check::same('пустой prompt — тогда шаблон', $emptyPrompt->getChatMessages(), [
	['role' => 'user', 'content' => 'шаблон'],
]);

Check::group('переход в фон');

Check::same('toArray/fromArray без потерь', Request::fromArray($text->toArray())->toArray(), $text->toArray());

Check::finish();

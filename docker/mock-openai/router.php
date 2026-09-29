<?php declare(strict_types=1);

/**
 * Заглушка OpenAI-совместимого API для стенда: без сети наружу и без денег.
 *
 *   php -S 0.0.0.0:8000 router.php
 *
 * Отвечает так же по форме, как OpenAI:
 *
 *   POST /v1/audio/transcriptions  multipart: model, language, response_format, file
 *        -> verbose_json {text, duration, language}; длительность — из размера
 *           файла (128 кбит/с), чтобы расход на стенде был не нулевым
 *   POST /v1/chat/completions      {model, messages, response_format?}
 *        -> choices[0].message.content; при json_schema — JSON по схеме
 *           анализа сделки; usage — по длине текста
 *   GET  /__stats                  сколько запросов пришло и последний запрос
 *   POST /__fail?status=429        следующие N запросов ответят этим статусом
 *
 * Ключ проверяется, только если задан MOCK_API_KEY: так проверяется, что
 * модуль шлёт его заголовком.
 */

$stateFile = sys_get_temp_dir().'/mock-openai-state.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['count' => [], 'last' => null, 'fail' => ['status' => 0, 'left' => 0]];

$save = static function() use (&$state, $stateFile): void
{
	file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
};

$json = static function(int $status, array $body): never
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($body, JSON_UNESCAPED_UNICODE);
	exit;
};

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if($path === '/__stats')
{
	$json(200, $state);
}

if($path === '/__reset')
{
	$state = ['count' => [], 'last' => null, 'fail' => ['status' => 0, 'left' => 0]];
	$save();
	$json(200, ['reset' => true]);
}

if($path === '/__fail')
{
	$state['fail'] = ['status' => (int)($_GET['status'] ?? 500), 'left' => (int)($_GET['times'] ?? 1)];
	$save();
	$json(200, $state['fail']);
}

$state['count'][$path] = ($state['count'][$path] ?? 0) + 1;

$key = getenv('MOCK_API_KEY');
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$state['last'] = [
	'path' => $path,
	'method' => $method,
	'authorization' => $auth !== '' ? 'Bearer ***' : '',
	'contentType' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
	'post' => $_POST,
	'files' => array_map(static fn(array $file): array => ['name' => $file['name'], 'type' => $file['type'], 'size' => $file['size']], $_FILES),
	'time' => date('c'),
];
$save();

if($state['fail']['left'] > 0)
{
	$state['fail']['left']--;
	$save();
	$json($state['fail']['status'], ['error' => ['message' => 'mock: forced failure', 'type' => 'mock']]);
}

if(is_string($key) && $key !== '' && $auth !== 'Bearer '.$key)
{
	$json(401, ['error' => ['message' => 'Incorrect API key provided: '.substr($auth, 7), 'type' => 'invalid_request_error']]);
}

if($method === 'POST' && $path === '/v1/audio/transcriptions')
{
	$file = $_FILES['file'] ?? null;
	if(!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || (int)$file['size'] === 0)
	{
		$json(400, ['error' => ['message' => 'file is required']]);
	}

	$duration = round((int)$file['size'] * 8 / 128000, 2);

	$json(200, [
		'task' => 'transcribe',
		'language' => (string)($_POST['language'] ?? 'ru'),
		'duration' => $duration,
		'text' => sprintf(
			"[mock-openai] Менеджер: Добрый день, компания «Стенд». Клиент: Здравствуйте, хочу уточнить сроки поставки. Менеджер: Отгрузим в пятницу. (модель %s, %d байт, %.1f с)",
			(string)($_POST['model'] ?? '?'),
			(int)$file['size'],
			$duration
		),
	]);
}

if($method === 'POST' && $path === '/v1/chat/completions')
{
	$request = json_decode((string)file_get_contents('php://input'), true);
	if(!is_array($request) || !is_array($request['messages'] ?? null) || $request['messages'] === [])
	{
		$json(400, ['error' => ['message' => 'messages is required']]);
	}

	$text = '';
	foreach($request['messages'] as $message)
	{
		$text .= (string)($message['content'] ?? '')."\n";
	}

	if(($request['response_format']['type'] ?? '') === 'json_schema')
	{
		$idle = preg_match('/Дней без активности: (\d+)/u', $text, $m) ? (int)$m[1] : 0;
		$risk = min(95, $idle * 5 + 10);
		$content = json_encode([
			'risk' => $risk,
			'needSenior' => $risk >= 70,
			'why' => '[mock-openai] Дней без активности: '.$idle.'.',
			'nextStep' => '[mock-openai] Позвонить клиенту и назначить встречу.',
		], JSON_UNESCAPED_UNICODE);
	}
	else
	{
		$last = end($request['messages']);
		$content = '[mock-openai] Резюме: клиент уточнял сроки поставки, договорились об отгрузке в пятницу. '
			.'(на запрос из '.count($request['messages']).' сообщ., последнее: '.mb_substr((string)($last['content'] ?? ''), 0, 80).')';
	}

	$in = max(1, intdiv(mb_strlen($text), 2));
	$out = max(1, intdiv(mb_strlen($content), 2));

	$json(200, [
		'id' => 'chatcmpl-mock-'.bin2hex(random_bytes(4)),
		'object' => 'chat.completion',
		'model' => (string)($request['model'] ?? 'mock'),
		'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
		'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
	]);
}

if($path === '/v1/models')
{
	$json(200, ['object' => 'list', 'data' => [['id' => 'whisper-1'], ['id' => 'gpt-4o-mini']]]);
}

$json(404, ['error' => ['message' => 'mock: unknown route '.$method.' '.$path]]);

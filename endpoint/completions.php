<?php declare(strict_types=1);

/**
 * Точка входа собственного ИИ-движка.
 *
 * Открывается заглушкой /bitrix/tools/shef_toolsai_completions.php (её пишет
 * Main\PublicPage). Решение — какой статус отдать — принимает
 * Completion\Endpoint; здесь только чтение запроса, ответ и фон.
 *
 * ⚠ Таймаут запроса у ядра — 5 секунд (ThirdParty::HTTP_TIMEOUT). Поэтому
 * здесь только приём: ответ 202 уходит сразу, соединение закрывается, и лишь
 * потом идёт поход к провайдеру.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Shef\ToolsAi\Container;

const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const NO_AGENT_CHECK = true;
const NOT_CHECK_PERMISSIONS = true;
const DisableEventsCheck = true;
const STOP_STATISTICS = true;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

$respond = static function(int $status, array $body): void
{
	while(ob_get_level() > 0)
	{
		ob_end_clean();
	}

	$json = (string)json_encode($body, JSON_UNESCAPED_UNICODE);

	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Length: '.strlen($json));
	header('Connection: close');
	header('Cache-Control: no-store');
	echo $json;
};

if(!Loader::includeModule('shef.toolsai'))
{
	$respond(503, ['error' => 'module_not_installed']);
	die();
}

$response = Container::getEndpoint()->handle(
	(string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
	$_GET,
	(string)file_get_contents('php://input', false, null, 0, \Shef\ToolsAi\Completion\Endpoint::MAX_BODY_BYTES + 1)
);

$respond($response->status, $response->body);

if($response->job === null)
{
	die();
}

// --- долгая часть: уже после ответа ---------------------------------------
//
// Ответ отдан и соединение закрыто: ядро получило свои 202. Дальше процесс
// работает сам — распознавание часовой записи длится минуты.
ignore_user_abort(true);
set_time_limit(0);

if(function_exists('fastcgi_finish_request'))
{
	fastcgi_finish_request();
}
else
{
	// mod_php (BitrixVM: nginx -> apache): закрыть соединение нечем, но
	// Content-Length и Connection: close уже отданы — отдаём тело целиком,
	// и nginx завершает ответ клиенту. Проверка на стенде — время ответа
	// POST в docs/portal-check.md, шаг E.
	flush();
}

try
{
	Container::getDispatcher()->dispatch($response->job);
}
catch(\Throwable $throwable)
{
	Container::getLogger('endpoint')?->error($throwable);
}

Application::getInstance()->terminate();

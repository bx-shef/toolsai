<?php declare(strict_types=1);

/**
 * Входящий вебхук администратора со scope crm, telephony, user — для
 * test-call.sh. Печатает адрес вебхука.
 *
 *   docker compose exec -u www-data portal php /opt/stand/webhook.php
 *
 * Создаётся напрямую таблицами модуля rest (APAuth\PasswordTable и
 * PermissionTable). Если на вашем ядре их нет или поля другие — создайте
 * вебхук руками: Разработчикам -> Другое -> Входящий вебхук, права crm,
 * telephony, user, и передайте адрес в test-call.sh.
 */

const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

$_SERVER['DOCUMENT_ROOT'] = getenv('DOCUMENT_ROOT') ?: '/var/www/html';
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

if(!Loader::includeModule('rest'))
{
	fwrite(STDERR, "модуль rest не установлен\n");
	exit(1);
}

$userId = (int)(getenv('USER_ID') ?: 1);
$password = substr(bin2hex(random_bytes(12)), 0, 16);

$result = \Bitrix\Rest\APAuth\PasswordTable::add([
	'USER_ID' => $userId,
	'PASSWORD' => $password,
	'ACTIVE' => 'Y',
	'TITLE' => 'shef.toolsai stand',
	'COMMENT' => 'test-call.sh',
	'DATE_CREATE' => new DateTime(),
]);

if(!$result->isSuccess())
{
	fwrite(STDERR, implode('; ', $result->getErrorMessages())."\n");
	exit(1);
}

foreach(['crm', 'telephony', 'user'] as $scope)
{
	\Bitrix\Rest\APAuth\PermissionTable::add([
		'PASSWORD_ID' => $result->getId(),
		'PERM' => $scope,
	]);
}

printf("%s/rest/%d/%s/\n", getenv('PUBLIC_URL') ?: 'http://portal', $userId, $password);

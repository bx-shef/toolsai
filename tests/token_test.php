<?php declare(strict_types=1);

/**
 * Токен эндпоинта: единственное, что отделяет чужой POST от платного запроса.
 *
 * Что держит:
 *
 * * пустой настроенный токен — закрыто, а не открыто: hash_equals('', '')
 *   истинно, и без отдельной проверки ненастроенный модуль принимал бы всех;
 * * токен не строкой (?token[]=…) — отказ без warning;
 * * другая длина и регистр — отказ;
 * * генератор даёт 64 hex и каждый раз новый.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Shef\ToolsAi\Security\Token;

$token = str_repeat('ab', 32);

Check::group('проверка');

Check::same('верный', Token::check($token, $token), true);
Check::same('пустой настроенный и пустой присланный — закрыто', Token::check('', ''), false);
Check::same('пустой настроенный и любой присланный — закрыто', Token::check('', 'x'), false);
Check::same('пустой присланный', Token::check($token, ''), false);
Check::same('массив', Token::check($token, [$token]), false);
Check::same('null', Token::check($token, null), false);
Check::same('короче', Token::check($token, substr($token, 0, 63)), false);
Check::same('регистр', Token::check($token, strtoupper($token)), false);

Check::group('генерация');

$first = Token::generate();
Check::same('64 hex', 1 === preg_match('/^[a-f0-9]{64}$/', $first), true);
Check::same('каждый раз новый', $first === Token::generate(), false);

Check::finish();

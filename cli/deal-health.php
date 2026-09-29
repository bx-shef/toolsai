<?php declare(strict_types=1);

/**
 * Прогон анализа сделок руками — то, что делает агент, но сейчас и с отчётом.
 *
 *   php -f bitrix/modules/shef.toolsai/cli/deal-health.php            # кандидаты, как у агента
 *   DEALS=15,16 php -f .../cli/deal-health.php                        # только эти сделки
 *
 * Настройка «Включить анализ» здесь не проверяется — запуск руками и есть
 * решение. Квота, порог, лимит за прогон и эскалация — как у агента.
 */

const STOP_STATISTICS = true;
const NO_KEEP_STATISTIC = 'Y';
const NO_AGENT_STATISTIC = 'Y';
const NOT_CHECK_PERMISSIONS = true;
const NO_AGENT_CHECK = true;

if(php_sapi_name() !== 'cli')
{
	die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = (string)(getenv('DOCUMENT_ROOT') ?: realpath(__DIR__.'/../../../../'));

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Shef\ToolsAi\Agent\DealHealthAgent;
use Shef\ToolsAi\Main\OptionParser;

if(!Loader::includeModule('shef.toolsai') || !Loader::includeModule('crm'))
{
	fwrite(STDERR, "Нужны модули shef.toolsai и crm\n");
	exit(1);
}

$only = null;
$deals = getenv('DEALS');
if(is_string($deals) && $deals !== '')
{
	$only = array_values(array_filter(array_map(
		static fn(string $id): int => OptionParser::id(trim($id)),
		explode(',', $deals)
	)));
}

if($only === null)
{
	printf("Кандидатов: %d\n", count(DealHealthAgent::getCandidates()));
}

// Под той же блокировкой и от того же служебного пользователя, что агент:
// ручной прогон рядом с агентом платил бы за те же сделки дважды.
$report = DealHealthAgent::runLocked(static fn(): array => DealHealthAgent::process($only));
if($report === null)
{
	fwrite(STDERR, "Анализ уже идёт в другом процессе (агент или второй запуск)\n");
	exit(1);
}

foreach($report as $dealId => $row)
{
	printf(
		"  сделка %-8d %s\n",
		$dealId,
		$row['error'] !== ''
			? 'ОШИБКА: '.$row['error']
			: ($row['skipped']
				? 'пропущена: в работе'
				: sprintf('риск %d, старший: %s, эскалация: %s', $row['risk'], $row['needSenior'] ? 'да' : 'нет', implode(', ', $row['escalated']) ?: '—'))
	);
}

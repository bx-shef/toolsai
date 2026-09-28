<?php declare(strict_types=1);

namespace Shef\ToolsAi\Quota;

interface QuotaInterface
{
	/** Остаток на текущий месяц. */
	public function getMonthly(): Balance;
}

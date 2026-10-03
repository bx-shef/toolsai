<?php
/**
 * Значения настроек по умолчанию — их отдаёт Option::get(), пока настройку
 * не сохранили. Совпадают с умолчаниями в коде: Shef\ToolsAi\Config::DEFAULT_*.
 *
 * Провайдер по умолчанию — заглушка: весь путь обкатывается без денег, а
 * платный провайдер включается осознанно.
 */
$shef_toolsai_default_option = [
	'DEF_provideraudio' => 'echo',
	'DEF_providertext' => 'echo',
	'DEF_quota' => '0',
	'API_baseurl' => 'https://api.openai.com/v1',
	'API_asrmodel' => 'whisper-1',
	'API_llmmodel' => 'gpt-4o-mini',
	'API_llmextra' => '',
	'API_timeout' => '120',
	'DEAL_enabled' => 'N',
	'DEAL_threshold' => '70',
	'DEAL_maxperrun' => '20',
	'DEAL_reanalyzedays' => '7',
	'DEAL_idledays' => '3',
];

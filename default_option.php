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
	'API_ownprompts' => 'N',
	'API_timeout' => '120',
	'DEAL_enabled' => 'N',
	'DEAL_maxperrun' => '50',
	'DEAL_interval' => '60',
	'CHAT_enabled' => 'N',
	'CHAT_maxperrun' => '20',
	'CHAT_days' => '3',
	'CHAT_script' => '',
	'EMAIL_summary' => 'N',
	'EMAIL_todos' => 'N',
	'EMAIL_review' => 'N',
	'EMAIL_reply' => 'N',
	'EMAIL_maxperrun' => '20',
	'EMAIL_days' => '3',
	'EMAIL_criteria' => '',
	'EMAIL_replyhours' => '4',
	'EMAIL_escalatehours' => '24',
	'EMAIL_senior' => '',
];

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider\Llm;

use Shef\ToolsAi\Main\Constants;

/**
 * LLM-заглушка: без сети и без денег.
 *
 * Для анализа сделки отдаёт вердикт по одному правилу — чем дольше сделка без
 * дел, тем выше риск, — чтобы на стенде можно было пройти эскалацию целиком:
 * сделка без дел 14+ дней даёт риск 80 и «звать старшего».
 */
final class EchoLlm implements LlmProviderInterface
{
	public function getCode(): string
	{
		return Constants::PROVIDER_ECHO;
	}

	public function completeJson(string $system, string $user, array $schema): LlmResult
	{
		$idle = preg_match('/Дней без активности: (\d+)/u', $user, $match) ? (int)$match[1] : 0;
		$risk = min(95, $idle * 5 + 10);

		$json = [
			'risk' => $risk,
			'needSenior' => $risk >= 70,
			'why' => '[заглушка] Дней без активности: '.$idle.'.',
			'nextStep' => '[заглушка] Позвонить клиенту и договориться о следующем шаге.',
		];

		return new LlmResult((string)json_encode($json, JSON_UNESCAPED_UNICODE), $json);
	}

	public function complete(array $messages): LlmResult
	{
		$last = end($messages);

		return new LlmResult('[заглушка shef.toolsai] '.mb_substr(is_array($last) ? (string)$last['content'] : '', 0, 200));
	}
}

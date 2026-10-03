<?php declare(strict_types=1);

namespace Shef\ToolsAi\Stats;

/**
 * Разбор поля RESULT задания оценки звонка (b_crm_ai_queue, TYPE_ID = 4).
 *
 * Как оно туда попадает (crm/lib/integration/ai/operation):
 *
 * * scorecall.php, extractPayloadFromAIResult(): ответ модели — JSON с
 *   ключом call_review; из него берётся call_review.criteria, плюс
 *   overall_summary и recommendations -> ScoreCallPayload;
 * * abstractoperation.php: $job->setResult(Json::encode($payload, 0)) —
 *   Dto::jsonSerialize() = toArray(), свойства без null. В RESULT лежит
 *   {"criteria":[{"criterion":"…","status":true|false|null,
 *   "explanation":"…"}],"overallSummary":"…","recommendations":"…"};
 * * criteria — ScoringCriteria: criterion (строка), status (?bool),
 *   explanation. Оценку ядро считает только по критериям, где status —
 *   bool (getAssessmentsValue()); null — критерий не оценён.
 *
 * Защита: criteria в корне (так пишет ядро) или в call_review (сырой
 * ответ модели — на случай другой версии ядра). Мусор — пустой список.
 *
 * Чистая логика, без ядра.
 */
final class ScoreResult
{
	/**
	 * @return list<array{criterion: string, status: bool}> только оценённые
	 *         критерии; без названия — отброшены
	 */
	public static function parseCriteria(mixed $json): array
	{
		if(!is_string($json) || $json === '')
		{
			return [];
		}

		try
		{
			$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		}
		catch(\JsonException)
		{
			return [];
		}

		if(!is_array($data))
		{
			return [];
		}

		$criteria = $data['criteria'] ?? null;
		if(!is_array($criteria) && is_array($data['call_review'] ?? null))
		{
			$criteria = $data['call_review']['criteria'] ?? null;
		}
		if(!is_array($criteria))
		{
			return [];
		}

		$result = [];
		foreach($criteria as $item)
		{
			if(!is_array($item) || !is_bool($item['status'] ?? null) || !is_string($item['criterion'] ?? null))
			{
				continue;
			}

			$name = trim(preg_replace('/\s+/u', ' ', $item['criterion']) ?? '');
			if($name === '')
			{
				continue;
			}

			$result[] = ['criterion' => $name, 'status' => $item['status']];
		}

		return $result;
	}
}

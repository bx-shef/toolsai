<?php declare(strict_types=1);

namespace Shef\ToolsAi\Deal;

use Shef\ToolsAi\Provider\Llm\LlmProviderInterface;

/**
 * Оценка здоровья сделки: пора ли звать старшего продавца.
 *
 * ПОЧЕМУ СВОЁ, А НЕ ШТАТНОЕ
 * 1. AnalyzeCommunication — не оценка риска. Возвращает только список дел
 *    (crm/lib/integration/ai/dto/analyzecommunicationpayload.php): actions[],
 *    isClient, reasonIfIsClientFalse. Ни балла, ни флага эскалации.
 * 2. Узел автоматизации «AI-обработка» (returnType=json + jsonSchema) требует
 *    модуль aiassistant (bizproc/lib/Public/Service/AiAgent/NodeAvailabilityService.php:13,36),
 *    которого на коробке нет.
 *
 * Подробности — docs/02-deal-health.md.
 */
final class HealthAnalyzer
{
	public const SCHEMA = [
		'type' => 'object',
		'required' => ['risk', 'needSenior', 'why', 'nextStep'],
		'additionalProperties' => false,
		'properties' => [
			'risk' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
			'needSenior' => ['type' => 'boolean'],
			'why' => ['type' => 'string', 'maxLength' => 500],
			'nextStep' => ['type' => 'string', 'maxLength' => 300],
		],
	];

	public function __construct(
		private readonly FactsSourceInterface $facts,
		private readonly LlmProviderInterface $llm,
	)
	{
	}

	public function getLlmCode(): string
	{
		return $this->llm->getCode();
	}

	/**
	 * @throws \Shef\ToolsAi\Provider\ProviderException
	 */
	public function analyze(int $dealId, int $idleDays): Analysis
	{
		$facts = $this->facts->build($dealId);

		// Дешёвый фильтр до обращения к модели — экономит львиную долю запросов.
		if(!$facts->isWorthAnalyzing($idleDays))
		{
			return new Analysis(Verdict::skipped('Сделка в работе, анализ не требуется'));
		}

		$result = $this->llm->completeJson(
			static::getSystemPrompt(),
			$facts->toPromptText(),
			static::SCHEMA
		);

		return new Analysis(Verdict::fromArray($result->json ?? []), $result);
	}

	public static function getSystemPrompt(): string
	{
		return <<<'PROMPT'
		Ты — руководитель отдела продаж. По сводке о сделке оцени риск её потери.

		Тревожные признаки:
		— долгое молчание при активной работе ранее;
		— откат по стадиям назад;
		— обсуждение цены без движения к оплате;
		— клиент перестал отвечать после коммерческого предложения;
		— менеджер звонит, а клиент не перезванивает.

		needSenior = true только если вмешательство старшего реально может
		изменить исход. Для сделки, которая уже мертва или идёт нормально, — false.

		risk — вероятность потери сделки в процентах.
		why — одно-два предложения, по какому признаку сделан вывод.
		nextStep — что конкретно сделать прямо сейчас.

		Отвечай строго JSON по схеме, без пояснений вокруг.
		PROMPT;
	}
}

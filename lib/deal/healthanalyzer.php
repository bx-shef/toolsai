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
	/**
	 * Схема ответа — только типы, без minimum/maximum/maxLength: строгий
	 * режим structured outputs принимает не все ключевые слова JSON Schema, и
	 * лишнее даёт 400 на каждый анализ. Границы держит Verdict::fromArray():
	 * риск подрезается в 0-100, тексты обрезаются.
	 */
	public const SCHEMA = [
		'type' => 'object',
		'required' => ['risk', 'needSenior', 'why', 'nextStep'],
		'additionalProperties' => false,
		'properties' => [
			'risk' => ['type' => 'integer', 'description' => 'вероятность потери сделки, 0-100'],
			'needSenior' => ['type' => 'boolean'],
			'why' => ['type' => 'string', 'description' => 'одно-два предложения, до 500 символов'],
			'nextStep' => ['type' => 'string', 'description' => 'что сделать сейчас, до 300 символов'],
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
	 * Факты -> профиль -> фильтр -> модель.
	 *
	 * Профиль подбирает $pick по фактам (направление, тип клиента): так
	 * анализатор не знает про таблицу профилей и проверяется без базы. Нет
	 * профиля — пропуск без запроса к модели.
	 *
	 * @param callable(DealFacts): ?Profile $pick
	 * @throws \Shef\ToolsAi\Provider\ProviderException
	 */
	public function analyze(int $dealId, callable $pick): Analysis
	{
		$facts = $this->facts->build($dealId);
		$profile = $pick($facts);

		if($profile === null)
		{
			return new Analysis(Verdict::skipped('Нет подходящего профиля анализа'), null, $facts);
		}

		// Мёртвая сделка (нет активности дольше ACTIVE_DAYS профиля) — без
		// модели. Выборка кандидатов отсекает их уже в SQL по самому мягкому
		// сроку направления; здесь — точно по профилю самой сделки.
		if(!$facts->isAlive($profile->activeDays))
		{
			return new Analysis(Verdict::skipped(sprintf('Нет активности %d дн. (живые — до %d дн.)', $facts->daysSinceLastActivity, $profile->activeDays)), null, $facts, $profile);
		}

		// Дешёвый фильтр до обращения к модели — экономит львиную долю запросов.
		if(!$facts->isWorthAnalyzing($profile->idleDays))
		{
			return new Analysis(Verdict::skipped('Сделка в работе, анализ не требуется'), null, $facts, $profile);
		}

		$result = $this->llm->completeJson(
			static::buildSystemPrompt($profile->prompt),
			$facts->toPromptText(),
			static::SCHEMA
		);

		return new Analysis(Verdict::fromArray($result->json ?? []), $result, $facts, $profile);
	}

	/**
	 * Системный промпт: свой промпт профиля или общий. Схема ответа общая
	 * (SCHEMA) и уходит отдельно — профиль её не меняет; а чтобы свой промпт
	 * не забыл про форму ответа, модуль всегда дописывает требование JSON.
	 */
	public static function buildSystemPrompt(string $profilePrompt): string
	{
		$profilePrompt = trim($profilePrompt);
		if($profilePrompt === '')
		{
			return static::getSystemPrompt();
		}

		return $profilePrompt."\n\n".static::JSON_RULE;
	}

	/** Хвост своего промпта профиля: форма ответа — общая. */
	public const JSON_RULE = 'Отвечай строго JSON по схеме: risk — вероятность потери сделки 0-100, needSenior — нужен ли старший, why — почему, nextStep — что сделать сейчас. Без пояснений вокруг.';

	public static function getSystemPrompt(): string
	{
		return <<<'PROMPT'
		Ты — руководитель отдела продаж. По сводке о сделке оцени риск её потери.

		Тревожные признаки:
		— долгое молчание при активной работе ранее;
		— откат по стадиям назад;
		— обсуждение цены без движения к оплате;
		— клиент перестал отвечать после коммерческого предложения;
		— менеджер звонит, а клиент не перезванивает;
		— просроченные дела: менеджер обещал и не сделал.

		Если в сводке есть резюме звонков — опирайся на них: что клиент
		сказал, о чём договорились, есть ли возражения. Оценка звонка по
		скрипту и невыполненные пункты показывают, где менеджер недорабатывает.
		Звонок «не клиент» (спам, ошиблись номером) — не признак интереса.

		needSenior = true только если вмешательство старшего реально может
		изменить исход. Для сделки, которая уже мертва или идёт нормально, — false.

		risk — вероятность потери сделки в процентах.
		why — одно-два предложения, по какому признаку сделан вывод.
		nextStep — что конкретно сделать прямо сейчас.

		Отвечай строго JSON по схеме, без пояснений вокруг.
		PROMPT;
	}
}

<?php declare(strict_types=1);

namespace Shef\ToolsAi\Provider;

use Shef\ToolsAi\Completion\Request;
use Shef\ToolsAi\Main\Constants;

/**
 * Заглушка для обкатки контракта БЕЗ ДЕНЕГ.
 *
 * Ставится первой: на ней прогоняется весь путь «звонок -> эндпоинт -> колбэк
 * -> транскрипт в таймлайне» и ловятся все грабли (public_url, фоновые
 * задачи, статус 202, идемпотентность) до того, как подключён платный
 * провайдер.
 *
 * Текст — с разметкой по говорящим, как у штатного движка: дальше по цепочке
 * (Summarize, ScoreCall) транскрипт уходит в промпт как есть.
 */
final class EchoProvider implements ProviderInterface
{
	public const MARK = '[заглушка shef.toolsai]';

	public function getCode(): string
	{
		return Constants::PROVIDER_ECHO;
	}

	public function estimateCostMicro(Request $request): int
	{
		return 0;
	}

	public function run(Request $request): Result
	{
		if($request->category === Constants::CATEGORY_AUDIO)
		{
			return new Result(
				sprintf(
					"%s Расшифровка записи (%s).\n"
					."Менеджер: Добрый день, это тестовая реплика.\n"
					."Клиент: Да, я вас слышу, всё работает.",
					static::MARK,
					$request->getAudioExtension()
				)
			);
		}

		$messages = $request->getChatMessages();
		$last = end($messages);
		$text = is_array($last) ? $last['content'] : '';

		return new Result(sprintf(
			'%s Ответ на промпт (%d сообщ.): %s',
			static::MARK,
			count($messages),
			mb_substr($text, 0, 200)
		));
	}
}

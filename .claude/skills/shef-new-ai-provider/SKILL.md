---
name: shef-new-ai-provider
description: Подключить к модулю shef.toolsai (свой ИИ-движок Копилота в коробочном Битрикс24) новый провайдер распознавания речи или LLM — YandexGPT, SpeechKit, GigaChat, свой сервер с нестандартным API — либо вызвать LLM из своего модуля с учётом расхода в квоте shef.toolsai. Брать на задачи «подключи другой ASR/LLM к Копилоту», «распознавание звонков через …», «добавь провайдера в toolsai», «вызови модель из моего модуля так, чтобы расход считался». Не для OpenAI-совместимых серверов (OpenAI, whisper-сервер, vLLM, LocalAI, Ollama /v1) — они уже работают настройкой «Адрес API»; не для HTTP-клиента к произвольному API вне ИИ — shef-new-api-client из shef.insync.
---

# Новый провайдер для shef.toolsai

Операция: научить движок [shef.toolsai](https://github.com/bx-shef/toolsai)
ходить в ещё один сервис распознавания речи или LLM — так, чтобы расход
считался, квота соблюдалась, а ядро Битрикса получало ответ в том виде, в
каком ждёт.

**Сначала проверьте, нужен ли код вообще.** Всё, что говорит протоколом
OpenAI (`/v1/audio/transcriptions`, `/v1/chat/completions`), подключается
настройкой: «Провайдер → Адрес API», модели, ключ, цены. Это OpenAI,
faster-whisper-server, whisper.cpp server, vLLM, LocalAI, Ollama, большинство
прокси. Код — только для своего протокола.

## Контракт

Провайдер категории движка — `\Shef\ToolsAi\Provider\ProviderInterface`:

```php
public function getCode(): string;                            // код в журнале расхода
public function estimateCostMicro(Request $request): int;     // оценка ДО запроса, вверх
public function run(Request $request): Result;                // бросает ProviderException
```

* `Request` — запрос ядра (`\Shef\ToolsAi\Completion\Request`): для
  `audio` — `getAudioUrl()`, `getAudioMimeType()`, `getLanguage()`; для
  `text` — `getChatMessages()`, уже собранные из роли, контекста и промпта;
* `Result` (`\Shef\ToolsAi\Provider\Result`) — текст, единицы (секунды
  аудио или токены) и стоимость в **микро-единицах** валюты (1/1_000_000);
* сбой — `\Shef\ToolsAi\Provider\ProviderException` с кодом: он уходит
  ядру в `error_code` колбэка ошибки.

Для LLM, которую зовёт анализ сделок, — второй интерфейс,
`\Shef\ToolsAi\Provider\Llm\LlmProviderInterface`: `complete(array $messages)`
и `completeJson(string $system, string $user, array $schema)`, оба
возвращают `LlmResult` с токенами и ценой.

## Порядок

1. Класс в `lib/provider/<имя>/` — путь **строчными**, namespace
   `Shef\ToolsAi\Provider\<Имя>`.
2. HTTP — только через `\Shef\ToolsAi\Http\TransportInterface`, не
   `HttpClient` напрямую: так провайдер проверяется тестом без сети
   (`tests/stub/fakes.php`, `FakeTransport`).
3. Код провайдера — в `\Shef\ToolsAi\Main\Constants::getProviderList()` и
   в выбор `\Shef\ToolsAi\Container::getProvider()`; подпись — в
   `lang/ru/options.php`. Список на странице настроек строится из того же
   списка, и `tests/optionsconf_test.php` сверит.
4. Настройки провайдера — новой вкладкой в `options_conf.php`
   (навык shef-new-option), чтение — методом `\Shef\ToolsAi\Config` через
   `\Shef\ToolsAi\Main\OptionParser`: деньги — `micro()`, адрес — `url()`.
5. Тест в `tests/`: что уходит (адрес, заголовки, тело), как разбирается
   ответ, коды ошибок, стоимость.

## Что ядро не простит

* **Пустой текст — не успех.** Пустая транскрипция обрывает цепочку ядра
  молча (`canProceedToNextStep()`); бросайте `ProviderException` с кодом
  `empty_result`.
* **Стоимость — вверх и в микро-единицах.** Оценка до запроса решает,
  пустит ли квота; занижение даёт перерасход.
* **Ключ — не в тексте ошибки.** Текст ошибки попадает в журнал расхода,
  лог проблем и карточку; провайдеры любят повторять присланный ключ.
  `\Shef\ToolsAi\Provider\OpenAi\Client` маскирует его — повторите.
* **Запись скачивается по адресу от ядра** с лимитом
  `\Shef\ToolsAi\Provider\OpenAi\AsrProvider::MAX_BYTES`; адрес в текст
  ошибки не кладите — в нём параметры доступа к файлу.
* **Время.** Провайдер работает уже после ответа 202, но php-fpm и
  `max_execution_time` никто не отменял: часовая запись у медленного
  провайдера — минуты.

## Вызвать LLM из своего модуля

```php
\Bitrix\Main\Loader::includeModule('shef.toolsai');

$llm = \Shef\ToolsAi\Container::getLlm();   // провайдер категории text из настроек
$result = $llm->completeJson($system, $user, $schema);
```

Расход такого вызова журнал сам не увидит — запишите его, как делает агент
анализа сделок (`\Shef\ToolsAi\Agent\DealHealthAgent::process()`):
`\Shef\ToolsAi\Quota\Ledger` — `start()` и `finish()` со статусом
`\Shef\ToolsAi\Quota\Status::SUCCESS`, а до вызова — проверка
`\Shef\ToolsAi\Container::getMeter()->getMonthly()->isExceeded()`.

## Проверка

* `./build.sh --check` — зелёный, новый тест в прогоне;
* на стенде: провайдер выбран в настройках, «Проверить и включить» — OK,
  тестовый звонок даёт транскрипт, на странице «ИИ: расход и остаток» —
  строка с вашим кодом провайдера и ненулевой стоимостью.

## В конце

Оставьте отзыв о навыке — shef-feedback.

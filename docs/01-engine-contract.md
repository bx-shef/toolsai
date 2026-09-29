# Контракт третьесторонного ИИ-движка

Что именно Битрикс присылает на `completions_url` и что ждёт обратно.
Источник — `www/bitrix/modules/ai/lib/Engine/ThirdParty.php`.

---

## Шаг 0. Регистрация

Битрикс делает **GET** на `completions_url` и требует ровно `200`
(`ThirdPartyRegisterService::validateCompletionsUrl`). Эндпоинт обязан
отвечать на GET, иначе регистрация упадёт с
`ENGINE_REGISTER_ERROR_COMPLETIONS_URL_FAIL`.

---

## Шаг 1. Битрикс -> наш эндпоинт

`POST` с `Content-Type: application/json`.

> ⚠️ **Таймаут — 5 секунд.** `ThirdParty::HTTP_TIMEOUT = 5` (`ThirdParty.php:24`).
> Это не «желательно быстро», а жёсткая граница: фоновая обработка обязательна,
> синхронный поход к ASR/LLM прямо в обработчике гарантированно не уложится.

Тело собирается в `ThirdParty::completions()` (`ThirdParty.php:226-248`):

```jsonc
{
  // payload->getData(); для audio (Payload/Audio.php:20-27):
  "prompt": {
    "file": "https://crm.example.by/bitrix/tools/crm_show_file.php?fileId=965723&…",
    "fields": {},
    "fileExtension": "mp3"
  },

  "payload_raw":         "…",        // payload->getRawData(): сырьё до упаковки
  "payload_provider":    "audio",    // strtolower(короткое имя класса Payload)
                                     //   audio | prompt | classify | styledpicture
  "payload_role":        "…",        // инструкция роли, может быть null
  "payload_prompt_text": null,       // текст промпта, заполнен только при provider=prompt

  "context": [                       // packContextMessages(), ThirdParty.php:199-212
    { "role": "system", "content": "…" },
    { "role": "user",   "content": "…" }
  ],

  "payload_markers": { "language": "ru" },   // маркеры + язык пользователя

  "auth":     null,                  // Rest::getAuthInfo(app_code); у нас null
  "category": "audio",               // audio | text | image | call | vision | classify
  "ttl":      300,                   // QueueJob::getTTL()

  "callbackUrl":      "https://crm.example.by/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackSuccess&hash=abc123",
  "errorCallbackUrl": "https://crm.example.by/bitrix/services/main/ajax.php?action=ai.controller.integration.thirdparty.callbackError&hash=abc123"
}
```

### Ответ эндпоинта: строго 202

`ThirdParty.php:23,254-271`:

```php
protected const HTTP_STATUS_OK = 202;
...
if ($http->getStatus() === self::HTTP_STATUS_OK) { /* принято */ }
else { $this->onResponseError("Unknown error occurred with status {$http->getStatus()}", 'unknown_error'); }
```

> ⚠️ **Не 200, а `202 Accepted`.** Обычный `200` будет расценён как ошибка:
> сравнение строгое, по одному-единственному значению. Это самая дорогая
> ловушка контракта — код выглядит рабочим, POST уходит, а задание сразу
> помечается ошибкой.

Итого два разных ожидаемых статуса на одном URL:

| Метод | Когда | Требуемый статус |
|---|---|---|
| `GET` | проверка при регистрации движка | **200** |
| `POST` | запрос на генерацию | **202** |

202 означает «взял в работу», а не «сделал». Долгую работу уводим в фон.

### `hash` — ключ идемпотентности

Один и тот же `hash` лежит в query обоих колбэков. Это идентификатор
`QueueJob`. Используем его, чтобы не списать квоту дважды при ретрае.

---

## Шаг 2. Наш сервис -> Битрикс (асинхронно)

### Успех

`POST` на `callbackUrl`, `Content-Type: application/json`:

```json
{ "result": ["текст расшифровки одной строкой"] }
```

Принимает `Thirdparty::callbackSuccessAction(string $hash, JsonPayload $result)`
-> `QueueJob::execute($result->getData())`.

Разбор — `ThirdParty::getResultFromRaw()` (`ThirdParty.php:159-179`):

```php
if (isset($rawResult['result'])) {
    $result = is_array($rawResult['result']) ? $rawResult['result'] : [$rawResult['result']];
    $result = $this->getCategory() === 'image' ? $result : $result[0];
} else {
    $result = $rawResult;          // ключа 'result' нет -> берётся всё тело целиком
}
```

Значит допустимы три формы:

| Форма | Что получит потребитель |
|---|---|
| `{"result": ["текст"]}` | `"текст"` — **рекомендуемая** |
| `{"result": "текст"}` | `"текст"` — тоже корректно |
| `"текст"` без обёртки | `"текст"` — работает, но полагается на ветку else |

Для `category = "image"` результат остаётся массивом.

### Ошибка

`POST` на `errorCallbackUrl`:

```json
{ "error": "описание", "error_code": "provider_error" }
```

Принимает `callbackErrorAction` -> `QueueJob::fail($result->getData())`.
Если `fail()` сам бросит исключение, контроллер перехватит и запишет
`QueueJob::ERROR_FAIL_PROCESSING` (`thirdparty.php:96-104`).

---

## Что ждёт потребитель в CRM

Для транскрибации звонка результат проходит дальше через
`TranscribeCallRecording`, и следующий шаг сценария запускается только если
(`transcribecallrecording.php:83-93`):

```php
return $payload instanceof TranscribeCallRecordingPayload && !empty($payload->transcription);
```

Пустая строка = цепочка обрывается молча. Лучше вернуть ошибку в
`errorCallbackUrl`, чем пустой успех.

### Формат расшифровки с разделением по говорящим

Штатный облачный движок возвращает текст, где реплики размечены. Если ваш ASR
умеет диаризацию — размечайте так же, иначе `Summarize` и `ScoreCall` будут
работать по сплошному тексту и качество просядет.

Ориентир на разметку смотрите в `SummarizeCallTranscription` и
`Payload/SummarizeTranscript` — там видно, что дальше по цепочке
транскрипт уходит текстом в промпт без дополнительного разбора.

---

## Требования к сети

1. **Исходящие от портала к вашему сервису.** `HttpClient::setPrivateIp(false)`
   (`ThirdParty.php:220`) — приватные адреса разрешены, можно держать сервис
   в той же сети.
2. **Входящие от вашего сервиса к порталу** — для колбэка. Хост берётся из
   `Config::getValue('public_url')`, иначе `UrlManager::getHostUrl()`
   (`QueueJob.php:336-347`). За обратным прокси проверьте, что там внешний
   адрес: иначе колбэк уйдёт в никуда и задание повиснет до истечения `ttl`.
3. **Доступ к файлу записи.** URL в `prompt.file` строится в
   `TranscribeCallRecording::getFileInfo()` (`transcribecallrecording.php:144-189`)
   из `CFile::GetFileArray()['SRC']`. Если в `SRC` нет хоста (файл лежит
   локально в `/upload`), хост подставляется из того же
   `\Bitrix\AI\Config::getValue('public_url')`, иначе `UrlManager::getHostUrl()`.
   Ваш сервис должен уметь скачать этот URL сам — **ещё одна причина выставить
   `public_url` правильно**: при неверном хосте ломается и колбэк, и загрузка
   записи.

### Допустимые расширения записи

`TranscribeCallRecording::SUPPORTED_AUDIO_EXTENSIONS` ссылается на
`\Bitrix\Crm\Service\Timeline\Config::ALLOWED_AUDIO_EXTENSIONS`
(`crm/lib/Service/Timeline/Config.php:7`):

```php
['mp3', 'mp4', 'm4a', 'vp6', 'aac', 'wav']
```

Файл с другим расширением до движка не доедет: операция упадёт раньше, с
`FILE_NOT_SUPPORTED` (`transcribecallrecording.php:110-118`). Тот же список
проверяет `SuitableAudiosChecker` на этапе автозапуска — то есть проверка
двойная.

`fileExtension` в `prompt` — это расширение из имени файла, а
`payload_markers.type` — MIME-тип из `CFile` (`transcribecallrecording.php:122-123`).
Провайдеру лучше доверять MIME, а не расширению.

---

## Безопасность

Эндпоинт публичный: колбэк-контроллер Битрикса работает **без префильтров**
(`thirdparty.php:22-30,55-59`), а ваш `completions_url` по умолчанию тоже
доступен всем. Обязательно:

- общий секрет. **Не в заголовке**: запрос шлёт ядро (`ThirdParty::completions()`),
  и своих заголовков оно не добавляет — единственное, что задаём мы, это
  `completions_url`. Поэтому секрет едет в нём: `?token=<64 hex>`
  (`\Shef\ToolsAi\Security\Token`, `\Shef\ToolsAi\Config::getCompletionsUrl()`).
  В ките стояла подпись `X-Shef-Signature` — с ней эндпоинт отвечал бы 403
  на каждый настоящий запрос;
- колбэк — только на хост портала (`\Shef\ToolsAi\Security\CallbackGuard`):
  адрес колбэка приходит в теле, и без сверки утёкший токен давал бы слать
  POST куда угодно;
- адрес записи (`prompt.file`) тоже из тела: приватные адреса при
  скачивании — только для хоста портала, редиректы проходятся вручную и
  проверяются на каждом шаге (`\Shef\ToolsAi\Provider\OpenAi\AsrProvider`);
  транспорт автоматических редиректов не делает вовсе;
- без `hash` в колбэке — 400: без него нет защиты от повторов;
- токен оседает в access-логах — не логировать query этого адреса; утёк —
  «Сменить токен эндпоинта» (`\Shef\ToolsAi\Main\Setup::rotateToken()`);
- ограничение по IP портала, если сеть позволяет;
- лимит частоты — иначе чужой POST сожжёт вашу квоту у провайдера.

См. `lib/security/` и `\Shef\ToolsAi\Completion\Endpoint`.

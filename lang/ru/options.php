<?php
$MESS['shef.toolsai_PROVIDER_echo'] = 'Заглушка (без денег, для обкатки)';
$MESS['shef.toolsai_PROVIDER_openai'] = 'OpenAI-совместимое API';

$MESS['shef.toolsai_TAB_DEF_NAME'] = 'Движок';
$MESS['shef.toolsai_TAB_DEF_TITLE'] = 'Свой ИИ-движок для Копилота';
$MESS['shef.toolsai_TAB_DEF_Engine'] = 'Эндпоинт движка: #ENDPOINT#. Регистрация движков, обход BaaS и [URL=#URL#]расход и остаток[/URL] — на странице «ИИ: расход и остаток».';
$MESS['shef.toolsai_TAB_DEF_Engine_nourl'] = 'не задан внешний адрес портала';
$MESS['shef.toolsai_TAB_DEF_Selection'] = 'Движок в настройках ИИ портала — CRM берёт его строго по коду. Распознавание звонков: #AUDIO# (движок модуля — #OWN_AUDIO#). Текст: #TEXT# (движок модуля — #OWN_TEXT#). Выбрать движок модуля: <a href="#URL#">оба</a> · <a href="#URL_TEXT#">только текст</a> · <a href="#URL_AUDIO#">только распознавание</a> — запишет выбранное в /configs/?page=ai, заменив текущий выбор этой категории; другую не трогает. Распознавание модуля работает, только пока зарегистрирован его текстовый движок, — выбирать его для текста не обязательно. #RESULT#';
$MESS['shef.toolsai_TAB_DEF_Selection_none'] = 'настройка не найдена';
$MESS['shef.toolsai_TAB_DEF_Selection_empty'] = 'не выбран';
$MESS['shef.toolsai_TAB_DEF_Selection_ok'] = 'Готово: выбор записан.';
$MESS['shef.toolsai_TAB_DEF_Selection_fail'] = 'Не удалось: движки не зарегистрированы или настройка ИИ не найдена — сначала «Проверить и включить» на странице расхода.';
$MESS['shef.toolsai_TAB_DEF_publicurl'] = 'Внешний адрес портала';
$MESS['shef.toolsai_TAB_DEF_publicurl_descr'] = 'Например https://crm.example.by. Пусто — берётся ai::public_url. По нему ядро шлёт запросы движку и строит колбэк и ссылку на запись: за обратным прокси нужен внешний адрес, иначе колбэк уйдёт в никуда. После смены — «Проверить и включить» на странице расхода.';
$MESS['shef.toolsai_TAB_DEF_provideraudio'] = 'Распознавание речи (audio)';
$MESS['shef.toolsai_TAB_DEF_provideraudio_descr'] = 'Начинайте с заглушки: весь путь «звонок -> транскрипт в карточке» проверяется без денег.';
$MESS['shef.toolsai_TAB_DEF_providertext'] = 'Текст (text) и анализ сделок';
$MESS['shef.toolsai_TAB_DEF_providertext_descr'] = 'Резюме звонка, заполнение полей, оценка разговора — и анализ сделок.';
$MESS['shef.toolsai_TAB_DEF_quota'] = 'Месячная квота, в валюте';
$MESS['shef.toolsai_TAB_DEF_quota_descr'] = 'Например 500 или 12,50. 0 или пусто — без ограничения. Исчерпана — запросы получают ошибку quota_exceeded, провайдеру не уходят.';

$MESS['shef.toolsai_TAB_API_NAME'] = 'Провайдер';
$MESS['shef.toolsai_TAB_API_TITLE'] = 'OpenAI-совместимое API: OpenAI, свой whisper-сервер, vLLM, LocalAI, прокси';
$MESS['shef.toolsai_TAB_API_baseurl'] = 'Адрес API';
$MESS['shef.toolsai_TAB_API_baseurl_descr'] = 'С версией, без слэша в конце: https://api.openai.com/v1';
$MESS['shef.toolsai_TAB_API_apikey'] = 'Ключ API';
$MESS['shef.toolsai_TAB_API_apikey_descr'] = 'Уходит заголовком Authorization. Своему серверу без ключа — оставить пустым.';
$MESS['shef.toolsai_TAB_API_asrmodel'] = 'Модель распознавания';
$MESS['shef.toolsai_TAB_API_llmmodel'] = 'Модель текста';
$MESS['shef.toolsai_TAB_API_ownprompts'] = 'Свои промпты для резюме, заполнения полей и оценки звонка';
$MESS['shef.toolsai_TAB_API_ownprompts_descr'] = 'Выключено — модели уходит готовый промпт Копилота, который присылает ядро. Включено — модуль пишет инструкцию сам: резюме пунктами, поля — JSON с именами полей CRM и комментарием, оценка звонка — по критериям скрипта речевой аналитики. Включайте, если по промпту ядра резюме в карточке не по делу или заполнение полей падает с PAYLOAD_IS_EMPTY. Прочие сценарии — всегда промптом ядра.';
$MESS['shef.toolsai_TAB_API_llmextra'] = 'Дополнительные параметры модели текста (JSON)';
$MESS['shef.toolsai_TAB_API_llmextra_descr'] = 'Добавляются в запрос /chat/completions как есть. Например, выключить рассуждения, которые оплачиваются как выходные токены: у DeepSeek — {"thinking":{"type":"disabled"}}, у OpenAI — {"reasoning_effort":"low"}; сверяйте с документацией своего провайдера. Модель, сообщения и формат ответа задаёт модуль — их здесь не перекрыть. Пусто — ничего не добавляется.';
$MESS['shef.toolsai_TAB_API_asrprice'] = 'Цена минуты распознавания';
$MESS['shef.toolsai_TAB_API_llmpricein'] = 'Цена 1 млн входных токенов';
$MESS['shef.toolsai_TAB_API_llmpriceout'] = 'Цена 1 млн выходных токенов';
$MESS['shef.toolsai_TAB_API_price_descr'] = 'В валюте квоты, до 6 знаков после запятой. Пусто — 0: расход считается в единицах, но не в деньгах.';
$MESS['shef.toolsai_TAB_API_timeout'] = 'Таймаут запроса к провайдеру, секунд';

$MESS['shef.toolsai_TAB_DEAL_NAME'] = 'Анализ сделок';
$MESS['shef.toolsai_TAB_DEAL_TITLE'] = 'Агент «пора звать старшего»';
$MESS['shef.toolsai_TAB_DEAL_enabled'] = 'Включить анализ';
$MESS['shef.toolsai_TAB_DEAL_enabled_descr'] = 'Агент — главный потребитель квоты. Начинайте с одного профиля и небольшого лимита.';
$MESS['shef.toolsai_TAB_DEAL_Profiles'] = 'Направления, типы клиента, промпты, пороги риска и старшие — на странице <a href="#URL#">ИИ: профили анализа сделок</a>. Сделку без подходящего профиля агент не анализирует.';
$MESS['shef.toolsai_TAB_DEAL_maxperrun'] = 'Анализов за прогон, не больше';

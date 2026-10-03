<?php
$MESS['SH_TOOLSAI_PROFILES_ACCESS_DENIED'] = 'Доступ запрещён';
$MESS['SH_TOOLSAI_PROFILES_NO_MODULE'] = 'Нужны модули shef.toolsai и crm';
$MESS['SH_TOOLSAI_PROFILES_TITLE'] = 'ИИ: профили анализа сделок';
$MESS['SH_TOOLSAI_PROFILES_INTRO'] = 'Профиль говорит, какие сделки анализировать (направление, тип клиента), с каким промптом и что делать по риску. Сделку без подходящего профиля агент не анализирует. Если подходят несколько: профиль с типом клиента главнее профиля «любой», дальше — меньшая сортировка, меньший ID.';
$MESS['SH_TOOLSAI_PROFILES_F_TITLE'] = 'Название';
$MESS['SH_TOOLSAI_PROFILES_F_IS_ENABLED'] = 'Включён';
$MESS['SH_TOOLSAI_PROFILES_F_SORT'] = 'Сортировка';
$MESS['SH_TOOLSAI_PROFILES_F_CATEGORY_ID'] = 'Направление';
$MESS['SH_TOOLSAI_PROFILES_F_CLIENT_TYPES'] = 'Тип клиента';
$MESS['SH_TOOLSAI_PROFILES_F_CLIENT_TYPES_DESCR'] = 'Не отмечено ни одного — любой клиент, в том числе сделки, где тип не определился.';
$MESS['SH_TOOLSAI_PROFILES_CLIENT_HELP'] = 'Как считается тип. Берётся компания сделки, нет компании — контакт. Нет ни того ни другого — тип не определён, и сделке подходят только профили без отмеченных типов.
• Вернувшийся покупатель — у клиента есть хотя бы одна успешная сделка.
• Повторное обращение — успешных сделок нет, но есть проваленная.
• Новый — клиент создан меньше часа назад. В CRM с лидами контакт и компания новыми не бывают вовсе, а сделку анализируют через дни после создания — для анализа сделок почти не встречается.
• В работе — все остальные.';
$MESS['SH_TOOLSAI_PROFILES_F_IDLE_DAYS'] = 'Сделка зависла, если дел не было, дней';
$MESS['SH_TOOLSAI_PROFILES_F_REANALYZE_DAYS'] = 'Повторный анализ и повторные дела не чаще, дней';
$MESS['SH_TOOLSAI_PROFILES_F_LOW_BORDER'] = 'Нижняя граница риска, 0-100';
$MESS['SH_TOOLSAI_PROFILES_F_LOW_BORDER_DESCR'] = 'С неё — дело ответственному, если у сделки нет запланированных дел.';
$MESS['SH_TOOLSAI_PROFILES_F_HIGH_BORDER'] = 'Верхняя граница риска, 0-100';
$MESS['SH_TOOLSAI_PROFILES_F_HIGH_BORDER_DESCR'] = 'С неё, если ИИ считает, что нужен старший, — комментарий в таймлайне и дело старшему. Не ниже нижней.';
$MESS['SH_TOOLSAI_PROFILES_F_SENIOR_ID'] = 'Старший (ID пользователя)';
$MESS['SH_TOOLSAI_PROFILES_F_SENIOR_ID_DESCR'] = 'Пусто — при высоком риске только комментарий в таймлайне.';
$MESS['SH_TOOLSAI_PROFILES_F_PROMPT'] = 'Промпт';
$MESS['SH_TOOLSAI_PROFILES_F_PROMPT_DESCR'] = 'Свой системный промпт целиком. Пусто — общий (показан серым). Форму ответа (JSON) модуль добавляет сам.';
$MESS['SH_TOOLSAI_PROFILES_COL_SCALE'] = 'Шкала';
$MESS['SH_TOOLSAI_PROFILES_SCALE'] = 'менеджер от #LOW#%, старший от #HIGH#%; зависла через #IDLE# дн.; повтор через #REANALYZE# дн.';
$MESS['SH_TOOLSAI_PROFILES_CLIENT_ANY'] = 'любой';
$MESS['SH_TOOLSAI_PROFILES_CLIENT_1'] = 'Новый (для сделок почти не встречается)';
$MESS['SH_TOOLSAI_PROFILES_CLIENT_2'] = 'В работе';
$MESS['SH_TOOLSAI_PROFILES_CLIENT_3'] = 'Повторное обращение';
$MESS['SH_TOOLSAI_PROFILES_CLIENT_4'] = 'Вернувшийся покупатель';
$MESS['SH_TOOLSAI_PROFILES_PROMPT_COMMON'] = 'общий';
$MESS['SH_TOOLSAI_PROFILES_PROMPT_OWN'] = 'свой';
$MESS['SH_TOOLSAI_PROFILES_YES'] = 'да';
$MESS['SH_TOOLSAI_PROFILES_NO'] = 'нет';
$MESS['SH_TOOLSAI_PROFILES_ADD'] = 'Добавить профиль';
$MESS['SH_TOOLSAI_PROFILES_SAVE'] = 'Сохранить';
$MESS['SH_TOOLSAI_PROFILES_CANCEL'] = 'Отмена';
$MESS['SH_TOOLSAI_PROFILES_DELETE'] = 'Удалить';
$MESS['SH_TOOLSAI_PROFILES_DELETE_CONFIRM'] = 'Удалить профиль? Сделки его направления перестанут анализироваться, если другого профиля нет.';
$MESS['SH_TOOLSAI_PROFILES_SAVED'] = 'Профиль сохранён';
$MESS['SH_TOOLSAI_PROFILES_DELETED'] = 'Профиль удалён';
$MESS['SH_TOOLSAI_PROFILES_ERRORS'] = 'Профиль не сохранён';
$MESS['SH_TOOLSAI_PROFILES_ERROR_TITLE'] = 'Не задано название';
$MESS['SH_TOOLSAI_PROFILES_ERROR_SORT'] = 'Сортировка — целое число';
$MESS['SH_TOOLSAI_PROFILES_ERROR_CATEGORY_ID'] = 'Направление не разобралось';
$MESS['SH_TOOLSAI_PROFILES_ERROR_IDLE_DAYS'] = 'Дни «зависла» — целое число 0-365';
$MESS['SH_TOOLSAI_PROFILES_ERROR_REANALYZE_DAYS'] = 'Дни повтора — целое число 1-365';
$MESS['SH_TOOLSAI_PROFILES_ERROR_LOW_BORDER'] = 'Нижняя граница — целое число 0-100';
$MESS['SH_TOOLSAI_PROFILES_ERROR_HIGH_BORDER'] = 'Верхняя граница — целое число 0-100, не ниже нижней';
$MESS['SH_TOOLSAI_PROFILES_ERROR_SENIOR_ID'] = 'Старший — ID пользователя числом';

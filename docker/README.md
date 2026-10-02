# Стенд: коробка Битрикс24 в Docker

Для приёмки shef.toolsai, не для работы. Пароли простые, отладка включена.
Процедура целиком, с этапами и бланком отчёта, —
[docs/agent-docker-test.md](../docs/agent-docker-test.md).

## Что внутри

| сервис | что | зачем |
|---|---|---|
| `portal` | apache + mod_php 8.2, ffmpeg, mysql-client | как BitrixVM (nginx -> apache): у mod_php нет `fastcgi_finish_request`, и эндпоинт обязан отдать 202 быстро без него |
| `db` | MySQL 8.0, `sql_mode` пуст, utf8mb4 | режим, который требует Битрикс |
| `mock-openai` | [mock-openai/router.php](mock-openai/router.php) на `php -S` | OpenAI-совместимое API без денег: распознавание, чат, JSON Schema, принудительные сбои |

Каталоги:

| на хосте | в `portal` | что |
|---|---|---|
| `docker/www` | `/var/www/html` | корень сайта — коробка |
| `docker/sh_log` | `/var/www/sh_log` | логи вне корня сайта: shef.problems, `crm-ai.log`, ошибки PHP |
| репозиторий | `/opt/shef.toolsai` (только чтение) | исходники модуля |
| `docker/stand` | `/opt/stand` (только чтение) | скрипты стенда |

Внутри сети портал зовёт себя по имени **`portal`**: `http://portal` — это
«внешний адрес портала» стенда. С хоста — `http://localhost:8080`, mock —
`http://localhost:8000`.

Сеть стенда — `198.18.0.0/24`, а не подсеть Docker по умолчанию: ядро ai
26.1100 ходит на адрес движка только по публичному IP, а `172.16/12`
приватная — движок не зарегистрируется (`docker-compose.yml`, блок
`networks`). Подсеть занята (другой compose-проект, VPN или прокси с
фейковыми IP из `198.18/15`) — «Pool overlaps»: смените на соседнюю
`198.19.x.0/24`. REST коробки может требовать HTTPS: `test-call.sh` по
`http://` получит «Https required.» — тогда звонок создаётся по `https://`
или из PHP на портале; что сделали — в отчёт.

## Запуск

```bash
cd docker
docker compose up -d --build
# развернуть коробку в docker/www: restore.php с резервной копией
# или bitrixsetup.php — docs/agent-docker-test.md, этап 0
```

БД для мастера установки: хост `db`, база `bitrix`, пользователь `bitrix`,
пароль `bitrix`.

## Скрипты стенда

```bash
# настроить модуль и прогнать «Проверить и включить»
docker compose exec -u www-data portal php /opt/stand/configure.php
docker compose exec -u www-data portal env PROVIDER=openai php /opt/stand/configure.php
docker compose exec -u www-data portal env PROVIDER=openai QUOTA=0.000001 php /opt/stand/configure.php

# входящий вебхук администратора (crm, telephony, user)
docker compose exec -u www-data portal php /opt/stand/webhook.php

# входящий звонок с записью: лид или сделка
docker compose exec portal /opt/stand/test-call.sh http://portal/rest/1/<пароль>/ lead
docker compose exec portal /opt/stand/test-call.sh http://portal/rest/1/<пароль>/ deal

# mock-openai
curl http://localhost:8000/__stats
curl http://localhost:8000/__reset
curl -X POST 'http://localhost:8000/__fail?status=429&times=1'
MOCK_API_KEY=test docker compose up -d mock-openai     # требовать ключ
```

## Убрать

```bash
docker compose down -v && rm -rf www sh_log
```

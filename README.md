# Marketplace Account Automation Service

Первая рабочая версия сервиса передаёт готовые Epic проекта `MARAUT` из Jira в 1С через Kafka и сохраняет RAW-ответ 1С. Сервис сознательно ограничен цепочкой:

```text
Jira MARAUT Epic → Symfony service → Kafka → 1С → Kafka → Symfony service
```

## Архитектура

Scheduler раз в `JIRA_POLL_INTERVAL` отправляет `CheckJiraIssuesMessage` в Symfony Messenger. Handler получает Epic по JQL, mapper переводит Jira fields во внутренний `MarketplaceAccountData`, Validator проверяет обязательные данные, а producer публикует JSON. Состояние операции доступно только через `ProcessingStateRepository`; текущая реализация использует Redis и может быть заменена PostgreSQL без изменения processor.

Consumer читает response topic с выключенным auto commit. Он сохраняет исходный payload и переводит операцию в `RECEIVED_RAW`, после чего подтверждает offset. Если correlation определить или связать невозможно, offset не подтверждается.

## Запуск

1. Скопировать `.env.example` в `.env` и заполнить настройки.
2. Оставить `PROCESSING_ENABLED=0`, запустить ручную проверку и проверить JQL/логи: `docker compose run --rm app php bin/console app:jira:process`.
3. После проверки выборки включить `PROCESSING_ENABLED=1`.
4. С внешним Kafka: `docker compose up --build app scheduler kafka-consumer redis`.
5. С локальным Kafka: `docker compose --profile local-kafka up --build`.

Ручной polling ставит то же сообщение, что Scheduler. Worker Jira: `php bin/console messenger:consume async`. Scheduler: `php bin/console messenger:consume scheduler_jira`. Consumer 1С: `php bin/console app:kafka:consume-results`.

Проверки: `composer validate --strict`, `composer install`, `vendor/bin/phpunit`, `php bin/console lint:container`.

## Конфигурация

Все параметры перечислены в `.env.example`. Секреты в репозиторий не добавляются. `JIRA_FIELD_MAPPING` — единственное место соответствия бизнес-полей реальным Jira IDs. Пустой `JIRA_READY_STATUS` останавливает поиск. `KAFKA_RESPONSE_CORRELATION_PATH` задаёт dot-path correlation ID в будущем подтверждённом JSON ответа.

Поддерживается Jira Bearer authentication (`JIRA_AUTH_TYPE=bearer`) и Basic (`basic`), как в PoC. Kafka поддерживает `security.protocol` и SASL-настройки librdkafka; для plaintext секреты не устанавливаются.

## Идемпотентность и Redis

Redis хранит два индекса состояния — по Jira key и correlation ID. Состояние содержит Jira ID/key, correlation ID, SHA-256 payload, status, timestamps, RAW response и error. Статусы `PUBLISHING`, `WAITING_1C` и `RECEIVED_RAW` запрещают повторный CREATE. `VALIDATION_FAILED` допускает повтор после исправления Epic. Изменения уже отправленного Epic намеренно игнорируются до отдельного UPDATE workflow.

Kafka key первой версии — стабильный Jira issue key. Это удобно для партиционирования одного Epic, но правило должно быть подтверждено владельцем корпоративной шины.

## Ошибки

Ошибки данных сохраняются как `VALIDATION_FAILED`, Kafka не вызывается, а в Jira добавляется понятный комментарий. Jira HTTP/auth/malformed response и Kafka connection/publish/consume ошибки остаются техническими исключениями и не маскируются под бизнес-валидацию. Секреты и полный Jira JSON сервис не логирует.

## Ограничения первой версии

- нет UI, RPA, CRM, Partners, фидогенератора и полного workflow;
- нет PostgreSQL и UPDATE для ранее отправленного Epic;
- polling получает максимум `JIRA_MAX_RESULTS`; high-load pagination оставлена следующей итерации;
- response 1С не интерпретируется до появления подтверждённой схемы;
- `PROCESSING_ENABLED=0` по умолчанию защищает первый production-запуск от отправки исторических Epic.

## Открытые вопросы перед production

1. Получить реальный MARAUT Epic JSON и IDs пяти полей — без них validation всегда остановит отправку; сейчас mapping пуст и конфигурируем; блокирует production-запуск, не deployment.
2. Подтвердить точный Ready status — он нужен безопасному JQL; сейчас пустое значение останавливает polling; блокирует production-запуск.
3. Утвердить request schema 1С и имя correlation field — сейчас используется явно помеченный минимальный контракт из бизнес-полей; блокирует production-запуск.
4. Получить response schema и correlation path — сейчас consumer умеет сохранить RAW только при настраиваемом path; блокирует полноценный production consumer.
5. Подтвердить topics, Jira-key как message key и production consumer group naming — значения настраиваются, dev defaults документированы; не блокирует deployment, блокирует подключение к production Kafka.
6. Определить действие с Epic после отправки и будущий UPDATE workflow — сейчас Epic не меняется, повторный CREATE запрещён; не блокирует первую интеграцию.

## Что взято из PoC

Сохранены Jira REST API v2, Bearer/Basic authentication, схема Scheduler → Messenger, Redis transport и контейнерный способ запуска. Самописный curl/RESP, watermark новых задач, тестовые комментарии и старая связанная с ними модель удалены: новая реализация использует Symfony HttpClient, repository состояния и предметный pipeline.

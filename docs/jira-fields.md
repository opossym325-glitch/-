# Подтверждённые поля Jira MARAUT

Справочник составлен по реальным ответам `/rest/api/2/field` и `/rest/api/2/search?fields=*all`. Это Jira-структура, а не финальный mapping в контракт 1С.

## Системные поля

`id`, `key`, `summary`, `status`, `issuetype`, `project`, `created`, `updated`.

Подтверждённый проект: `MARAUT` (`Создание ЛК МП`). Тип задачи: `Эпика`, ID `10002`.

## Custom fields

| Jira field | Название |
|---|---|
| `customfield_13617` | Маркетплейс |
| `customfield_13618` | Схема работы |
| `customfield_13619` | Название фирмага |
| `customfield_13549` | Склады со стоками |
| `customfield_13550` | География доставок |
| `customfield_13551` | Идентификатор ЛК на МП |
| `customfield_13553` | login |
| `customfield_13554` | password |
| `customfield_13555` | id_mp |
| `customfield_13556` | id_1c |
| `customfield_13557` | name |
| `customfield_13558` | Список SKU |
| `customfield_13559` | Требуется перенос рич-контента |
| `customfield_13560` | Список e-mail адресов |
| `customfield_13561` | Символьный код магазина в CRM |
| `customfield_13562` | Бренд |
| `customfield_13563` | werk_id |
| `customfield_13564` | xru_warehouse |
| `customfield_13565` | mp_warehouse |
| `customfield_13566` | department |
| `customfield_13567` | priceColumn_id |
| `customfield_13568` | priceColumn_name |
| `customfield_13569` | departament_id |
| `customfield_13570` | departament_name |
| `customfield_13571` | accountMeta_id |
| `customfield_13572` | accountMeta_name |
| `customfield_13573` | accountMeta_code |
| `customfield_13574` | accountMeta_counterparty |
| `customfield_13575` | accountMeta_matrixName |
| `customfield_13576` | accountMeta_priceColumnName |
| `customfield_13577` | cashbox_id |
| `customfield_13578` | cashbox_name |
| `customfield_13580` | API-ключ для Partners |
| `customfield_13581` | Комиссия |
| `customfield_13582` | sum_комиссии |
| `customfield_13583` | id_юр.лицо |
| `customfield_13584` | name_юр.лицо |
| `customfield_13585` | code_Группа пользователей |
| `customfield_13586` | name_Группа пользователей |
| `customfield_13587` | Токен API для ЛК |
| `customfield_13588` | Проверка выдачи доступов к ЛК команде |
| `customfield_13589` | Настройка складов и географии |
| `customfield_13592` | werks_department |
| `customfield_13593` | Загрузка контента в ЛК_проверка |
| `customfield_13594` | Количество скопированных карточек |
| `customfield_13595` | Настройка матрицы и цен_проверка |
| `customfield_13596` | Ссылка в admin-mp |
| `customfield_13597` | Настройка фидогенератора (цены, стоки, оффера)_проверка |
| `customfield_13598` | Тестирование и включение_првоерка |
| `customfield_13599` | Включение стоков и запуск_проверка |
| `customfield_13622` | Список SKU добавлен во вложение? |
| `customfield_13721` | ЛК Донор для копирования |
| `customfield_13722` | Тарифы доставки настроены |
| `customfield_13723` | ПВЗ и возвраты настроены |
| `customfield_13724` | Матрица наполнена |
| `customfield_13725` | Цены настроены |
| `customfield_13726` | Репрайсер настроен |
| `customfield_13727` | Фид настроен |
| `customfield_13728` | Цены настроены |
| `customfield_13729` | Стоки настроены |
| `customfield_13730` | Оффера настроены |
| `customfield_13731` | Тестовый заказ успешен |
| `customfield_13732` | Цены проверены |
| `customfield_13733` | Стоки проверены |
| `customfield_13734` | Стоки включены |

Поля с секретами или персональными данными перечислены только по имени и ID. Их реальные значения не хранятся в репозитории и не логируются.

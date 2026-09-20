# BZN Resource Engine

Один инкапсулированный движок серверных ресурсов. Он хранит внутренние пути, каталоги, эскизы и WebP за стабильным API.

## Публичный контракт

- `GET api.php?type=resources&kind=list` — общий список ресурсов.
- `GET api.php?type=resources&kind=resource&id=...` — оптимизированный ресурс, если он подготовлен; иначе оригинал.
- `GET api.php?type=resources&kind=thumbnail&id=...` — сохранённый WebP-эскиз.
- `GET api.php?type=resources&kind=original&id=...` — сохранённый оригинал.

Клиент не строит файловые пути и не знает физическое расположение коллекции.

```html
<script src="https://images.bsns.ru/resource-engine/resource-client.js"></script>
<script>
const resources = new BZNResourceClient({
    endpoint: 'https://images.bsns.ru/resource-engine/api.php',
});
const page = await resources.list({ type: 'resources', tags: ['product'] });
</script>
```

## Единый редактор

`resource-admin.js` можно открыть отдельной страницей, смонтировать в контейнер или вызвать как модальное окно. Один редактор обслуживает все коллекции из конфигурации.

```html
<link rel="stylesheet" href="https://images.bsns.ru/resource-engine/resource-admin.css">
<script src="https://images.bsns.ru/resource-engine/resource-client.js"></script>
<script src="https://images.bsns.ru/resource-engine/resource-admin.js"></script>
<script>
const editor = await BZNResourceEditor.resolve('pictures');
if (editor !== BZNResourceEditor.UNAVAILABLE) await editor.open();
</script>
```

Типы `pictures`, `images`, `masks` и `shadows` сейчас одной константой выбирают общий каталог `resources`. Неизвестный тип возвращает одну константу `BZNResourceEditor.UNAVAILABLE`. Когда появятся разные сценарии, только внутренняя таблица `resolve()` будет заменена маршрутизирующей функцией; вызов интерфейса не изменится.

## Закрытый межсерверный gateway

`service-api.php` использует тот же Resource Engine, но принимает только серверный `X-BZN-Service-Key`. Браузер обращается к same-origin gateway своего приложения; уже этот gateway передаёт логические `type`, `scope`, opaque `owner` и `kind` на ресурсный домен.

- `kind=list` возвращает нормализованный список без physical path и внутреннего collection id.
- `kind=original` возвращает исходный переносимый файл.
- `kind=thumbnail` лениво создаёт и возвращает WebP-эскиз.
- `scope=user` использует только 64-символьный opaque owner; email не принимается.
- Физические demo/user каталоги, service-key file и политики коллекций находятся только в исключённом из Git `service.local.php`.

Инструмент содержит:

- загрузку изображений;
- общие теги при загрузке и редактирование тегов каждого ресурса;
- перестроение каталога;
- отдельные сохранённые WebP-эскизы;
- одиночную и пакетную конвертацию в WebP;
- удаление через защищённую серверную операцию.

Пакетные операции ограничены одновременно `operation_batch_size` и `operation_time_budget_seconds`. Одна кнопка обрабатывает только короткую порцию, показывает остаток и блокирует повторный запуск до ответа. Оригинал при оптимизации не удаляется: WebP хранится отдельной производной версией.

## Авторизация

Серверная проверка обязательна независимо от видимости вызывающей кнопки.

- Для отдельной страницы задаётся `admin.password_hash` в исключённом из Git `config.local.php`.
- Для кабинета можно задать `admin.authorization_callback`. Callback сам проверяет сессию, роль, тариф или подписанный заголовок и возвращает `true/false`.
- Для cross-domain кабинета указывается точный `admin_cors_origin`; значение `*` с административной сессией не используется.

## Развёртывание

1. Скопировать `config.example.php` в исключённый из Git `config.local.php`.
2. Задать один абсолютный `source_directory` для общего каталога `resources`.
3. Задать отдельный `runtime_directory`, доступный PHP на запись.
4. Создать `password_hash()` или подключить `authorization_callback`.
5. Открыть `admin.php` и выполнить только нужные операции.

Старые каталоги и старый PHP масок движок не удаляет и не заменяет автоматически.

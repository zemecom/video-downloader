# Chrome Extension + Native Host

Расширение Chrome с запуском в один клик и native host для локального проекта-загрузчика `YTD` внутри основного репозитория `video-downloader`.

## Структура

- `extension/` - распакованное расширение Chrome на Manifest V3
- `native-host/` - установщик для macOS, шаблон manifest и wrapper для Chrome Native Messaging

## Команды проекта

Из корня проекта можно пользоваться готовыми target'ами:

```bash
make chrome-ext-paths
make chrome-ext-install
make chrome-ext-uninstall
```

Если нужен override `Extension ID`:

```bash
make chrome-ext-install CHROME_EXT_ID=YOUR_EXTENSION_ID
```

## Быстрый старт

1. Открой `chrome://extensions`.
1. Включи `Developer mode`.
1. Загрузи распакованное расширение из каталога:
   - `chrome-ext/extension`
1. Установи native host:

   ```bash
   cd chrome-ext/native-host
   ./install-macos.sh
   ```

1. Открой поддерживаемую страницу по `http://` или `https://` с видео и нажми на иконку расширения.

## Хранение списка недавних файлов

- Список `Недавние` хранится на стороне native host в `sqlite`-базе `chrome-ext/data/native-host-recent-downloads.sqlite`.
- История больше не обрезается по фиксированному количеству записей: сохраняются все доступные загрузки, пока файлы существуют на диске.
- Legacy-история для мягкой миграции по умолчанию тоже лежит рядом: `chrome-ext/data/native-host-recent-downloads.json`.
- При первом запуске после обновления существующая история из legacy JSON мягко мигрирует в новую `sqlite`-базу.
- В popup показываются последние 5 элементов, а кнопка `Все загрузки` открывает отдельную страницу со всей историей.
- Операции `Открыть`, `Finder`, `Удалить` и предпросмотр по-прежнему выполняются через native host как из popup, так и со страницы всех загрузок.
- В popup добавлена кнопка `Очистить` для полного удаления истории загрузок через native host (`ytd:clear-recent-downloads-history`).

## UI расширения

- Общие стили (цветовые переменные) вынесены в `chrome-ext/extension/shared/theme.css`.
- Плашка загрузки (overlay), отображаемая поверх сайтов, поддерживает перетаскивание по экрану (drag-and-drop).
- Всплывающее окно корректно обрабатывает длинные названия файлов с переносом по словам, избегая горизонтального скроллинга и обрезки.

## Extension ID по умолчанию

Когда Chrome загружает расширение с зафиксированным `key` в manifest, extension ID по умолчанию такой:

- `jepbbgfekomejjmhikenbdmoefogifpn`

Если локальный extension ID у тебя отличается, переустанови host с override:

```bash
./install-macos.sh --extension-id=YOUR_EXTENSION_ID
```

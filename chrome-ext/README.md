# Chrome Extension + Native Host

Расширение Chrome с запуском в один клик и native host для локального проекта-загрузчика `YTD` внутри `./ytd`.

## Структура

- `extension/` - распакованное расширение Chrome на Manifest V3
- `native-host/` - установщик для macOS, шаблон manifest и wrapper для Chrome Native Messaging

## Быстрый старт

1. Открой `chrome://extensions`.
2. Включи `Developer mode`.
3. Загрузи распакованное расширение из каталога:
   - `/Users/aleksandrzemlanuhin/Dev/PROJECTS/TOOLS/video-downloader/ytd/chrome-ext/extension`
4. Установи native host:

```bash
cd /Users/aleksandrzemlanuhin/Dev/PROJECTS/TOOLS/video-downloader/ytd/chrome-ext/native-host
./install-macos.sh
```

5. Открой поддерживаемую страницу по `http://` или `https://` с видео и нажми на иконку расширения.

## Extension ID по умолчанию

Когда Chrome загружает расширение с зафиксированным `key` в manifest, extension ID по умолчанию такой:

- `jepbbgfekomejjmhikenbdmoefogifpn`

Если локальный extension ID у тебя отличается, переустанови host с override:

```bash
./install-macos.sh --extension-id=YOUR_EXTENSION_ID
```

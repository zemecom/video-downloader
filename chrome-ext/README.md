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
2. Включи `Developer mode`.
3. Загрузи распакованное расширение из каталога:
   - `chrome-ext/extension`
4. Установи native host:

```bash
cd chrome-ext/native-host
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

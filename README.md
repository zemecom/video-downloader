# YTD PHP

PHP-версия `YTD` - это CLI-обёртка вокруг `yt-dlp` для скачивания видео и плейлистов с маршрутизацией через прокси по доменам.

Она полезна, когда тебе нужен предсказуемый локальный workflow: один CLI-вход для одиночных загрузок и плейлистов, явные правила маршрутизации в `proxy_rules.yaml`, проверка окружения через `doctor` и совместимые runtime-конфиги в `.env`.

## Что делает проект

- Скачивает одиночные видео в автоматическом и ручном режиме.
- Поддерживает playlist flow: чтение плейлиста, выбор элементов, preflight и параллельные загрузки.
- Выбирает `direct`, `local` или `remote` маршрут по `proxy_rules.yaml`.
- Поддерживает dry-run и опциональное отключение SSL-проверки.
- Предупреждает перед перезаписью существующих файлов.
- Пишет подробности необработанных ошибок в `logs/errors.log` внутри runtime-root.
- Содержит Chrome-расширение в `chrome-ext/`, которое умеет запускать локальные загрузки через native host.

## Когда это полезно

- когда для разных доменов нужны разные прокси или прямой выход без прокси;
- когда хочется запускать загрузки через один и тот же CLI, а не собирать команду `yt-dlp` вручную каждый раз;
- когда перед первым запуском хочется проверить окружение и конфиги отдельной командой;
- когда нужен управляемый локальный сценарий для плейлистов, dry-run и ручного выбора форматов.

## Требования

Runtime-зависимости:

- PHP 8.4+
- Composer
- `yt-dlp`
- `ffmpeg` - нужен для merge video/audio потоков, включая режим `--fast`

## Установка

Самый быстрый способ локальной установки — через `Makefile`:

```bash
cd /path/to/video-downloader
make install-deps
make init
```

Что это делает:

- ставит Composer-зависимости проекта;
- создаёт локальные runtime-файлы `.env` и `proxy_rules.yaml` из шаблонов.

Если хочешь добавить shell-alias для удобного запуска `ytd` из любого каталога:

```bash
make install
```

> 📖 **Подробнее:** Альтернативные способы установки (вручную или сборка PHAR-архива) описаны в [Инструкции по установке](docs/installation.md).

## Быстрый старт

После установки сделай следующее:

1. Заполни `.env` (особенно `PROXY_LOCAL`, `DOWNLOAD_DIR_YOUTUBE`) и при необходимости поправь `proxy_rules.yaml`.
1. Проверь окружение:

   ```bash
   make doctor
   ```

1. Скачай видео:

   ```bash
   php bin/ytd https://youtu.be/example
   ```

   *(Или просто `ytd https://youtu.be/example`, если ты добавил alias).*

> 📖 **Подробнее:** Полный список CLI-флагов и примеры использования смотри в [Документации по использованию](docs/usage.md). Описание всех переменных окружения и правил маршрутизации — в [Настройках конфигурации](docs/configuration.md).

## Chrome Extension

Расширение Chrome живёт прямо внутри проекта:

- `chrome-ext/` - общая папка расширения
- `chrome-ext/extension/` - unpacked extension для Chrome
- `chrome-ext/native-host/` - installer и wrapper для Native Messaging

Быстрые команды для установки системной части расширения:

```bash
make chrome-ext-paths
make chrome-ext-install
make chrome-ext-uninstall
```

> 📖 **Подробнее:** О том, как устроена работа с SQLite, как дебажить расширение и как **настроить обход Local Network Privacy в macOS (ytd-proxy)**, читай в [Документации Chrome Extension](docs/chrome-extension.md).

## Документация

Вся детальная техническая информация вынесена в папку `docs/`:

- [Установка (Installation)](docs/installation.md)
- [Конфигурация (Configuration)](docs/configuration.md)
- [Использование (Usage & CLI)](docs/usage.md)
- [Разработка и тестирование (Development)](docs/development.md)
- [Интеграция с Chrome Extension](docs/chrome-extension.md)
- [Архитектура проекта](docs/architecture.md)
- [Внутренние утилиты (Tools)](docs/tools.md)
- [Решение частых проблем (Troubleshooting)](docs/troubleshooting.md)

## Лицензия

Проект распространяется по лицензии MIT. Полный текст смотри в файле `LICENSE`.

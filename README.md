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

Composer-зависимости проекта ставятся через `composer install`:

- Symfony Console, Dotenv, Filesystem, Process и YAML

Dev-зависимости проекта ставятся через `require-dev`:

- PHPUnit
- PHPStan
- PHP CS Fixer
- PHP_CodeSniffer

Опционально:

- Makefile для удобных локальных команд;
- локальный или удалённый прокси, если он нужен в твоей сети;
- Chrome/Chromium и macOS Native Messaging для `chrome-ext`;
- реальные URL для ручных integration-проверок через `TEST_*`.

Node.js и npm (команда `npm install`) нужны только для локального линтера (ESLint) в `chrome-ext/`. Само расширение в `chrome-ext/extension` статическое и не требует сборки.

## Установка

Выбери удобный для себя способ.

### Вариант 1. Быстрая локальная установка через Makefile

```bash
cd /path/to/video-downloader
make install-deps
make init
```

Что это делает:

- ставит Composer-зависимости проекта;
- создаёт локальные runtime-файлы `.env` и `proxy_rules.yaml` из шаблонов.

Если хочешь добавить shell-alias для запуска из любого каталога:

```bash
make install
```

По умолчанию этот target добавляет alias `ytd` в `~/.zshrc`.

Если ты хочешь другой alias, можно передать имя явно:

```bash
make install PHP_ALIAS_NAME=ytd
```

Либо ты можешь добавить алиас вручную, дописав в `~/.zshrc`:

```bash
alias ytd="php /абсолютный/путь/к/video-downloader/bin/ytd"
```

*(Затем выполни `source ~/.zshrc`, чтобы применить изменения)*

### Вариант 2. Ручная установка без Makefile

Сначала установи внешние runtime-зависимости вручную:

- `yt-dlp`
- `ffmpeg`

Затем поставь PHP-зависимости проекта:

```bash
cd /path/to/video-downloader
composer install
cp .env.example .env
cp proxy_rules.example.yaml proxy_rules.yaml
```

Этот путь подойдёт, если тебе удобнее использовать только Composer и обычные shell-команды.

### Вариант 3. Сборка и запуск в виде автономного PHAR-архива

Ты можешь собрать всё приложение в один независимый исполняемый `.phar` файл, который можно переносить и запускать без папки `vendor/` и вызовов `composer install` (хотя внешние зависимости `yt-dlp` и `ffmpeg` всё равно должны быть в системе).

1. Собери PHAR-архив:

   ```bash
   make build
   ```

   В корне проекта появится файл `ytd.phar`.

1. Запусти его локально:

   ```bash
   ./ytd.phar https://youtu.be/example
   ```

1. (Опционально) Сделай его доступным глобально в системе:

   ```bash
   sudo mv ytd.phar /usr/local/bin/ytd
   chmod +x /usr/local/bin/ytd
   ```

   После этого ты сможешь использовать команду `ytd` из любой папки.

> [!NOTE]
> Скомпилированный PHAR-архив всё ещё будет искать файлы конфигурации `.env` и `proxy_rules.yaml`. Он ищет их сначала в текущей рабочей директории, откуда запущен скрипт, а затем в директории, где лежит сам PHAR-файл.

## Быстрый старт

После установки сделай следующее.

1. Убедись, что локальные конфиги существуют.

Если ты уже запускал `make init`, они созданы автоматически. Если нет:

```bash
cp .env.example .env
cp proxy_rules.example.yaml proxy_rules.yaml
```

1. Заполни `.env` и при необходимости поправь `proxy_rules.yaml`.

Минимум, что обычно нужно проверить:

- `PROXY_LOCAL`
- `PROXY_REMOTE`
- `DOWNLOAD_DIR_YOUTUBE`
- `DOWNLOAD_DIR_GENERAL`
- `OUTPUT_FORMAT`
- `CONCURRENT_DOWNLOADS`
- `CONCURRENT_FRAGMENTS`

1. Проверь окружение:

```bash
make doctor
```

или напрямую:

```bash
php bin/ytd --doctor
```

1. Убедись, что команда доступна:

```bash
php bin/ytd --help
```

1. Скачай видео:

```bash
php bin/ytd https://youtu.be/example
```

Если ты уже добавил alias, можно запускать так:

```bash
ytd https://youtu.be/example
```

## Chrome Extension

Расширение Chrome теперь живёт прямо внутри проекта:

- `chrome-ext/` - общая папка расширения
- `chrome-ext/extension/` - unpacked extension для Chrome
- `chrome-ext/native-host/` - installer и wrapper для Native Messaging

Что важно для текущего UX расширения:

- список `Недавние` хранится в `sqlite`-базе native host и переживает reload/update extension
- история загрузок больше не обрезается по количеству записей на стороне native host
- popup по-прежнему показывает только последние 5 записей, но теперь даёт кнопку `Все загрузки` для перехода на отдельную страницу со всей историей и управлением файлами
- default-путь этой базы и legacy JSON теперь лежит рядом с расширением: `chrome-ext/logs/`
- при первом запуске после обновления существующая история из legacy JSON мягко мигрирует в новую `sqlite`

Быстрые команды:

```bash
make chrome-ext-paths
make chrome-ext-install
make chrome-ext-uninstall
```

Если нужно переопределить `Extension ID`:

```bash
make chrome-ext-install CHROME_EXT_ID=YOUR_EXTENSION_ID
```

## Конфигурация

Runtime-конфигурация хранится в:

- `.env`
- `proxy_rules.yaml`

Эти файлы игнорируются Git. В репозитории лежат только шаблоны:

- `.env.example`
- `proxy_rules.example.yaml`

### Переменные окружения

- `PROXY_LOCAL` - прокси для маршрутов с типом `local`
- `PROXY_REMOTE` - прокси для маршрутов с типом `remote`
- `PROXY_RULES_FILE` - путь к YAML-файлу маршрутизации; относительные пути считаются от автоматически найденного runtime-root
- `DOWNLOAD_DIR_YOUTUBE` - каталог для загрузок с YouTube
- `DOWNLOAD_DIR_GENERAL` - каталог для загрузок с прочих сайтов
- `OUTPUT_FORMAT` - итоговый контейнер: `mkv` или `mp4`
- `CONCURRENT_DOWNLOADS` - сколько роликов из плейлиста качать одновременно по умолчанию
- `CONCURRENT_FRAGMENTS` - сколько фрагментов одного файла качать одновременно через `yt-dlp`
- `YTD_PROGRESS_DELTA` - интервал обновления прогресса `yt-dlp` в секундах
- `YTD_PROGRESS_NEWLINE` - печатать прогресс построчно (`1`, `true`, `yes`)
- `TEST_URL_DIRECT` - необязательный URL для ручного direct integration-теста
- `TEST_URL_REMOTE` - необязательный URL для ручного remote integration-теста
- `TEST_URL_LOCAL` - необязательный URL для ручного local integration-теста
- `TEST_PLAYLIST_LOCAL` - необязательный URL плейлиста для ручной проверки playlist flow через local-route
- `YTD_PROJECT_ROOT` - явный runtime-root, если нужно переопределить автоматическое определение каталога проекта
- `YTD_ERROR_LOG_FILE` - кастомный путь для лога ошибок
- `YTD_NATIVE_HOST_RECENT_DOWNLOADS_DB_FILE` - кастомный путь для `sqlite`-базы recent downloads native host

### Правила маршрутизации

`proxy_rules.yaml` использует три фиксированные секции:

- `direct`
- `local`
- `remote`

Поведение правил:

- приоритет секций фиксирован: `direct` > `local` > `remote`
- поддерживаются точные хосты
- поддерживаются wildcard-хосты вида `*.`
- `*` можно использовать как глобальный fallback
- правило с суффиксом `!` получает приоритет над обычными правилами

Пример:

```yaml
routing:
  direct:
    - "*.ru"

  local:
    - "*.youtube.com"
    - "*.youtu.be"

  remote:
    - "*"
```

## Использование

Базовая команда:

```bash
php bin/ytd URL
```

Поддерживаются и другие способы запуска:

```bash
./bin/ytd URL
php ytd.php URL
```

Если установлен alias:

```bash
ytd URL
```

### CLI-флаги

| Флаг | Short | Описание |
| :--- | :--- | :--- |
| `--help` | `-h` | Показать справку |
| `--doctor` | `-dc` | Проверить окружение и конфиг без скачивания |
| `--manual` | `-m` | Показать форматы и выбрать вручную для одиночного видео |
| `--audio` | `-a` | Скачать только аудио в лучшем формате (opus) |
| `--fast` | | Скачать video/audio потоки параллельно и объединить через `ffmpeg` |
| `--quality` | `-Q` | Пресет качества видео: `b/best`, `m/medium`, `l/low` |
| `--dry-run` | `-dr` | Показать preflight-результат без реальной загрузки |
| `--remote` | `-r` | Принудительно использовать удалённый прокси |
| `--no-proxy` | `-np` | Отключить прокси для этого запуска |
| `--insecure` | `-i` | Отключить SSL-проверку сертификатов |
| `--mp4` | | Сохранять итоговый файл как MP4 вместо MKV |
| `--output-format` | | Явно выбрать итоговый контейнер: `mkv` или `mp4` |
| `--download-dir` | | Переопределить папку назначения для текущего запуска |
| `--no-playlist-sizes` | `-nps` | Пропустить предварительную оценку размеров плейлиста |
| `--concurrent-downloads` | `-cd` | Количество параллельных загрузок плейлиста |
| `--concurrent-fragments` | `-cf` | Количество параллельных фрагментов одного файла для `yt-dlp` |
| `--progress-newline` | | Печатать прогресс построчно вместо перерисовки |
| `--no-progress-newline` | | Явно выключить построчный прогресс для текущего запуска |
| `--progress-delta` | | Задать интервал обновления прогресса `yt-dlp` |
| `--proxy` | | Явно указать прокси и перекрыть другие настройки |

Для совместимости поддерживаются legacy short-формы вроде `-dc`, `-dr`, `-np`, `-nps`, `-cd`, `-cf`.

### Примеры

| Сценарий | Команда |
| :--- | :--- |
| Скачать одно видео | `php bin/ytd https://youtu.be/example` |
| Показать форматы и выбрать вручную | `php bin/ytd https://youtu.be/example -m` |
| Скачать только аудио | `php bin/ytd https://youtu.be/example -a` |
| Скачать в среднем качестве | `php bin/ytd https://youtu.be/example -Q m` |
| Скачать быстрее, параллельно загрузив video/audio потоки и объединив их через `ffmpeg` | `php bin/ytd https://youtu.be/example --fast` |
| Принудительно использовать удалённый прокси | `php bin/ytd https://youtu.be/example -r` |
| Полностью отключить прокси | `php bin/ytd https://youtu.be/example -np` |
| Сделать dry-run | `php bin/ytd https://youtu.be/example -dr` |
| Скачать в MP4 | `php bin/ytd https://youtu.be/example --mp4` |
| Скачать в произвольную папку и сделать более редкий прогресс | `php bin/ytd https://youtu.be/example --download-dir ~/Desktop/Test --progress-delta 1.5` |
| Запустить проверку окружения | `php bin/ytd -dc` |

## Документация

Подробности о внутреннем устройстве и инструментах проекта можно найти в папке `docs/`:

- [Архитектура проекта](docs/architecture.md)
- [Инструменты разработки](docs/tools.md)
- [Интеграция с Chrome Extension](docs/chrome-extension.md)
- [Тестирование и проверка качества кода](docs/testing.md)
- [Решение частых проблем (Troubleshooting)](docs/troubleshooting.md)

## Разработка

Полезные команды:

- `make install-deps` — ставит Composer-зависимости.
- `make init` — создаёт runtime-конфиги из шаблонов.
- `make doctor` — проверяет окружение и локальные конфиги.
- `make doctor-smoke` — прогоняет `doctor` на шаблонных конфигах во временном runtime-root.
- `make chrome-ext-paths` — показывает пути к unpacked extension и native host.
- `make chrome-ext-install` — устанавливает native host для Chrome-расширения.
- `make chrome-ext-uninstall` — удаляет manifest native host из профиля Chrome.
- `make clean-logs` — удаляет все файлы логов.
- `make test` — запускает unit-тесты.
- `make test-integration` — запускает integration-suite.
- `make lint` — запускает PHP CS Fixer и PHPStan.
- `make lint-fix` — автоматически исправляет проблемы со стилем кода.
- `make check` — удобный локальный минимум: сначала lint, потом unit-тесты.
- `make build` — собирает готовый исполняемый файл `ytd.phar` с помощью Box.

> [!TIP]
> Для локальной отладки CLI используется Xdebug на порту `9003`. Текущая конфигурация рассчитана на ручной запуск по триггеру (`xdebug.start_with_request=trigger`), поэтому для отладки запускай команды так: `XDEBUG_TRIGGER=1 php bin/ytd --help`, а в PhpStorm заранее включай `Start Listening for PHP Debug Connections`.
> [!NOTE]
> В проекте установлен **GrumPHP**. При попытке сделать Git-коммит он автоматически запустит линтеры, статический анализ и тесты. Если что-то упадет — коммит будет отменен. Это гарантирует чистоту кода в репозитории.

Можно запускать и напрямую через Composer:

```bash
composer test
composer test-integration
composer lint
composer lint-fix
composer check
```

## Integration-тесты

Сейчас integration-suite остаётся ручной и облегчённой.

Практически это значит:

- `composer test-integration` и `make test-integration` существуют как отдельный контур;
- для реальных сетевых сценариев тебе всё равно нужно заполнить `TEST_*` в `.env`;
- часть integration-логики пока служит каркасом и не заменяет полноценные ручные прогоны на рабочей сети.

Если хочешь прогонять реальные сценарии, заполни:

- `TEST_URL_DIRECT`
- `TEST_URL_REMOTE`
- `TEST_URL_LOCAL`
- `TEST_PLAYLIST_LOCAL`

## Логи и диагностика

- `doctor` проверяет наличие `yt-dlp`, `ffmpeg`, `.env`, `proxy_rules.yaml` и базовую корректность маршрутизации.
- `--fast` использует `ffmpeg` напрямую и может откатиться к обычной загрузке, если источник не отдаёт подходящие отдельные video/audio потоки.
- Необработанные ошибки пишутся в `logs/errors.log` внутри runtime-root.
- Если задан `YTD_ERROR_LOG_FILE`, лог можно вынести в другой путь.

## Ограничения

- проект зависит от внешних бинарников `yt-dlp` и `ffmpeg`;
- `--fast` в первой версии работает только для одиночных видео, без `--audio`, `--manual` и playlist flow;
- playlist flow интерактивный и рассчитан в первую очередь на локальный терминал;
- реальное поведение загрузки зависит от доступности сайтов, текущих прокси и политики самих источников;
- integration-покрытие в PHP-версии пока менее развито, чем исторически было в Python-версии.

## Лицензия

Проект распространяется по лицензии MIT. Полный текст смотри в файле `LICENSE`.

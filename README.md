# YTD PHP

PHP-версия `YTD` - это CLI-обёртка вокруг `yt-dlp` для скачивания видео и плейлистов с маршрутизацией через прокси по доменам.

Она полезна, когда тебе нужен предсказуемый локальный workflow: один CLI-вход для одиночных загрузок и плейлистов, явные правила маршрутизации в `proxy_rules.yaml`, проверка окружения через `doctor` и совместимые runtime-конфиги в `.env`.

Каталог [../python](/Users/aleksandrzemlanuhin/Dev/PROJECTS/TOOLS/video-downloader/python) остаётся старой Python-реализацией. Каталог [./](/Users/aleksandrzemlanuhin/Dev/PROJECTS/TOOLS/video-downloader/php) внутри `php` — текущая основная реализация на PHP 8.5.

## Что делает проект

- Скачивает одиночные видео в автоматическом и ручном режиме.
- Поддерживает playlist flow: чтение плейлиста, выбор элементов, preflight и параллельные загрузки.
- Выбирает `direct`, `local` или `remote` маршрут по `proxy_rules.yaml`.
- Поддерживает dry-run и опциональное отключение SSL-проверки.
- Предупреждает перед перезаписью существующих файлов.
- Пишет подробности необработанных ошибок в `logs/errors.log` внутри runtime-root.

## Когда это полезно

- когда для разных доменов нужны разные прокси или прямой выход без прокси;
- когда хочется запускать загрузки через один и тот же CLI, а не собирать команду `yt-dlp` вручную каждый раз;
- когда перед первым запуском хочется проверить окружение и конфиги отдельной командой;
- когда нужен управляемый локальный сценарий для плейлистов, dry-run и ручного выбора форматов.

## Требования

Обязательно:

- PHP 8.5+
- Composer
- `yt-dlp`
- `ffmpeg`

Опционально:

- локальный или удалённый прокси, если он нужен в твоей сети;
- реальные URL для ручных integration-проверок через `TEST_*`.

## Установка

Выбери удобный для себя способ.

### Вариант 1. Быстрая локальная установка через Makefile

```bash
cd php
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

По умолчанию этот target добавляет alias `ytdphp` в `~/.zshrc`.

Если ты хочешь другой alias, можно передать имя явно:

```bash
make install PHP_ALIAS_NAME=ytd
```

### Вариант 2. Ручная установка без Makefile

Сначала установи внешние runtime-зависимости вручную:

- `yt-dlp`
- `ffmpeg`

Затем поставь PHP-зависимости проекта:

```bash
cd php
composer install
cp .env.example .env
cp proxy_rules.example.yaml proxy_rules.yaml
```

Этот путь подойдёт, если тебе удобнее использовать только Composer и обычные shell-команды.

## Быстрый старт

После установки сделай следующее.

1. Убедись, что локальные конфиги существуют.

Если ты уже запускал `make init`, они созданы автоматически. Если нет:

```bash
cp .env.example .env
cp proxy_rules.example.yaml proxy_rules.yaml
```

2. Заполни `.env` и при необходимости поправь `proxy_rules.yaml`.

Минимум, что обычно нужно проверить:

- `PROXY_LOCAL`
- `PROXY_REMOTE`
- `DOWNLOAD_DIR_YOUTUBE`
- `DOWNLOAD_DIR_GENERAL`
- `OUTPUT_FORMAT`

3. Проверь окружение:

```bash
make doctor
```

или напрямую:

```bash
php bin/ytd --doctor
```

4. Убедись, что команда доступна:

```bash
php bin/ytd --help
```

5. Скачай видео:

```bash
php bin/ytd https://youtu.be/example
```

Если ты уже добавил alias, можно запускать так:

```bash
ytdphp https://youtu.be/example
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
- `TEST_URL_DIRECT` - необязательный URL для ручного direct integration-теста
- `TEST_URL_REMOTE` - необязательный URL для ручного remote integration-теста
- `TEST_URL_LOCAL` - необязательный URL для ручного local integration-теста
- `TEST_PLAYLIST_LOCAL` - необязательный URL плейлиста для ручной проверки playlist flow через local-route
- `YTD_PROJECT_ROOT` - явный runtime-root, если нужно переопределить автоматическое определение каталога проекта
- `YTD_ERROR_LOG_FILE` - кастомный путь для лога ошибок

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
ytdphp URL
```

### CLI-флаги

| Флаг | Short | Описание |
| :--- | :--- | :--- |
| `--help` | `-h` | Показать справку |
| `--doctor` | `-dc` | Проверить окружение и конфиг без скачивания |
| `--manual` | `-m` | Показать форматы и выбрать вручную для одиночного видео |
| `--dry-run` | `-dr` | Показать preflight-результат без реальной загрузки |
| `--remote` | `-r` | Принудительно использовать удалённый прокси |
| `--no-proxy` | `-np` | Отключить прокси для этого запуска |
| `--insecure` | `-i` | Отключить SSL-проверку сертификатов |
| `--mp4` |  | Сохранять итоговый файл как MP4 вместо MKV |
| `--no-playlist-sizes` | `-nps` | Пропустить предварительную оценку размеров плейлиста |
| `--concurrent-downloads` | `-cd` | Количество параллельных загрузок плейлиста |
| `--proxy` |  | Явно указать прокси и перекрыть другие настройки |

Для совместимости поддерживаются legacy short-формы вроде `-dc`, `-dr`, `-np`, `-nps`, `-cd`.

### Примеры

Скачать одно видео:

```bash
php bin/ytd https://youtu.be/example
```

Показать форматы и выбрать вручную:

```bash
php bin/ytd https://youtu.be/example -m
```

Принудительно использовать удалённый прокси:

```bash
php bin/ytd https://youtu.be/example -r
```

Полностью отключить прокси:

```bash
php bin/ytd https://youtu.be/example -np
```

Сделать dry-run:

```bash
php bin/ytd https://youtu.be/example -dr
```

Скачать в MP4:

```bash
php bin/ytd https://youtu.be/example --mp4
```

Запустить проверку окружения:

```bash
php bin/ytd -dc
```

## Разработка

Полезные команды:

- `make install-deps` — ставит Composer-зависимости.
- `make init` — создаёт runtime-конфиги из шаблонов.
- `make doctor` — проверяет окружение и локальные конфиги.
- `make doctor-smoke` — прогоняет `doctor` на шаблонных конфигах во временном runtime-root.
- `make test` — запускает unit-тесты.
- `make test-integration` — запускает integration-suite.
- `make lint` — запускает PHP CS Fixer и PHPStan.
- `make check` — удобный локальный минимум: сначала lint, потом unit-тесты.

Можно запускать и напрямую через Composer:

```bash
composer test
composer test-integration
composer lint
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
- Необработанные ошибки пишутся в `logs/errors.log` внутри runtime-root.
- Если задан `YTD_ERROR_LOG_FILE`, лог можно вынести в другой путь.

## Ограничения

- проект зависит от внешних бинарников `yt-dlp` и `ffmpeg`;
- playlist flow интерактивный и рассчитан в первую очередь на локальный терминал;
- реальное поведение загрузки зависит от доступности сайтов, текущих прокси и политики самих источников;
- integration-покрытие в PHP-версии пока менее развито, чем исторически было в Python-версии.

## Лицензия

Проект распространяется по лицензии MIT. Полный текст смотри в файле `LICENSE`.

# YTD PHP

PHP-версия CLI-обёртки над `yt-dlp` с маршрутизацией через `direct`, `local` и `remote` прокси по доменам.

Каталог `../python` остаётся референсной Python-реализацией. Каталог `./` внутри `php` — новая актуальная CLI-версия на PHP 8.5.

## Требования

- PHP 8.5+
- Composer
- `yt-dlp`
- `ffmpeg`

## Установка

```bash
cd php
make install-deps
cp .env.example .env
cp proxy_rules.example.yaml proxy_rules.yaml
```

Если хочешь добавить shell-alias для запуска из любого каталога:

```bash
make install
```

По умолчанию этот target добавляет alias `ytdphp`.

## Использование

Основной запуск:

```bash
php bin/ytd --help
php bin/ytd https://youtu.be/example
```

Прямой запуск shim:

```bash
php ytd.php https://youtu.be/example
```

Поддерживаемые флаги:

- `--proxy`
- `--no-proxy` / `-np`
- `--remote` / `-r`
- `--insecure` / `-i`
- `--manual` / `-m`
- `--dry-run` / `-dr`
- `--mp4`
- `--no-playlist-sizes` / `-nps`
- `--concurrent-downloads` / `-cd`
- `--doctor` / `-dc`

## Make targets

- `make install`
- `make install-deps`
- `make init`
- `make doctor`
- `make doctor-smoke`
- `make test`
- `make test-integration`
- `make lint`
- `make check`

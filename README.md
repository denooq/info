# Подготовка декларации ИМ 40

Текущий профиль `im40-kz-graphs` ведётся по `openspec/changes/customs-declaration-mvp`: три страницы — выбор официальных видов/вложения, 54 графы ДТ, расчёт. Mistral выполняет OCR и сопоставление, backend проверяет источники и считает суммы. PDF/XML и четвёртая страница отключены. Границы: ДТС-1 метода 1, без БД, регистрации, истории, отправки в Keden и электронной подписи. [Сценарий и допущения](docs/graphs-mvp.md), [полные справочники](docs/classifiers.md), [матрица книги](docs/graph-matrix.md). Фактическая приёмка этой версии на PS.kz XS ещё не выполнена.

## Локальный запуск

Нужны PHP 8.3 с расширениями curl, fileinfo, gd, mbstring, openssl, dom/libxml, xmlreader/xmlwriter, zip/zlib; Composer 2; Node.js для сборки frontend. Node не требуется для работы собранного приложения на хостинге. Проверенные локальные версии: PHP 8.3.36 и Node.js 26.13.0.

```powershell
composer install
npm ci
Copy-Item config/local.example.php config/local.php
php bin/check-platform.php
npm run build
php -S 127.0.0.1:8080 -t public public/router.php
```

Для локального HTTP в `config/local.php` установить secure_cookie=false. Для размещения через HTTPS оставить true. Ключ задаётся только в закрытом config/local.php либо переменной окружения MISTRAL_API_KEY. Без ключа можно проверять сборку, правила и тестовые сценарии; реальное распознавание потребует ключа позже. Раскрытые в переписке значения ключей не используются.

При переносимом PHP/Composer из `.local-tools` запускать соответствующие exe/phar полными путями вместо глобальных php/composer. Эти инструменты и их кэши исключены из Git. Установка глобальных программ не является частью запуска приложения на XS.

Открыть http://127.0.0.1:8080. Для разработки frontend отдельно: `npm run dev`; Vite перенаправляет `/api` к PHP на 8080. Проверка API: `/api/health`.

Во втором терминале выполнять `php bin/worker.php` для обработки очереди; команда завершает ограниченный проход и не является постоянным сервером. Пока локальный планировщик не настроен, повторять её до завершения обработки. Очистка: `php bin/cleanup.php`. На Plesk обе команды запускаются заданиями PHP по расписанию.

## Проверки

```powershell
node --test tests/*.test.mjs
php tests/php/run.php
node --test --test-concurrency=1 tests/graphs.browser.mjs
composer validate --no-check-publish
npm run build
php -d memory_limit=192M bin/resource-probe.php
```

На Windows с ограничением дочерних процессов тестам Node может потребоваться разрешение среды выполнения. Браузерный прогон использует установленный Edge и временный локальный PHP-сервер. Тесты не обращаются к Mistral и не требуют API-ключа. Реальные документы используются для проверки чтения/ячеек и описаний источников; зарегистрированные ДТ не входят в AI-входы. Прежние browser-файлы четырёхстраничного профиля — исторические сценарии; активная приёмка текущего UI находится в `graphs.browser.mjs`.

## Размещение файлов

- `public/` — единственный document root: PHP entrypoint и собранные статические файлы `assets-app/`.
- `src/`, `resources/`, `vendor/` — закрытый код, правила и production-зависимости вне публичного корня.
- `config/local.php` — закрытые настройки, исключённые из Git; альтернативный путь задаётся CUSTOMS_CONFIG_FILE.
- Временные сессии — CUSTOMS_RUNTIME_DIR или runtime_dir из конфигурации; по умолчанию закрытый подкаталог системного temp. Он не должен находиться в public или заменяться Git-деплоем.
- `bin/` — служебные PHP-команды для проверки среды и будущих заданий Plesk; они не публикуются как открытые HTTP-операции.
- `reference/`, `tests/`, `openspec/`, `.local-tools/` — исследовательские материалы и разработка, не публичная часть релиза.

Детали: [реестр полей](docs/field-registry.md), [проверка и расчёт](docs/review-and-calculation.md), [дата составления и курсы НБК](docs/exchange-rates.md), [профили XML и ограничения](docs/xml-profiles.md), [ресурсный прототип](docs/resource-probe.md), [инструкция PS.kz](docs/ps-kz-xs.md). Готовый комплект Git/Plesk создаётся командой `php bin/build-release.php`; в архив входят frontend/vendor, но не ключи и reference. Обычный исходный checkout целиком не публиковать.

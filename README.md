# Crypto Trading Platform

**Разработчик:** KLIMIATA  
**Назначение:** Учебный симулятор криптовалютной биржи (Институт Следственного комитета)

Торговая платформа для клиентов **CRM-системы** (call-центр): клиент регистрируется сам либо входит по уже выданным учётным данным, открывает позиции Buy/Sell по криптовалютам, видит график за последние 7 дней, пополняет счёт и оформляет вывод через оператора. Плечо — 1x (залог = стоимость позиции). Платёжного шлюза нет: пополнение и вывод оформляет оператор CRM, поэтому баланс меняется только действиями оператора и сделками клиента.

* **Стек:** Laravel 12, PHP 8.3+, MySQL, Chart.js 4 (CDN).
* **Данные рынка:** бесплатный публичный API CoinGecko (`/coins/{id}/market_chart`).
* **База:** общая база с CRM. Приложение создаёт **только** свои таблицы `trading_accounts`, `trading_positions`, `price_history`, `trading_events`, `withdrawal_cards`, `withdrawal_requests`. Единственное исключение — регистрация клиента: она добавляет строки в `users`, `roles`, `persons` и `leads`, потому что клиент CRM и лид в воронке создаются именно там (см. «Регистрация клиента»).

## Как это работает

| Таблица | Назначение |
|---|---|
| `trading_accounts` | баланс (`balance_usd`), по одной записи на `user_id` |
| `trading_positions` | позиции: `symbol`, `side`, `amount`, `entry_price`, `close_price`, `status` |
| `price_history` | тики цен CoinGecko, уникальный индекс `(symbol, recorded_at)` |
| `trading_events` | журнал действий клиента для CRM (`event_type` + JSON `payload`) |

* Открытие позиции списывает `entry_price × amount` с баланса (залог).
* Закрытие возвращает залог и применяет P&L по последней сохранённой цене.
* Убыток не уводит баланс ниже нуля — убыточная позиция «ликвидируется» в 0.
* Все операции идут в `DB::transaction()` с `lockForUpdate()` на счёт и позицию.

## Регистрация клиента

`GET /register` — публичная форма (имя, e-mail, телефон, пароль, согласие с условиями), `POST /register` — создание клиента. Обработчик — `App\Http\Controllers\Auth\RegisterController`, вся запись в CRM — в `App\Services\ClientRegistrationService`.

За один проход, в одной транзакции:

1. `users` — активный пользователь CRM (`status = 1`) с ролью клиента и `view_permission = individual`; пароль хешируется стандартным cast-ом модели, поэтому те же данные подходят для входа в CRM.
2. `roles` — роль клиента: берётся `TRADING_CLIENT_ROLE_ID`, иначе роль `TRADING_CLIENT_ROLE_NAME` (по умолчанию «Trading Client»), при первом обращении создаётся с `permission_type = custom` и **без единого права** — клиент может войти в CRM, но ни один её раздел ему не открыт.
3. `persons` — карточка контакта с e-mail (`emails`) и телефоном (`contact_numbers`).
4. `leads` — лид «<Имя> — регистрация на сайте» в воронке по умолчанию и на её первом этапе (`code = new`), источник — «Сайт» (иначе первый доступный), тип — «Новый бизнес», контакт — созданный `persons`. Владелец лида — сам клиент: CRM читает `leads.user_id` и как владельца торгового счёта (начисление из карточки лида), и как ключ ленты сделок, поэтому лид нельзя передавать оператору — иначе его баланс и история операций указывали бы на чужой счёт.
5. `trading_accounts` — торговый счёт с `TRADING_REGISTRATION_INITIAL_BALANCE` (по умолчанию 0), поэтому новый клиент сразу видит баланс `$0.00` и подсказку пополнить счёт.

После создания клиент автоматически авторизуется и попадает на дашборд с приветствием. Если CRM-схемы нет или роль из `TRADING_CLIENT_ROLE_ID` не найдена, транзакция откатывается и форма показывает ошибку — «полузаписанных» клиентов не остаётся.

Идентификаторы воронки, этапа, источника и типа ищутся в базе, а не зашиты в код: CRM редактируется операторами, и сид-иды в разных окружениях различаются.

Область данных решает, кто увидит лид: администратор и модератор с `global` (или `group`) видят регистрацию сразу, оператор с `individual` — только свои лиды. Если нужно, чтобы лид принадлежал оператору, а счёт оставался за клиентом, CRM понадобится отдельное поле «счёт клиента»: сейчас обе стороны читают один `leads.user_id`.

## Установка

```bash
cd D:\KLIMIATA\PROJECT\crypto-trading

composer install --prefer-source   # см. раздел "Проблемы" ниже
copy .env.example .env
php artisan key:generate
```

Пропишите в `.env` доступ к общей базе CRM:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=krayin_crm
DB_USERNAME=root
DB_PASSWORD=...
```

Затем создайте только таблицы платформы:

```bash
php artisan migrate
```

`migrate` аддитивный: он создаёт только таблицы платформы и пишет историю в отдельную таблицу `trading_migrations`, не смешиваясь с батчами CRM.

### Защита общей базы

`AppServiceProvider` слушает консольные события и **блокирует** `migrate:fresh`, `migrate:refresh`, `migrate:reset` и `db:wipe`, если в базе найдены таблицы CRM (`contacts`, `leads`, `roles`, `activities`). Отключается через `TRADING_PROTECT_SHARED_DB=false` — делать это не нужно.

Тесты используют `DatabaseTransactions`, а не `RefreshDatabase`, поэтому данные CRM не очищаются.

## Загрузка цен

```bash
php artisan trading:fetch-prices            # все инструменты из config/trading.php
php artisan trading:fetch-prices --symbol=BTC --symbol=ETH
php artisan trading:fetch-prices --days=30
```

Команда тянет 7 дней истории (настраивается `TRADING_HISTORY_DAYS`), сохраняет тики батчами по 500 строк через `upsert`, поэтому повторный запуск не создаёт дублей. Между запросами к CoinGecko — пауза 350 мс, на 429/сетевые ошибки есть ретраи (`COINGECKO_RETRY_TIMES`, `COINGECKO_RETRY_DELAY_MS`).

Автообновление — раз в час (`routes/console.php`):

```bash
php artisan schedule:work
# или cron: * * * * * php /path/to/artisan schedule:run
```

## Запуск

```bash
php artisan serve --host=127.0.0.1 --port=8001
```

Откройте <http://127.0.0.1:8001>. Клиент либо входит по учётным данным CRM, либо нажимает «Зарегистрируйтесь» на странице входа — регистрация создаёт его в CRM и сразу открывает кабинет. Вход разрешён только **активным** пользователям (`users.status = 1`), а деактивация клиента в CRM закрывает ему кабинет: пароли и статус хранятся в одной таблице.

`TRADING_INITIAL_BALANCE` (10 000 USD в `.env.example`, 0 — значение по умолчанию в конфиге) начисляется только существующему пользователю CRM при первом открытии дашборда. Самостоятельно зарегистрированный клиент стартует с `TRADING_REGISTRATION_INITIAL_BALANCE` = 0: пустой счёт, подсказка «Торговый счёт пуст» и форма «Пополнение».

## Инструменты

Список задаётся в `config/trading.php`:

```php
'symbols' => [
    'BTC' => 'bitcoin',
    'ETH' => 'ethereum',
    'SOL' => 'solana',
    'BNB' => 'binancecoin',
    'XRP' => 'ripple',
],
```

Символ слева — внутренний (используется в позициях и на графике), справа — id монеты в CoinGecko. Добавление пары автоматически расширяет и форму, и вкладки графика, и команду импорта.

## Тесты

```bash
php artisan test --compact
```

* `tests/Feature/TradingFlowTest.php` — вход (активный/неактивный пользователь), депозит, открытие/закрытие Buy и Sell, нехватка средств, чужой `close`.
* `tests/Feature/ClientRegistrationTest.php` — регистрация клиента: активный `users`, роль клиента, карточка `persons`, лид в воронке с этапом `new`, баланс `$0.00` на дашборде, повтор e-mail в любом регистре, валидация пароля и согласия, переиспользование роли и принадлежность лида клиенту.
* `tests/Feature/MarketDataServiceTest.php` — импорт CoinGecko через `Http::fake()`, дедупликация тиков внутри секунды, идемпотентный upsert.
* `tests/Unit/TradingPositionTest.php` — расчёт P&L и залога.

Тестам нужна мигрированная база CRM (таблица `roles` для FK `users.role_id`). Без неё feature-тесты помечаются как skipped.

## Структура

```
app/
  Console/Commands/FetchPrices.php   импорт истории CoinGecko
  Http/Controllers/                  DashboardController, WithdrawalController,
                                     Auth\LoginController, Auth\RegisterController
  Http/Requests/                     RegisterRequest, DepositRequest, OpenPositionRequest
  Models/                            User (CRM users), TradingAccount, TradingPosition, PriceHistory
  Services/ClientRegistrationService.php  регистрация клиента (users/roles/persons/leads)
  Services/CoinGeckoClient.php       HTTP-клиент с ретраями
  Services/MarketDataService.php     upsert тиков
  Services/TradingService.php        депозит, открытие/закрытие, P&L
  Providers/AppServiceProvider.php   защита общей БД
config/trading.php                   инструменты, лимиты, регистрация, API, флаги
public/css/trading.css               тёмная тема (без сборки Vite)
resources/views/                     dashboard, auth.login, auth.register, layouts.app
```

## Проблемы

**`composer install` падает на скачивании архивов.** На этой машине `codeload.github.com` заблокирован (резолвится в 8.8.8.8, вероятно антивирус), поэтому dist-архивы GitHub недоступны. Используйте `--prefer-source`: пакеты клонируются через `git clone` с `github.com`, который работает.

**Конфликт сессий с CRM.** Оба приложения на одном хосте (разные порты — одна cookie-банка), поэтому в `.env` задан отдельный `SESSION_COOKIE=trading_session` и файловые драйверы сессий/кэша: в общей базе не появляются лишние таблицы `sessions`/`cache`.
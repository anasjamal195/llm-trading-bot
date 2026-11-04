# Deployment

## Server prerequisites

- PHP 8.2+, MySQL 8, Supervisor, cron, Node 20+ (build only).

## Install

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # fill values; then:
php artisan key:generate
php artisan migrate --force
php artisan db:seed
npm ci && npm run build
```

Create the admin user via seeder defaults, then change the password and set
the OpenRouter key in Settings (stored encrypted in the DB, never in files).

## Supervisor

```bash
sudo cp deploy/supervisor.conf /etc/supervisor/conf.d/llm-trading-bot.conf
# edit /var/www/llm-trading-bot paths inside first
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status trading:*
```

Workers use `queue:work database` (bounded jobs, `--max-time=3600` recycles
workers). Restart after each deploy: `supervisorctl restart trading:*`.

## Cron (scheduler)

```
* * * * * cd /var/www/llm-trading-bot && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler dispatches bounded jobs only; overlap is prevented with
`withoutOverlapping`, and stale RUNNING agent tasks are recovered by
`bot:expire-recover` via lease expiry.

## Queues

`market-data, intelligence, news, trading, trade-monitoring, learning,
notifications, default` — see worker allocation in `deploy/supervisor.conf`.

## First run checklist

1. Settings → OpenRouter API key + model assignments.
2. Settings → enable symbols, risk limits, news provider.
3. Settings → Bot control → Start. Watch System Activity + queue sizes.
4. Verify candles flowing (Live Market), then agent tasks completing.

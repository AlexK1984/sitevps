# VPN subscriptions

A lightweight Laravel application for selling VPN subscriptions through a website and Telegram. The website runs separately from the 3x-ui servers and contacts them through their HTTPS API.

## Requirements

- PHP 8.3 or later with `bcmath`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_sqlite`, `sqlite3`, and `xml`
- Composer 2
- SQLite
- Ubuntu 24.04 LTS for the included installer

## Local development

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan app:make-admin admin@example.com
php artisan serve
```

In another terminal, run the database queue worker:

```sh
php artisan queue:work database --tries=1
```

The admin command asks for the administrator name and a password twice. Add tariffs and remote 3x-ui panels from `/admin`. Prices, terms, traffic allowances, and approximate concurrent-IP values are configured there; no production prices or credentials are bundled.

## Payments

Set `YOOKASSA_SHOP_ID` and `YOOKASSA_SECRET_KEY` in the server's `.env`, then configure the YooKassa notification URL as:

```text
https://your-domain.example/webhooks/yookassa
```

The notification is not trusted by itself. The application fetches the payment from YooKassa and checks the payment ID, order reference, RUB currency, exact amount, and succeeded status before queuing access provisioning. Repeated notifications do not create extra subscription time or traffic.

If the merchant's YooKassa setup requires fiscal receipts, set `YOOKASSA_RECEIPT_VAT_CODE` (and, when applicable, `YOOKASSA_TAX_SYSTEM_CODE`) to the values configured for the merchant account. With a VAT code set, checkout includes the buyer's account email and a VPN service line item in the receipt. Confirm the tax settings with the merchant's accounting requirements before accepting production payments.

Payment methods are managed in `/admin`. YooKassa can be switched on or off there; its credentials remain in the server `.env`. Telegram Stars are available only in the linked Telegram bot: enable Stars in `/admin` and set a whole-number Stars price on each tariff. The bot uses Telegram invoices in `XTR`; it accepts a pre-checkout query only when the pending order, linked Telegram account, currency, and amount match. Access is provisioned only after Telegram sends a valid `successful_payment` update through the secret-protected webhook. The payment event is recorded idempotently like YooKassa notifications.

The admin panel also has provider-independent crypto preparation fields (asset, network, confirmation count, payment window, and internal note). They do not enable checkout, generate an address, verify a transfer, or mark an order paid. Crypto payments remain unavailable until a provider is selected and its server-side create/verify integration is implemented. Do not enter API keys or secrets in these fields.

## 3x-ui panels

Add each panel in `/admin` using its HTTPS API base URL, subscription URL prefix, account credentials, inbound ID, protocol, and optional flow. The subscription URL prefix is the public 3x-ui subscription endpoint before the per-client ID (for example, `https://vpn.example.com:2096/sub`); configure it according to the panel's subscription settings. Panel passwords are encrypted at rest using Laravel's application key. The site calls the 3x-ui login, inbound-list, add-client, and update-client API endpoints from the server; credentials are never sent to the browser.

An administrator assigns a panel to each user. A paid order received before assignment stays in `paid_unassigned`; assigning a panel queues provisioning. Failed API calls remain visible to the administrator and can be retried. The connection value maps to 3x-ui's IP limit where supported and does not guarantee a hard device count. Traffic allowance is cumulative across paid renewals.

The API and inbound behavior can vary between 3x-ui releases and panel configurations. Verify the panel version and inbound settings before enabling real customer payments.

## Telegram bot

Set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_NAME`, and a random `TELEGRAM_WEBHOOK_SECRET` in `.env`. Register the webhook URL as:

```text
https://your-domain.example/webhooks/telegram
```

Configure Telegram to send the same secret in its `X-Telegram-Bot-Api-Secret-Token` header. Users generate a single-use, ten-minute account-link URL in their dashboard. The bot supports `/plans` and `/renew`; customers can pay with YooKassa or, when enabled and priced by the administrator, Telegram Stars. Both methods provision through the same subscription queue.

## One-command server installation

On an Ubuntu 24.04 LTS server with the repository checkout available, run:

```sh
sudo bash scripts/install.sh
```

The installer asks for the website domain and certificate email, installs PHP-FPM, Nginx, Composer, SQLite, and Certbot, configures Let's Encrypt HTTPS, creates the first admin, and starts a database-backed queue worker. It installs only the website; 3x-ui remains on its separate server. The installer stores application data under `/var/lib/vpnsite`, can resume an interrupted installation, and refuses to overwrite a completed or unrelated installation.

After installation, add the YooKassa and Telegram credentials to `/var/www/vpnsite/.env`, then clear the cached configuration:

```sh
sudo -u vpnsite php /var/www/vpnsite/artisan config:clear
sudo systemctl restart vpnsite-worker php8.3-fpm
```

Keep `.env` private and backed up securely. Back up `/var/lib/vpnsite/database.sqlite` and the Laravel `APP_KEY` together: the key is required to decrypt stored panel credentials.

# tanemrahman/zkteco-adms

ZKTeco **ADMS / iClock Push** protocol for Laravel.

Devices push attendance to `/iclock/*` on your app — no BioTime / ZKPush required.

## Install (from Packagist / Git later)

```bash
composer require tanemrahman/zkteco-adms
php artisan migrate
php artisan vendor:publish --tag=zkteco-adms-config
```

## Tables

- `zkteco_devices`
- `zkteco_transactions`
- `zkteco_device_commands`
- `zkteco_device_users`
- `zkteco_heartbeat_logs`
- `zkteco_adms_logs`

## Device setup

Comm → Cloud Server / ADMS → Server = your domain, Port 443, Communication = ADMS.

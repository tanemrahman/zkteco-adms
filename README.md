# tanemrahman/zkteco-adms

ZKTeco **ADMS / iClock Push** protocol server for Laravel.

Your biometric device dials out to your Laravel app at `/iclock/*` and pushes attendance in real time.  
**No** ZKBioTime server, **no** ZKPush.exe, **no** TCP SDK required.

Works with SenseFace, K40, MB series, and most ZKTeco Push-SDK devices.

---

## Requirements

- PHP `^8.2`
- Laravel `11` / `12` / `13`
- MySQL / MariaDB recommended (SQLite also works)
- A public HTTPS URL the device can reach (port `443`)

---

## Installation (Composer)

### Option A — from GitHub (current)

Add the VCS repository, then require the package.

**`composer.json`**

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/tanemrahman/zkteco-adms.git"
    }
  ],
  "require": {
    "tanemrahman/zkteco-adms": "dev-main"
  }
}
```

```bash
composer update tanemrahman/zkteco-adms
php artisan migrate
php artisan vendor:publish --tag=zkteco-adms-config
```

Or one-liner style:

```bash
composer config repositories.zkteco-adms vcs https://github.com/tanemrahman/zkteco-adms.git
composer require tanemrahman/zkteco-adms:dev-main
php artisan migrate
php artisan vendor:publish --tag=zkteco-adms-config
```

### Option B — path / local copy (offline)

```text
your-laravel-app/
  packages/tanemrahman/zkteco-adms/   ← clone or copy here
```

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/tanemrahman/zkteco-adms",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "tanemrahman/zkteco-adms": "*"
  }
}
```

```bash
composer update tanemrahman/zkteco-adms
php artisan migrate
```

Laravel auto-discovers `ZktecoAdmsServiceProvider` — no manual register needed.

---

## What this package gives you

| Piece | Purpose |
|-------|---------|
| Routes `/iclock/*` | Device protocol endpoints |
| Migrations | Tables listed below |
| Models | Eloquent models for devices, punches, logs |
| Events | Hook punches into your HRM / attendance |
| Config | Tunable protocol options |
| Artisan | List devices, prune logs, requeue commands |

**This package does not ship an admin UI.**  
You design pages in your own app (Blade / Inertia / Livewire / Filament) on top of the tables & models.

---

## Database tables

Created automatically on `php artisan migrate`:

| Table | Purpose |
|-------|---------|
| `zkteco_devices` | Registered devices (by serial `SN`) |
| `zkteco_transactions` | Attendance punches |
| `zkteco_device_commands` | Commands waiting for the device |
| `zkteco_device_users` | USERINFO roster from device |
| `zkteco_heartbeat_logs` | `getrequest` polls (liveness) |
| `zkteco_adms_logs` | Raw protocol audit trail |

### Important columns

**`zkteco_transactions`**

| Column | Meaning |
|--------|---------|
| `device_id` | FK → `zkteco_devices.id` |
| `user_id` | Device PIN (enrolment id) |
| `timestamp` | Punch time |
| `status` | Punch state (in/out/…) |
| `verify` | Verify type (fingerprint/face/…) |
| `source` | Always `adms` for this package |
| `terminal_sn` | Device serial |

Map `user_id` (PIN) to your `employees.biometric_emp_id` (or similar) in **your** app.

---

## Device configuration

On the device: **Menu → Comm → Cloud Server / ADMS**

| Setting | Value |
|---------|-------|
| Server Address | `attendance.yourdomain.com` |
| Server Port | `443` |
| Enable Domain Name | On (if using hostname) |
| Protocol / HTTPS | On |
| Communication | ADMS (Push) |
| Comm Key (optional) | Must match `ZKTECO_ADMS_COMM_KEY` |

The device will call:

```text
https://attendance.yourdomain.com/iclock/cdata?SN=XXXXXXXX
```

> Use a real TLS certificate (Let's Encrypt is fine). Some firmwares reject self-signed certs.

### Local / XAMPP testing

- Device must reach your PC (same LAN or public IP + port forward).
- For quick protocol tests without a device:

```bash
# Handshake
curl "http://127.0.0.1:8000/iclock/cdata?SN=DEMO-01&options=all&pushver=2.4.1"

# Push one punch (tab-separated PIN + time + status + verify)
curl -X POST "http://127.0.0.1:8000/iclock/cdata?SN=DEMO-01&table=ATTLOG" \
  -H "Content-Type: text/plain" \
  --data-binary $'1001\t2026-08-10 10:00:00\t0\t1'
```

Expected reply: `OK: 1`

---

## Protocol endpoints (auto-registered)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/iclock/cdata` | Handshake / options |
| `POST` | `/iclock/cdata` | ATTLOG / OPERLOG upload |
| `GET` | `/iclock/getrequest` | Command poll + heartbeat |
| `POST` | `/iclock/devicecmd` | Command result |
| `GET` | `/iclock/ping` | Liveness |
| `POST` | `/iclock/fdata` | Template / photo upload |
| `*` | `/iclock/registry`, `/iclock/push` | Push 2.x probes |

Prefix is configurable via `ZKTECO_ADMS_PREFIX` (default `iclock`).  
Do **not** change it unless every device is reconfigured — firmware expects `iclock`.

---

## Config (`.env`)

Publish first:

```bash
php artisan vendor:publish --tag=zkteco-adms-config
```

Useful keys:

```env
ZKTECO_ADMS_PREFIX=iclock
ZKTECO_ADMS_AUTO_REGISTER=true
ZKTECO_ADMS_REQUIRE_COMM_KEY=false
ZKTECO_ADMS_COMM_KEY=
ZKTECO_ADMS_TIMEZONE=6
ZKTECO_ADMS_DEVICE_TIMEZONE=Asia/Dhaka
ZKTECO_ADMS_RETENTION_DAYS=30
ZKTECO_ADMS_DEDUP_TOLERANCE=5
ZKTECO_ADMS_LOG_ENABLED=true
ZKTECO_ADMS_LOG_HEARTBEATS=false
```

---

## How to design your app around this package

Think of this package as the **ingestion + device control layer**. Your Laravel app owns UX & business rules.

### Facade API (recommended)

```php
use TanemRahman\ZktecoAdms\ZktecoAdms;

// Add / update user on device (PIN + name + card…)
ZktecoAdms::addUser('DEVICE-SN', [
    'pin' => 1001,
    'name' => 'Karim',
    'privilege' => 0,      // 0=user, 14=admin
    'password' => '',
    'card' => '',
]);

ZktecoAdms::deleteUser('DEVICE-SN', 1001);

// Fingerprint / face template push (raw TMP from another device or export)
ZktecoAdms::addFingerprint('DEVICE-SN', [
    'pin' => 1001,
    'fid' => 0,
    'tmp' => $base64OrHexTemplate,
]);

ZktecoAdms::addFace('DEVICE-SN', [
    'pin' => 1001,
    'fid' => 0,
    'tmp' => $faceTemplate,
]);

ZktecoAdms::deleteFingerprint('DEVICE-SN', 1001, 0);
ZktecoAdms::deleteFace('DEVICE-SN', 1001, 0);

// Device control
ZktecoAdms::reboot('DEVICE-SN');
ZktecoAdms::syncTime('DEVICE-SN');
ZktecoAdms::info('DEVICE-SN');
ZktecoAdms::check('DEVICE-SN');          // re-handshake
ZktecoAdms::clearLog('DEVICE-SN');       // clear attendance on device
ZktecoAdms::clearData('DEVICE-SN');      // clear operation data
ZktecoAdms::queryUsers('DEVICE-SN');     // ask device to re-upload USERINFO
ZktecoAdms::queryAttlog('DEVICE-SN', '2026-08-01 00:00:00', '2026-08-10 23:59:59');
ZktecoAdms::resetStamps('DEVICE-SN');    // force re-upload on next handshake
```

Device picks commands on the next `getrequest` poll (~every 10 seconds).

### Suggested screens (you build these)

1. **Devices** — list `ZktecoDevice`, show online/offline from `last_seen_at`
2. **Live punches** — paginate `ZktecoTransaction` where `source = adms`
3. **Device users** — show `ZktecoDeviceUser` roster + “Add user” form that calls `ZktecoAdms::addUser`
4. **Biometrics** — enroll face/finger on device, or push templates via `addFace` / `addFingerprint`
5. **Protocol logs** — debug with `ZktecoAdmsLog` (dev only)
6. **Commands** — show pending queue from `ZktecoDeviceCommand`

### Example: Devices index (controller sketch)

```php
use TanemRahman\ZktecoAdms\Models\ZktecoDevice;

public function index()
{
    $devices = ZktecoDevice::query()
        ->orderByDesc('last_seen_at')
        ->paginate(20);

    return view('zkteco.devices.index', compact('devices'));
}
```

### Example: Add user from your admin form

```php
use TanemRahman\ZktecoAdms\ZktecoAdms;

public function storeUser(Request $request, string $serial)
{
    $data = $request->validate([
        'pin' => 'required',
        'name' => 'required|string|max:100',
        'card' => 'nullable|string',
    ]);

    ZktecoAdms::addUser($serial, $data);

    return back()->with('success', 'User queued to device.');
}
```

### Example: Today's punches

```php
use TanemRahman\ZktecoAdms\Models\ZktecoTransaction;

$punches = ZktecoTransaction::query()
    ->where('source', 'adms')
    ->whereDate('timestamp', today())
    ->orderByDesc('timestamp')
    ->paginate(50);
```

### Example: Map PIN → Employee in your HRM

```php
use App\Models\Employee;
use TanemRahman\ZktecoAdms\Models\ZktecoTransaction;

$txn = ZktecoTransaction::latest('timestamp')->first();

$employee = Employee::where('biometric_emp_id', $txn->user_id)->first();
// create/update your attendances row…
```

### React to punches in real time (recommended)

```php
use TanemRahman\ZktecoAdms\Events\TransactionsReceived;

Event::listen(TransactionsReceived::class, function (TransactionsReceived $e) {
    // $e->device  — ZktecoDevice
    // $e->saved   — how many new rows
    // $e->pins    — list of device PINs in this batch
    // $e->source  — "adms"
});
```

---

## Artisan

```bash
# Devices
php artisan zkteco-adms:devices
php artisan zkteco-adms:devices --register=SN123 --name="Gate 1"
php artisan zkteco-adms:devices --reset-stamp=SN123
php artisan zkteco-adms:devices --reset-stamp=all
php artisan zkteco-adms:devices --delete=SN123

# Commands / users
php artisan zkteco-adms:commands --list
php artisan zkteco-adms:commands --sn=SN123 --enqueue=info
php artisan zkteco-adms:commands --sn=SN123 --enqueue=reboot
php artisan zkteco-adms:commands --sn=SN123 --enqueue=sync-time
php artisan zkteco-adms:commands --sn=SN123 --enqueue=clear-log
php artisan zkteco-adms:commands --sn=SN123 --enqueue=query-users
php artisan zkteco-adms:commands --sn=SN123 --add-user=1001 --user-name=Karim
php artisan zkteco-adms:commands --sn=SN123 --delete-user=1001
php artisan zkteco-adms:commands --requeue-stale
php artisan zkteco-adms:commands --prune

# Legacy maintain helper
php artisan zkteco-adms:maintain --list-devices
```

Scheduled automatically:

- every 30 min → requeue stale commands  
- daily 02:00 → prune old protocol / heartbeat logs  

---

## Full ADMS feature list (this package)

| Feature | Status |
|---------|--------|
| `/iclock` handshake + options | ✅ |
| ATTLOG punch ingest + dedupe | ✅ |
| OPERLOG / USERINFO sync → DB | ✅ |
| FP / Face template flags from device | ✅ |
| fdata / registry / push / ping | ✅ |
| Auto-register device by SN | ✅ |
| Comm key auth | ✅ |
| Protocol + heartbeat logs | ✅ |
| Command queue (getrequest / devicecmd) | ✅ |
| Add / update / delete user | ✅ |
| Push fingerprint template | ✅ |
| Push face template | ✅ |
| Query users / attlog from device | ✅ |
| Reboot / CHECK / INFO / sync time | ✅ |
| Clear log / clear data | ✅ |
| Reset sync stamps | ✅ |
| Pre-register / delete device (artisan) | ✅ |
| Facade `ZktecoAdms::…` | ✅ |
| Admin UI | ❌ (build in your app) |

---

## Using with BioTime package

You can install **both**:

- [`tanemrahman/zkteco-adms`](https://github.com/tanemrahman/zkteco-adms) — device push  
- [`tanemrahman/zkteco-biotime`](https://github.com/tanemrahman/zkteco-biotime) — BioTime API pull  

They share `zkteco_transactions`. Filter by `source`:

```php
ZktecoTransaction::where('source', 'adms')->…;
ZktecoTransaction::where('source', 'biotime')->…;
```

---

## Publishable tags

```bash
php artisan vendor:publish --tag=zkteco-adms-config
php artisan vendor:publish --tag=zkteco-adms-migrations   # optional copy into app
```

---

## License

MIT

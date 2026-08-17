# Changelog

## 1.4.3 — 2026-08-18

### Fixed
- `fp_count`/`face_count` no longer inflate on repeated syncs. Templates are now tracked
  by FID (`zkteco_device_users.fp_fids` / `face_fids`, new nullable JSON columns) and the
  count is derived from the number of distinct FIDs, instead of an unconditional
  increment on every uploaded template row — the device re-uploads its full template
  table on every OPERLOG sync, so the old counter grew every time even with zero real
  enrollment changes.
- `DATA DELETE FINGERTMP` / `DATA DELETE FACE` command confirmations now remove the
  specific FID from the tracked set (instead of a blind `count - 1`), so counts stay
  accurate even with multiple templates per user.

## 1.4.2 — 2026-08-13

### Changed
- Schema columns/indexes now live in the create migrations (`devices` unique serial, `transactions` workcode + status unique, `device_users` verify_mode/is_blocked)
- Replaced `000007_enhance_zkteco_schema` with `000007_create_zkteco_attphotos_table` only

## 1.4.1 — 2026-08-12

### Changed
- Merged migrations `000007`–`000010` into a single idempotent `2026_01_01_000007_enhance_zkteco_schema.php`

## 1.4.0 — 2026-08-12

### Added
- `zkteco_device_users.verify_mode` (USERINFO `Verify=`) and `is_blocked` soft punch-block flag
- Facade helpers: `ZktecoAdms::blockUser()` / `ZktecoAdms::unblockUser()`
- `buildUpdateUser()` / `addUser()` accept optional `verify` / `verify_mode`

### Changed
- Successful `DATA DELETE USERINFO` keeps the local roster row when `is_blocked=true` (apps can unblock later)
- OPERLOG / USERINFO upsert maps `Verify` → `verify_mode` without clearing `is_blocked`

## 1.3.0 — 2026-08-10

### Fixed
- User / fingerprint / face commands no longer optimistic-write `zkteco_device_users` before the device confirms — roster updates only on successful `devicecmd` reply (or OPERLOG upload)

### Added
- ATTPHOTO binary photos saved to disk + `zkteco_attphotos` table + `AttendancePhotoReceived` event
- `workcode` column on `zkteco_transactions` (parsed from ATTLOG)

### Config
- `zkteco-adms.attphoto.enabled|disk|path` (`ZKTECO_ADMS_ATTPHOTO*`)

## 1.2.0 — 2026-08-10

### Changed
- Package layout aligned with standard Laravel vendor packages:
  - `config/` · `database/migrations/` · `routes/` · `src/` · `tests/` · `docs/`
  - Service provider: `TanemRahman\ZktecoAdms\ZktecoAdmsServiceProvider`
  - Facade: `TanemRahman\ZktecoAdms\Facades\ZktecoAdms`

## 1.1.1 — 2026-08-10

### Fixed
- Unknown/inactive devices no longer get protocol `OK` (prevents silent punch loss)
- ATTLOG stamp only advances when `rejected === 0`
- `devicecmd` reply key casing (`CMD`/`ID`/`Return`) normalized correctly
- Command replies scoped to the requesting device serial
- INFO reply no longer writes `Return=0` into firmware
- Event payload limited to punches that actually exist after insert
- Device timezone uses hour offset unless IANA zone explicitly set
- `FINGERTMP` / `BIODATA` template flags
- `require_comm_key` fails closed when key empty
- INFO counters no longer overwrite app `transaction_count`
- Unique index on `zkteco_devices.serial`
- Scheduler can be disabled via `ZKTECO_ADMS_SCHEDULE`

## 1.1.0 — 2026-08-10

### Added
- Full device command helpers: users, fingerprint, face, query attlog/users, reboot, check, sync-time, clear log/data
- Facade `ZktecoAdms` + `ZktecoAdmsManager`
- Events: `DeviceRegistered`, `CommandCompleted`, `UsersSynced` (plus richer `TransactionsReceived`)
- Optional queued punch post-processing (`ZKTECO_ADMS_QUEUE_PROCESSING`)
- ATTPHOTO table handling
- Inactive device rejection (`ZKTECO_ADMS_REJECT_INACTIVE`)
- Artisan: `zkteco-adms:devices`, `zkteco-adms:commands`
- Model scopes: `online`, `offline`, `adms`, `active`
- `addUsers()` bulk helper
- Unit tests for ATTLOG/OPERLOG parsing and command builders
- Protocol docs + HTTP samples

## 1.0.0 — 2026-08-10

- Initial ADMS / iClock Push server for Laravel

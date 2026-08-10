# Changelog

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

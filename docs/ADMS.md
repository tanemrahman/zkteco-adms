# ADMS protocol notes

See the main [README](../README.md) for install and API usage.

## Flow

1. Device `GET /iclock/cdata?SN=…&options=all` → option block
2. Device `POST /iclock/cdata?table=ATTLOG` → punches
3. Device `GET /iclock/getrequest` every ~10s → commands or `OK`
4. Device `POST /iclock/devicecmd` → command results

## Cloud deploy checklist

- [ ] Public VPS / cloud with Laravel reachable on 80 or 443
- [ ] Firewall / security group allows inbound 80/443
- [ ] Valid TLS cert if using HTTPS (preferred)
- [ ] Device ADMS server = your domain or public IP
- [ ] `php artisan migrate` ran
- [ ] Queue worker running if `ZKTECO_ADMS_QUEUE_PROCESSING=true`
- [ ] Scheduler cron for stale command recovery

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Connect failed | Cloud firewall? Port open? TLS valid? |
| Handshake OK, no punches | `zkteco_adms_logs` POST cdata rows; device timezone |
| Commands never run | Heartbeats in `zkteco_heartbeat_logs`? Command status pending? |
| Duplicate punches | Raise `ZKTECO_ADMS_DEDUP_TOLERANCE` |
| Old punches dropped | Raise `ZKTECO_ADMS_RETENTION_DAYS` |

```sql
SELECT created_at, endpoint, method, table_name, level, records_count, message
FROM zkteco_adms_logs
WHERE serial = 'YOUR-SN'
ORDER BY id DESC
LIMIT 50;
```

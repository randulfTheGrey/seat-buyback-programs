# Operations and Troubleshooting

## Compression data

CCP's Static Data Export is authoritative. The package checks daily for a new
build and exposes a manual command:

```bash
php artisan buyback:sync-compression-data
```

An administrator can also queue **Sync now**. Candidate data is validated before
atomic activation. Failures preserve the last-known-good mapping.

- `MISSING`: no usable dataset has ever been activated. Programs containing
  compression-qualified GROUP rules cannot enable/appraise until sync succeeds.
- `AVAILABLE`: an active dataset is usable.
- `STALE`: the last-known-good dataset remains usable, but refresh/check health
  requires attention.

Programs without compression-qualified rules are not made unavailable solely by
a missing initial dataset.

## Pricing outcomes

- `UNPRICED`: a particular type had no usable price while its provider channel
  remained operational.
- `REFERENCE_UNAVAILABLE`: a required provider stream failed systemically.
- `REFERENCE_MISCONFIGURED`: the configured provider instance cannot be resolved
  or the required logical channel configuration is invalid.

No outcome triggers a hidden fallback. Re-appraise after fixing the cause; Quotes
already created are never repriced.

## Provider drift

Provider-instance IDs are external references without database foreign keys. If
an instance is deleted or renamed in `seat-prices-core`, stored Program
configuration is not silently changed and Program status is not automatically
mutated. Health shows the drift and affected appraisals fail with a normalized
configuration result. Select a valid replacement instance explicitly. Historical
Quote provenance retains its saved instance ID/name.

## Queue and scheduler

Run SeAT's Horizon/queue worker continuously and restart it after package
upgrades. Run Laravel's scheduler every minute. In SeAT Docker, verify the
dedicated worker and scheduler/cron services. On bare metal, use the standard
SeAT process supervisor and cron configuration.

The CLI compression command runs explicitly; the administration action uses the
queue. If a queued sync never starts, inspect Horizon status, failed jobs, queue
connection, and worker logs. If daily checks do not run, use
`php artisan schedule:list` and verify the host cron invokes
`php artisan schedule:run`.

## Logs

Use the logging location configured by the host Laravel/SeAT application.
Standard file-based deployments usually expose logs through Laravel's
`storage/logs` directory; container deployments should use container/service
logs or the configured centralized logging driver. Buyback normalizes requester
errors while recording provider, sync, and persistence details for operators.

## Permission and ACL cache lifecycle

The package registers `randulfthegrey-buyback.request`,
`randulfthegrey-buyback.manage`, and `randulfthegrey-buyback.admin` during
package discovery. SeAT exposes those definitions to normal role management and
materializes ACL records there; `PluginDatabaseSeeder` does not synchronize
permission names.

During the RC4 namespace upgrade, SeAT cannot distinguish which plugin owns a
legacy `buyback.*` ACL row. Buyback Programs therefore does not rename, copy, or
delete those records. Record affected roles before updating, grant the matching
`randulfthegrey-buyback.*` permission through normal role management afterward,
and leave every old grant intact for the other plugin. Access to Buyback Programs
fails closed until the new grants are present.

After remapping roles, run `php artisan cache:clear redis`, then
`php artisan optimize:clear`, and restart Horizon/workers. The Redis clear covers
SeAT's entity-scoped ACL cache keys and also expires transient appraisals. Do not
add old-name aliases or directly edit ACL tables.

## Common failures

### Program cannot enable

Review its health in Buyback Administration. Resolve missing BUY/SELL/SPLIT
references, deleted provider instances, invalid active rules, or required missing
compression data. Stored validity and runtime dependency health are separate.

### No compression dataset

Run the manual sync command, check outbound HTTPS/DNS, PHP ZIP support, available
temporary space, and the normalized sync error. A failed attempt does not damage
an existing dataset.

### Broken pricing reference

Confirm the selected instance still exists in `seat-prices-core`, its provider
package is installed/configured, and the logical role is intentional. Buyback
does not inspect provider backend credentials or semantics.

### Expired Quote

Quotes cannot be extended or refreshed. Start a complete new appraisal. Existing
submitted Requests remain valid regardless of Program status.

### Permissions missing

Confirm package discovery is healthy and inspect the user's roles. Grant each
needed vendor-prefixed permission explicitly; none implies another. For an RC4
upgrade, verify the role was manually remapped and the Redis/application caches
and workers were refreshed. Running the plugin database seeder does not
synchronize ACL identifiers.

### Queue or scheduler not running

Restart Horizon/workers, check failed jobs, and verify scheduler execution. Run a
manual compression sync to distinguish scheduler failure from SDE/network
failure.

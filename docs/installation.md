# Installation

This guide installs the newest published `1.0.0` release candidate into an
existing SeAT 5 application. Published tags are distributed from the public
GitHub repository through Packagist.

## Requirements

- SeAT 5 (`eveseat/seat` 5.0.1 and compatible current 5.x components)
- PHP 8.2 or later within the PHP 8 major series
- Composer 2
- a database supported by the SeAT 5 installation
- a persistent Laravel cache shared by web and worker processes
- a running SeAT Horizon/queue worker and Laravel scheduler
- `recursivetree/seat-prices-core` 1.0.1 or a compatible 1.x release
- the PHP JSON and ZIP extensions, plus the normal SeAT extensions

The package depends directly on `eveseat/services`, `eveseat/eveapi`, and
`eveseat/web`. Composer resolves compatible versions. Do not force incompatible
versions with `--ignore-platform-reqs` or `--with-all-dependencies` without
reviewing the resulting SeAT upgrade.

## Composer installation

Back up the application database and Composer files. From the SeAT root, run as
the same operating-system user that owns the SeAT installation:

```bash
php artisan down
composer require randulfthegrey/seat-buyback-programs:^1.0@RC
php artisan migrate --force
php artisan db:seed --class='Seat\\Services\\Database\\Seeders\\PluginDatabaseSeeder' --force
php artisan optimize:clear
php artisan up
```

The `@RC` stability flag admits the newest matching `1.0.0` release candidate
without changing the root project's global minimum stability. Once stable
`1.0.0` exists, normal stable constraints can replace it.

Laravel package discovery loads
`RandulfTheGrey\Seat\BuybackPrograms\BuybackProgramsServiceProvider`. Manual
registration is not required unless package discovery has been disabled in the
host application. The provider loads plugin-owned migrations and registers the
permissions, routes, command, views, and daily schedule. It does not modify SeAT
core tables.

The standard SeAT plugin database seeder maintains normal plugin lifecycle
metadata; it is not a permission synchronizer. Package discovery registers the
permission definitions, and SeAT materializes their ACL records through its
normal role-management flow.

For SeAT Docker, add `randulfthegrey/seat-buyback-programs:^1.0@RC` to the
installation's `SEAT_PLUGINS` list using the normal SeAT Docker workflow, rebuild
the affected containers, and confirm the web, worker, and scheduler services are
healthy. Do not edit a running container as the durable installation method.

## Permissions

Grant the registered permissions through normal SeAT roles:

- `randulfthegrey-buyback.request` for requesters;
- `randulfthegrey-buyback.manage` for fulfillment managers;
- `randulfthegrey-buyback.admin` for configuration administrators.

Permissions are independent; `randulfthegrey-buyback.admin` does not imply `randulfthegrey-buyback.manage`,
and `randulfthegrey-buyback.manage` does not imply `randulfthegrey-buyback.request`. Grant combinations
explicitly when one person needs more than one workflow.

## Queue and scheduler

Keep SeAT's Horizon/queue worker running. The administration **Sync now** action
dispatches compression synchronization to the queue. Keep the Laravel scheduler
running every minute so the package's daily compression-data check executes.
Typical SeAT Docker deployments provide separate `worker` and `scheduler`
services; bare-metal deployments normally run Horizon under a process supervisor
and invoke `php artisan schedule:run` from cron.

## Initialize compression data

Run the initial synchronization once:

```bash
php artisan buyback:sync-compression-data
```

The command downloads and validates CCP's current `compressibleTypes` SDE data,
then atomically activates the plugin-owned projection. A failed first sync leaves
the dataset `MISSING`; fix the reported network/archive problem and retry. The
normal test suite never performs this network operation.

## Configure pricing

Install/configure suitable price-provider packages through `seat-prices-core`,
then create opaque provider instances for the price streams you intend to call
BUY and SELL. Optionally create a dedicated SPLIT provider; otherwise Buyback can
derive SPLIT as the exact midpoint of BUY and SELL. Buyback does not inspect or
validate provider backend semantics, so administrators are responsible for
assigning each provider instance to the intended logical role.

## Create the first Program

1. Grant yourself `randulfthegrey-buyback.admin` and open **Buyback Administration**.
2. Create a Program. It starts `DISABLED` with default `ACCEPT`.
3. Configure default reference, modifier, Quote validity, and contract text.
4. Select the BUY and SELL provider instances and configure SPLIT.
5. Add only the sparse GROUP/TYPE exceptions the policy needs.
6. Review Program health and the effective-rule preview.
7. Enable the Program after pricing references and required compression data are
   healthy.
8. With a separate `randulfthegrey-buyback.request` grant, perform an appraisal/Quote/Request
   smoke test; use `randulfthegrey-buyback.manage` to validate fulfillment.

See [configuration.md](configuration.md) for policy semantics and
[operations.md](operations.md) for health and troubleshooting.

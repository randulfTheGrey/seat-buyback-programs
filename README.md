# Buyback Programs for SeAT

Buyback Programs is a community plugin for SeAT 5 that provides configurable
item-buyback workflows. It supports multiple Programs, global ACCEPT or REJECT
defaults, sparse GROUP and TYPE overrides, compression-aware GROUP policies,
and logical BUY, SELL, and SPLIT price references supplied exclusively by
`seat-prices-core`.

The requester journey is appraisal to immutable Quote to fulfillment Request.
Appraisals are transient server-trusted calculations; Quotes preserve payable
prices and policy evidence; Requests provide independent requester and manager
workflows. Configuration, fulfillment, and requester access use three separate
SeAT permissions.

## Release status

The first public target is **1.0.0-rc.1**. It is a release candidate intended
for public installation and package validation before stable `1.0.0`. There is
no prior public version.

The source repository will be
[`randulfTheGrey/seat-buyback-programs`](https://github.com/randulfTheGrey/seat-buyback-programs).
Until that repository and its RC tag are public, the Composer command below is
the intended post-publication installation command and will not resolve from
Packagist.

## Compatibility

| Component | Supported constraint | Verified release/baseline |
| --- | --- | --- |
| SeAT | 5.x | `eveseat/seat` 5.0.1 |
| PHP | `^8.2` | 8.2 and 8.4 CI matrix |
| Laravel | `^10.48.29` | Laravel 10 as required by SeAT 5 |
| `eveseat/services` | `^5.1` | 5.1.0 |
| `eveseat/eveapi` | `^5.0.34` | current compatible 5.0.x |
| `eveseat/web` | `^5.0.35` | 5.0.35 |
| `seat-prices-core` | `^1.0.1` | 1.0.1 |
| Composer | 2.x | current Composer 2 |

PHP 8.2 is the effective minimum because `seat-prices-core` requires it. SeAT
5 and its packages require Laravel 10; this package does not target SeAT 6.
Laravel 10 is no longer supported upstream. The focused development-security
policy and accepted residual advisories are recorded in
[ADR 0013](docs/adr/0013-accept-laravel-10-for-seat-5-with-focused-security-exceptions.md).

The plugin requires the normal SeAT database, cache, queue worker/Horizon, and
Laravel scheduler processes. Compression reference data is stored only in
plugin-owned tables; SeAT core tables are never altered.

## Installation

After the GitHub repository, RC tag, and Packagist package are published, run
these commands from the root of the SeAT installation as its application user:

```bash
php artisan down
composer require randulfthegrey/seat-buyback-programs:^1.0@RC
php artisan migrate --force
php artisan db:seed --class='Seat\\Services\\Database\\Seeders\\PluginDatabaseSeeder' --force
php artisan optimize:clear
php artisan up
```

Laravel package discovery registers
`RandulfTheGrey\Seat\BuybackPrograms\BuybackProgramsServiceProvider`; manual
provider registration is not normally required. Keep SeAT's Horizon/queue
worker and scheduler running, then initialize compression data:

```bash
php artisan buyback:sync-compression-data
```

Next, configure one or more provider instances in `seat-prices-core`. Create
BUY and SELL instances and, if desired, a dedicated SPLIT instance. In Buyback
Administration, create a disabled Program, assign its references and policy,
review its health, and enable it only after validation succeeds.

See the complete [installation guide](docs/installation.md), including Docker
notes and the first-Program checklist.

## Configuration model

- Programs are `ENABLED`, `DISABLED`, or `ARCHIVED`; new Programs are disabled.
- Program defaults select ACCEPT or REJECT, BUY/SELL/SPLIT, a basis-point
  modifier, Quote validity, and optional contract instructions.
- Sparse policy precedence is `TYPE > GROUP + compression > GROUP > Program
  defaults / GLOBAL`.
- GROUP policies can apply to ANY, COMPRESSED, or UNCOMPRESSED members. TYPE
  policies identify one exact TypeID and have no independent compression
  qualifier.
- Modifiers use INHERIT, REPLACE, or ADJUST. ADJUST adds percentage points; it
  never compounds percentages.
- SPLIT is either a dedicated opaque provider instance or an exact midpoint
  derived from both BUY and SELL. No reference silently falls back to another.

The [configuration guide](docs/configuration.md) explains Programs, pricing,
modifiers, rules, Quotes, Requests, and a worked policy example.

## Permissions

| Permission | Purpose |
| --- | --- |
| `buyback.request` | Create appraisals and manage the user's own Quotes and Requests. |
| `buyback.manage` | View submitted Requests, update manager fields, and complete or reject pending Requests. |
| `buyback.admin` | Configure Programs, references, rules, previews, and compression data. |

Permissions are independent; `buyback.admin` does not imply `buyback.manage`,
and `buyback.manage` does not imply `buyback.request`.

## Operations

The scheduled compression check runs daily and preserves the last-known-good
CCP SDE projection when a refresh fails. Administrators can also run
`php artisan buyback:sync-compression-data`. Normal appraisals use configured
`seat-prices-core` instances synchronously and do not call provider backends
directly.

See [operations and troubleshooting](docs/operations.md) for compression
health, pricing failure meanings, provider drift, queue/scheduler requirements,
logs, and common recovery steps. See [upgrading](docs/upgrading.md) before every
package update.

## Deliberate v1 limits

Version 1 does not include automatic EVE contract reconciliation,
provider-specific pricing clients, partial fulfillment, manual price overrides,
a payout/accounting ledger, Program-specific corporation/alliance/user
eligibility, notifications or Discord integration, category rules, or an
arbitrary expression engine.

## Development and release governance

The private GitLab repository remains authoritative for development, issues,
merge requests, and CI during RC validation. Public GitHub starts with a clean,
release-only history for tags, release notes, and Packagist integration; private
development history is not mirrored. After stable `1.0.0` is validated and the
recorded authority cutover is complete, GitHub becomes authoritative for future
development and public contribution. See
[ADR 0015](docs/adr/0015-clean-public-release-history-and-github-authority-transition.md)
and the release process.

Install development dependencies and run the deterministic release checks:

```bash
composer install
composer validate --strict
composer check
composer audit --locked
```

Normal tests use fixtures/fakes and make no live CCP SDE, ESI, or market-provider
HTTP requests. The dependency audit remains intentionally failing for only the
reviewed Laravel 10 advisories described by ADR 0013; any additional advisory
blocks release.

Release preparation, mirroring, tagging, and Packagist steps are documented in
[the release process](docs/releasing.md) and [release checklist](docs/release-checklist.md).

## License

Buyback Programs is released under the [MIT License](LICENSE). It is a community
project and is not affiliated with CCP Games or the SeAT project maintainers.
See [Third-Party Notices](NOTICE.md) for the CCP proprietary notice and developer
license information.

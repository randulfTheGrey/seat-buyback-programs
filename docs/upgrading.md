# Upgrading

Review the target release notes, back up the database and Composer files, and
test upgrades outside production when possible. During the release-candidate
phase, schema and migration compatibility may be more constrained than it will
be after stable `1.0.0`.

Before upgrading an rc.1, rc.2, or rc.3 installation to rc.4, record which SeAT
roles currently hold each `buyback.request`, `buyback.manage`, and
`buyback.admin` permission. You will use that record for the manual remapping
described below.

From the SeAT root, run as the application owner:

```bash
php artisan down
composer update randulfthegrey/seat-buyback-programs --with-dependencies
php artisan migrate --force
php artisan db:seed --class='Seat\\Services\\Database\\Seeders\\PluginDatabaseSeeder' --force
php artisan optimize:clear
php artisan up
```

Restart Horizon/workers after the Composer update so long-running processes load
the new code. Until the manual grants below are complete, Buyback Programs access
fails closed because the package no longer authorizes the legacy identifiers.

## RC4 manual permission remapping

`1.0.0-rc.4` changes the public ACL identifiers from `buyback.*` to the
vendor-prefixed `randulfthegrey-buyback.*` namespace. Another plugin may own the
same legacy titles, and SeAT's ACL records do not identify their source package.
RC4 therefore performs no automatic ACL rename, copy, merge, or deletion.

After updating the package:

1. Sign in as a SeAT administrator who can manage roles.
2. For every role recorded before the update, grant the corresponding new
   permission:

   | Existing shared permission | Add for Buyback Programs |
   | --- | --- |
   | `buyback.request` | `randulfthegrey-buyback.request` |
   | `buyback.manage` | `randulfthegrey-buyback.manage` |
   | `buyback.admin` | `randulfthegrey-buyback.admin` |

3. Leave every existing `buyback.*` grant and permission row untouched. The
   other plugin may still require it.
4. Update role-provisioning scripts, deployment checks, and other integrations
   to use the new identifier when referring to Buyback Programs.
5. After saving all role changes, clear authorization and framework caches:

   ```bash
   php artisan cache:clear redis
   php artisan optimize:clear
   ```

6. Restart Horizon and every queue worker, then verify requester, manager, and
   administrator access independently. Confirm the scheduler is active, inspect
   Program health, and run a small appraisal/Quote/Request smoke test.

Clearing Redis also removes transient cached appraisals, so requesters must begin
those appraisals again. Persisted Quotes and Requests are unaffected. The SeAT
plugin database seeder maintains plugin lifecycle data; it does not create the
new grants or synchronize permission names.

There is no ACL data migration to roll back. If the package code is reverted,
the untouched `buyback.*` grants remain available to the old code. Newly created
vendor-prefixed permission rows may remain unused; do not remove them until the
rollback is validated and no installed package consumes them. Restore the
database backup and matching Composer state if a complete rollback is required.

`optimize:clear` safely removes stale framework caches after package discovery or
route/config changes. Republish package configuration only when release notes
say its published defaults changed and after comparing local overrides; never
overwrite local configuration blindly.

## First public RC schema

There is no public package version before `1.0.0-rc.1`. Public RC installations
start from the final corrected migration set. The pre-release TYPE-rule migration
history was corrected in place so every TYPE target has exactly one
canonical `ANY` rule identity. That correction affected developer databases made
from unreleased private snapshots, not upgrades from a public release.

Public installers must **not** run `migrate:fresh`; normal `php artisan migrate`
is the supported path. Private pre-RC development databases created before the
correction must be rebuilt only according to their internal development notes.

Before any future RC-to-RC upgrade, read the applicable entry in
[CHANGELOG.md](../CHANGELOG.md) and its release notes for explicit schema
instructions. Back up immutable Quote/Request history before migration.

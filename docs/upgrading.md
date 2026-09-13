# Upgrading

Review the target release notes, back up the database and Composer files, and
test upgrades outside production when possible. During the release-candidate
phase, schema and migration compatibility may be more constrained than it will
be after stable `1.0.0`.

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
the new code. Confirm the scheduler is active, inspect Program health, and run a
small end-to-end appraisal/Quote/Request test.

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

# ADR 0013: Accept Laravel 10 for SeAT 5 with Focused Security Exceptions

- Status: Accepted
- Date: 2026-09-09
- Amended: 2026-10-01

## Context

SeAT 5 currently requires Laravel 10 through `eveseat/seat`, `eveseat/services`, and `eveseat/web`. The approved `seat-prices-core` 1.x pricing boundary also requires Laravel 10. Moving this package alone to a later Laravel major would therefore make it incompatible with the supported SeAT 5 host rather than securing that host.

Laravel 10 reached the end of upstream security support on 2025-02-04. Composer consequently refuses every Laravel 10 release because four reviewed advisory records cover the whole 10.x line:

- `PKSA-m5cs-t1y6-qpcs` / `GHSA-crmm-hgp2-wgrp` describes path confusion in local-filesystem temporary signed URLs;
- `PKSA-3r5d-mb8f-1qw9` / `GHSA-5vg9-5847-vvmq` describes CRLF injection through the default email validation rule;
- `PKSA-mdq4-51ck-6kdq` / `CVE-2026-48019` is a second database record for the same email-validation vulnerability; and
- `PKSA-d5tc-s1qs-h781` / `GHSA-jh5r-qr3c-85q8` / `CVE-2026-102279` describes low-severity DOM XSS in debug-page information tooltips when `APP_DEBUG=true`, attacker-controlled input reaches the debug page, and a user interacts with the affected tooltip. Upstream lists patches for Laravel 12 and 13 but no Laravel 10 backport.

Two other advisories shown while Composer evaluates the broad Laravel `^10.0` candidate range are patched in Laravel 10: `PKSA-w7xr-vk7n-rstm` was fixed in 10.48.23 and `PKSA-8qx3-n5y5-vvnd` was fixed in 10.48.29.

The current plugin design does not use `temporaryUrl()` or
`temporaryUploadUrl()`, and version 1 introduces no email workflow. Later
package-owned email inputs can reject carriage-return and line-feed characters
explicitly. The debug-page issue requires Laravel debug mode, which must already
be disabled in production and must not be exposed on an untrusted network.
These controls do not remediate unrelated host-application code, so a SeAT
operator remains responsible for assessing and mitigating the host deployment.

## Decision

Version 1 will continue to target SeAT 5 and Laravel 10. The Composer constraint is raised to `^10.48.29`, excluding Laravel versions affected by the two advisories that have official Laravel 10 fixes.

The development-root Composer policy keeps advisory blocking enabled and keeps audit failures enabled. It exempts only `PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, `PKSA-mdq4-51ck-6kdq`, and `PKSA-d5tc-s1qs-h781` from dependency-resolution blocking. The exceptions set `on-audit` to `false`, so the advisories remain visible to `composer audit` and continue to produce a non-zero audit result.

No package-wide or severity-wide exception is permitted. New advisories remain blocked by default. Composer configuration in this library is root-only, so these exceptions govern development in this repository and do not silently change the consuming SeAT application's policy.

The plugin MUST NOT use Laravel local-filesystem temporary signed download or upload URLs while the accepted Laravel 10 line remains affected. Any future package-owned input that can become an email recipient MUST reject `\r` and `\n` before persistence or delivery and MUST be covered by automated tests. Production deployments MUST set `APP_DEBUG=false` and MUST NOT expose Laravel debug exception pages. Any non-production environment using debug mode MUST be access-controlled and unavailable to untrusted users.

Laravel 10 is an accepted compatibility constraint, not a declaration that it is actively supported upstream. Compatibility and open advisories MUST be reviewed before release and when SeAT publishes support for a newer Laravel generation.

## Consequences

- The plugin remains installable in the current SeAT 5 ecosystem.
- Laravel 10.48.29 is the minimum permitted framework version and Composer may select newer 10.x patch releases.
- Development dependency resolution can proceed despite the reviewed residual
  Laravel 10 advisories.
- The debug-page XSS advisory is accepted only for SeAT 5 compatibility under
  the mandatory production `APP_DEBUG=false` control; the exception is not a
  declaration that publicly exposed debug mode is safe.
- `composer audit` is expected to report the accepted advisories and fail until the host platform can move to patched framework versions or equivalent reviewed fixes are adopted.
- Tests and reviews must prevent this plugin from exercising the affected temporary-URL feature or accepting CR/LF in package-owned email recipients.
- Operators using SeAT mail integrations or other host/plugin code that accepts email addresses need a separate deployment-level mitigation.
- A policy that forbids unsupported frameworks or any known high-severity advisory requires pausing deployment until SeAT supports a maintained Laravel release; it does not justify silently porting this plugin away from SeAT 5.

## Sources

- [Laravel 10 support policy](https://laravel.com/docs/10.x/releases#support-policy)
- [SeAT application Composer constraints](https://github.com/eveseat/seat/blob/master/composer.json)
- [Temporary signed URL advisory](https://github.com/advisories/GHSA-crmm-hgp2-wgrp)
- [CRLF email-validation advisory](https://github.com/advisories/GHSA-5vg9-5847-vvmq)
- [Laravel debug-page XSS advisory](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8)
- [Laravel file-validation advisory](https://github.com/advisories/GHSA-78fx-h6xr-vch4)
- [Laravel environment-manipulation advisory](https://github.com/advisories/GHSA-gv7v-rgg6-548h)
- [Composer dependency-policy configuration](https://getcomposer.org/doc/06-config.md#policy)

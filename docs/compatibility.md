# Compatibility Matrix

Verified for the targeted `1.0.0-rc.4` candidate on 2026-09-19 against the
locked dependency set and the PHP 8.2/8.4 CI matrix. Publication remains a
separate release-manager action.

| Dependency | Package constraint | Current verified upstream | Notes |
| --- | --- | --- | --- |
| SeAT application | 5.x only | `eveseat/seat` 5.0.1 | Root project requires PHP `^8.1`, Laravel `^10.0`, and SeAT components `^5.0`. |
| PHP | `^8.2` | 8.2 and 8.4 CI | `seat-prices-core` raises the effective minimum from SeAT's PHP 8.1 baseline. |
| Laravel | `^10.48.29` | 10.50.3 | SeAT 5 components require Laravel 10; ADR 0013 raises the patch floor and records EOL risk. |
| Services | `^5.1` | `eveseat/services` 5.1.0 | Provides `AbstractSeatPlugin` and SeAT plugin integration. |
| EVE API models | `^5.0.34` | `eveseat/eveapi` 5.0.37 | Direct dependency because Buyback reads SeAT-materialized SDE models. |
| Web | `^5.0.35` | `eveseat/web` 5.0.35 | Supplies the SeAT 5 web/AdminLTE/DataTables host. |
| Pricing | `^1.0.1` | `recursivetree/seat-prices-core` 1.0.1 | Exclusive market-pricing boundary; requires PHP `^8.2`. |
| Composer | 2.x | current Composer 2 | Package install and Packagist resolution. |

Authoritative references:

- [SeAT application Composer manifest](https://github.com/eveseat/seat/blob/master/composer.json)
- [SeAT Services Composer manifest](https://github.com/eveseat/services/blob/master/composer.json)
- [SeAT Eveapi Composer manifest](https://github.com/eveseat/eveapi/blob/master/composer.json)
- [SeAT Web Composer manifest](https://github.com/eveseat/web/blob/master/composer.json)
- [`seat-prices-core` on Packagist](https://packagist.org/packages/recursivetree/seat-prices-core)
- [SeAT community-package installation conventions](https://eveseat.github.io/docs/community_packages/)
- [Laravel 10 support policy](https://laravel.com/docs/10.x/releases#support-policy)

The package deliberately does not target SeAT 6. Compatibility must be
reverified before each release, when SeAT publishes a newer supported Laravel
generation, or when any required component changes its supported constraints.

# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and released versions
follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Vendor-prefix the three independent SeAT ACL identifiers as
  `randulfthegrey-buyback.request`, `randulfthegrey-buyback.manage`, and
  `randulfthegrey-buyback.admin` to avoid collisions with other plugins. This is
  an intentional breaking identifier correction before stable `1.0.0`.
- Leave legacy `buyback.*` ACL records and assignments untouched because SeAT
  does not record which plugin owns a permission title. Existing RC deployments
  must explicitly grant the corresponding new permissions to intended roles.
- Document permission discovery, the fail-closed manual remapping procedure,
  cache invalidation, and external automation updates for the targeted
  `1.0.0-rc.4` release.

## [1.0.0-rc.3] - 2026-09-18

### Fixed

- Allow corrective and non-worsening Program and rule edits when previously
  persisted policy paths already exceed the effective modifier bounds, while
  still rejecting newly introduced or worsened violations.
- Distinguish valid TYPE `REPLACE` rules at the exact `-100%` and `+100%`
  boundaries from TYPE `ADJUST` compositions that exceed those bounds.
- Report structured, deduplicated effective-policy diagnostics once in the
  administration UI, including the affected path and percentage values.

## [1.0.0-rc.2] - 2026-09-18

### Fixed

- Resolve pasted appraisal item names using case-insensitive exact matching
  while preserving canonical EVE type identity and non-fuzzy behavior.
- Reject composed effective policy modifiers outside the supported `-100%`
  through `+100%` domain, including invalid combinations saved while a Program
  is disabled.

## [1.0.0-rc.1] - 2026-09-13

### Added

- Multiple configurable Programs with independent requester, manager, and
  administrator permissions.
- Sparse deterministic GROUP/TYPE policy rules, compression-qualified GROUP
  policies, and additive basis-point modifiers.
- BUY, SELL, and dedicated/derived SPLIT pricing through `seat-prices-core`.
- CCP SDE-backed compression synchronization with last-known-good behavior.
- Transient trusted appraisals, immutable payable-only Quotes, and fulfillment
  Requests with concurrency-safe terminal transitions.
- SeAT Blade/AdminLTE/DataTables requester, manager, and administration flows.
- Public installation, upgrade, configuration, operations, and release guides.
- CCP proprietary notice and synthetic compression test mappings suitable for
  public distribution.

### Changed

- Finalized the public Composer identity as
  `randulfthegrey/seat-buyback-programs` and root PHP namespace as
  `RandulfTheGrey\Seat\BuybackPrograms`.
- Relicensed the package under the MIT License for its first public release.
- Chose a checksum-backed, clean public GitHub release history; GitLab remains
  authoritative through RC validation and GitHub becomes authoritative after
  the stable-release cutover.

### Known limitations

- No automatic contract reconciliation, direct provider client, partial
  fulfillment, payout ledger, manual price override, Program eligibility,
  notifications, category rules, or arbitrary policy expressions.
- The release targets SeAT 5/Laravel 10. Laravel 10 is end-of-life upstream; the
  reviewed compatibility/security policy is documented in ADR 0013.
- Public contribution workflow remains deferred until after stable `1.0.0`.

[Unreleased]: https://github.com/randulfTheGrey/seat-buyback-programs/compare/v1.0.0-rc.3...HEAD
[1.0.0-rc.3]: https://github.com/randulfTheGrey/seat-buyback-programs/compare/v1.0.0-rc.2...v1.0.0-rc.3
[1.0.0-rc.2]: https://github.com/randulfTheGrey/seat-buyback-programs/compare/v1.0.0-rc.1...v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/randulfTheGrey/seat-buyback-programs/releases/tag/v1.0.0-rc.1

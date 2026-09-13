# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and released versions
follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

No unreleased changes are currently scheduled beyond release-candidate
validation.

## [1.0.0-rc.1] - Unreleased

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

[Unreleased]: https://github.com/randulfTheGrey/seat-buyback-programs/compare/v1.0.0-rc.1...HEAD
[1.0.0-rc.1]: https://github.com/randulfTheGrey/seat-buyback-programs/releases/tag/v1.0.0-rc.1

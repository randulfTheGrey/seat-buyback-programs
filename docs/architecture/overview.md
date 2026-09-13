# Architecture Overview

## Purpose and scope

This repository defines a reusable buyback-program plugin for existing SeAT 5 installations. It is a normal Composer-distributed SeAT ecosystem package and is intentionally independent of deployment-specific corporation-management systems and other private platforms.

The private GitLab repository is the authority for active development and issue tracking during release-candidate validation. GitHub receives a clean, verified release history for public distribution and becomes the development authority only after stable-release validation and the recorded cutover defined by ADR 0015. Releases are intended to be consumable through GitHub, Composer, and Packagist.

The locked public identities are GitHub repository `randulfTheGrey/seat-buyback-programs`, Composer package `randulfthegrey/seat-buyback-programs`, and PHP root namespace `RandulfTheGrey\Seat\BuybackPrograms`. The SeAT menu/display name remains the user-facing `Buyback Programs`.

## Documentation authority

The documents in `docs/architecture/` and accepted ADRs in `docs/adr/` are the implementation authority and MUST be read as one design corpus. Implementation issues may refine mechanics, but MUST NOT silently alter an approved behavior, boundary, or invariant. If implementation exposes a necessary material architecture change, the relevant architecture document and ADR MUST be updated or explicitly superseded before implementation proceeds with the changed semantics.

## Architectural principles

- The package MUST integrate through current SeAT 5 plugin conventions and `Seat\Services\AbstractSeatPlugin`.
- The supported SeAT 5 baseline requires Laravel `^10.48.29`. The focused development-root security exceptions, prohibited affected features, and required review conditions are governed by ADR 0013; advisory blocking MUST remain enabled for every other advisory.
- All plugin persistence MUST remain plugin-owned. The plugin MUST NOT alter SeAT core tables.
- Prefer SeAT's materialized SDE and EVE models to independent ESI access.
- Market retrieval, buyback policy, and historical financial evidence MUST remain separate.
- Use exact decimal arithmetic for prices and integer basis points for policy modifiers.
- Quotes MUST be immutable, and Requests MUST remain a separate fulfillment lifecycle.
- Failures MUST be normalized at subsystem boundaries and MUST NEVER silently change financial policy.
- Use Blade, AdminLTE, and DataTables for the version 1 interface.
- Use queues for suitable background maintenance; keep ordinary appraisal pricing synchronous.

## System context and boundaries

The plugin has three important upstream boundaries:

1. **SeAT** supplies users, authorization integration, and materialized SDE/EVE data. SeAT user and SDE identifiers may be stored as application-level values, but plugin tables do not create foreign keys into SeAT tables.
2. **`seat-prices-core`** exclusively supplies market reference prices. The plugin identifies opaque configured provider instances; it contains no provider-specific clients, provider registry, Fuzzwork or Janice domain logic, private price cache, or scheduled price warming in version 1.
3. **CCP Static Data Export** is the authority for compression relationships. A small plugin-owned projection is refreshed outside migrations and appraisal requests.

Within the plugin, four concepts remain distinct:

- a **Program** holds mutable administrative configuration;
- an **AppraisalResult** is short-lived trusted server-side state;
- a **Quote** is an immutable financial offer containing payable lines only;
- a **BuybackRequest** tracks fulfillment of one Quote.

## Main components

### Program administration

Administrators configure multiple Programs, their default policies, logical price-reference channels, sparse rules, validity periods, and optional contract instructions. A new Program is `DISABLED` and defaults to accepting items unless a rule rejects them.

The editor is divided into General, Default Policy, Price References, and Contract Instructions. Rule management uses searchable SeAT SDE type/group selectors and invokes the production evaluator for effective-rule previews.

### Policy evaluator

The evaluator starts from the Program defaults, which are the sole semantic `GLOBAL` policy, and applies sparse `GROUP` and `TYPE` rules. Each rule changes acceptance, reference mode, and modifier independently. Specificity is deterministic; no administrator-controlled priority exists. The evaluator produces both the effective policy and an explanation of how each field was resolved.

### Compression classifier

The classifier uses the canonical SDE `compressibleTypes` relationship. A minimal active mapping identifies the uncompressed and compressed side of every pair and yields `UNCOMPRESSED`, `COMPRESSED`, or `NOT_APPLICABLE` at runtime.

### Pricing adapter and planner

The planner deduplicates accepted type IDs and consolidates bulk requests by provider instance. The adapter normalizes `seat-prices-core` results into precise decimals and provider-independent outcomes. Logical `BUY`, `SELL`, and `SPLIT` channels are Program semantics, not claims about provider internals.

### Appraisal pipeline

An appraisal parses pasted inventory, resolves exact EVE types, merges duplicates, classifies compression, evaluates policy, excludes rejected items, prices accepted items, applies modifiers, and returns a transient result. A short-lived opaque cache token binds the result to one user and permits idempotent creation of at most one Quote.

### Quote and Request services

Quote creation atomically persists only `PRICED` lines and the snapshots needed to explain the offer later. Submission creates at most one Request per Quote. Request transitions use conditional transactional updates so concurrent terminal actions cannot both succeed.

## High-level flow

1. A user with `buyback.request` selects an enabled Program and submits inventory text.
2. The synchronous appraisal pipeline resolves, evaluates, prices, and caches a trusted `AppraisalResult`.
3. The UI displays priced and non-payable outcomes without accepting client-calculated money.
4. The user exchanges the opaque token for an immutable Quote. The Program must still be enabled.
5. A valid, unsubmitted Quote may create one pending Buyback Request, even if the Program was later disabled or archived.
6. The requester or a manager maintains permitted pending fields and performs their respective terminal actions.

## Data ownership

The plugin owns these persistence areas:

- Programs and logical price-reference configuration;
- sparse rules;
- compression mappings and dataset metadata;
- immutable Quotes and QuoteItems;
- Buyback Requests and lifecycle evidence.

Internal relationships use plugin foreign keys. References to SeAT users/SDE rows and `seat-prices-core` provider instances are stored as application-level identifiers without cross-package database constraints. Quotes and Requests have internal numeric keys plus public ULID-style identifiers.

## Authorization

SeAT-native permissions are independent:

- `buyback.request` covers appraisals and the requester's own Quotes and Requests;
- `buyback.manage` covers all submitted Requests and manager actions;
- `buyback.admin` covers Program, rule, price-reference, and reference-data administration.

No permission implies another. Policies/resource authorization and query scoping enforce ownership and role boundaries. Mutations use normal POST, PATCH, or DELETE routes with CSRF protection; no GET route changes state.

## Operational behavior

Ordinary appraisals price synchronously. Background jobs may maintain the compression projection and other operational diagnostics. Compression refresh validates a candidate dataset and atomically replaces the active projection; failure preserves the last-known-good version.

Stored Program validity and runtime dependency health are separate. For example, deletion of an external provider instance degrades health and blocks affected pricing but does not silently disable the Program or redirect it to another channel.

## Version 1 exclusions

Version 1 does not include:

- Program-specific user, corporation, or alliance eligibility;
- categories, market groups, expressions, name patterns, or arbitrary rule ordering;
- provider-specific pricing implementations or a private price cache;
- persistent appraisals;
- payout overrides, partial Request completion, reopening, or mutable Quote lines;
- automatic EVE contract reconciliation;
- unused placeholder reconciliation interfaces.

The future reconciliation seam is described in [Workflows and Lifecycle](workflows-and-lifecycle.md) and ADR 0011.

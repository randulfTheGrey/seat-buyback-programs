# Testing and Implementation Plan

## Purpose

This document records the approved delivery sequence and verification strategy. It is planning documentation only: the architecture-capture work does not create implementation child issues or plugin functionality.

## Test approach

Prefer fast domain tests for deterministic policy and money behavior, focused integration tests at SeAT and `seat-prices-core` boundaries, database tests for transactional invariants, and HTTP/authorization tests for routes and scopes. Normal CI is deterministic and does not depend on live CCP, Fuzzwork, Janice, or ESI HTTP services.

Fixtures record representative SDE/provider shapes without copying provider-specific behavior into the domain. Contract tests keep external adapters thin. Concurrency tests verify outcomes rather than relying solely on implementation mocks.

## Architecture Guardrail Matrix

This matrix maps approved invariants to mandatory regression behavior. Test organization and future method names may vary, but every behavior below MUST remain covered. The linked architecture document or ADR is the primary semantic authority; this matrix does not redefine it.

| Behavior | Required regression-test behavior | Primary authority |
| --- | --- | --- |
| New Program default policy | A newly created Program is `DISABLED` with default acceptance `ACCEPT`. | [Domain Model](domain-model.md), [Workflows](workflows-and-lifecycle.md) |
| Configurable global rejection | A Program configured with default `REJECT` rejects an otherwise unmatched type. | [Rule Engine](rule-engine.md), [ADR 0003](../adr/0003-sparse-hierarchical-rule-engine.md) |
| Single global policy source | Program defaults are the sole `GLOBAL` contribution; explanation contains exactly one global/default baseline and no persisted sparse `GLOBAL` rule can alter it. | [Rule Engine](rule-engine.md), [ADR 0003](../adr/0003-sparse-hierarchical-rule-engine.md) |
| TYPE overrides GROUP rejection | A TYPE `ACCEPT` re-accepts a type rejected by its unqualified GROUP. | [Rule Engine](rule-engine.md) |
| TYPE overrides compression-qualified GROUP rejection | A TYPE `ACCEPT` re-accepts a compressed or uncompressed type rejected by its matching qualified GROUP. | [Rule Engine](rule-engine.md), [ADR 0003](../adr/0003-sparse-hierarchical-rule-engine.md) |
| Single TYPE layer | A Program + TypeID has exactly one effective TYPE rule and new explanations contain at most one `TYPE` layer with no `TYPE_COMPRESSION` source. | [Rule Engine](rule-engine.md), [ADR 0003](../adr/0003-sparse-hierarchical-rule-engine.md) |
| TYPE qualifier persistence | TYPE rules persist canonical `ANY`; application and database boundaries reject independent `COMPRESSED` or `UNCOMPRESSED` qualifiers. | [Domain Model](domain-model.md), [ADR 0014](../adr/0014-bind-item-type-rules-to-intrinsic-compression-state.md) |
| GROUP qualifiers remain layered | For a matching item, GROUP+`ANY` can supply reference/modifier while GROUP+`COMPRESSED` or GROUP+`UNCOMPRESSED` changes acceptance; both contribute in order. | [Rule Engine](rule-engine.md) |
| GROUP qualifier availability | Administration offers and accepts each compression qualifier only when the selected group has a canonical member of that classification; `ANY` always remains valid. | [Rule Engine](rule-engine.md), [ADR 0014](../adr/0014-bind-item-type-rules-to-intrinsic-compression-state.md) |
| GROUP changes reference only | A GROUP rule selects `SELL` while inheriting the existing modifier unchanged. | [Rule Engine](rule-engine.md) |
| TYPE changes modifier only | A TYPE modifier operation changes basis points while inheriting the effective reference mode. | [Rule Engine](rule-engine.md) |
| Additive `ADJUST` | `-1000` bps followed by `ADJUST -500` produces `-1500`, not a compounded percentage. | [Rule Engine](rule-engine.md), [ADR 0004](../adr/0004-additive-basis-point-modifiers.md) |
| `REPLACE` resets modifier | An accumulated `-1500` followed by `REPLACE +250` produces exactly `+250`. | [Rule Engine](rule-engine.md), [ADR 0004](../adr/0004-additive-basis-point-modifiers.md) |
| Modifier bounds | Out-of-range configured values and out-of-range effective combinations are rejected explicitly. | [Rule Engine](rule-engine.md) |
| Derived SPLIT requires both sides | Missing/unavailable BUY or SELL makes derived SPLIT unavailable; no one-sided midpoint or fallback is produced. | [Pricing](pricing-and-reference-data.md), [ADR 0005](../adr/0005-logical-buy-sell-split-reference-channels.md) |
| Provider-instance consolidation | Each distinct required provider-instance ID receives exactly one initial bulk call with deduplicated type IDs. | [Pricing](pricing-and-reference-data.md) |
| Three consecutive recovery failures | The third consecutive per-type failure stops recovery and marks every dependent line in every logical channel using that provider stream `REFERENCE_UNAVAILABLE`. | [Pricing](pricing-and-reference-data.md), [ADR 0005](../adr/0005-logical-buy-sell-split-reference-channels.md) |
| Unaffected channels continue | A systemic failure in one provider-dependent channel does not suppress independent outcomes from unaffected channels. | [Pricing](pricing-and-reference-data.md) |
| Rejected items never reach pricing | `EXCLUDED` types are absent from provider request plans and calls. | [Workflows](workflows-and-lifecycle.md) |
| Non-payable outcomes never enter Quote | `EXCLUDED`, `UNPRICED`, `REFERENCE_UNAVAILABLE`, `UNKNOWN`, and `INVALID_INPUT` produce no QuoteItem. | [Workflows](workflows-and-lifecycle.md), [ADR 0007](../adr/0007-immutable-payable-only-quotes.md) |
| Payable set cannot be cherry-picked | Quote creation persists all `PRICED` appraisal lines atomically, one item per merged type ID, and rejects client attempts to select a subset. | [Domain Model](domain-model.md), [ADR 0007](../adr/0007-immutable-payable-only-quotes.md) |
| HALF_UP unit rounding | An exact calculation ending at a half-cent rounds the final unit price `HALF_UP` to `0.01 ISK`; totals multiply that rounded unit value. | [Pricing](pricing-and-reference-data.md) |
| Server-derived Quote total | Client-supplied prices/totals are ignored and the Quote total equals the exact sum of server-derived line totals. | [Domain Model](domain-model.md), [ADR 0008](../adr/0008-transient-server-side-appraisals.md) |
| Quote survives market/policy/provider drift | Market movement, rule edits, and provider rename/deletion leave persisted Quote evidence and money unchanged. | [ADR 0001](../adr/0001-market-price-policy-and-quote-separation.md), [ADR 0007](../adr/0007-immutable-payable-only-quotes.md) |
| Disabled Program blocks appraisal conversion | Disabling a Program after appraisal causes appraisal-to-Quote conversion to fail without repricing or partial persistence. | [Workflows](workflows-and-lifecycle.md), [ADR 0008](../adr/0008-transient-server-side-appraisals.md) |
| Disabled/archived Program permits valid submission | An existing unexpired Quote can create its Request after the Program becomes `DISABLED` or `ARCHIVED`. | [Workflows](workflows-and-lifecycle.md), [ADR 0009](../adr/0009-separate-quote-and-request-lifecycles.md) |
| Existing Request ignores later Program status | Pending Request edits/transitions and authorized historical views continue after Program disable/archive. | [Workflows](workflows-and-lifecycle.md) |
| Expired Quote cannot submit | Submission at or after expiry creates no Request and requires full re-appraisal. | [Workflows](workflows-and-lifecycle.md) |
| Quote submission is idempotent | Concurrent/repeated submissions return one Request for the Quote and never duplicate it. | [ADR 0009](../adr/0009-separate-quote-and-request-lifecycles.md) |
| Appraisal token ownership and validity | Another user cannot consume the token; retry returns the same Quote; creation never extends the pricing-completion deadline. | [ADR 0008](../adr/0008-transient-server-side-appraisals.md) |
| Cancellation/completion race | Concurrent requester cancellation and manager completion yield exactly one terminal state; the loser receives `REQUEST_NOT_PENDING`. | [Workflows](workflows-and-lifecycle.md), [ADR 0009](../adr/0009-separate-quote-and-request-lifecycles.md) |
| Permission independence | A principal with only `buyback.admin` cannot view manager-only data or complete/reject a Request. | [ADR 0010](../adr/0010-seat-native-permissions.md) |
| Requester query isolation | Requester DataTables, pages, and direct resource routes cannot expose another user's Quote or Request. | [Workflows](workflows-and-lifecycle.md), [ADR 0010](../adr/0010-seat-native-permissions.md) |
| Failed SDE refresh preserves active data | Invalid/failed candidate import leaves the complete last-known-good mapping active and reports degraded health. | [Pricing](pricing-and-reference-data.md), [ADR 0006](../adr/0006-sde-backed-compression-classification.md) |
| Missing versus stale compression data | Missing initial data blocks only a Program using compression-qualified rules; stale valid data remains usable with an admin warning. | [ADR 0006](../adr/0006-sde-backed-compression-classification.md) |
| Reconciliation observation semantics | Future tests treat `NOT_OBSERVED` separately from invalid, incomplete materialization separately from mismatch, and never mutate a Quote after mismatch. | [ADR 0011](../adr/0011-seat-materialized-contract-reconciliation-boundary.md) |

Additional suites MUST cover all legal/illegal Request transitions, pending-field edit/visibility rules, rejection-reason requirements, optional contract IDs, exact decimal edge cases (zero prices, low-value/high-volume items, large quantities, maximum discounts/premiums), fixture-based compression classification/import, thin SeAT and `seat-prices-core` adapter contracts, CSRF/non-GET mutation enforcement, and absence of live CCP/provider HTTP dependencies from normal CI.

## Approved implementation sequence

The order below is the approved planning order. Separate implementation issues may be created later, but are intentionally not created by this documentation task.

### 1. Plugin foundation and package skeleton

- **Purpose/scope:** Establish Composer metadata, `AbstractSeatPlugin` integration, service-provider/configuration boundaries, route namespaces, migration/testing harness, SeAT permission registration, and baseline quality gates without downstream business behavior.
- **Dependencies:** None; this is the foundation consumed by every later slice.
- **Governing decisions:** [Overview](overview.md), [ADR 0010](../adr/0010-seat-native-permissions.md), and [ADR 0012](../adr/0012-private-gitlab-development-public-github-distribution.md).
- **Acceptance criteria:** The package boots under supported SeAT 5 conventions; registers all three independent permissions; has plugin-owned migration space; and contains no provider-specific clients, independent ESI retrieval, or business placeholders.
- **Required regression tests:** Package/service-provider boot, configuration publication/loading, route/permission registration, and test-harness execution.

### 2. Core domain model and schema

- **Purpose/scope:** Introduce string-backed enums/value concepts and plugin-owned Program, reference, Rule, compression, Quote/QuoteItem, and Request persistence.
- **Dependencies:** Slice 1 migration/test foundation.
- **Governing decisions:** [Domain Model](domain-model.md), [ADR 0001](../adr/0001-market-price-policy-and-quote-separation.md), [ADR 0007](../adr/0007-immutable-payable-only-quotes.md), and [ADR 0009](../adr/0009-separate-quote-and-request-lifecycles.md).
- **Acceptance criteria:** Internal plugin relationships are constrained; external SeAT/provider IDs have no database FKs; Quotes/Requests have numeric internal and public ULID-style IDs; Request ownership derives through Quote; monetary columns support exact arithmetic; and no non-payable QuoteItem status is persisted.
- **Required regression tests:** Migration up/down behavior, uniqueness constraints (rule identity, Quote+type, one Request per Quote), enum round trips, external-reference deletion tolerance, and decimal capacity.

### 3. Rule engine

- **Purpose/scope:** Build the pure deterministic evaluator, per-field resolution/provenance, modifier operations/bounds, explanation output, and rule validation.
- **Dependencies:** Slice 2 policy value types and Rule/Program representation.
- **Governing decisions:** [Rule Engine](rule-engine.md), [ADR 0003](../adr/0003-sparse-hierarchical-rule-engine.md), and [ADR 0004](../adr/0004-additive-basis-point-modifiers.md).
- **Acceptance criteria:** Evaluation follows exactly Program defaults / GLOBAL -> GROUP -> GROUP+compression -> TYPE; Program defaults are the sole global contribution; qualified/unqualified GROUP rules both contribute; TYPE contributes once; fields inherit independently; rejection does not short-circuit; no priority/creation-order behavior exists; and explanation output identifies every contribution.
- **Required regression tests:** All rule-engine rows in the guardrail matrix, the single-global invariant, no-op/duplicate validation, `NOT_APPLICABLE` matching, configured/effective bounds, and stable explanation output.

### 4. Compression SDE classification

- **Purpose/scope:** Build the canonical SDE projection importer, dataset metadata/health, exact classifier, explicit synchronization, and scheduled build-aware refresh.
- **Dependencies:** Slices 1-2 persistence/queue foundations; no dependency on appraisal HTTP flows.
- **Governing decisions:** [Pricing and Reference Data](pricing-and-reference-data.md) and [ADR 0006](../adr/0006-sde-backed-compression-classification.md).
- **Acceptance criteria:** Only canonical `compressibleTypes` pairs are projected; no name/dogma/manual override path exists; candidate validation precedes atomic activation; network work occurs outside migrations/interactive requests; and last-known-good data survives failures.
- **Required regression tests:** Fixture importer, all three runtime classifications, invalid candidate rejection, atomic replacement, build-aware no-op, missing/stale health behavior, and last-known-good preservation.

### 5. `seat-prices-core` integration

- **Purpose/scope:** Integrate opaque provider instances through a thin adapter, bulk planner, exact decimal normalization, derived SPLIT, bounded recovery, normalized failures, and provenance capture.
- **Dependencies:** Slice 1 integration foundation, Slice 2 reference/value storage, and Slice 3 effective policy output.
- **Governing decisions:** [Pricing and Reference Data](pricing-and-reference-data.md), [ADR 0002](../adr/0002-seat-prices-core-pricing-boundary.md), and [ADR 0005](../adr/0005-logical-buy-sell-split-reference-channels.md).
- **Acceptance criteria:** No backend is called directly; each provider instance receives one deduplicated bulk call; both derived components are mandatory; no reference fallback exists; recovery/failure taxonomy is exact; unaffected channels continue; and snapshots carry instance IDs/names plus required components.
- **Required regression tests:** Pricing rows in the guardrail matrix, provider drift/misconfiguration, isolated `UNPRICED`, recovery counter reset on success, dedicated/derived SPLIT, shared-provider consolidation, caching independence, and no-fallback cases.

### 6. Appraisal pipeline

- **Purpose/scope:** Compose parsing, exact SeAT SDE lookup, duplicate merging, compression, policy, rejection-before-pricing, price planning, exact calculation, outcome rendering, and trusted cache-token creation.
- **Dependencies:** Slices 3-5 and the Program persistence from Slice 2.
- **Governing decisions:** [Workflows and Lifecycle](workflows-and-lifecycle.md), [Pricing and Reference Data](pricing-and-reference-data.md), and [ADR 0008](../adr/0008-transient-server-side-appraisals.md).
- **Acceptance criteria:** The 12-stage pipeline order is preserved; one result line exists per resolved type; all approved line outcomes are distinguishable; partial appraisals are displayable; rejected types never reach pricing; tokens are opaque/user-owned/short-lived; and no client monetary value is trusted.
- **Required regression tests:** Parser/unknown/invalid cases, duplicate merging, pipeline ordering, rejected-item exclusion, mixed outcomes, full re-appraisal behavior, token ownership/expiry, and exact money examples/edge cases.

### 7. Immutable Quote creation

- **Purpose/scope:** Convert one trusted appraisal token into one immutable, payable-only Quote and its historical snapshots.
- **Dependencies:** Slice 2 Quote schema and Slice 6 appraisal/token output.
- **Governing decisions:** [Domain Model](domain-model.md), [Workflows and Lifecycle](workflows-and-lifecycle.md), [ADR 0007](../adr/0007-immutable-payable-only-quotes.md), and [ADR 0008](../adr/0008-transient-server-side-appraisals.md).
- **Acceptance criteria:** Program is rechecked as ENABLED; validity starts at pricing completion; token consumption and all Quote rows are atomic/idempotent; all and only `PRICED` merged types enter; totals are server-derived; snapshots are versioned; and state is derived rather than stored.
- **Required regression tests:** Payable-set, rounding, token, Program-disable, drift immutability, atomic-failure, one-item-per-type, derived-state, and no-client-selection rows from the guardrail matrix.

### 8. Buyback Request lifecycle

- **Purpose/scope:** Implement idempotent Quote submission, pending Request fields, authorized terminal transitions, conflict normalization, and lifecycle events.
- **Dependencies:** Slice 7 valid immutable Quotes and Slice 2 Request constraints.
- **Governing decisions:** [Workflows and Lifecycle](workflows-and-lifecycle.md), [ADR 0009](../adr/0009-separate-quote-and-request-lifecycles.md), and [ADR 0010](../adr/0010-seat-native-permissions.md).
- **Acceptance criteria:** One Request exists per Quote; ownership derives through Quote; only the three approved transitions exist; rejection reason is required; contract ID is optional; pending edit/visibility rules are enforced; terminal data is read-only; and races return `REQUEST_NOT_PENDING` rather than 500.
- **Required regression tests:** All legal/illegal transitions, idempotent submission, expiry, Program status independence, pending edits, note visibility, optional contract completion, rejection validation, and competing terminal actions.

### 9. Requester UI

- **Purpose/scope:** Deliver the Program -> Paste -> Appraisal -> Quote -> Request journey and requester-owned history using Blade/AdminLTE/DataTables.
- **Dependencies:** Slices 6-8 and `buyback.request` authorization.
- **Governing decisions:** [Workflows and Lifecycle](workflows-and-lifecycle.md), [ADR 0007](../adr/0007-immutable-payable-only-quotes.md), [ADR 0008](../adr/0008-transient-server-side-appraisals.md), and [ADR 0010](../adr/0010-seat-native-permissions.md).
- **Acceptance criteria:** Every appraisal status is understandable; payable Quote contents are explicit; retry means full re-appraisal; “My Buybacks” focuses on submitted Requests; requester pending edits/cancellation are available; manager-only data is absent; and no GET changes state.
- **Required regression tests:** End-to-end requester happy/error paths, mixed appraisal rendering, expired appraisal/Quote recovery, ownership isolation in pages/DataTables/direct URLs, CSRF/method enforcement, and manager-note non-disclosure.

### 10. Admin UI

- **Purpose/scope:** Deliver Program, logical reference, sparse Rule, effective-preview, and reference-data health administration.
- **Dependencies:** Slices 2-5 and `buyback.admin` authorization.
- **Governing decisions:** [Rule Engine](rule-engine.md), [Pricing and Reference Data](pricing-and-reference-data.md), [ADR 0003](../adr/0003-sparse-hierarchical-rule-engine.md), [ADR 0005](../adr/0005-logical-buy-sell-split-reference-channels.md), [ADR 0006](../adr/0006-sde-backed-compression-classification.md), and [ADR 0010](../adr/0010-seat-native-permissions.md).
- **Acceptance criteria:** UI sections/wording match the approved workflow; provider instances remain opaque; SPLIT strategy is explicit; SDE selectors require no numeric IDs; modifier semantics say percentage points; no-op/invalid rules are blocked; preview uses production evaluation; and health is separate from stored validity.
- **Required regression tests:** Program defaults/status, every control mapping, searchable selector authorization, duplicate/no-op validation, preview parity, provider drift warnings, compression health/sync behavior, and admin-without-manage denial.

### 11. Manager UI and authorization hardening

- **Purpose/scope:** Deliver manager-wide submitted-Request operations and harden all collection/resource authorization boundaries.
- **Dependencies:** Slices 8-10 and `buyback.manage` registration.
- **Governing decisions:** [Workflows and Lifecycle](workflows-and-lifecycle.md) and [ADR 0010](../adr/0010-seat-native-permissions.md).
- **Acceptance criteria:** Managers can view/manage all submitted Requests and only approved fields/actions; requester/admin permissions imply nothing; requester/manager note visibility is exact; all queries and resources enforce scope; all mutations are CSRF-protected non-GET; and state races are normalized.
- **Required regression tests:** Permission-combination matrix, requester DataTable isolation, direct-ID access, manager notes, contract correction, complete/reject validation, terminal read-only behavior, HTTP method/CSRF checks, and transition races.

### 12. Documentation, release hardening, and community packaging

- **Purpose/scope:** Complete user/operator guidance, compatibility and upgrade/release procedures, security review, Composer/Packagist readiness, and planned public GitHub distribution.
- **Dependencies:** All functional slices and their passing verification suites.
- **Governing decisions:** This full architecture corpus, especially [Overview](overview.md) and [ADR 0012](../adr/0012-private-gitlab-development-public-github-distribution.md).
- **Acceptance criteria:** Documentation matches implemented behavior; GitLab remains authoritative until an explicit release process says otherwise; public packaging contains no private configuration/secrets; compatibility is declared; and all guardrail/integration/authorization suites pass.
- **Required regression tests:** Full supported-version CI matrix, clean package install/upgrade, documentation/link validation, distribution-archive inspection, secret/private-configuration checks, and complete Architecture Guardrail Matrix execution.

## Cross-slice completion criteria

Every slice MUST preserve the architecture documents and accepted ADRs, add proportionate automated tests, avoid unused future abstractions, update relevant operator/user documentation, and keep external boundaries thin. Implementation issues may refine mechanics but MUST NOT silently alter approved semantics. If a material change becomes necessary, the governing architecture document and ADR MUST be updated or explicitly superseded before implementation continues under the new decision.

A slice is not complete if it silently degrades financial policy, relies on provider caching for correctness, introduces live external dependencies into normal CI, or weakens historical immutability/authorization.

## Deferred work

Version 1 defers Program-specific eligibility, additional rule dimensions, provider implementations, private market caching/warming, automatic contract reconciliation, partial completion, payout overrides, terminal reopening, and unused reconciliation placeholders. Deferrals may be reconsidered only through explicit follow-up decisions rather than incidental implementation.

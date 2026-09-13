# ADR 0002: Make `seat-prices-core` the Exclusive Pricing Boundary

- Status: Accepted
- Date: 2026-09-09

## Context

The plugin needs market data, while the SeAT ecosystem already has `seat-prices-core` for provider configuration and retrieval. Reimplementing provider behavior would create competing abstractions, caches, and operational semantics.

## Decision

All market-price retrieval MUST go through a thin adapter to `seat-prices-core`. Buyback treats provider-instance IDs as opaque external application identifiers and does not inspect backend-specific configuration. It MUST NOT call Fuzzwork, Janice, SeAT Prices, or any other pricing backend directly.

Version 1 contains no provider-specific HTTP clients, competing provider registry, Fuzzwork/Janice domain logic, private price cache, or scheduled price warming. Pricing is bulk-first, deduplicated, and synchronous for ordinary appraisals. Provider caching may help performance but is never required for correctness.

No database foreign key points into `seat-prices-core`.

## Consequences

- Provider availability and caching remain owned by `seat-prices-core`.
- Missing/deleted instances are explicit runtime drift, not referential-database failures.
- Contract tests are required at the thin adapter boundary.
- Buyback can snapshot provider instance identity without persisting provider payloads or configuration dumps.

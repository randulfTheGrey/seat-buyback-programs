# ADR 0011: Reserve a SeAT-Materialized Contract Reconciliation Boundary

- Status: Accepted
- Date: 2026-09-09

## Context

Future automation may compare submitted EVE contracts with immutable Quotes. SeAT already materializes character/corporation contracts, details, and items, but version 1 does not need reconciliation and should not add an independent ESI dependency or speculative abstractions.

## Decision

Do not implement automatic contract reconciliation in version 1. Preserve the future boundary through immutable Quote/QuoteItems, an optional numeric `eve_contract_id`, Program contract instructions, and lifecycle events.

Any future implementation MUST read SeAT materialized contract models rather than fetching ESI independently. It distinguishes `NOT_OBSERVED`, incomplete SeAT materialization, matched, and mismatched. `NOT_OBSERVED` is not invalid, and incomplete materialization is not a mismatch. Verification may cover ID/type, issuer, recipient/assignee, item quantities, contract price, and status. A mismatch never mutates the immutable Quote.

Concepts such as `SeatContractRepository`, `ObservedContract`, `ContractReconciliationService`, and `ContractReconciliationResult` may be introduced only when implemented; version 1 must not contain unused placeholders. Reconciliation success does not automatically imply Request completion.

## Consequences

- Version 1 stays focused and avoids duplicate ESI/cache ownership.
- Existing data preserves a clean path to later reconciliation.
- Future comparison must handle materialization latency/incompleteness explicitly.
- Automatic completion remains a separate policy decision.

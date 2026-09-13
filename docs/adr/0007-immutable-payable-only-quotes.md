# ADR 0007: Persist Immutable, Payable-Only Quotes

- Status: Accepted
- Date: 2026-09-09

## Context

An appraisal can contain unknown, rejected, or unpriced lines alongside successful calculations. A Quote is a financial offer and must remain understandable after markets, policy, or external provider configuration changes.

## Decision

After duplicate type IDs have been merged during appraisal, Quote creation MUST persist exactly one QuoteItem for every `PRICED` type ID and no other lines. Every QuoteItem is payable by definition. The requester cannot select only some payable lines. `UNKNOWN`, `INVALID_INPUT`, `EXCLUDED`, `UNPRICED`, and `REFERENCE_UNAVAILABLE` outcomes remain visible only in the transient appraisal.

QuoteItems snapshot type identity/name, quantity, compression, logical/reference resolution, raw reference unit price, effective modifier, rounded final unit price, line total, and versioned policy/pricing provenance. Provider evidence includes instance ID/name and necessary component prices, not HTTP payloads or configuration dumps.

The server derives line totals and the Quote total from trusted appraisal values. Quote creation is atomic; persistence failure creates no partial Quote. Quotes and their monetary/policy data never change. Quote state is derived as AVAILABLE, SUBMITTED, or EXPIRED from expiry and Request existence rather than a persisted status column.

## Consequences

- Quote totals represent the complete payable offer with no ambiguous zero-value lines.
- Historical explanation survives rule, market, Program, and provider drift.
- Snapshots consume more storage but prevent later joins from redefining history.
- Expired offers require a new appraisal.

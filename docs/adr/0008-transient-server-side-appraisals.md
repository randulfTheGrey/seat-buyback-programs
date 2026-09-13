# ADR 0008: Keep Appraisals as Transient Trusted Server-Side State

- Status: Accepted
- Date: 2026-09-09

## Context

Users need to inspect pricing outcomes before creating a Quote, but storing every attempt as a financial record adds lifecycle and cleanup complexity. Posting calculated values back from the browser would permit tampering.

## Decision

Store each completed `AppraisalResult` temporarily in Laravel cache under an opaque token. The state is server-trusted but is not a persistent market-price cache or financial record.

The token belongs to one user, expires no later than Quote validity, and can create at most one Quote. Quote creation is idempotent so retries return/reuse the same Quote. Program status is checked again and must remain ENABLED. If enabled, intervening rule/provider changes do not recalculate the cached result.

Validity begins when appraisal pricing completes, and Quote creation never extends it. Client-submitted prices, modifiers, totals, or snapshots are ignored as authority.

## Consequences

- The UI can show non-payable outcomes without polluting financial persistence.
- Cache consumption and database creation require atomic/idempotent coordination.
- A lost or expired token requires re-appraisal.
- Tests must cover ownership, expiry, duplicate submissions, and Program lifecycle races.

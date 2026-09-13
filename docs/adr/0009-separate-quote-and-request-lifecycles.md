# ADR 0009: Separate Quote and Buyback Request Lifecycles

- Status: Accepted
- Date: 2026-09-09

## Context

A Quote is a fixed offer, while a submitted buyback requires mutable fulfillment data and controlled terminal actions. One status model would either make the offer mutable or burden it with operational workflow fields.

## Decision

Model `BuybackRequest` as a separate aggregate referencing one immutable Quote. Requester ownership is derived through that Quote and MUST NOT be duplicated on the Request. A Quote creates at most one Request, and submission is idempotent.

Requests start `PENDING`. The only transitions are PENDING to COMPLETED or REJECTED by a manager, and PENDING to CANCELED by the requester. Rejection requires a requester-visible reason. Terminal states cannot reopen.

While pending, requester and manager may update only their authorized contract/note fields. The numeric EVE contract ID is optional, including on completion. There is no partial completion, Quote-item mutation, or payout override.

Transitions use conditional transactions so concurrent attempts produce one terminal outcome and `REQUEST_NOT_PENDING` conflicts for losers. Existing Requests remain processable and viewable regardless of later Program disable/archive.

## Consequences

- Financial evidence remains immutable while fulfillment can progress independently.
- Quote availability/submission derives from the Request relationship.
- Existing valid Quotes may be submitted after Program disable/archive.
- Concurrency and field-visibility rules require focused workflow and authorization tests.

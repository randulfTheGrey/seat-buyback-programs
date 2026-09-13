# ADR 0001: Separate Market Price, Buyback Policy, and Quote

- Status: Accepted
- Date: 2026-09-09

## Context

A buyback calculation involves a current external market observation, mutable Program policy, and a historical financial offer. Combining them would make ownership unclear and could cause old offers to change as markets or rules change.

## Decision

Model these as three separate concepts:

1. reference market price, retrieved exclusively through `seat-prices-core`;
2. buyback policy, resolved from Program defaults and sparse rules;
3. immutable Quote/QuoteItems, storing the exact evidence and payable result offered at appraisal time.

Appraisals are transient trusted server-side state between policy/pricing and Quote creation. Quotes do not re-query markets or policy after creation.

## Consequences

- Provider and Program changes cannot rewrite historical offers.
- Quote snapshots require deliberate storage of policy, price, and provenance evidence.
- Market retrieval and rule evaluation remain independently testable.
- Users must re-appraise expired Quotes rather than refresh their values in place.

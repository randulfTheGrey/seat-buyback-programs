# ADR 0003: Use a Sparse Hierarchical Rule Engine

- Status: Accepted
- Date: 2026-09-09

## Context

Pre-populating policy rows for every EVE type would be large, hard to administer, and brittle as the SDE changes. Administrators need broad defaults plus a small number of predictable exceptions.

## Decision

Program defaults are the sole semantic `GLOBAL` policy baseline. Use sparse `GROUP` and `TYPE` rules only; no separately persisted or configurable GLOBAL rule exists. GROUP rules may be qualified by `ANY`, `COMPRESSED`, or `UNCOMPRESSED`; `NOT_APPLICABLE` is runtime-only. A TYPE target identifies one concrete EVE TypeID and therefore has exactly one semantic rule layer. Compression qualifiers apply only to GROUP targets.

Resolve the baseline and apply matching sparse rules in this order:

1. Program defaults / GLOBAL baseline;
2. GROUP;
3. GROUP + matching compression qualifier;
4. TYPE.

Therefore TYPE > GROUP+compression > GROUP > Program defaults / GLOBAL. A TYPE rule ALWAYS outranks a compression-qualified GROUP rule. At one GROUP target, its matching unqualified and qualified rules both contribute in that order. There is no TYPE+compression layer, arbitrary priority/order field, creation-order tie break, or last-edited tie break.

Rules override acceptance, logical reference, and modifier independently. Rejection does not short-circuit because a more-specific rule may re-accept. At most one logical GROUP rule exists per Program + GroupID + qualifier; at most one logical TYPE rule exists per Program + TypeID. The retained persistence qualifier is canonical `ANY` for TYPE rows and cannot create another identity. No-op rules cannot be saved.

## Consequences

- Policy storage grows with exceptions rather than the SDE type count.
- Global policy has one authoritative persistence source on the Program.
- Evaluation is deterministic and explainable without user-managed priorities.
- Intrinsic item compression still selects matching qualified GROUP rules and remains snapshot context, but does not create TYPE specificity.
- Administrative previews must call the same evaluator used by appraisals.
- Category, market-group, expression, and name-pattern rules are deferred from version 1.

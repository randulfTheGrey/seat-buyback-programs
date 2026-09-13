# ADR 0004: Represent Modifiers as Additive Basis Points

- Status: Accepted
- Date: 2026-09-09

## Context

Percentage modifiers need exact storage, predictable inheritance, and an administrator-friendly meaning. Floating-point percentages and multiplicative layering would make results difficult to reproduce and explain.

## Decision

Store modifiers as signed integer basis points. Discounts are negative and premiums positive; for example, -1000 is a 10% discount and +500 is a 5% premium.

Program defaults establish the sole global initial modifier. Sparse GROUP and TYPE rule operations are:

- `INHERIT`: no change;
- `REPLACE`: set the rule value;
- `ADJUST`: add the rule basis points to the effective value.

`ADJUST` means additive percentage points, not multiplicative compounding. For example, `-1000` followed by `ADJUST -500` yields `-1500` bps, while `+200` followed by `ADJUST +300` yields a `+500` bps premium. `REPLACE` discards every previously inherited or accumulated modifier: `-1500` followed by `REPLACE +250` yields exactly `+250` bps. Configured and effective values MUST remain between -10000 and +10000 bps. The UI presents Discount, Premium, and None and labels adjustment as percentage points.

## Consequences

- Results are exact, composable, and easy to explain.
- Combinations must be validated for effective-range overflow.
- The monetary calculation applies `(10000 + bps) / 10000` with decimal arithmetic.
- UI conversion must preserve the signed internal value while avoiding basis-point jargon for administrators.

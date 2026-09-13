# Sparse Hierarchical Rule Engine

## Goals

The rule engine expresses broad or narrow buyback policies without creating one row per EVE type. It is sparse, deterministic, field-oriented, and explainable. The same evaluator serves real appraisals and administrative previews.

## Program defaults

A Program supplies the initial effective policy:

- acceptance is `ACCEPT` or `REJECT`;
- reference mode is `BUY`, `SELL`, or `SPLIT`;
- modifier is a signed integer number of basis points.

The UI describes acceptance as either “Accept items unless a rule rejects them” or “Reject items unless a rule accepts them.” Acceptance is configurable per Program as `ACCEPT | REJECT`; new Programs MUST use `ACCEPT`. The Program defaults are the sole semantic `GLOBAL` policy baseline, and the Program's default modifier is ALWAYS the initial effective modifier before any matching rule is applied.

There is no separately persisted or configurable `GLOBAL` sparse rule. The global/default contribution comes exactly once from the Program fields.

## Rule shape

A `BuybackRule` targets one of:

- a SeAT SDE `GROUP` ID;
- a SeAT SDE `TYPE` ID.

`GROUP` rules can use `ANY`, `COMPRESSED`, or `UNCOMPRESSED`. A compression-specific GROUP rule matches only that runtime classification. `NOT_APPLICABLE` never matches a compression-specific rule and is not selectable by an administrator.

A TYPE target identifies one concrete EVE TypeID and therefore has exactly one semantic rule layer. Compression qualifiers apply only to GROUP targets. The persisted qualifier column is retained as an implementation detail and MUST be canonical `ANY` for TYPE rows; intrinsic `COMPRESSED`, `UNCOMPRESSED`, or `NOT_APPLICABLE` classification remains item context rather than TYPE specificity.

No version 1 rule targets categories, market groups, arbitrary expressions, item-name patterns, or other dimensions.

Each rule holds independent field overrides:

- acceptance: `INHERIT`, `ACCEPT`, or `REJECT`;
- reference: inherit, `BUY`, `SELL`, or `SPLIT`;
- modifier: `INHERIT`, `REPLACE`, or `ADJUST`, with a value when required.

A rule that changes no field is a no-op and MUST NOT be saved. At most one logical GROUP rule may exist for Program + GroupID + qualifier, and at most one TYPE rule may exist for Program + TypeID.

## Precedence and application order

The evaluator MUST resolve the baseline and apply all matching sparse-rule layers from least to most specific in exactly this order:

1. Program defaults / `GLOBAL` baseline;
2. `GROUP` with `ANY`;
3. `GROUP` with the matching compression qualifier;
4. `TYPE`.

Thus:

`TYPE > GROUP+compression > GROUP > Program defaults / GLOBAL`.

A TYPE rule ALWAYS outranks a compression-qualified GROUP rule. Qualified and unqualified rules at the same GROUP target both contribute, with the qualified rule applied after the unqualified rule. There is no TYPE+compression layer, priority/order field, creation-order tie break, or “last edited wins” behavior.

## Independent field resolution

Acceptance, reference, and modifier are resolved separately. A matching rule can change one field while inheriting the other two. The evaluator therefore carries an effective value and provenance for each field through every matching layer.

Rejection MUST NOT short-circuit evaluation. A more-specific rule may re-accept an item.

The result includes:

- final acceptance;
- final logical reference mode;
- final modifier basis points;
- the single Program-default/`GLOBAL` baseline contribution;
- matched rules in application order;
- per-field explanation showing which default/rule supplied or adjusted the final value;
- validation/failure information when the effective policy cannot be used.

## Modifier semantics

Modifiers are signed integer basis points:

- a 10% discount is `-1000` bps;
- a 5% premium is `+500` bps;
- no adjustment is `0` bps.

Operations mean:

- `INHERIT`: make no change;
- `REPLACE`: set the effective modifier to the rule value;
- `ADJUST`: add the rule value to the current effective modifier.

`ADJUST` is additive percentage points, NEVER multiplicative compounding. A `-1000` bps effective discount followed by a `-500` bps adjustment becomes `-1500` bps (15% discount).

Every configured and effective result must be within `-10000` through `+10000` bps. Validation covers both stored values and combinations that can exceed the effective range. The admin UI presents Discount, Premium, or None and labels `ADJUST` as a percentage-point adjustment; signed basis points remain the internal representation.

## Worked precedence examples

These examples are normative illustrations of the fixed algorithm.

### TYPE re-accepts after compression-qualified GROUP rejection

A Program defaults to `ACCEPT`, a GROUP+`COMPRESSED` rule sets acceptance to `REJECT`, and a TYPE rule for one compressed type sets acceptance to `ACCEPT`. For that type, evaluation applies GROUP+compression before TYPE, so the final acceptance is `ACCEPT`. The TYPE rule wins even though the GROUP rule is compression-qualified, and rejection does not stop later evaluation.

### Qualified and unqualified rules both contribute

For a compressed item, an unqualified GROUP rule changes reference mode to `SELL` and a GROUP+`COMPRESSED` rule applies `ADJUST -200` bps while inheriting reference mode. Both rules apply in order. The result uses `SELL` and adds a two-percentage-point discount to the effective modifier.

### Fields inherit independently across taxonomy levels

A GROUP rule changes reference mode from `BUY` to `SELL` and inherits the modifier. A more-specific TYPE rule uses `ADJUST +300` bps and inherits the reference. The result uses `SELL` with a three-percentage-point premium added to the modifier that was effective before the TYPE rule.

### REPLACE discards accumulated modifier changes

A Program starts at `-1000` bps. A GROUP rule uses `ADJUST -500`, producing `-1500` bps. A TYPE rule then uses `REPLACE +250`. The final modifier is exactly `+250` bps, a 2.5% premium; the prior `-1500` bps does not contribute after `REPLACE`.

## Evaluation outline

For an item resolved to a type, group, and runtime compression classification:

1. Start from Program default acceptance, reference mode, and modifier.
2. Select the one possible sparse rule for each matching GROUP/TYPE specificity layer.
3. Visit matching rules in the fixed application order.
4. For each field, retain the current value on inherit, replace it for explicit acceptance/reference or modifier `REPLACE`, and add for modifier `ADJUST`.
5. Validate the effective modifier range.
6. Return the policy and structured explanation.

The evaluator performs no market calls and does not classify compression itself. It consumes stable inputs from those boundaries.

## Administration and validation

Rule selectors search SeAT SDE types and groups so administrators need not know numeric IDs. Qualifier labels are All, Compressed, and Uncompressed. Reference and modifier controls expose the exact inherit/override operations.
For a `TYPE` target, the admin UI does not present compression as a policy qualifier. It shows the selected type's intrinsic SDE classification informationally when available. Server-side saving and database constraints require canonical `ANY`. The affected-items table is hidden and is not loaded because the selected item is already the complete affected set. ADR 0014 records the administration consequence.

For a `GROUP` target, each qualifier remains selectable only when usable compression reference data shows that the group contains a member with that classification: `COMPRESSED` requires a canonically compressed member and `UNCOMPRESSED` requires a canonically uncompressed member. The admin UI hides the control and submits `ANY` for a group with neither, and server-side validation rejects each impossible qualifier independently. If applicability cannot be determined because reference data is unavailable, the control remains available and normal Program health validation applies. The rule editor provides an admin-only, paginated preview of the published SDE item types affected by the selected group and qualifier, including each item's runtime compression classification when reference data is available.

Stored configuration validity is separate from runtime dependency health. Deterministic but surprising configuration should produce a warning rather than an arbitrary prohibition. Examples can include rules that are shadowed for a particular preview item or references to a logical channel whose external provider has drifted. Invalid no-op, duplicate, out-of-range, or structurally incomplete rules are rejected.

An effective-rule preview supplies a Program, type, and resulting compression classification to the production evaluator and displays its explanation. A separate “preview implementation” is prohibited because it could diverge from appraisal behavior.

## Required tests

The rule engine suite covers:

- global accept and global reject Program-default policies;
- exactly one global/default contribution and no persisted sparse `GLOBAL` rule;
- group override and type override;
- a type re-accepting an item excluded by its group;
- field-by-field inheritance;
- the Program-default baseline, all three possible sparse rule layers, and GROUP compression matching;
- exactly one TYPE layer and no `TYPE_COMPRESSION` source;
- the guarantee that TYPE outranks GROUP+compression;
- `INHERIT`, `REPLACE`, and `ADJUST` modifier behavior;
- configured/effective bounds;
- rejection without short-circuiting;
- stable, useful explanation output;
- uniqueness and no-op validation.

# ADR 0014: Represent Item Type Rules as One Exact Type Layer

- Status: Accepted
- Date: 2026-09-12

## Context

ADR 0003 models an item-type target as two possible evaluator layers: an unqualified `TYPE` rule and a `TYPE` rule qualified by the item's runtime compression state. That distinction is useful for groups, where different member types can have different compression states, but it is redundant for a concrete EVE item type. A published type has one intrinsic classification in the installed compression reference data.

Exposing the qualifier as a choice for item-type targets allows an administrator to create an opposite-state rule that can never match, or both an `ANY` rule and a state-qualified rule for the same concrete type. This was an original design flaw discovered while validating the Issue 12 administration UI.

## Decision

The final administration boundary MUST represent every `TYPE` rule as one exact-TypeID layer. Compression is not an independent TYPE qualifier. The retained persistence column stores canonical `ANY` for all TYPE rows, and application plus database constraints reject `COMPRESSED` or `UNCOMPRESSED` on TYPE.

The rule editor shows the selected item's intrinsic `COMPRESSED`, `UNCOMPRESSED`, or `NOT_APPLICABLE` classification informationally when reference data is usable. It does not present a selectable or disabled compression-qualifier control for TYPE. The affected-items table is hidden and not loaded for item-type targets because the selected item is already the complete affected set.

Group targets retain the existing `ANY`, `COMPRESSED`, and `UNCOMPRESSED` choices and the affected-items preview.

ADR 0003 defines the final evaluator order as Program defaults / GLOBAL, GROUP / ANY, matching GROUP qualifier, then TYPE. Intrinsic compression continues to choose the GROUP qualifier and remains appraisal/Quote context.

Because this correction is pre-v1, the original configuration migration is corrected in place. Developer databases built from the earlier schema must be rebuilt rather than merging legacy TYPE rows. This avoids silently choosing among conflicting TYPE policies by qualifier, row ID, timestamps, or ordering. Immutable historical Quote snapshots are not rewritten; display code retains read-only support for the old `TYPE_COMPRESSION` source string.

## Consequences

- Administrators cannot create an impossible or redundant item-type qualifier through the UI, save endpoint, model/domain path, or database.
- Program + TypeID has one logical rule identity with canonical stored qualifier `ANY`.
- The one-item affected-items preview is removed while the useful group preview remains.
- Changes to CCP compression mappings do not alter TYPE identity or specificity.
- Existing Quote/QuoteItem policy and compression snapshots remain immutable.

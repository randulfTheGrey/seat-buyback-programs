# Configuration

## Programs

A Program owns mutable buyback policy and instructions:

- `ENABLED`: accepts new appraisals and can convert cached appraisals to Quotes;
- `DISABLED`: blocks new appraisals/Quote conversion while retained Quotes and
  Requests keep their lifecycle;
- `ARCHIVED`: removed from new use while historical Quotes/Requests remain.

New Programs are `DISABLED` and default to `ACCEPT`. Administrators also choose
the default BUY/SELL/SPLIT reference, signed modifier, Quote validity in minutes,
and optional EVE contract instructions. Quote validity starts when appraisal
pricing completes, not when the user later creates the Quote.

## Pricing references

- `BUY` maps to one configured `seat-prices-core` provider instance.
- `SELL` maps to one configured provider instance.
- `SPLIT` maps either to a dedicated provider instance or to
  `DERIVED_MIDPOINT = (BUY + SELL) / 2`.

A derived SPLIT requires both component prices. There is no one-sided midpoint
or fallback between channels. Provider instances are configured and named in
`seat-prices-core`; Buyback treats them as opaque streams and never inspects the
backend to decide whether its semantics are really buy or sell.

## Modifiers

Modifiers are exact signed basis points internally: `-1000` is a 10% discount
and `+500` is a 5% premium.

- `INHERIT` leaves the current modifier unchanged.
- `REPLACE` discards the inherited value and sets a new modifier.
- `ADJUST` adds percentage points to the current modifier. It never compounds.

Example: a Program default discount of 10% (`-1000`) followed by GROUP `ADJUST`
of another 5 percentage points (`-500`) yields a 15% discount (`-1500`), not
14.5%. A more-specific TYPE `REPLACE +250` then produces exactly a 2.5% premium.

Configured and effective modifiers must remain between -100% and +100%.

## Sparse rules and precedence

The fixed precedence from most to least specific is:

```text
TYPE
> GROUP + compression
> GROUP
> Program defaults / GLOBAL
```

Evaluation applies the layers in the reverse order shown: defaults, GROUP ANY,
matching GROUP compression qualifier, then TYPE. Acceptance, reference, and
modifier fields inherit independently, and a rejection does not stop a more
specific rule from re-accepting an item.

Each Program has at most one semantic rule per exact TypeID. Compression is an
intrinsic TYPE fact, not another TYPE-rule dimension. GROUP rules may use:

- `ANY` for every type in the group;
- `COMPRESSED` for canonically compressed members;
- `UNCOMPRESSED` for canonically uncompressed members.

Only CCP SDE `compressibleTypes` data determines compression. Categories,
market groups, name expressions, priorities, and arbitrary rule ordering are not
supported in v1.

## Quotes

A Quote is an immutable financial offer. It contains all and only the appraisal
lines that were successfully `PRICED`; users cannot select a payable subset.
Unknown, invalid, excluded, and unpriced lines remain appraisal-only results.

Quote snapshots preserve type names, quantities, compression, applied policy,
provider-instance identity, raw references, modifiers, final unit prices, and
totals. Expired Quotes require a new appraisal. Market movement, Program edits,
and provider deletion never reprice historical Quotes.

## Requests

Submitting a valid Quote creates one Request in `PENDING`. The only terminal
transitions are:

- `PENDING → COMPLETED` by a manager;
- `PENDING → REJECTED` by a manager with a requester-visible reason;
- `PENDING → CANCELED` by the requester.

While pending, the requester can edit the optional numeric contract ID and their
note; managers can correct the contract ID and edit an internal manager note.
Terminal Requests are read-only. Completion does not require a contract ID.
Partial fulfillment, reopening, item edits, and payout overrides are not
available in v1.

## Permissions

`buyback.request`, `buyback.manage`, and `buyback.admin` correspond to requester,
manager, and configuration workflows. Permissions are independent;
`buyback.admin` does not imply `buyback.manage`, and `buyback.manage` does not
imply `buyback.request`.

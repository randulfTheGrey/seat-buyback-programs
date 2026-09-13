# Pricing and Reference Data

## Ownership boundary

`seat-prices-core` exclusively owns market-price retrieval. The buyback plugin MUST NOT call Fuzzwork, Janice, SeAT Prices, or any other market backend directly. It MUST NOT implement provider-specific HTTP clients, a parallel provider registry, backend-specific domain behavior, private price caching, or scheduled price warming in version 1.

The plugin owns the policy decision describing which logical reference channel to use. It calls a thin adapter over `seat-prices-core`, treats configured provider instances as opaque price streams, and normalizes returned values and errors into its own provider-independent domain outcomes.

Three concepts must not be collapsed:

1. a current reference market price returned through `seat-prices-core`;
2. mutable buyback policy that selects a reference and modifier;
3. an immutable Quote snapshot preserving the price actually offered.

## Logical reference channels

Every effective item policy selects `BUY`, `SELL`, or `SPLIT`.

- `BUY` maps to a Program-configured `seat-prices-core` provider-instance ID.
- `SELL` maps to a Program-configured `seat-prices-core` provider-instance ID.
- `SPLIT` maps either to its own provider instance or to `DERIVED_MIDPOINT`.

For `DERIVED_MIDPOINT`:

`split = (buy + sell) / 2`

Both component channels MUST be available. If either component channel is unavailable or misconfigured for a type, derived SPLIT is unavailable for that type. The exact midpoint precision is retained until the buyback modifier has been applied.

Names such as BUY and SELL are application semantics attached by the Program. The plugin does not inspect a provider's backend-specific configuration to decide whether it is truly “buy” or “sell.” Provider-instance IDs are application-level external identifiers, not database foreign keys.

There is NEVER an implicit fallback between channels. A missing or failed BUY cannot use SELL or SPLIT, and derived SPLIT cannot proceed with only one component.

## Bulk-first price planning

Pricing occurs only after rejected lines have been excluded. The planner:

1. collects accepted type IDs and their effective logical channels;
2. expands derived SPLIT into BUY and SELL component needs;
3. resolves logical channels to configured opaque provider instances;
4. deduplicates type IDs within each provider instance;
5. consolidates compatible work into exactly one initial bulk call per distinct required provider-instance ID;
6. maps normalized results back to all appraisal lines that depend on them.

Consolidation matters when BUY, SELL, or a dedicated SPLIT happen to reference the same provider instance. The planner requests a type once per provider stream even if several logical uses depend on it.

Ordinary pricing is synchronous. Provider caching may reduce cost and latency when available, but correctness cannot depend on it.

## Partial failure recovery

If a bulk provider call succeeds partially, individually missing/unavailable types are classified as item-level `UNPRICED` outcomes. If the bulk call itself fails, recovery is bounded:

1. retry the affected unique types individually through the same provider instance;
2. count consecutive per-type recovery failures;
3. reset the consecutive count when a per-type recovery succeeds;
4. stop recovery after three consecutive failures;
5. classify the entire affected logical reference channel as `REFERENCE_UNAVAILABLE`.

On the third consecutive per-type recovery failure, recovery for that provider stream MUST stop. The entire affected logical channel becomes `REFERENCE_UNAVAILABLE`: already recovered, failed, and not-yet-attempted dependent lines MUST all carry that channel-wide outcome and remaining lines MUST NOT be mislabeled `UNPRICED`. Other logical channels that do not depend on the failed provider stream MAY continue and produce their independent outcomes.

Every logical channel whose direct price or derived component depends on that failed provider stream is affected. A channel backed entirely by other provider instances is unaffected; a derived SPLIT is affected when either of its required component streams is affected.

Normalized outcomes distinguish:

- `UNPRICED`: a particular type was isolated as unavailable while the channel remained usable;
- `REFERENCE_UNAVAILABLE`: a logical channel failed systemically;
- `REFERENCE_MISCONFIGURED`: a required configured provider instance could not be resolved.

Provider/transport exceptions are logged for operators and normalized before reaching ordinary requesters. No raw provider exception or backend configuration is displayed.

## Exact monetary calculation

Binary floating point is prohibited. Adapter outputs are normalized immediately to fixed/arbitrary precision decimals.

For each payable line:

1. retain the raw reference unit price at its available precision;
2. for derived SPLIT, calculate the exact decimal midpoint;
3. apply the effective basis-point modifier as `reference × (10000 + bps) / 10000`;
4. round the final unit price `HALF_UP` to `0.01 ISK`;
5. multiply that rounded unit price by the integer quantity for the line total.

The Quote total is the exact sum of line totals. Unit prices are not rounded to whole ISK. A zero provider price is valid numeric input and is not confused with a missing value.

If a future contract comparison requires whole-ISK contract totals, rounding happens once after producing the final expected contract value, not on individual units or components.

### Worked rounding example

For a derived SPLIT with BUY `10.00 ISK` and SELL `10.01 ISK`, the exact reference is `(10.00 + 10.01) / 2 = 10.005 ISK`. With a `0` bps modifier, the unrounded final unit value remains `10.005 ISK` and MUST round `HALF_UP` to `10.01 ISK`. For quantity `3`, the line total is `10.01 × 3 = 30.03 ISK`. If it is the only payable line, the Quote total is exactly `30.03 ISK`; rounding the reference or unit to whole ISK at any earlier step would be incorrect.

## Compression reference data

The CCP Static Data Export is authoritative for compression. The canonical `compressibleTypes` relationship uses:

- `_key` as the uncompressed type ID;
- `compressedTypeID` as the corresponding compressed type ID.

Because SeAT does not currently expose this relationship through a convenient native model, the plugin owns a minimal derived projection of `(uncompressed_type_id, compressed_type_id)`. Dataset/build metadata is stored separately from mappings.

Runtime classification is exact:

- a type on the uncompressed side is `UNCOMPRESSED`;
- a type on the compressed side is `COMPRESSED`;
- a type absent from both is `NOT_APPLICABLE`.

Name matching, dogma heuristics, and administrator overrides are prohibited.

## Compression synchronization

The projection is maintained by an explicit command/service and a scheduled build-aware refresh; a daily check is acceptable. SDE network access MUST NOT occur in migrations or interactive user-request/appraisal execution. An administrative trigger may dispatch the explicit synchronization service, but the network/import work occurs outside the request path.

A refresh:

1. identifies whether a newer/different SDE build requires work;
2. loads a candidate relationship through the approved SDE source path;
3. validates identifiers, pair shape, uniqueness, and dataset-level expectations;
4. stages the candidate without altering the active mapping;
5. atomically replaces/activates the complete validated projection and metadata.

Any download, parse, validation, or replacement failure leaves the last-known-good projection active. A stale valid projection remains usable and creates an administrative warning.

If no initial projection exists, appraisal is blocked only when the selected Program contains compression-qualified rules. Programs with no such rules can classify no compression for policy purposes without turning unrelated reference-data absence into an outage.

## Health and diagnostics

Program configuration validation distinguishes stored structural validity from runtime health:

- unresolved provider-instance IDs are dependency drift and produce `REFERENCE_MISCONFIGURED` for affected work;
- stale compression data is a warning with continued use of last-known-good data;
- missing compression data is blocking only when compression policy requires it;
- external drift never silently changes a Program lifecycle status.

The admin surface exposes provider resolution and compression dataset status without exposing secrets or provider-specific configuration dumps.

## Required tests

Pricing tests cover bulk planning, type deduplication, provider-instance consolidation, dedicated and derived SPLIT, exact midpoint calculation, partial recovery, the three-consecutive-failure threshold, provider drift, and the no-fallback rule.

Money tests cover exact decimals, half-up cent rounding, low-value/high-volume commodities, large quantities, zero prices, premiums, and discounts.

Compression tests cover all three runtime classifications, fixture-based import, invalid candidate rejection, atomic activation, last-known-good preservation, staleness behavior, and the conditional missing-initial-data block. Normal CI uses fixtures and no live CCP, Fuzzwork, or Janice HTTP calls.

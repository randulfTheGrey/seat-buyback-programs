# Domain Model and Persistence

## Aggregate boundaries

### BuybackProgram

`BuybackProgram` is the mutable administrative aggregate. It owns defaults, logical price references, and sparse rules. It does not own market providers; provider-instance IDs refer to configurations managed by `seat-prices-core`.

Required Program data includes:

- name and description;
- lifecycle status: `ENABLED`, `DISABLED`, or `ARCHIVED`;
- default acceptance: `ACCEPT` or `REJECT`;
- default logical reference mode: `BUY`, `SELL`, or `SPLIT`;
- signed default modifier in integer basis points;
- Quote validity in minutes;
- optional free-text contract instructions;
- created/updated actor metadata.

New Programs MUST be `DISABLED` and MUST default to `ACCEPT`. Disabling or archiving changes future appraisal eligibility but never rewrites historical Quotes. A Program with any historical Quote MUST be archived rather than hard-deleted. If hard deletion is exposed at all, it is allowed ONLY for a Program that has never produced a Quote and has no other retained history.

### AppraisalResult

`AppraisalResult` is transient, trusted server-side state held in Laravel cache. It includes Program and user identity, pricing-completion and expiry times, resolved lines and outcomes, the calculated policy/pricing evidence needed to build a Quote, and consumption/idempotency metadata.

It is not a persistent aggregate, audit ledger, or market-price cache. Client submissions never supply authoritative prices, modifiers, or totals.

### Quote

`Quote` is an immutable financial offer. It owns one or more `QuoteItem` records, and every item is payable by definition. The appraisal pipeline merges duplicate input into one line per resolved type ID; Quote creation MUST therefore persist exactly one QuoteItem per `PRICED` type ID. It MUST persist all and only the appraisal's `PRICED` lines atomically: the requester cannot select a payable subset, and non-payable outcomes are never QuoteItems.

The Quote stores its public ULID-style identifier, requester identity, Program reference/snapshot details, creation time, pricing-completion time, expiry, total, and relevant version metadata. Each QuoteItem preserves:

- type ID and type-name snapshot;
- integer quantity;
- compression-classification snapshot;
- logical reference mode and resolution;
- raw reference unit price;
- effective modifier in basis points;
- final rounded unit price and line total;
- versioned policy snapshot;
- versioned pricing/provenance snapshot.

Provider provenance contains the provider instance ID/name and, when needed, component prices. It excludes provider-specific HTTP payloads and configuration dumps. The server derives each line total and the Quote total from the trusted appraisal; client totals are never authoritative.

Quote state is derived rather than maintained in a status column:

- `AVAILABLE`: no Request exists and expiry has not been reached;
- `SUBMITTED`: a Request exists;
- `EXPIRED`: no Request exists and expiry has been reached.

### BuybackRequest

`BuybackRequest` is a separate fulfillment aggregate with exactly one immutable Quote. It has an internal numeric ID and public ULID-style identifier. Requester ownership is derived from the referenced Quote; the Request MUST NOT duplicate requester identity.

Its state is `PENDING`, `COMPLETED`, `REJECTED`, or `CANCELED`. While pending it may hold:

- optional numeric `eve_contract_id`;
- requester note;
- manager note.

A rejection also requires an explicit requester-visible reason. Terminal actor and timestamp fields record completion, rejection, or cancellation. Once terminal, the Request and its mutable pending fields become read-only historical data.

## Value types and enums

Application enums are string-backed in code and stored in ordinary string columns rather than database-native ENUMs. Core values include:

- Program status: `ENABLED`, `DISABLED`, `ARCHIVED`;
- default/effective acceptance: `ACCEPT`, `REJECT`;
- rule acceptance: `INHERIT`, `ACCEPT`, `REJECT`;
- reference mode: `BUY`, `SELL`, `SPLIT`;
- modifier operation: `INHERIT`, `REPLACE`, `ADJUST`;
- rule target: `GROUP`, `TYPE`;
- GROUP rule qualifier: `ANY`, `COMPRESSED`, `UNCOMPRESSED`;
- runtime compression: `COMPRESSED`, `UNCOMPRESSED`, `NOT_APPLICABLE`;
- Request state: `PENDING`, `COMPLETED`, `REJECTED`, `CANCELED`.

`NOT_APPLICABLE` is a classifier result, never an administrator-selectable rule qualifier.

## Proposed plugin-owned schema

Exact framework column types, indexes, and constraint names are implementation details, but the following logical schema is approved.

### `buyback_programs`

- internal numeric primary key;
- name and description;
- string-backed `status`;
- string-backed `default_acceptance`;
- string-backed `default_reference_mode`;
- signed integer `default_modifier_bps`, constrained in application logic to -10000 through +10000;
- positive `quote_validity_minutes`;
- nullable `contract_instructions`;
- created/updated actor identifiers and timestamps.

### `buyback_program_price_references`

- internal primary key and Program foreign key;
- logical channel/configuration identifying `BUY`, `SELL`, and `SPLIT` behavior;
- external provider-instance ID and display-name metadata as appropriate;
- SPLIT strategy distinguishing a dedicated provider from `DERIVED_MIDPOINT`;
- internal uniqueness constraints preventing ambiguous channel configuration.

Provider-instance IDs are not foreign keys into `seat-prices-core`.

### `buyback_rules`

- internal primary key and Program foreign key;
- target kind `GROUP` or `TYPE` and its positive SeAT SDE target ID;
- qualifier `ANY`, `COMPRESSED`, or `UNCOMPRESSED`; TYPE rows use canonical `ANY` and only GROUP treats the qualifier as policy identity;
- acceptance `INHERIT`, `ACCEPT`, or `REJECT`;
- nullable reference override `BUY`, `SELL`, or `SPLIT`;
- modifier operation and nullable signed basis-point value;
- enabled flag, nullable `archived_at`, and optional admin note;
- created/updated actor metadata and timestamps.

At most one logical GROUP rule exists for Program + GroupID + qualifier. At most one logical TYPE rule exists for Program + TypeID. A TYPE target identifies one concrete EVE TypeID and therefore has exactly one semantic rule layer. Compression qualifiers apply only to GROUP targets. The retained qualifier column stores canonical `ANY` for TYPE rows, enforced by application and database constraints; it cannot create another TYPE identity. There is no priority/order column. No-op rules are rejected by application validation.

Program defaults are the sole semantic `GLOBAL` policy baseline. `buyback_rules` contains only more-specific `GROUP` and `TYPE` overrides; it does not persist a second `GLOBAL` rule or reserve target ID zero.

### `buyback_compression_mappings`

- `uncompressed_type_id`;
- `compressed_type_id`;
- internal uniqueness/indexing sufficient for lookup from either side.

This table is a derived, non-editable projection of the SDE relationship.

### `buyback_compression_metadata`

- dataset/build identity and source metadata;
- imported/activated timestamps;
- health/staleness information needed for diagnostics;
- sufficient state to distinguish a missing initial dataset from a stale last-known-good dataset.

Dataset metadata is kept separately from pair mappings.

### `buyback_quotes`

- internal numeric primary key and public ULID-style ID;
- requester and Program application-level IDs plus necessary snapshots;
- appraisal pricing-completion time, creation time, and expiry;
- exact decimal Quote total;
- versioned quote-level policy/pricing evidence;
- uniqueness/idempotency key tied to appraisal consumption;
- timestamps that do not imply mutability of financial fields.

### `buyback_quote_items`

- internal primary key and Quote foreign key;
- the immutable payable-line evidence listed in the Quote aggregate section;
- exact decimal price/total columns with suitable precision and scale;
- integer quantity;
- uniqueness of Quote + type ID, enforcing one merged payable line per type.

### `buyback_requests`

- internal numeric primary key and public ULID-style ID;
- unique Quote foreign key, enforcing at most one Request per Quote;
- string-backed lifecycle state;
- nullable numeric `eve_contract_id`;
- nullable requester note, manager note, and rejection reason;
- terminal actor and timestamp fields;
- timestamps and concurrency-safe state-transition support.

## Keys and referential integrity

Use database foreign keys within plugin-owned tables: Program to rules/references, Quote to QuoteItems, and Request to Quote. Do not add database foreign keys to SeAT user/SDE tables or `seat-prices-core`; those packages have independent lifecycle and migration ownership.

External references are validated/resolved in application services and represented honestly as potentially drifting dependencies. Historical snapshots prevent later deletion or renaming from damaging Quote explanations.

Requester ownership of a Request is resolved through `buyback_requests.quote_id -> buyback_quotes.requester_id`. It is intentionally not copied onto `buyback_requests`, avoiding two identity fields that could disagree.

## Monetary representation

No financial calculation uses binary floating point. Provider values are normalized immediately to fixed/arbitrary precision decimals. Reference prices may keep higher precision; a derived midpoint retains exact midpoint precision until the modifier is applied.

The final unit buyback price is rounded `HALF_UP` to `0.01 ISK`. A line total is the rounded unit price multiplied by integer quantity, and a Quote total is the sum of line totals. Whole-ISK rounding is not performed per unit. If contract reconciliation later requires a whole-ISK contract value, it occurs once at that final boundary.

## Invariants enforced transactionally

- Quote creation consumes/reuses one appraisal token idempotently and persists all Quote rows atomically.
- A Quote contains exactly all `PRICED` lines from its trusted appraisal, one QuoteItem per merged type ID, and no non-payable outcomes.
- A Quote total is calculated server-side as the exact sum of its server-derived line totals.
- A Quote is never repriced or edited.
- A Quote creates at most one Request.
- Request state can leave `PENDING` once only.
- Rejection requires a reason.
- Terminal Requests cannot be reopened, partially completed, or have payouts/items modified.

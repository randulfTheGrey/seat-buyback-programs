# Workflows and Lifecycle

## Program lifecycle

A Program is `ENABLED`, `DISABLED`, or `ARCHIVED`.

- New Programs MUST be `DISABLED` until configured and deliberately enabled. Their default acceptance MUST be `ACCEPT` unless an administrator selects `REJECT`.
- A new appraisal may start ONLY for an `ENABLED` Program.
- A cached appraisal may become a Quote ONLY while its Program remains `ENABLED`.
- An existing unexpired, unsubmitted Quote MAY become a Request even if its Program is later `DISABLED` or `ARCHIVED`.
- Existing Requests MUST continue through their normal lifecycle regardless of later Program status.
- Historical Quotes and Requests MUST remain viewable according to authorization regardless of later Program status.

### Program operation matrix

| Operation | ENABLED | DISABLED | ARCHIVED |
| --- | --- | --- | --- |
| Start a new appraisal | Allowed | Blocked | Blocked |
| Convert cached appraisal to Quote | Allowed | Blocked | Blocked |
| Submit an existing valid Quote as a Request | Allowed | Allowed | Allowed |
| Process an existing Request | Allowed | Allowed | Allowed |
| View authorized historical Quote/Request | Allowed | Allowed | Allowed |

A Program with historical Quotes MUST be archived rather than hard-deleted. If the product exposes hard deletion, it MAY apply only to a Program that has never produced a Quote and has no retained history.

Stored configuration validity and dependency health are separate from lifecycle status. Provider drift or stale reference data does not automatically mutate the status.

## Appraisal workflow

Appraisal is a synchronous calculation that produces short-lived trusted state, not a financial database record.

The approved pipeline is:

1. the user selects a Program;
2. the user pastes inventory;
3. parse each input row;
4. resolve exact EVE types through SeAT SDE data;
5. merge duplicate resolved type IDs and quantities;
6. classify compression from the active SDE-derived projection;
7. evaluate the effective policy and explanation;
8. remove rejected lines from price planning;
9. create deduplicated bulk price requests;
10. price exclusively through `seat-prices-core`;
11. apply exact modifiers and rounding;
12. render and cache a transient `AppraisalResult`.

Appraisal line outcomes are:

- `PRICED`;
- `EXCLUDED`;
- `UNPRICED`;
- `REFERENCE_UNAVAILABLE`;
- `UNKNOWN`;
- `INVALID_INPUT`.

An appraisal MAY be partial: payable and non-payable outcomes may appear together. Quote creation is not a selectable partial conversion. It MUST atomically persist all and only `PRICED` lines, one QuoteItem per merged type ID; the requester cannot retry or cherry-pick individual lines into the Quote. `EXCLUDED`, `UNPRICED`, `REFERENCE_UNAVAILABLE`, `UNKNOWN`, and `INVALID_INPUT` remain appraisal-only outcomes. `REFERENCE_MISCONFIGURED` is a normalized configuration/pricing failure at the channel boundary; the UI must communicate the affected configuration problem without inventing a price.

When the requester wants another pricing attempt—for example after `UNPRICED` or `REFERENCE_UNAVAILABLE` outcomes—they perform a full new appraisal. Version 1 has no per-line retry that mutates or supplements an existing appraisal or Quote.

Input problems are ordinary appraisal outcomes, not server errors. Infrastructure faults and invalid policy/configuration are normalized separately and do not expose raw exceptions to requesters.

## Appraisal token

After pricing finishes, the server stores the result in Laravel cache under a short-lived opaque token. The token:

- belongs to exactly one user;
- contains or references trusted server-side results;
- expires no later than the Quote's validity deadline;
- may create at most one Quote;
- supports idempotent duplicate submission by returning/reusing the same Quote.

Quote validity begins when appraisal pricing completes. Waiting to press “Create Quote” does not extend the deadline, and Quote creation preserves the original expiry.

At token exchange, the Program MUST still be `ENABLED`. If it is, later rule or provider changes MUST NOT recalculate the cached result. The trusted appraisal evidence is used exactly as priced. The client cannot amend type resolution, prices, modifiers, totals, snapshots, or which `PRICED` lines enter the Quote.

Token consumption and Quote creation are atomic. Cache/locking and database uniqueness are designed so retries cannot produce two Quotes or a partially created Quote.

## Quote lifecycle

A Quote is immutable immediately after creation. Its market, policy, and monetary evidence MUST NEVER change when:

- markets move;
- rules change;
- provider instances are renamed or deleted;
- the Program is disabled or archived.

The UI derives Quote state:

- `AVAILABLE` when there is no Request and it has not expired;
- `EXPIRED` when there is no Request and expiry has arrived;
- `SUBMITTED` when its Request exists.

The server MUST derive the Quote total as the exact sum of server-derived line totals. An expired Quote cannot be submitted; the requester must re-appraise. A valid Quote can create at most one Request, and submission MUST be idempotent.

## Buyback Request lifecycle

Creating a Request references the immutable Quote and starts in `PENDING`. Only these transitions exist:

- `PENDING -> COMPLETED` by a manager;
- `PENDING -> REJECTED` by a manager, with a requester-visible reason;
- `PENDING -> CANCELED` by the requester.

Terminal states are immutable. Version 1 has no reopening, partial completion, Quote-item edits, or payout overrides.

While pending:

- the requester may set/update the numeric EVE contract ID and requester note;
- a manager may correct/set the numeric EVE contract ID and manager note;
- the requester may cancel;
- a manager may complete or reject.

Completion does not require an EVE contract ID. After any terminal transition, contract ID and notes become read-only historical data. Requester notes and rejection reasons are visible to the requester and managers; manager notes are manager-only.

Transitions use transactional conditional updates that require the stored state to still be `PENDING`. Competing terminal actions yield one success and a normalized `REQUEST_NOT_PENDING` workflow conflict for the loser, never multiple terminal outcomes.

## Authorization and visibility

`randulfthegrey-buyback.request`, `randulfthegrey-buyback.manage`, and `randulfthegrey-buyback.admin` are registered as independent SeAT permissions.

Their vendor-prefixed scope is part of the authorization contract. RC4 does not
mutate legacy ACL rows because SeAT stores no package ownership for globally
named permissions. Existing installations manually grant the new permissions,
then clear Redis ACL caches and restart workers. The old identifiers are never
authorization aliases for this package.

Requester queries MUST be ownership-scoped through the Quote requester. Manager views may query and manage all submitted Requests but do not gain requester or administrator actions implicitly. Administrator views configure Programs and diagnostics. `randulfthegrey-buyback.admin` alone MUST NOT authorize completing or rejecting Requests; that requires `randulfthegrey-buyback.manage`.

Controllers remain thin and enforce policy/resource authorization for every object. DataTables/query endpoints apply the same scopes as page and mutation endpoints. All mutations use POST, PATCH, or DELETE plus CSRF protection.

## Requester and administrator workflow

The requester journey is Program -> Paste -> Appraisal -> Quote -> Request. The appraisal displays every line outcome and clearly identifies which lines will enter the Quote. Quote creation includes all and only `PRICED` outcomes. “My Buybacks” is focused on submitted Buyback Requests; Quotes may be viewed where needed for offer/submission flow but are not presented as submitted buybacks.

The administrator manages Programs and sparse Rules. Program defaults use the plain-language choices “Accept items unless a rule rejects them” and “Reject items unless a rule accepts them.” Price references select opaque provider instances for BUY and SELL and select either a dedicated provider or derived midpoint for SPLIT; Buyback never configures provider backends.

Rule controls use searchable SeAT SDE type/group selectors rather than requiring numeric IDs. GROUP targets expose All/Compressed/Uncompressed qualifiers. TYPE targets have one exact-TypeID rule identity and show intrinsic compression classification as information rather than a selectable qualifier. Both expose Inherit/Accept/Reject acceptance, inherit/BUY/SELL/SPLIT reference, and Inherit/Replace/Adjust modifier behavior. `ADJUST` is labeled as a percentage-point adjustment. No-op rules MUST NOT be saved. Effective-rule preview MUST call the production evaluator. Stored configuration validity and runtime dependency health remain separate, and deterministic but surprising configurations warn rather than being arbitrarily blocked.

## Lifecycle events

Domain/application events should mark meaningful boundaries such as Quote creation, Request submission, pending-field updates, and terminal transitions. Event payloads use stable plugin identifiers and avoid leaking provider secrets. They support audit/notification integration and preserve a future reconciliation seam without defining unused version 1 abstractions.

## Future contract reconciliation boundary

Automatic reconciliation is not implemented in version 1. The current design preserves the boundary through immutable Quotes/QuoteItems, numeric `eve_contract_id`, Program instructions, and lifecycle events. There is no direct ESI contract dependency.

A future implementation must read SeAT's materialized character/corporation contracts, contract details, and contract items. It may introduce concepts such as `SeatContractRepository`, `ObservedContract`, `ContractReconciliationService`, and `ContractReconciliationResult` only when they have real behavior.

Future outcomes distinguish `NOT_OBSERVED`, incomplete SeAT materialization, matched, and mismatched. `NOT_OBSERVED` MUST NOT be treated as invalid, and incomplete materialization MUST NOT be treated as a mismatch. Verification may compare contract ID/type, issuer, recipient/assignee, item quantities, contract price, and contract status. An observed mismatch MUST NOT mutate the Quote. A match does not imply automatic Request completion; that remains an explicit future policy decision.

## Failure semantics

Failures are normalized where subsystems meet and MUST retain these distinct meanings:

| Category | Required behavior |
| --- | --- |
| Input problem | Malformed or unknown input becomes `INVALID_INPUT` or `UNKNOWN` appraisal outcomes, not a server failure. |
| Policy/configuration fault | Invalid policy or an unresolved required provider is reported explicitly; no alternate policy/reference is substituted. |
| Reference-data health | Missing required compression data blocks only Programs that need it; stale last-known-good data remains usable with an admin warning. |
| Item-level pricing failure | A type isolated as unavailable while its channel remains usable is `UNPRICED`. |
| Channel-wide pricing failure | A systemic affected channel is `REFERENCE_UNAVAILABLE`; its remaining lines are not downgraded to `UNPRICED`, while unaffected channels may continue. |
| Workflow conflict | Expiry requires re-appraisal and conditional state races return a named conflict such as `REQUEST_NOT_PENDING`, not a generic server error. |
| Infrastructure error | Provider/database details are logged for operators and MUST NOT be exposed raw to ordinary requesters. |

Workflow conflicts such as `REQUEST_NOT_PENDING` MUST be returned as explicit conflict outcomes, not generic server errors. A partial Appraisal MAY show independent successful and unsuccessful outcomes, but Quote creation MUST atomically capture all and only its payable `PRICED` set; it MUST NOT create an incomplete subset because of a persistence or client-selection failure.

No failure path may silently substitute a reference channel, alter policy, or mutate historical financial evidence.

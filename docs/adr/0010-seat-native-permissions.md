# ADR 0010: Use Independent SeAT-Native Permissions

- Status: Accepted
- Date: 2026-09-09
- Amended: 2026-09-19

## Context

Requesters, managers, and configuration administrators have different responsibilities. Assuming a hierarchy could expose requester financial data or fulfillment actions to roles that only administer configuration.

## Decision

Register three independent SeAT permissions:

- `randulfthegrey-buyback.request` for appraisals and the user's own Quotes/Requests;
- `randulfthegrey-buyback.manage` for all submitted Requests, manager notes, contract corrections, completion, and rejection;
- `randulfthegrey-buyback.admin` for Programs, price references, rules, previews, and reference-data diagnostics/sync.

No permission implies another. In particular, `randulfthegrey-buyback.admin` alone MUST NOT authorize Request completion or rejection; `randulfthegrey-buyback.manage` is required. Enforce access with both ownership/query scoping and policies/resource authorization, including DataTable endpoints. All state changes use POST, PATCH, or DELETE with CSRF protection; GET is read-only.

The permission scope is vendor-prefixed rather than the generic `buyback`
scope. SeAT composes the scope and ability into a global Gate name, so the
generic scope collides with another plugin. SeAT's persisted permission title
and role pivots carry no package provenance, so code cannot determine which
plugin owns a legacy `buyback.request`, `buyback.manage`, or `buyback.admin`
record. Do not automatically rename, copy, merge, or delete those ACL records.
Do not register compatibility aliases, because an alias would retain the
collision inside this package.

Existing RC installations explicitly grant the corresponding
`randulfthegrey-buyback.*` permissions to intended roles during the rc.4
upgrade. Legacy rows and grants remain untouched for the other plugin. Until the
new grants are applied, Buyback Programs authorization fails closed.

## Consequences

- Installations can compose duties according to local governance.
- Administrators who also fulfill Requests must be explicitly granted both permissions.
- Direct-object and collection-query authorization require separate tests.
- Manager-only notes remain hidden from requesters regardless of URL knowledge.
- Existing RC deployments require a documented one-time manual role remapping;
  external scripts that name permissions must also be updated.
- The package never assumes ownership of or retires legacy `buyback.*` ACL data.
- Deployment must clear SeAT's Redis ACL cache and restart long-running workers
  after administrators save the new grants.

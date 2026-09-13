# ADR 0010: Use Independent SeAT-Native Permissions

- Status: Accepted
- Date: 2026-09-09

## Context

Requesters, managers, and configuration administrators have different responsibilities. Assuming a hierarchy could expose requester financial data or fulfillment actions to roles that only administer configuration.

## Decision

Register three independent SeAT permissions:

- `buyback.request` for appraisals and the user's own Quotes/Requests;
- `buyback.manage` for all submitted Requests, manager notes, contract corrections, completion, and rejection;
- `buyback.admin` for Programs, price references, rules, previews, and reference-data diagnostics/sync.

No permission implies another. In particular, `buyback.admin` alone MUST NOT authorize Request completion or rejection; `buyback.manage` is required. Enforce access with both ownership/query scoping and policies/resource authorization, including DataTable endpoints. All state changes use POST, PATCH, or DELETE with CSRF protection; GET is read-only.

## Consequences

- Installations can compose duties according to local governance.
- Administrators who also fulfill Requests must be explicitly granted both permissions.
- Direct-object and collection-query authorization require separate tests.
- Manager-only notes remain hidden from requesters regardless of URL knowledge.

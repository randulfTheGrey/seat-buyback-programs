# ADR 0006: Classify Compression from the CCP SDE

- Status: Accepted
- Date: 2026-09-09

## Context

Compression-qualified rules require authoritative classification. Names and dogma heuristics are incomplete and unstable, while SeAT does not currently expose the canonical relationship through a convenient native model.

## Decision

Use the CCP SDE `compressibleTypes` relationship: `_key` is the uncompressed type ID and `compressedTypeID` its compressed counterpart. Maintain a minimal plugin-owned, non-editable projection of these pairs plus separate dataset metadata.

Classify the uncompressed side as `UNCOMPRESSED`, the compressed side as `COMPRESSED`, and absent types as `NOT_APPLICABLE`.

Refresh through an explicit command/service and scheduled build-aware check. Perform no SDE network work in migrations or interactive user-request/appraisal paths; an admin action may dispatch the out-of-request synchronization service. Validate a candidate before atomic activation; failure preserves last-known-good data. Stale data is usable with an admin warning. Missing initial data blocks only Programs that contain compression-qualified rules.

## Consequences

- Classification is reproducible and aligned with CCP data.
- The plugin owns a small derived projection, not a second general SDE model.
- Import and activation require fixture-based validation tests.
- Administrators cannot manually override mappings.

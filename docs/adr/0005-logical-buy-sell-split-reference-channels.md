# ADR 0005: Use Logical BUY, SELL, and SPLIT Reference Channels

- Status: Accepted
- Date: 2026-09-09

## Context

Buyback policy needs stable pricing semantics while market providers and their configurations remain externally managed. Inferring semantics by inspecting a provider backend would couple policy to provider-specific details.

## Decision

Programs expose logical `BUY`, `SELL`, and `SPLIT` channels. BUY and SELL each map to a configured opaque `seat-prices-core` provider instance. SPLIT is either a dedicated provider instance or `DERIVED_MIDPOINT = (BUY + SELL) / 2`.

Derived SPLIT requires both component prices and retains exact midpoint precision until modification. The plugin does not inspect provider internals to decide whether an instance is semantically buy or sell.

The planner makes exactly one initial bulk call per distinct required provider-instance ID. A bulk exception starts bounded per-type recovery. On three consecutive per-type failures, recovery stops and every dependent line in every logical channel using that provider stream—including recovered, failed, and not-yet-attempted types—becomes `REFERENCE_UNAVAILABLE`; remaining lines MUST NOT be labeled `UNPRICED`. Isolated type absence while the channel remains usable is `UNPRICED`; unresolved provider configuration is `REFERENCE_MISCONFIGURED`. Channels backed entirely by unaffected provider instances may continue. No channel may silently fall back to another, and derived SPLIT is unavailable if either component channel is unavailable.

## Consequences

- Program policy remains stable across provider implementations.
- One provider instance may efficiently serve multiple logical channels through request consolidation.
- Derived SPLIT has an explicit two-component health dependency.
- Administrators, rather than provider introspection, assign provider streams to logical roles.

# ADR 0012: Use Private GitLab for Development and Public GitHub for Distribution

- Status: Superseded by ADR 0015
- Date: 2026-09-09

ADR 0015 supersedes the public-history synchronization and authority-transition
parts of this decision. GitLab remains authoritative during RC validation, but
the first public GitHub repository uses a verified clean release history and
GitHub becomes authoritative after stable-release validation and an explicit
cutover.

## Context

The plugin is intended for public SeAT community distribution, while active development and issue tracking currently occur in a private environment. Treating two repositories as simultaneous authorities would create divergent issues, branches, and release state.

## Decision

The private GitLab repository is authoritative for active development, issues, branches, reviews, and merge requests during this phase. GitHub is a later public repository and distribution surface, not the current development authority.

The package will follow SeAT/Composer conventions and be prepared for public GitHub plus Composer/Packagist distribution during release hardening. The public repository is `randulfTheGrey/seat-buyback-programs`; the Composer package is `randulfthegrey/seat-buyback-programs`. GitLab CI remains release authority until a separately approved transition. Any publication/mirroring process must preserve a single authoritative development history and avoid moving active planning to GitHub implicitly. The public contribution workflow is deferred until after stable release.

## Consequences

- Contributors follow GitLab issue/branch/MR conventions for current work.
- Implementation child issues are created in GitLab only when their planned phase begins; this architecture-capture task does not create them.
- Public packaging and repository synchronization require an explicit release process.
- GitHub state must not override private GitLab decisions during this phase.

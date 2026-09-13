# ADR 0015: Use Clean Public Release History and Transition Authority to GitHub

- Status: Accepted
- Date: 2026-09-13
- Supersedes: ADR 0012 publication-history and authority-transition mechanics

## Context

The authoritative pre-public GitLab history contains useful development detail,
but it also preserves private hostnames in old diffs, development-only names,
internal issue and branch topology, and author e-mail metadata. None of the
reviewed history contains credentials, but publishing every reachable commit is
unnecessary exposure for a new community package.

The first public release must still be demonstrably derived from the exact state
validated by GitLab CI. At the same time, maintaining two active development
authorities would create divergent issues, changes, and release decisions.

## Decision

During release-candidate validation, private GitLab remains authoritative for
development, issues, merge requests, CI, and release approval. Public GitHub is
initialized from the source-only distribution artifact produced by the green
GitLab pipeline. Private Git history, branches, tags, and metadata are not
mirrored.

The first GitHub commit is a new root commit created from the extracted,
validated artifact using an approved public author identity. Later RC exports
are ordinary public synchronization commits descended from that root so GitHub
shows useful release-to-release diffs without receiving private development
history. Public and private commits therefore have different commit IDs.

Each release record maps:

- the authoritative GitLab commit and successful pipeline;
- the source archive SHA-256 and file manifest;
- the public GitHub commit and semantic release tag; and
- the public-package installation and smoke-test evidence.

The GitHub tag and any private GitLab release marker may share the same semantic
version name, but they are created separately and point to their respective
commits. The validated archive and its recorded digest define content
equivalence; identical commit or tag-object IDs are neither expected nor
claimed.

After the release candidate is confirmed stable, the stable `1.0.0` export,
GitHub CI, issue and pull-request settings, security-reporting channel, and
Packagist integration must all be ready before a recorded authority cutover.
At cutover, GitHub becomes authoritative for `main`, issues, pull requests, CI,
releases, and public contribution. GitLab becomes a read-only historical archive
or downstream mirror. Development must not continue independently in both
repositories.

## Consequences

- A public push must use the reviewed export procedure, never `git push --mirror`
  or a push of private GitLab refs.
- Public history begins at the first release candidate and does not expose
  pre-public author metadata or internal infrastructure history.
- GitLab and GitHub commit hashes differ during RC stabilization, so provenance
  records and archive checksums are mandatory.
- Detailed pre-public blame and bisect history remains available only in GitLab.
- Public contribution remains deferred during RC validation and begins only
  after the stable authority cutover is recorded.
- The transition requires a GitHub-authoritative CI workflow before development
  moves; GitLab CI remains authoritative until then.

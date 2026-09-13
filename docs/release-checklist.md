# Public Release Checklist

The release manager records evidence for every item before publication.

- [ ] Authoritative `main` is clean and matches `origin/main`.
- [ ] Full authoritative GitLab CI pipeline is green.
- [ ] Compatibility matrix has been reverified against current upstream sources.
- [ ] PHP namespace, Composer name, GitHub name, UI name, and MIT license are final.
- [ ] `composer validate --strict` passes.
- [ ] Full tests and Architecture Guardrail Matrix pass.
- [ ] PHP lint and adopted release checks pass on every supported CI PHP version.
- [ ] Current tree, history review, and distribution archive contain no secrets or
      inappropriate private paths/hosts/configuration.
- [ ] README, installation, upgrade, configuration, and operations docs are current.
- [ ] CHANGELOG and `1.0.0-rc.1` release notes are ready.
- [ ] Clean public export was taken from the exact successful GitLab job artifact;
      no private Git history or refs were pushed.
- [ ] Private release record maps the GitLab commit/pipeline, archive SHA-256 and
      file manifest, public GitHub commit, and public tag.
- [ ] Separate annotated `v1.0.0-rc.1` tags identify the corresponding private and
      public release commits.
- [ ] GitHub Release is published using the prepared release notes.
- [ ] Packagist package is registered/updated from the public GitHub repository.
- [ ] Fresh Composer install from the public package passes in a disposable SeAT 5
      environment.
- [ ] End-to-end Program → appraisal → Quote → Request → terminal action smoke test
      passes with independent permissions.
- [ ] Stable `1.0.0` is promoted only after RC validation succeeds.
- [ ] Before authority cutover, GitHub CI, protection, issues/PRs, security
      reporting, and Packagist integration are validated; GitLab is then made
      read-only or downstream-only.

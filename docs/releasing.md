# Release and Distribution Process

## Governance and versioning

Private GitLab is authoritative for active development, issues, branches, merge
requests, CI, and implementation planning through RC validation. Public GitHub
uses a clean release-only history for public code, tags, release notes, and
Packagist integration. The private GitLab checkout must never add GitHub as a
mirror target or push its reachable history to GitHub.

After stable `1.0.0` is validated and the cutover prerequisites below are met,
GitHub becomes authoritative. GitLab then becomes a read-only historical archive
or downstream mirror; the two repositories must not remain competing development
authorities. See ADR 0015.

The project follows Semantic Versioning. `1.0.0-rc.1` is the first public
release candidate; stable `1.0.0` follows successful public/package validation.
Pre-stable RC behavior and migrations may evolve with responsible release notes.
After stable release, breaking public changes require an appropriate major
version.

Use annotated tags in the form `v1.0.0-rc.1`. Composer normalizes the leading
`v`, and the convention is compatible with common PHP/GitHub practice. Do not
create or push the tag until the checklist is complete and publication is
explicitly authorized.

## Prepare the clean public GitHub repository

Create exactly `randulfTheGrey/seat-buyback-programs` on GitHub as a public,
empty repository. Do not initialize it with a README, license, or unrelated
history. Do not use `git push --mirror`, `git push --all`, or push any private
GitLab commit or tag to GitHub.

After the release change is merged, run GitLab CI on the intended `main` commit.
The distribution job produces:

- the source-only `seat-buyback-programs.zip` artifact;
- the archive SHA-256; and
- a sorted SHA-256 manifest of every extracted file.

Record the GitLab commit, pipeline, artifact digest, and manifest in the private
release record. Download that exact successful-job artifact rather than building
an unrecorded replacement. Extract the GitLab job artifact and enter its `build/`
directory. Verify the inner package ZIP and every packaged file, extract that ZIP
to a new temporary directory, and run its distribution check before initializing
Git:

```bash
sha256sum --check seat-buyback-programs.zip.sha256
public_manifest_path="$(realpath seat-buyback-programs.files.sha256)"
public_export_dir="$(mktemp -d)"
unzip -q seat-buyback-programs.zip -d "$public_export_dir"
(cd "$public_export_dir" && sha256sum --check "$public_manifest_path")
php "$public_export_dir/scripts/verify-release.php" "$public_export_dir" --distribution
cd "$public_export_dir"
git init -b main
git config user.name "RandulfTheGrey"
git config user.email "YOUR_APPROVED_GITHUB_NOREPLY_EMAIL"
git add -A
git commit -m "Release Buyback Programs 1.0.0-rc.1"
git remote add github https://github.com/randulfTheGrey/seat-buyback-programs.git
```

Compare the committed public files and executable modes with the recorded
manifest and source artifact. Record the new public commit in the private release
record. Public and private commit hashes are expected to differ.

With explicit release authorization, create separate annotated tags with the
same semantic version name on the respective private and public commits:

```bash
# In the authoritative private GitLab checkout at the validated commit:
git tag -a v1.0.0-rc.1 -m 'Buyback Programs 1.0.0-rc.1'
git push origin v1.0.0-rc.1

# In the clean public export repository:
git tag -a v1.0.0-rc.1 -m 'Buyback Programs 1.0.0-rc.1'
git push -u github main
git push github v1.0.0-rc.1
```

The tags identify the same release contents but are not the same Git object.
Publish the GitHub Release from the public tag using
[the prepared release notes](releases/1.0.0-rc.1.md) and attach the recorded
archive checksum. GitLab CI remains release authority throughout RC validation.

For a later RC, start from a clone of the clean public repository, replace its
working tree with the newly validated source artifact, verify the manifest, and
commit the synchronization normally. This retains public release-to-release
diffs without importing private history.

## Packagist

After the GitHub repository is public and the tag is visible:

1. Sign in to Packagist with the intended maintainer account.
2. Submit `https://github.com/randulfTheGrey/seat-buyback-programs`.
3. Confirm the discovered name is exactly
   `randulfthegrey/seat-buyback-programs`, license is MIT, and version is
   `1.0.0-rc.1`.
4. Enable GitHub/Packagist automatic updates using the current Packagist GitHub
   integration or webhook guidance.
5. Verify the dist archive matches the GitHub tag and contains no private data.
6. In a disposable SeAT 5 installation, run
   `composer require randulfthegrey/seat-buyback-programs:^1.0@RC` without a VCS
   repository override, then execute migrations and the smoke test.

Packagist requires a publicly readable repository with a valid Composer manifest,
recognized license, and reachable semantic version tag. Registration, public
push, tag, and GitHub Release are account-level publication actions and are not
performed by release-hardening implementation alone.

## Stable authority cutover

After RC validation confirms stability, prepare the stable `1.0.0` source export
in GitLab and ensure it includes the reviewed GitHub CI workflow and final public
governance documentation. Before recording the cutover:

1. Publish and validate the stable export through the same provenance process.
2. Confirm GitHub branch protection, required reviews, CI, security reporting,
   issue templates, and release permissions are ready.
3. Confirm Packagist consumes the stable public tag successfully.
4. Record the last authoritative GitLab commit/pipeline and the corresponding
   public commit/tag.
5. Mark GitLab read-only or configure it only as a downstream archival mirror.
6. Announce that GitHub issues, pull requests, `main`, CI, and releases are now
   authoritative, and update contributor documentation accordingly.

No new development begins on GitHub before this record is complete, and no
authoritative development continues in GitLab afterward.

## Validation evidence

Record exact GitLab pipeline/MR links, Composer validation, test counts, PHP
matrix versions, lint/release checks, dependency audit classification, secret
scan, distribution archive inventory, fresh-install result, and end-to-end smoke
result with the checklist. Do not use live Alliance systems, ESI, CCP SDE, or
market APIs for CI; use the existing deterministic fixtures and fakes.

No static-analysis tool was adopted before the RC. Adding an uncalibrated strict
analyzer during release hardening would require a broad non-behavioral refactor
and produce a misleading new gate. The RC pipeline therefore uses strict
Composer validation, whole-tree PHP syntax checks, package metadata/link/private
data checks, the complete PHPUnit guardrail suite, and clean archive installation.
A focused static-analysis configuration may be proposed after stable release.

For a local container matching CI, build a supported PHP version and mount the
checkout:

```bash
docker build --build-arg PHP_VERSION=8.2 -f Dockerfile.validation -t seat-buyback-validation:8.2 .
docker run --rm -v "$PWD:/app" seat-buyback-validation:8.2 composer check
```

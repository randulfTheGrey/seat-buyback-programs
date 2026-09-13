# Security Policy

## Supported versions

Security support begins with the public `1.0.0-rc.1` line. Release candidates
may require upgrading to the newest RC rather than receiving long-lived patch
backports.

## Reporting

Do not disclose suspected vulnerabilities in a public issue. Until a public
security contact is announced, contact the repository owner privately through
their GitHub profile and include the affected version, impact, and reproduction
details. Do not include live credentials or private deployment data.

The package targets SeAT 5 and therefore Laravel 10. The reviewed compatibility
decision and focused residual-advisory policy are documented in
[ADR 0013](docs/adr/0013-accept-laravel-10-for-seat-5-with-focused-security-exceptions.md).

Before GitHub becomes authoritative, the release manager must enable a durable
private security-reporting channel on the public repository and replace the
temporary profile-contact instruction above with that channel.

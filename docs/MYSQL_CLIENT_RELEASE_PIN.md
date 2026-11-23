# MySQL backup-client release pin

The runtime retains MySQL client **9.7.4-1debian12**, matching the existing server release. Oracle moved its rolling Bookworm index to a newer package revision on 23 November 2026, which made the former version-qualified APT installation fail. The original vendor files remain available.

`docker/install-backup-client.sh` now downloads the exact amd64 release files over verified HTTPS from `https://repo.mysql.com/apt/debian/pool/mysql-9.7-lts/m/mysql-community/`, verifies fixed SHA-256 checksums before installation, and checks all installed versions. It fails on missing files, checksum differences, unsupported architecture or version mismatch. Debian dependencies still use signed Debian repositories.

| Package | SHA-256 |
|---|---|
| mysql-community-client-core | `24f222e06e28dc2e1edaac501904795c009c41c4fcadca3074a7eb6e81cd24f4` |
| mysql-community-client-plugins | `404a1e5f88a85123901c466d1ad2a8a304f179e857e173bb778217ca6502c43f` |
| mysql-community-server-core | `cd840712980c9c35bdd204643070c28dfef21f952179ed2b1082f9f7ed9fdaeb` |

The plugins package requires server-core binaries, so that transitive package is also pinned. The mysql-community-server daemon package is not installed by this procedure. No production database upgrade is performed.

Future client updates require explicit vendor URLs, reviewed checksums/version pins, and the existing exact-runtime encrypted backup/restore CI check. Do not replace the pin with latest, bypass hash failures, or disable TLS verification. The production deployment remains behind passing release checks.

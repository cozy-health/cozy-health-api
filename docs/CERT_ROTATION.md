# Certificate rotation

The complete mobile overlap and incident procedure is in the mobile repository's
`docs/CERT_ROTATION.md`. Use leaf DER SHA-256, normal TLS verification, and
fail-closed checks before transmitting health requests. Two real independently
verified current + next leaf certificates and a named owner are required.

Observed current Railway wildcard on 2026-10-09:
`fb370ca26842da2b2ff8e3182e0e8cfc6fb8eac32cdb5718f45a4b39b9e140af`,
valid until 2026-12-26 03:01:42 UTC. Railway controls renewal. The next certificate
and rotation owner are outstanding; do not ship a single-pin client.

Switch the server only after supported clients carry overlapping current + next
pins. Monitor outside the pinned API. Roll back only to a valid uncompromised
approved certificate, or distribute a verified app update independently of the
API. Never disable pin verification.

MySQL TLS is separate: provision its real certificate/chain and mount a trusted
CA for API and worker. Configure `MYSQL_SSL_CA` and `DB_REQUIRE_TLS=true`; verify
hostname and `SHOW SESSION STATUS LIKE 'Ssl_cipher'` / `Ssl_version`. Repeat this
verification during DB certificate rotation. Railway WireGuard private-network
encryption does not establish that the MySQL session uses TLS.

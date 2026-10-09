# Crypto deployment status

Owner approved the temporary MySQL TLS enforcement opt-out on 2026-10-09.
Production API and queue worker are configured with `DB_TLS_ENFORCE=false`.
This bypasses the CA requirement and explicit PDO TLS options, preserving the
pre-12.2 connection behavior. It does not prove a MySQL TLS session or TLS-only
server configuration. No Railway SSH key is authorized or installed.

`DB_TLS_ENFORCE` defaults to true. With enforcement enabled, the production
connector requires a readable trusted CA, server certificate verification and a
nonempty session `Ssl_cipher`. Local/testing behavior continues to use
`DB_REQUIRE_TLS`; do not carry a local false value into strict production setup.

During Phase 24, configure the actual trusted CA and TLS-capable endpoint for
both services, enable `DB_TLS_ENFORCE=true` and `DB_REQUIRE_TLS=true`, and verify
hostname, `Ssl_cipher` and `Ssl_version` before accepting the deployment.

The current API leaf pin expires December 26, 2026. Real next-rotation pin
provisioning and Railway volume AES-256 evidence remain Phase 24 requirements.
Native debug builds use normal TLS verification; profile/release require both
real pins. See `PHASE_12_2_CRYPTO.md` and `CERT_ROTATION.md`.

Existing plaintext provider PDFs require the separately coordinated
`reports:encrypt` migration with a verified backup. This deployment does not run
that migration; legacy plaintext downloads fail closed until migrated.

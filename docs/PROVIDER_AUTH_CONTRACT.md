# Provider sign-in and account deletion

Google and Apple sign-in use the existing login response envelope and Sanctum
bearer tokens. Provider subjects, linked timestamps and stored Apple grants are
hidden from user responses.

## Linking

Verified public emails link to the same account across email/password, Google
and Apple. Existing provider subjects remain authoritative when emails change.
Apple private relay identities and unrelated public emails create separate
accounts. An exact relay-email collision returns 409 without automatic linking.
An already-linked subject is never overwritten with a different subject.

## Apple grant capture

`POST /api/v1/auth/apple` accepts the existing `identity_token`, optional
`full_name` and `device_name`, plus an optional `authorization_code`.

An authorization code is short-lived and single-use. The backend immediately
exchanges it at Apple's `/auth/token` endpoint, verifies that the returned ID
token matches the original subject and audience, and stores the refresh token
with Laravel's encrypted cast. The original authorization code is not stored.
Requests without a code preserve any existing stored grant.

The client ID is the verified audience: `APPLE_SERVICE_ID` for web or
`APPLE_BUNDLE_ID` for native iOS. Signing uses `APPLE_TEAM_ID`, `APPLE_KEY_ID` and
`APPLE_PRIVATE_KEY` (P-256 `.p8` content). `APPLE_REDIRECT_URI` is needed only if
the authorization request used a web redirect URI. Keep `APP_KEY` stable once
refresh tokens are encrypted; key rotation requires a ciphertext migration or
supported previous-key configuration.

If signing configuration is missing, grant capture logs a warning and preserves
identity-only login. Configured exchanges with invalid codes return 401;
provider outages/malformed responses return 503 without creating a local account.
Phase 4b must forward the authorization code; Phase 4c must configure signing and
verify capture/revocation with Apple. Earlier Apple users without stored grants
need another Apple authorization to populate them.

## Deletion

`DELETE /api/v1/account` requires the current user's Sanctum bearer token and is
throttled to five requests per minute. No supplied user ID selects the account.

Before a local database transaction, Apple-linked accounts attempt revocation of
their stored refresh token with `token_type_hint=refresh_token` and an ES256
client-secret JWT. Authorization codes and identity tokens are not revocation
tokens. Missing grants/configuration or provider failures log sanitized warnings
and do not block local deletion. No provider token values, signing keys or
provider response bodies are logged.

The transaction deletes all Sanctum tokens, password-reset records, polymorphic
notifications and provider identity/grant fields, then deletes the user. Existing
foreign-key cascades remove user-owned database records. Other users are untouched.
Google ID-token-only sign-in has no stored OAuth access/refresh grant to revoke.

Local deletion success does not claim Apple revocation succeeded when it was
skipped or failed; there is currently no asynchronous revocation retry.

Apple references: [token validation](https://developer.apple.com/documentation/signinwithapplerestapi/generate-and-validate-tokens),
[token revocation](https://developer.apple.com/documentation/signinwithapplerestapi/revoke-tokens),
[account deletion guidance](https://developer.apple.com/documentation/technotes/tn3194-handling-account-deletions-and-revoking-tokens-for-sign-in-with-apple).

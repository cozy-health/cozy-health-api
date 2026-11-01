# Required-consent availability contract

The configuration responses retain consent.enforce_blocking as the explicitly configured policy and add consent.documents_ready. A readiness failure must not silently disable the policy. When enforcement is true and readiness is false, patient private endpoints and sync return HTTP 403 with code consent_required. Consent history/review and approved emergency routes remain available. Clients show the existing unavailable/retry state rather than private content or a draft Accept action.

Required types come from canonical consent configuration; missing required types, empty maps, draft versions, unpublished documents and missing text are not ready. Marketing is optional. A false enforcement setting preserves the staged rollout. No deployment should enable the setting until the existing readiness and compatible-client acceptance gates are satisfied.

Regression coverage: Tests\Feature\DocumentConsentTest. Broader provider UI and operational consent activation require separate acceptance.

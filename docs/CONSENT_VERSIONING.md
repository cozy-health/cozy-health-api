# Document consent versions

Patient records use `user_consents`; provider records use `provider_consents`. GET and POST consent endpoints are scoped to the authenticated account. Each document/version has one current state, with state transitions retained in the corresponding consent events table. Account deletion cascades both tables.

Draft tracking has `status=pre_acceptance` and `accepted_at=null`. It is not evidence of agreement. Initial draft tracking is silent; later draft notices are skippable and do not offer acceptance of missing legal text. Explicit published agreement has `status=accepted`, a timestamp, IP and user agent. Retries are idempotent. Never interpret a draft tracking record as legal consent.

Marketing starts off, requires an explicit Settings → Privacy opt-in and can be withdrawn. It never blocks access. `DocumentConsentService::marketingAllowed()` returns eligibility only for an active account with explicit acceptance of the current marketing version. There is currently no marketing campaign sender; any future sender must use this check immediately before delivery. Existing password, verification and security mail is transactional.

## Publish approved documents during Phase 13

Use the existing administrator settings endpoint or `settings:set` command to set `consent.documents` and `consent.provider_documents` to complete JSON objects with the same document types as `config/consent.php`. Each document includes title, version, required, published and plain-text content. Preserve version/content immutability once published; increment the version for every substantive change. These endpoints do not supply or approve legal text.

Keep `consent.enforce_blocking=false` until counsel approves the documents and the owner confirms the rollout. Publishing requires non-empty text and a non-draft version. Set the flag to true only after the required documents are published; patient and provider configuration independently suppress blocking while their required documents remain drafts. Marketing and community guidelines remain optional. The mobile and provider flows use exact version equality, never lexical ordering.

No production migration or flag change was performed in this phase. Enforcement is a client workflow, not a backend ban on crisis resources. Offline draft rollout remains usable. Future mandatory-consent rollout must separately define offline behavior and crisis access with legal and clinical reviewers.

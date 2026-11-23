# Emergency delivery safety — 22 November 2026

Local-only acceptance for items 10–15. API base ee2e6b3 (same pre-addition tree as recovery runtime 205c61c). No application/schema/config change or feature activation.

Added seven tests in tests/Feature/EmergencyDeliverySafetyTest.php: recipient unsubscribe and account deletion remove queued jobs; withdrawn feature, stale jobs and lost confirmation do not send; transport exceptions are terminal and not replayed; expired confirmations/forged unsubscribe tokens cannot change recipient consent. All use SQLite/synthetic accounts and fake or mocked mail, with no outbound delivery.

Focused result: seven passes/19 assertions. Initial broader API selection: 401 passes/3,429 assertions; full LaunchWorkflow: nine passes/40 assertions (three overlap). Final complete affected selection including additions: 408 passes/3,448 assertions, exit 0. Existing application source is unchanged. API vendor dependencies were restored from the existing lockfile without scripts; installed GD/curl extensions enabled for these test processes only. Graph refresh rejected by the launcher develop-only policy; direct source checks used. Shared detailed evidence: workspace output/items-10-15-20261122/ and mobile docs/validation/2026-11-22-items-10-15.md.

Real recipient receipt, operational activation and production observation remain open. Graph refresh is blocked by the develop-only branch policy; source/route/job checks used instead. Existing API source and dependency lockfile unchanged. No push/deploy.

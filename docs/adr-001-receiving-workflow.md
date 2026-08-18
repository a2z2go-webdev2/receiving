# ADR-001: Staged, asynchronous receiving workflow

- **Status**: Accepted
- **Requirements**: REQ-002 through REQ-006, REQ-009, REQ-011
- **Reversibility**: Type 1 for persistence/status contracts; Type 2 for provider adapters.

## Context

The application accepts hostile document bytes, must return promptly, uses private Cloudflare R2, and depends on malware scanning, email, and Gemini. Those systems fail independently. A transaction must survive partial failure and must not expose or process a file before it is trusted.

## Decision

Use a Laravel modular monolith with a cohesive `Receiving` feature. The browser uploads to private, transaction-scoped R2 staging objects through short-lived presigned URLs. Completion verifies object metadata and queues a retry-safe file pipeline. Each file is downloaded to bounded temporary storage, signature-validated, scanned, optionally re-encoded, and only then promoted to a final key. Notification, AI extraction, and review-link creation run after their persisted prerequisites. The upload type owns its workflow profile: standard receiving lanes include notifications and review, while the Purchase Order lane performs strict JSON extraction without either side effect.

The database is the workflow source of truth; R2 stores bytes only. Statuses are enums and transitions are guarded by services. Record-specific authorization uses policies; broad capabilities use Spatie permissions. Provider behavior is behind small contracts so local fakes and future provider replacement do not weaken domain rules.

## Options considered

1. **Synchronous server upload and full processing** — rejected because scan, compression, email, and AI latency would hold the request open and make partial recovery fragile.
2. **Direct upload to the final key** — rejected because untrusted bytes would appear accepted before inspection.
3. **Microservices per pipeline stage** — rejected because current scale and team constraints do not justify distributed coordination and operational cost.
4. **Skip scanning when unavailable** — rejected because it violates the explicit trust boundary and could distribute malware to email recipients or Gemini.

## Consequences

- The UI receives quick durable acknowledgement and visible background statuses.
- Staging cleanup and queue-worker health become required operations.
- R2 needs private-bucket CORS for presigned PUT requests.
- Local/test adapters are necessary for deterministic tests.
- A transaction can become partially accepted or partially extracted without losing successful file results.

## Pre-mortem and controls

Thirty days after release, the most plausible failure is a growing queue or a provider outage leaving uploads stuck. Users notice old Pending statuses; operators see pending-age and failure counts. Persisted state prevents loss, idempotent retry operations permit recovery, and alerts must fire on queue depth/oldest-pending age. A second failure is scanner bypass through configuration; production fail-closed behavior and readiness checks prevent promotion when scanning is not verifiably available.

## Reversal plan

Provider adapters can be replaced without changing transaction/extraction contracts. Replacing the staged workflow requires a new ADR and a migration that preserves object keys, hashes, and status history.

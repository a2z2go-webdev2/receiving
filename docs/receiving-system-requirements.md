# Receiving system requirements

Source: `C:\Users\durin\Downloads\Receiving.md`, supplied 2026-06-28.

This document is the traceability seed for the durable implementation. Requirement IDs are referenced by architecture decisions, modules, and tests.

## Ubiquitous language

- **Upload type**: one receiving business identity: A2Z2GO, PINGCON, BONITA, or KEYSYS INC.
- **Access grant**: an active assignment allowing a user to enter one upload type.
- **Upload transaction**: one serial-numbered submission containing one or more files.
- **Staging object**: a private R2 object that is not yet trusted or accepted.
- **Accepted file**: a file whose type, signature, size, integrity, and malware checks passed.
- **Extraction**: Gemini's raw JSON plus the editable corrected JSON for one file.
- **Review link**: an expiring, hashed bearer-token grant to review one transaction.
- **Verification**: the terminal review action that makes corrected JSON the accepted result.
- **Upload log**: transaction state stored on the upload transaction.
- **Activity log**: append-only security and operational event history.

## Bounded contexts

1. **Identity and access**: Fortify authentication, active users, permissions, upload-type grants, scoped email OTP.
2. **Receiving**: upload initiation, staged objects, validation, malware scanning, compression, promotion, and transaction status.
3. **Notification**: upload and review email delivery, recipients, failures, and authorized retries.
4. **Extraction and review**: Gemini processing, per-file JSON, review tokens, corrections, invoice invariants, and verification.
5. **Administration and audit**: dashboards, users, access, recipients, settings, monitoring, retry operations, and activity history.

## Crystallized requirement cards

### REQ-001 — Scoped access and OTP

- **Crystallized**: An active authenticated user may open an active upload type only with an active access grant and a single-use, hashed email OTP scoped to that user and upload type. OTPs expire after the configured duration (default 5 minutes), allow at most five failed attempts, and resend/verify endpoints are rate-limited.
- **Type**: Functional, Security.
- **Architecturally significant**: Yes; it is a trust boundary.
- **Test**: authorization matrix, inactive/removed access, wrong-scope OTP, expiry, attempt limit, replay, and rate-limit feature tests.

### REQ-002 — Private staged upload

- **Crystallized**: One transaction accepts 1..N files (default maximum 10), each no larger than the configured limit (default 15 MiB), using private R2 staging keys. Allowed extensions are JPG/JPEG/PNG/PDF, and metadata initiation must match the uploaded objects before processing is queued.
- **Type**: Functional, Security, Performance.
- **Architecturally significant**: Yes; it controls untrusted bytes and storage cost.
- **Test**: empty, count limit±1, size limit±1, unsupported extension, missing/tampered staged object, and ownership tests.

### REQ-003 — Acceptance pipeline

- **Crystallized**: A staged file is promoted to `receiving/{type}/{yyyy}/{MM}/{dd}/SN-{serial}/...` only after extension, MIME, magic-byte, size, corruption, safe-name, and malware checks pass. Images are safely re-encoded when compression is enabled; readable PDFs are preserved. Production fails closed when scanning cannot complete.
- **Type**: Functional, Security.
- **Architecturally significant**: Yes; incorrect behavior can distribute malware.
- **Test**: signature spoof, corrupt file, unsafe filename, infected/suspicious/scanner-unavailable, compression failure, and staging cleanup tests.

### REQ-004 — Responsive asynchronous workflow

- **Crystallized**: Upload completion returns after durable transaction/file records exist and processing is queued; email, file processing, Gemini extraction, and review notification do not block the upload page. Queue jobs are retry-safe and status transitions are persisted.
- **Type**: Quality Attribute, Functional.
- **Architecturally significant**: Yes; determines transaction and job boundaries.
- **Test**: queued-job assertions, duplicate-finalize/idempotency tests, partial file failure, and recovery-after-failure tests.

### REQ-005 — Notifications and retry authorization

- **Crystallized**: Active To/CC/BCC recipients are resolved per upload type. Upload notification failures do not remove the transaction. Admins may retry failed delivery; an uploader may retry only a failed notification on their own valid transaction while they retain type access. Every attempt is audited.
- **Type**: Functional, Security.
- **Architecturally significant**: Yes; record ownership and side effects are involved.
- **Test**: recipient composition, sent/failed transitions, wrong-owner, access-removed, non-failed retry, and duplicate-click tests.

### REQ-006 — File-level Gemini extraction

- **Crystallized**: Only accepted clean final objects are submitted to Gemini. Each file has an independent extraction record and status. Work is split into configured batches, continues after individual failures, retries with backoff, stores partial results immediately, and accepts only parseable JSON.
- **Type**: Functional, Reliability, Performance.
- **Architecturally significant**: Yes; external AI is unreliable and data-bearing.
- **Test**: malformed AI response, timeout, retry, partial batch failure, non-clean file exclusion, and aggregate-status tests.

### REQ-007 — Invoice semantics

- **Crystallized**: Invoice extraction distinguishes supplier from buyer, TIN from input tax, and gross from purchases; missing values remain null. Corrected invoice JSON always contains `account_title`, `ewt_1_percent`, `ewt_2_percent`, and `atc`.
- **Type**: Functional, Data Quality.
- **Architecturally significant**: Yes; financial meaning is affected.
- **Test**: schema normalization, null preservation, and prompt/response contract tests.

### REQ-008 — Invoice verification invariant

- **Crystallized**: Verification requires a non-empty account title and exactly one positive EWT value. EWT 1% forces ATC 158; EWT 2% forces ATC 160; clients cannot choose ATC. Invalid files prevent transaction verification.
- **Type**: Functional, Data Integrity.
- **Architecturally significant**: Yes; it is the final financial-data gate.
- **Test**: neither/both EWT, zero/negative/non-numeric EWT, spoofed ATC, and valid 1%/2% property cases.

### REQ-009 — Secure review

- **Crystallized**: Review URLs contain at least 256 bits of random token material; only SHA-256 token hashes are stored. Tokens expire within the configured duration (default 24 hours), are invalid after verification, and file previews use short-lived R2 signed URLs (default 30 minutes).
- **Type**: Functional, Security.
- **Architecturally significant**: Yes; bearer links expose documents and extracted data.
- **Test**: unknown, expired, used, and cross-transaction tokens; URL expiry configuration; no raw token persistence.

### REQ-010 — Administration and audit

- **Crystallized**: Permission-protected admin modules manage users, access grants, recipients, safe settings, upload/file/extraction/review monitoring, signed file access, and failed-operation retries. Activity events record actor, role, module, action, outcome, related serial, IP, and safe error context.
- **Type**: Functional, Operability, Security.
- **Architecturally significant**: Yes; these are privileged controls.
- **Test**: unauthenticated/uploader/admin authorization coverage for every privileged endpoint and activity assertions for mutations.

### REQ-011 — Secret isolation

- **Crystallized**: R2, Gemini, mail, session, and scanner credentials are environment-only. The settings UI exposes boolean readiness checks but never secret values. Logs, Inertia props, mail, validation errors, and tests must not disclose credentials or raw review/OTP tokens.
- **Type**: Security, Operability.
- **Architecturally significant**: Yes; credential disclosure is a release blocker.
- **Test**: response-prop and logging assertions plus configuration-status tests.

### REQ-012 — Usable operational UI

- **Crystallized**: Admin and uploader workflows are responsive and WCAG 2.1 AA-oriented, keep the primary action recognizable within three seconds, expose loading/empty/error/success/partial states, prevent duplicate submissions, and provide upload progress and confirmation before transfer.
- **Type**: Usability, Accessibility.
- **Architecturally significant**: No; it shapes delivery but not persistence boundaries.
- **Test**: type/build checks, semantic labels, keyboard-operable modal, disabled pending actions, and browser workflow verification.

### REQ-013 - Non-blocking OTP delivery

- **Crystallized**: Admin and upload OTP requests persist the hashed one-time code and enqueue encrypted email delivery without opening an SMTP connection in the web request. The default queue worker delivers the message with bounded retries and a 20-second attempt timeout.
- **Type**: Performance, Reliability, Security.
- **Architecturally significant**: Yes; authentication latency and secret handling cross the web/queue boundary.
- **Test**: the OTP page queues `SendQueuedNotifications`, stores only the hash, and renders without executing the mail transport.

### REQ-014 - Required upload geolocation

- **Crystallized**: The authenticated upload page blocks file selection and submission until the user explicitly grants browser location access. Upload initiation requires latitude/longitude in valid ranges, a reading no older than 120 seconds, and browser-reported accuracy within the configurable practical bound (default 1,000 meters). The transaction stores the coordinates, reported accuracy, and capture time; the admin detail page shows them and an embedded map.
- **Type**: Functional, Data Integrity, Privacy, Usability.
- **Architecturally significant**: Yes; location is personal data and becomes part of the durable upload record.
- **Test**: missing, denied, stale, inaccurate, and out-of-range readings; persistence; admin prop/map rendering; upload remains disabled before consent.

### REQ-015 - Compact shared pagination

- **Crystallized**: Every paginated admin and uploader page uses one content-width navigation control with 44-pixel previous/next targets, an accessible label, and a concise current/last-page indicator. It must not expand to the page width.
- **Type**: Usability, Accessibility.
- **Architecturally significant**: No; it is a shared presentation contract.
- **Test**: type/build checks and browser verification across one admin page and uploader history.

### REQ-016 - Corrected-data integration API

- **Crystallized**: Administrators with upload-view permission can generate named 30-, 90-, 365-day, or explicitly never-expiring API keys after password confirmation. A key is shown once, stored only as a SHA-256 hash, scoped to `corrected-data:read`, individually rate-limited, and immediately revocable. Dedicated endpoints return all verified data or exact indexed matches by receiving serial number, invoice number, or document type using bounded ID-keyset pagination of at most 100 rows. They never return raw extraction JSON, credential material, uploader email, file bytes, or signed file URLs.
- **Type**: Functional, Security, Performance, Integration.
- **Architecturally significant**: Yes; this introduces a durable machine-to-machine trust boundary and public data contract.
- **Test**: missing/malformed/expired/revoked/wrong-scope keys, cross-owner revocation, finite and never expiry, one-time reveal, hash-only persistence, verified-only filtering, all three indexed filters, secret/raw-data absence, page-size limit, and keyset continuation.

### REQ-017 - Clear session expiry recovery

- **Crystallized**: Expired login, admin OTP, upload OTP, and CSRF sessions produce an explicit `SESSION_EXPIRED` response or a flash message. A global accessible popup explains what happened and reloads the correct login or verification screen instead of leaving a silent redirect or generic upload error.
- **Type**: Reliability, Security, Usability.
- **Architecturally significant**: Yes; session boundaries affect every protected workflow.
- **Test**: expired browser cookie, admin grant, upload grant, JSON upload request, shared Inertia prop, and browser popup recovery.

### REQ-018 - Single-open upload file details

- **Crystallized**: Upload detail rows are collapsed initially. Opening one file closes the previously open file, while file status and the Open File action remain visible without expansion.
- **Type**: Usability, Performance.
- **Architecturally significant**: No; it bounds rendered detail content and reduces page cognitive load.
- **Test**: production build and browser assertions for zero initially expanded rows and at most one expanded row after interaction.

### REQ-019 - Bounded, replay-safe background execution

- **Crystallized**: Upload completion is atomically claimed and workflow-start jobs are unique per upload on a shared cache, so concurrent or replayed requests enqueue each stage at most once. External AI requests retry only transient connection, timeout, rate-limit, and server failures. The configured queue retry lease must exceed the longest worker timeout, AI work per job must fit inside that timeout, and exhausted startup jobs persist an explicit failed state that operators can retry.
- **Type**: Reliability, Performance, Cost, Data Integrity.
- **Architecturally significant**: Yes; at-least-once queue delivery and paid external calls cross process and provider boundaries.
- **Test**: duplicate completion/start assertions, non-transient-versus-transient provider retry tests, queue-budget configuration tests, and failed-job state-transition tests.

### REQ-020 - Production configuration gate

- **Crystallized**: A deterministic deployment command fails before traffic is enabled when production uses debug mode, an insecure URL/session, a synchronous queue, process-local cache locks, a queue retry lease shorter than the worker timeout, local receiving storage, non-production mail, missing Gemini configuration, a disabled scanner, missing Cloudmersive credentials, or Cloudmersive limits that exceed the configured free-tier envelope. Provider connectivity remains a separate deployment smoke test.
- **Type**: Operability, Security, Reliability.
- **Architecturally significant**: Yes; unsafe configuration can bypass otherwise-correct application controls.
- **Test**: command tests for a complete production configuration and each release-blocking mismatch.

### REQ-021 - Purchase Order extraction lane

- **Crystallized**: Purchase Order is a separately assignable upload lane that accepts PDF files only. Gemini returns JSON containing the specified PO, buyer, vendor, payment-term, and per-item fields. Missing required values use `[See image]`; absent optional contact/address values remain empty. Purchase Order uploads never send receiving/review email and never create review links. Admins can search and filter the dedicated Purchase Orders list by AI extraction state and open the full extracted data.
- **Acceptance**: The lane is created during migration and seeding, appears in upload access and lane settings, rejects non-PDF metadata at the request boundary, persists `not_required` notification/review states, retains those states during reprocessing, and exposes only Purchase Order records on the dedicated admin route.
- **Evidence**: `UploadInitiationTest`, `ReceivingWorkflowTest`, `GeminiDocumentExtractorTest`, `AdminUploadLogSearchTest`, and `UploadAccessOtpTest`.

### REQ-022 - Cloudmersive free-tier malware scanning

- **Crystallized**: Every validated file consumes at most one Cloudmersive scan request per processing attempt. Across all workers, at most one request is in flight and request starts are at least one second apart. A durable monthly ledger prevents calls beyond the configured allowance (800 for the supplied account), and the effective upload size cannot exceed the configured provider plan cap. Provider 429 responses and a locally exhausted allowance leave the file fail-closed in private staging and release its queue job for automatic retry. Invalid, ambiguous, unauthorized, timeout, and server responses never produce a clean result. API credentials and raw provider errors never enter UI props, logs, or persisted failure text.
- **Type**: Security, Reliability, Performance, Cost.
- **Architecturally significant**: Yes; hostile files cross a third-party trust boundary with strict concurrency and economic limits.
- **Test**: clean/infected/malformed response mapping, secret-header assertions, no-call oversized rejection, monthly limit-plus-one, consecutive-request spacing, provider 429 deferral, retained staging, and production configuration checks.

### REQ-023 - Warehouse lot tracking and dwell reporting

- **Crystallized**: Only users with warehouse-operation permission can turn a linked PO/invoice delivery into stock, record opening inventory, create a customer delivery, allocate it at dispatch, or confirm customer receipt. The arrival queue shows PO date, ordered quantity, supplier-delivered quantity, supplier delivery/upload date, and PO waiting days. Confirming placement accepts the physical quantity plus optional lot/notes; the server, not the browser, records the current warehouse-placement timestamp and acting user. Administrators have read-only report access. Dispatch atomically allocates every consolidated delivery line using FIFO, consumes no stock placed after the dispatch date, never produces negative availability, and is idempotent under replay. Delivered-line reports use persisted allocations to show quantity-weighted customer-delivery-minus-placement warehouse dwell, dispatch-minus-placement warehouse holding, maximum contributing-lot dwell, and dated-quantity coverage without substituting PO/upload dates for missing opening-stock dates.
- **Type**: Functional, Data Integrity, Security, Auditability.
- **Architecturally significant**: Yes; stock ownership, temporal attribution, and privileged physical transitions become durable business records.
- **Acceptance**: A new `warehouse_operator` account is redirected to its own workspace; admin/uploader mutation attempts are forbidden; supplier waiting facts are visible before placement; client-supplied placement dates or confidence values cannot backdate dwell; old and new receipts of one item can contribute partial quantities to a single delivery; a shortage on any item rolls back the complete dispatch; future receipts are excluded; customer delivery cannot precede dispatch; transition retries create no duplicate allocations/events; and unknown opening dates reduce explicit report coverage rather than becoming zero-day dwell.
- **Evidence**: `WarehouseDwellWorkflowTest`, `WarehouseOperations`, `WarehouseDwellReport`, and ADR-005.

## Quality assumptions and operational targets

- **[ASSUMED] Workload**: up to 20 files per transaction and 25 concurrent uploaders. With the current Cloudmersive free-tier configuration, each file is capped at 3.5 MiB and monthly throughput is capped by the account allowance; both remain configurable without schema changes when the provider plan changes.
- **[ASSUMED] Latency**: authenticated read pages should remain below 500 ms p95 excluding signed-storage calls; upload initiation/completion below 1 s p95 excluding the byte transfer. Background processing is observable rather than synchronously bounded.
- **[ASSUMED] Availability**: R2, mail, scanner, and Gemini failures degrade to explicit persisted failure states with authorized retries; they do not lose transaction metadata.
- **[ASSUMED] Data retention**: no automatic deletion of accepted records or final objects is enabled until the business supplies a retention policy. Abandoned staging objects default to cleanup after 24 hours.
- **[ASSUMED] Review recipient rule**: uploader by default; administrators may switch to active upload-type recipients.
- **[ASSUMED] Local/test behavior**: local filesystem and a fake clean scanner are allowed outside production so the workflow can be tested without external credentials. Production readiness checks report missing dependencies.

## Risk scan

Risk scan: **WATCHLIST**

| Risk | Likelihood | Impact | Detection | Mitigation / kill criterion |
|---|---|---|---|---|
| Malware scanner not deployed | Medium | High | readiness UI, failed pipeline status | Fail closed in production; do not release uploads until a real scanner passes an end-to-end probe. |
| Cloudmersive quota or rate limit exhausted | High on free tier | High | durable usage row, deferred activity, provider portal | Serialize calls, cap monthly reservations, retain staging, and automatically retry deferred file jobs after capacity returns. |
| R2 CORS or credentials misconfigured | Medium | High | storage health check and direct-upload test | Private bucket, least-privilege key, explicit allowed origins/methods; block release if signed PUT/GET probe fails. |
| Gemini schema drift or hallucination | High | Medium | JSON/schema validation and extraction failures | Strict JSON contract, null preservation, human verification; never auto-verify. |
| Financial field misclassification | Medium | High | review invariants and sampled review metrics | Purpose-built prompt, field normalization, mandatory human review. |
| Email provider outage | Medium | Medium | persisted status and activity log | Retry-safe delivery and secure in-app access. |
| Queue not running | Medium | High | pending-age dashboard/monitor | Deployment health check and alert on oldest pending job; block release without a worker. |
| Unbounded object/data growth | Medium | Medium | storage/DB metrics | Pagination, indexes, staging cleanup, and a business-approved retention policy before scale demands it. |

Smallest valid production experiment: upload a spoofed-extension test corpus and the EICAR test file through the real R2 CORS path in a non-production bucket, verify fail-closed scanner behavior, then process one clean invoice through the real Gemini and mail providers. Release is blocked until that integration proof passes.

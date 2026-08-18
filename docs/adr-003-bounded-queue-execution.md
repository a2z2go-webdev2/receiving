# ADR-003: Bounded, replay-safe queue execution

- **Status**: Accepted
- **Requirements**: REQ-004, REQ-006, REQ-019, REQ-020
- **Reversibility**: Type 2; queue budgets and worker topology can change without changing persisted business data.

## Context

Laravel queues provide at-least-once delivery. A job whose worker timeout is longer than the backend retry lease can become visible to a second worker while the first copy is still running. AI extraction also combines HTTP-level and job-level retries, so an unbounded batch can exceed the worker timeout and repeat paid provider calls.

## Decision

- Claim upload completion with a conditional database update and make receiving/AI orchestration jobs unique per upload through the shared production cache.
- Make finalizers resume from persisted email and AI states so job replay does not repeat completed side effects.
- Retry Gemini only for connection failures, HTTP 408/429, and 5xx responses. Do not retry deterministic 4xx responses inside the HTTP client.
- Bound files per AI job from the worker timeout, provider timeout, HTTP-attempt count, and a fixed safety margin.
- Default database and Redis retry leases above the workload worker timeout and verify this relationship in a production configuration gate.
- Persist failed workflow-start states so operators do not see indefinitely processing records after retries are exhausted.

## Alternatives considered

- Exactly-once queues were rejected because the database, queue backend, mail provider, and Gemini do not share one transaction.
- A new orchestration service was rejected because the current scale is better served by the existing modular monolith and persisted state machine.
- Disabling retries was rejected because transient network and provider failures are expected.
- Retrying every provider response was rejected because authorization, validation, and other deterministic 4xx failures waste time and usage-based spend.

## Consequences

- More, smaller AI jobs may be created when provider timeouts are high; API-call count is unchanged and failure isolation improves.
- Queue workers and their backend visibility/retry leases must be configured as one budget.
- Mail can still be duplicated when a provider accepts a message but the connection fails before acknowledgement; eliminating that residual ambiguity requires a provider idempotency contract or transactional outbox integration.

## Reversal plan

The timeout and batching values are configuration-backed. A future managed workflow engine or provider batch API can replace the queue orchestration behind the existing persisted statuses and job entry points.

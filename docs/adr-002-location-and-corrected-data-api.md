# ADR-002: Upload location and corrected-data integration boundary

Status: Accepted

## Context

Receiving operations need durable evidence of where an upload was initiated and a safe way for another system to consume invoice and receipt extraction JSON before and after human verification. Browser location is permissioned and imperfect, while integration credentials create a new machine-to-machine trust boundary.

## Decision

- Capture a fresh high-accuracy browser geolocation reading before transaction initiation and enforce the location contract again in Laravel validation. Store coordinates, reported accuracy, and capture time on the receiving transaction.
- Treat the reported accuracy radius as the honest precision boundary. Do not claim survey-grade or fraud-proof location. Accept practical browser readings within a configurable bound (default 1,000 meters) and display the reported accuracy to administrators.
- Show the location only on the permission-protected admin detail page and render its map through the keyless Google Maps embed URL.
- Use first-party API keys owned by an authorized user. Keys contain a random public identifier plus 256 bits of secret material, are shown once, stored as SHA-256 hashes, optionally expire, carry a fixed read ability, and can be revoked. Never-expiring keys are an explicit higher-risk operator choice.
- Expose invoice and receipt extractions through versioned, rate-limited serial-number and PO-number endpoints with bounded keyset pagination. Return corrected JSON when it exists, otherwise return raw AI-extracted JSON, and label every record as verified or unverified. Maintain indexed PO lookup projections instead of scanning JSON for each request.

## Alternatives considered

- IP geolocation was rejected because it is materially less accurate and may identify a VPN or carrier gateway rather than the uploader.
- Client-only location checks were rejected because requests can bypass the UI.
- Permanent unhashed keys were rejected because a database read would immediately disclose every integration credential.
- Laravel Passport/Sanctum was not added because the required contract is one narrow server-to-server read scope; the local implementation preserves the same hash-only, expiry, ability, and revocation invariants without adding an OAuth lifecycle or dependency.
- Offset pagination was rejected for integration synchronization because its cost and consistency degrade as verified data grows.

## Consequences

- Uploads cannot begin when location permission is denied, location services are off, or the reading is outside the configured practical bound.
- Location is personal data and must follow the application's access, retention, and privacy policies.
- Consumers page in ascending extraction ID order using `next_after_id`. Because an unverified extraction can later be corrected and verified without changing its ID, consumers that need the final state must refetch their serial or PO lookup rather than treating the cursor as a change feed.
- Lookup metadata is projected from corrected JSON when available and raw AI JSON otherwise. The response also derives invoice and PO metadata from the selected payload so older unverified rows remain complete.
- The API key table and corrected-extraction index must be migrated before enabling integrations.

## Reversal plan

The location gate can be relaxed through configuration and a follow-up policy change without changing existing rows. API keys can all be revoked and the route disabled; no external identity provider or OAuth schema must be unwound.

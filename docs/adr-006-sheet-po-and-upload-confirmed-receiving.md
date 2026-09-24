# ADR-006: Google Sheet Purchase Orders and Upload-Confirmed Receiving Stock Posting

- **Status**: Accepted
- **Requirements**: Supersedes manual-placement requirement in ADR-005 for live receiving uploads; extends ADR-001 for non-PDF PO sources.
- **Reversibility**: Type 1 for inventory posting provenance and sheet PO snapshot lifecycle; Type 2 for sheet sync provider integrations.

## Context

In ADR-001, Purchase Orders were assumed to enter the system strictly as uploaded PDF documents processed through asynchronous AI extraction pipelines. In ADR-005, a two-step warehouse receiving model was established: linking an invoice or delivery receipt to a PO recorded supplier arrival facts, but creating dispatchable warehouse stock lots and initiating warehouse dwell attribution required a secondary manual confirmation step by a warehouse operator.

In actual operational practice:
1. Master Purchase Orders originate and are actively maintained in Google Sheets (`PO Tracking` and `PO Item Records` tabs) rather than uploaded PDF files.
2. Receiving uploads (packing lists, delivery receipts, supplier invoices) arrive via the live web application or are ingested from Google Sheet webhooks across four legacy sources (`a2z2go`, `bonita`, `keysys`, and `pingcon`).
3. Requiring a separate manual "Receive / Place" action in the warehouse workspace after an authorized receiving document upload creates operational friction, unnecessary lag in warehouse dwell tracking, and data entry backlog.
4. Relinking a receiving document to a PO previously deleted `PurchaseOrderItemArrival` records, severing stock lot linkages and risking data corruption.
5. Ingested sheet webhooks and background synchronization lacked source mode controls, risking overwrite of verified human corrections.

## Decision

1. **Durable Google Sheet Purchase Orders**:
   - Synchronize Purchase Orders from Google Sheets directly into durable `po_extractions` and `po_extraction_items` records with `source_type = 'google_sheet'` and a nullable `receiving_upload_id`.
   - Never generate synthetic `ReceivingUpload` records or mock AI extraction pipelines for sheet-sourced POs.
   - Track upstream order lifecycle (`DRAFT`, `ORDERED`, `RECEIVED`, `CANCELLED`) via `source_status`. Draft or cancelled orders are barred from automatic matching.

2. **Unified Purchase Order Resolution**:
   - Route all PO candidate matching across both PDF uploads and Google Sheet POs through a single, shared `PurchaseOrderResolver`.
   - The resolver evaluates vendor normalization, PO number exact/normalized matches, date proximity, and line item similarity. Ambiguous matches or cross-vendor conflicts require explicit operator resolution and are never auto-linked.

3. **Upload-Confirmed Automatic Stock Posting (Supersedes ADR-005 Manual Placement for Live Uploads)**:
   - When a receiving upload completes extraction and successfully links to a confirmed Purchase Order, `ReceiptPostingService` automatically posts dispatchable stock lots (`WarehouseStockLot`) atomically within a database transaction.
   - The placement timestamp (`received_at`) is derived from the verified upload timestamp (`upload_completed_at ?? created_at`), ensuring that warehouse dwell reflects the true physical arrival time rather than an administrative entry delay.
   - Each created stock lot records `posting_provenance = 'automatic_upload'`, `date_quality = 'confirmed'`, and a deterministic `source_key` tying it to the `PurchaseOrderItemArrival`.
   - Manual placement via `WarehouseArrivalsController` remains available for ad-hoc receipts, physical walk-ins, and legacy records without active receiving uploads.

4. **Idempotent Arrival Upsert and Deletion Guard**:
   - `PurchaseOrderLinker` upserts arrival rows by stable `source_key` (`arrival_{upload_id}_{po_item_id}`) instead of deleting existing rows. This preserves existing stock lot relationships and transaction history across relinking operations.
   - Unlinking or re-processing receiving uploads that have already posted stock or allocated units to customer deliveries is strictly blocked.

5. **Transition Modes and Human Review Protection**:
   - Google Sheet synchronization enforces transition modes (`parallel`, `reconciliation`, and `app_only`).
   - In `app_only` mode, incoming sheet receiving imports are rejected and staged as exceptions while continuing PO synchronization.
   - In `reconciliation` mode, sheet synchronization updates `raw_extracted_json` but preserves human-verified corrections (`review_status = 'verified'` and `corrected_json`).

## Consequences

- Warehouse operators no longer need to perform a redundant manual "Receive" click for standard verified receiving uploads.
- Stock becomes immediately dispatchable and available for FIFO customer delivery allocation upon document upload and linking.
- Warehouse dwell and holding time calculations in ADR-005 remain mathematically identical and preserve FIFO allocation integrity, but are anchored to verified document upload completion times.
- Both PDF-uploaded POs and Google Sheet POs share identical reporting, linking, line item tracking, and detail views (`admin.purchase-orders.show`).
- Re-processing or unlinking posted receipts requires formal administrative adjustment rather than casual deletion.

## Alternatives Considered

- **Retain mandatory secondary warehouse confirmation for all uploads**: Rejected because it caused multi-day backlogs where physically present inventory could not be dispatched, distorting dwell calculations.
- **Synthesize mock `ReceivingUpload` records for Sheet POs**: Rejected because it would conceal data provenance, pollute upload search logs, and risk accidental file deletion cascade.
- **Auto-post stock upon Sheet PO creation before delivery**: Rejected because a Purchase Order is a commitment to buy, not proof of physical arrival. Stock creation requires verified receipt evidence.
- **Delete and recreate arrival rows on relink**: Rejected because it broke foreign keys to `WarehouseStockLot` and destroyed lot ledger history.

## Reversal Plan

The automatic posting behavior can be disabled globally or per-environment via `config('receiving.auto_post_stock') => false`. When disabled, the system gracefully falls back to the manual placement workflow defined in ADR-005. All posted lots maintain explicit `posting_provenance` metadata, allowing complete separation between upload-posted and manually-placed inventory.

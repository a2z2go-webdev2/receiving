# Failure Modes

## Purchase-order item reporting

- Area: Purchase-order to invoice/receipt linking
- Failure: A second invoice or receipt for the same PO is rejected, so arrived quantities and waiting times are missing from item reports.
- Trigger: More than one delivery document references a single purchase order.
- Impact: Data accuracy and reporting.
- Detection: `PurchaseOrderLinkingTest` exercises multiple active document links and idempotent resync.
- Prevention: Keep the active-link uniqueness constraint on `ai_extraction_id`, not `po_extraction_id`, and aggregate arrival rows by canonical item record.
- Status: MITIGATED

- Area: Arrival item matching
- Failure: A valid invoice line remains unmatched when supplier wording differs from the PO wording.
- Trigger: Missing SKU plus materially different descriptions despite an exact PO-number link.
- Impact: Arrived quantity incorrectly reports as zero.
- Detection: `PurchaseOrderItemReportingTest` covers canonical item aggregation with supplier-specific descriptions.
- Prevention: Match by identifier and description first, then use quantity or single-line fallbacks only when the linked PO makes the match unambiguous.
- Status: MITIGATED

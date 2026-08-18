# ADR-005: Warehouse lot ledger and FIFO dwell attribution

- **Status**: Accepted
- **Requirements**: REQ-023
- **Reversibility**: Type 1 for historical allocation meaning; changing the allocation policy requires an explicit migration/version and must not rewrite completed reports.

## Context

Purchase Orders and linked receiving documents establish that quantities were ordered and arrived, but interchangeable goods from old and new POs can be physically mixed. Customer orders can also contain quantities drawn from several receipts. A report based only on item, PO, or current balance cannot know which arrival date contributed to a customer delivery.

The client defines "warehouse dwell" as time from placement in the warehouse through confirmed customer receipt. Time physically held before dispatch is a shorter interval and must remain separately visible as warehouse holding time.

## Decision

- Require a dedicated `warehouse_operator` permission group for all physical progress mutations. Administrators receive report-only access.
- Treat linked PO/invoice rows as supplier-delivery facts used for PO waiting time. Create dispatchable stock only when an operator confirms physical placement.
- For PO/invoice-linked placements, accept physical quantity plus optional lot/notes and derive the placement timestamp and confirmed date quality on the server. Do not accept a browser-selected placement date. Persist opening inventory with confirmed, estimated, or unknown historical dates because it predates the system workflow.
- Persist inventory as stock lots with positive quantities, warehouse placement or opening-stock dates, date-quality metadata, source provenance, and an idempotent source key.
- Represent pre-existing inventory as opening-balance lots. Preserve unknown dates as null instead of manufacturing dates.
- Create one customer delivery aggregate with one or more item lines and explicit draft, dispatched, and delivered transitions.
- Allocate all lines atomically at dispatch using FIFO. Consume unknown-date opening stock first, then known lots ordered by receipt date and identifier. Exclude known lots received after dispatch.
- Persist the exact lot allocations and never infer them later from current inventory.
- Report quantity-weighted `customer delivery - warehouse placement` as client-defined warehouse dwell and `dispatch - warehouse placement` as warehouse holding. Report dated-quantity coverage wherever opening-stock dates are missing.
- Record actor-bearing progress events and activity-log entries. Treat repeated transition requests as idempotent.

## Example: Mixed Stock Delivery

To illustrate the quantity-weighted calculation logic, consider a customer delivery of 100 units of "Item A" delivered on July 30th.

1. **Warehouse Receipts (FIFO Stock):**
   - **Lot 1:** 50 units received on May 1st (Sat for 90 days before delivery).
   - **Lot 2:** 50 units received on July 10th (Sat for 20 days before delivery).

2. **Delivery Allocation:**
   - When the 100 units are dispatched and delivered, the system allocates 50 units from Lot 1 and 50 units from Lot 2 based on FIFO.

3. **Metric Calculations:**
   - **Warehouse Placement Range:** May 1st - July 10th.
   - **Quantity-Weighted Dwell:**
     - 50 units * 90 days = 4,500
     - 50 units * 20 days = 1,000
     - Total Weighted = 5,500 / 100 total units = **55 Days Average Dwell**.
   - **Maximum Lot Dwell:** 90 Days (from Lot 1).
   - **Dated Quantity (Coverage %):** 100% (since all 100 units had known receiving dates).

This ensures the reported averages accurately reflect the true lifecycle of the inventory rather than simple unweighted date averages.

### Report Columns Defined

For absolute clarity, the report exposes the following 9 data points for every delivery line:

1. **CUSTOMER / REFERENCE:** The name of the customer receiving the delivery and the internal delivery reference number.
2. **ITEM:** The description and SKU of the physical item being delivered.
3. **QUANTITY:** The total number of units sent in this specific delivery line.
4. **WAREHOUSE PLACEMENT RANGE:** The date range between the oldest stock lot used and the newest stock lot used for this line, derived via FIFO allocation.
5. **DISPATCHED:** The date the delivery left the warehouse.
6. **CUSTOMER DELIVERED:** The date the delivery was confirmed as having arrived at the customer's location.
7. **WAREHOUSE HOLDING:** The quantity-weighted average number of days the items sat in the warehouse before being loaded onto the truck (Dispatched).
8. **WAREHOUSE DWELL:** The quantity-weighted average number of days between when the items arrived at the warehouse and when they were finally handed to the customer.
9. **DATED QUANTITY:** The percentage of the delivered items that were matched to stock with a known receiving date (e.g., 90% means 10% was legacy stock with an unknown arrival date).

## Alternatives considered

- **Use the latest or oldest PO date per item** was rejected because PO date is not physical warehouse arrival and cannot attribute mixed quantities.
- **Use average inventory age at report time** was rejected because it changes after the delivery and is not auditable.
- **Require scanning every individual piece** was rejected as disproportionate for interchangeable bulk goods. Lot-level quantity attribution is sufficient for the current process.
- **Let the operator select a lot manually** was rejected as the default because it is slow and inconsistent. Persisted FIFO gives a deterministic policy while retaining lot-level audit detail.
- **Assign the entire delivery to one receipt** was rejected because partial lot consumption is common and materially distorts dwell.
- **Use placement-to-dispatch as the only dwell metric** was rejected because the client explicitly defines dwell through customer delivery. It remains visible as warehouse holding time so physical storage performance is not lost.

## Consequences

- Dwell values remain reproducible after inventory changes because allocations are durable.
- Unknown opening dates reduce report coverage but do not corrupt averages with fabricated zero-day values.
- Dispatch locks delivery, line, and eligible lot rows in deterministic order. This serializes competing allocation attempts for the same stock.
- Corrections, returns, write-offs, transfers, and post-dispatch cancellation require future adjustment transaction types rather than destructive edits.
- Item identity and base-unit governance become operationally important; unit conversions are outside this decision.

## Reversal plan

A future FEFO, explicit-batch, or customer-reservation policy can be added as a versioned allocation method for new dispatches. Existing `warehouse_allocations` retain `fifo` and continue to drive historical reports. Do not recalculate completed allocations unless the business approves a separately audited correction migration.

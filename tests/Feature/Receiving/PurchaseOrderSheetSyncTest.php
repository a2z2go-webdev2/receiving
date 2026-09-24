<?php

namespace Tests\Feature\Receiving;

use App\Models\GoogleSheetConfig;
use App\Models\GoogleSheetSyncRecord;
use App\Models\PoExtraction;
use App\Models\PoExtractionItem;
use App\Services\GoogleSheets\PurchaseOrderSheetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PurchaseOrderSheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $sampleRows = [
        ['PO Number', 'Supplier', 'Type', 'Status', 'Date', 'Net Total', 'VAT Total', 'Total Amount', 'Item Code', 'Item Description', 'Qty', 'Unit', 'Unit Price', 'Line Total', 'Notes'],
        ['PO-2026-001', 'Acme Corp', 'Standard', 'Confirmed', '2026-03-15', '1000.00', '120.00', '1120.00', 'SKU-001', 'Widget A', '10', 'PCS', '50.00', '500.00', 'Urgent delivery'],
        ['PO-2026-001', 'Acme Corp', 'Standard', 'Confirmed', '2026-03-15', '1000.00', '120.00', '1120.00', 'SKU-002', 'Widget B', '5', 'BOX', '100.00', '500.00', 'Urgent delivery'],
        ['PO-2026-001', 'Acme Corp', 'Standard', 'Confirmed', '2026-03-15', '1000.00', '120.00', '1120.00', '', 'DISCOUNT 10%', '', '', '', '-100.00', 'Urgent delivery'],
        ['PO-2026-002', 'Beta Logistics', 'Standard', 'In preparation', '2026-03-16', '250.00', '30.00', '280.00', 'SKU-003', 'Cable 10m', '25', 'MTR', '10.00', '250.00', 'Draft PO'],
        ['', 'Unknown Supplier', 'Standard', 'Confirmed', '2026-03-17', '100.00', '0.00', '100.00', 'SKU-999', 'Ghost item', '1', 'PCS', '100.00', '100.00', 'Missing PO number'],
    ];

    public function test_preview_identifies_new_and_invalid_orders_without_persisting(): void
    {
        $config = GoogleSheetConfig::query()->create([
            'slug' => 'po_master',
            'name' => 'PO Master Sheet',
            'sheet_type' => 'purchase_order',
            'spreadsheet_id' => 'mock-spreadsheet-id',
        ]);

        /** @var PurchaseOrderSheetSyncService $syncService */
        $syncService = app(PurchaseOrderSheetSyncService::class);

        $preview = $syncService->preview($config, overrideRows: $this->sampleRows);

        $this->assertSame(2, $preview['total_orders']);
        $this->assertSame(2, $preview['new_count']);
        $this->assertSame(0, $preview['unchanged_count']);
        $this->assertSame(1, $preview['invalid_count']); // 1 row with missing PO number
        $this->assertNotEmpty($preview['snapshot_hash']);

        // Verify no DB records were created during preview
        $this->assertSame(0, PoExtraction::query()->count());
        $this->assertSame(0, PoExtractionItem::query()->count());
    }

    public function test_apply_snapshot_creates_po_extractions_and_items(): void
    {
        $config = GoogleSheetConfig::query()->create([
            'slug' => 'po_master',
            'name' => 'PO Master Sheet',
            'sheet_type' => 'purchase_order',
            'spreadsheet_id' => 'mock-spreadsheet-id',
        ]);

        /** @var PurchaseOrderSheetSyncService $syncService */
        $syncService = app(PurchaseOrderSheetSyncService::class);

        $result = $syncService->applySnapshot($config, overrideRows: $this->sampleRows);

        $this->assertSame(2, $result['applied_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertSame(0, $result['failed_count']);

        // Verify PoExtraction PO-2026-001
        $po1 = PoExtraction::query()
            ->where('po_number', 'PO-2026-001')
            ->where('source_type', 'google_sheet')
            ->first();

        $this->assertNotNull($po1);
        $this->assertSame('po2026001', $po1->po_number_normalized);
        $this->assertSame('Acme Corp', $po1->vendor_name);
        $this->assertSame('Confirmed', $po1->source_status);
        $this->assertSame('confirmed', $po1->status_normalized);
        $this->assertSame('1000.00', $po1->subtotal);
        $this->assertSame('1120.00', $po1->total_amount);
        $this->assertSame('2026-03-15', $po1->po_date_value?->toDateString());
        $this->assertNull($po1->receiving_upload_id);
        $this->assertNull($po1->ai_extraction_id);

        // Verify items for PO-2026-001: 3 items (2 products, 1 discount)
        $items = $po1->items;
        $this->assertCount(3, $items);

        $sku1 = $items->firstWhere('item_code', 'SKU-001');
        $this->assertNotNull($sku1);
        $this->assertSame('Widget A', $sku1->product_description);
        $this->assertSame('10', $sku1->quantity);
        $this->assertSame('PCS', $sku1->unit);
        $this->assertFalse($sku1->is_financial_adjustment);

        $discount = $items->firstWhere('is_financial_adjustment', true);
        $this->assertNotNull($discount);
        $this->assertSame('DISCOUNT 10%', $discount->product_description);
        $this->assertSame('-100.00', $discount->line_total);

        // Verify GoogleSheetSyncRecord
        $syncRecord = GoogleSheetSyncRecord::query()
            ->where('sheet_slug', 'po_master')
            ->where('source_key', 'po2026001')
            ->first();

        $this->assertNotNull($syncRecord);
        $this->assertSame('synced', $syncRecord->status);
        $this->assertSame(PoExtraction::class, $syncRecord->target_type);
        $this->assertSame($po1->id, $syncRecord->target_id);

        // Re-applying without changes skips
        $secondResult = $syncService->applySnapshot($config, overrideRows: $this->sampleRows);
        $this->assertSame(0, $secondResult['applied_count']);
        $this->assertSame(2, $secondResult['skipped_count']);
    }

    public function test_apply_snapshot_fails_on_hash_mismatch(): void
    {
        $config = GoogleSheetConfig::query()->create([
            'slug' => 'po_master',
            'name' => 'PO Master Sheet',
            'sheet_type' => 'purchase_order',
            'spreadsheet_id' => 'mock-spreadsheet-id',
        ]);

        /** @var PurchaseOrderSheetSyncService $syncService */
        $syncService = app(PurchaseOrderSheetSyncService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Snapshot hash mismatch');

        $syncService->applySnapshot(
            $config,
            expectedSnapshotHash: 'stale-or-wrong-hash',
            overrideRows: $this->sampleRows,
        );
    }

    public function test_changed_order_updates_existing_extraction_and_items(): void
    {
        $config = GoogleSheetConfig::query()->create([
            'slug' => 'po_master',
            'name' => 'PO Master Sheet',
            'sheet_type' => 'purchase_order',
            'spreadsheet_id' => 'mock-spreadsheet-id',
        ]);

        /** @var PurchaseOrderSheetSyncService $syncService */
        $syncService = app(PurchaseOrderSheetSyncService::class);

        // First apply
        $syncService->applySnapshot($config, overrideRows: $this->sampleRows);

        // Modified rows: change total and item description
        $modifiedRows = $this->sampleRows;
        $modifiedRows[1][5] = '1200.00'; // Net total
        $modifiedRows[1][7] = '1320.00'; // Total amount
        $modifiedRows[1][9] = 'Widget A Upgraded'; // Description

        $preview = $syncService->preview($config, overrideRows: $modifiedRows);
        $this->assertSame(1, $preview['changed_count']);
        $this->assertSame(1, $preview['unchanged_count']);

        $result = $syncService->applySnapshot($config, overrideRows: $modifiedRows);
        $this->assertSame(1, $result['applied_count']);
        $this->assertSame(1, $result['skipped_count']);

        $po1 = PoExtraction::query()
            ->where('po_number', 'PO-2026-001')
            ->first();

        $this->assertSame('1320.00', $po1->total_amount);
        $widgetItem = $po1->items()->firstWhere('item_code', 'SKU-001');
        $this->assertSame('Widget A Upgraded', $widgetItem->product_description);
    }
}

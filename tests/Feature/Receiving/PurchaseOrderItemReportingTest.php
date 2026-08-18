<?php

use App\Enums\AiStatus;
use App\Enums\ReviewStatus;
use App\Features\Receiving\Services\PoExtractionStore;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Features\Receiving\Services\PurchaseOrderItemScheduleImporter;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Models\AiExtraction;
use App\Models\PurchaseOrderItemFulfillment;
use App\Models\PurchaseOrderItemSchedule;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UploadTypeSeeder;

beforeEach(fn () => $this->seed([RolePermissionSeeder::class, UploadTypeSeeder::class]));

it('imports the item records csv into item schedule records', function (): void {
    $stats = app(PurchaseOrderItemScheduleImporter::class)->import(
        database_path('seeders/data/po_item_records.csv'),
    );

    expect($stats['rows'])->toBe(419)
        ->and($stats['records'])->toBe(419)
        ->and(PurchaseOrderItemSchedule::query()->where('source', PurchaseOrderItemScheduleImporter::SOURCE)->count())->toBe(419)
        ->and(PurchaseOrderItemSchedule::query()->whereNull('expected_week')->count())->toBe(419);

    $sample = PurchaseOrderItemSchedule::query()->where('serial_number', 4)->firstOrFail();
    expect($sample->sku_number)->toBe('2053P')
        ->and($sample->ean_barcode)->toBe('4809013751802')
        ->and((float) $sample->package_quantity)->toBe(4.0)
        ->and($sample->package_unit)->toBe('pc')
        ->and($sample->unit)->toBe('case');

    $rerun = app(PurchaseOrderItemScheduleImporter::class)->import(
        database_path('seeders/data/po_item_records.csv'),
    );

    expect($rerun['created'])->toBe(0)
        ->and($rerun['updated'])->toBe(419)
        ->and($rerun['deactivated'])->toBe(0);
});

it('matches purchase order items by EAN barcode', function (): void {
    $schedule = PurchaseOrderItemSchedule::query()->create([
        'sku_number' => '2053P',
        'sku_number_normalized' => '2053p',
        'ean_barcode' => '4809013751802',
        'ean_barcode_normalized' => '4809013751802',
        'description' => 'Pingcon Rubbing Alcohol Gallon',
        'description_normalized' => 'pingcon rubbing alcohol gallon',
        'target_quantity' => '10.000',
        'unit' => 'case',
        'is_special_order' => false,
        'is_active' => true,
    ]);

    [, $poAiExtraction] = poReportingExtraction('purchase-order', 'po-ean.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    app(PoExtractionStore::class)->store($poAiExtraction, poReportingPoData(
        'PO-EAN-1',
        '2026-07-03',
        [['itemCode' => '4809013751802', 'productDescription' => 'Rubbing Alcohol Gallon', 'quantity' => '10', 'unit' => 'case']],
    ));

    expect(PurchaseOrderItemFulfillment::query()->firstOrFail()->purchase_order_item_schedule_id)
        ->toBe($schedule->getKey());
});

it('does not turn conflicting dates into a negative waiting time', function (): void {
    $normalizer = app(PurchaseOrderDataNormalizer::class);

    expect($normalizer->waitingDays(
        CarbonImmutable::parse('2026-07-10'),
        CarbonImmutable::parse('2026-07-09'),
    ))->toBeNull();
});

it('backfills invoice po date when the matching purchase order is uploaded later', function (): void {
    [, $invoiceExtraction] = poReportingExtraction('a2z2go', 'invoice.pdf', [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Acme Supplier'],
            ['label' => 'PO Number', 'value' => 'PO-2026-0701'],
            ['label' => 'PO Date', 'value' => '[See image]'],
        ],
        'items' => [],
    ]);

    [, $poAiExtraction] = poReportingExtraction('purchase-order', 'po.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    $po = app(PoExtractionStore::class)->store($poAiExtraction, poReportingPoData(
        'PO 2026 0701',
        '2026-07-03',
        [['itemCode' => 'SKU-1', 'productDescription' => 'Paper cups', 'quantity' => '10', 'unit' => 'case']],
    ));

    $invoiceExtraction->refresh();
    expect(poReportingFieldValue($invoiceExtraction->raw_extracted_json, 'PO Date'))->toBe('2026-07-03')
        ->and($invoiceExtraction->po_date)->toBe('2026-07-03')
        ->and($invoiceExtraction->po_date_filled_from_po_extraction_id)->toBe($po->getKey());
});

it('matches uploaded po line items to weekly targets and reports ordered and missing items', function (): void {
    poReportingSchedule('SKU-1', 'Paper cups', 10, 'case', 1);
    $missing = poReportingSchedule('SKU-2', 'Napkins', 5, 'pack', 1);

    [, $poAiExtraction] = poReportingExtraction('purchase-order', 'po-week-one.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    app(PoExtractionStore::class)->store($poAiExtraction, poReportingPoData(
        'PO-JULY-1',
        '2026-07-03',
        [['itemCode' => 'SKU-1', 'productDescription' => 'Paper cups', 'quantity' => '12', 'unit' => 'case']],
    ));

    $admin = poReportingAdmin();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.ordered-items', ['month' => '2026-07', 'week' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/ordered-items')
            ->has('rows', 1)
            ->where('rows.0.sku_number', 'SKU-1')
            ->where('rows.0.ordered_quantity', 12)
            ->where('rows.0.status', 'over_target')
            ->where('rows.0.orders.0.po_week', 1));

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.missing-items', ['month' => '2026-07', 'week' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/missing-items')
            ->has('rows', 1)
            ->where('rows.0.schedule_id', $missing->getKey())
            ->where('rows.0.status', 'not_ordered')
            ->where('rows.0.missing_quantity', 5));
});

it('uses the canonical description to disambiguate duplicate item-record skus', function (): void {
    poReportingSchedule('SKU-DUP', 'Paper cups', 5, 'case', 1);
    $napkins = poReportingSchedule('SKU-DUP', 'Dinner napkins', 7, 'pack', 1);
    [, $poAiExtraction] = poReportingExtraction('purchase-order', 'duplicate-sku.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    app(PoExtractionStore::class)->store($poAiExtraction, poReportingPoData(
        'PO-DUPLICATE-SKU',
        '2026-07-03',
        [['itemCode' => 'SKU-DUP', 'productDescription' => 'Dinner napkins', 'quantity' => '7', 'unit' => 'pack']],
    ));

    expect(PurchaseOrderItemFulfillment::query()->firstOrFail()->purchase_order_item_schedule_id)
        ->toBe($napkins->getKey());
});

it('matches no-sku and extra-description po and arrival items and reports waiting days', function (): void {
    $schedule = poReportingSchedule('', 'Paper cups', 10, 'case', 1);

    [, $poAiExtraction] = poReportingExtraction('purchase-order', 'po-week-one-extra-text.pdf', [
        'document_type' => 'Purchase Order',
        'fields' => [],
        'items' => [],
    ]);

    app(PoExtractionStore::class)->store($poAiExtraction, poReportingPoData(
        'PO-JULY-FUZZY',
        '2026-07-03',
        [['productDescription' => 'Paper cups 12oz clear pack of 50', 'quantity' => '10', 'unit' => 'case']],
    ));

    [, $receiptExtraction] = poReportingExtraction('bonita', 'receipt-extra-text.pdf', [
        'document_type' => 'Delivery Receipt',
        'fields' => [
            ['label' => 'PO Number', 'value' => 'PO JULY FUZZY'],
            ['label' => 'PO Date', 'value' => '[See image]'],
        ],
        'items' => [
            ['description' => 'Paper cups clear delivered - 12 oz', 'quantity' => '6', 'unit' => 'case'],
        ],
    ], '2026-07-08');
    app(PurchaseOrderLinker::class)->syncExtraction($receiptExtraction);

    $admin = poReportingAdmin();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.ordered-items', ['month' => '2026-07', 'week' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/ordered-items')
            ->has('rows', 1)
            ->where('rows.0.schedule_id', $schedule->getKey())
            ->where('rows.0.ordered_quantity', 10)
            ->where('rows.0.arrived_quantity', 6)
            ->where('rows.0.average_waiting_days', 5)
            ->where('rows.0.orders.0.matched_by', 'description_partial')
            ->where('rows.0.arrivals.0.waiting_days', 5)
            ->where('rows.0.arrivals.0.arrival_date', '2026-07-08')
            ->where('rows.0.arrivals.0.matched_by', 'description_partial'));
});

it('aggregates split purchase orders and arrivals under the canonical item with mixed verification provenance', function (): void {
    $schedule = poReportingSchedule('SKU-SPLIT', 'Canonical cleaning sponge', 30, 'pc', 4);
    $documents = [
        ['PO-SPLIT-1', '2026-07-02', '2026-07-06', true],
        ['PO-SPLIT-2', '2026-07-09', '2026-07-14', true],
        ['PO-SPLIT-3', '2026-07-16', '2026-07-22', false],
    ];

    foreach ($documents as $index => [$poNumber, $poDate, $arrivalDate, $verified]) {
        [, $poAiExtraction] = poReportingExtraction('purchase-order', "split-po-{$index}.pdf", [
            'document_type' => 'Purchase Order',
            'fields' => [],
            'items' => [],
        ]);
        app(PoExtractionStore::class)->store($poAiExtraction, poReportingPoData(
            $poNumber,
            $poDate,
            [['itemCode' => 'SKU-SPLIT', 'productDescription' => 'Supplier sponge description', 'quantity' => '10', 'unit' => 'pc']],
        ));

        $invoiceData = [
            'document_type' => 'Delivery Receipt',
            'fields' => [
                ['label' => 'PO Number', 'value' => $poNumber],
                ['label' => 'PO Date', 'value' => '[See image]'],
            ],
            'items' => [
                ['description' => "Delivery line {$index} with unrelated wording", 'quantity' => '10'],
            ],
        ];
        [, $invoice] = poReportingExtraction(
            'a2z2go',
            "split-invoice-{$index}.pdf",
            $invoiceData,
            $arrivalDate,
        );
        if ($verified) {
            $invoice->forceFill([
                'corrected_json' => $invoiceData,
                'review_status' => ReviewStatus::Verified,
            ])->save();
        }
        app(PurchaseOrderLinker::class)->syncExtraction($invoice);
    }

    $admin = poReportingAdmin();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.ordered-items', ['month' => '2026-07', 'week' => 4]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/ordered-items')
            ->has('rows', 1)
            ->where('rows.0.schedule_id', $schedule->getKey())
            ->where('rows.0.description', 'Canonical cleaning sponge')
            ->where('rows.0.target_quantity', 30)
            ->where('rows.0.ordered_quantity', 30)
            ->where('rows.0.arrived_quantity', 30)
            ->where('rows.0.arrival_remaining_quantity', 0)
            ->has('rows.0.orders', 3)
            ->has('rows.0.arrivals', 3)
            ->where('rows.0.arrivals.0.waiting_days', 4)
            ->where('rows.0.arrivals.1.waiting_days', 5)
            ->where('rows.0.arrivals.2.waiting_days', 6)
            ->where('rows.0.arrivals.0.data_source', 'verified')
            ->where('rows.0.arrivals.1.data_source', 'verified')
            ->where('rows.0.arrivals.2.data_source', 'unverified')
            ->where('rows.0.has_unverified_data', true));

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.missing-items', ['month' => '2026-07', 'week' => 4]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/missing-items')
            ->has('rows', 0));
});

it('reports active monthly item records as the monthly recurring po requirements', function (): void {
    poReportingSchedule('SKU-W1', 'Week one cleaner', 2, 'gallon', 1);
    poReportingSchedule('SKU-W2', 'Week two towels', 4, 'case', 2);
    poReportingSchedule('SKU-W3', 'Week three soap', 6, 'box', 3);
    poReportingSchedule('SKU-W4', 'Week four cups', 8, 'pack', 4);
    $inactive = poReportingSchedule('SKU-OFF', 'Inactive item', 1, 'pc', 5);
    $inactive->forceFill(['is_active' => false])->save();

    $admin = poReportingAdmin();

    $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->get(route('admin.purchase-orders.reports.recurring-items'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/purchase-orders/reports/recurring-items')
            ->has('rows', 4)
            ->where('rows.0.sku_number', 'SKU-W1')
            ->where('rows.0.description', 'Week one cleaner')
            ->where('rows.0.schedule_label', 'Monthly target')
            ->where('rows.0.target_quantity', 2)
            ->where('rows.3.sku_number', 'SKU-W4')
            ->where('summary.item_count', 4)
            ->where('summary.monthly_count', 4));
});

/** @return array{ReceivingUpload, AiExtraction} */
function poReportingExtraction(string $uploadTypeSlug, string $fileName, array $data, ?string $completedAt = null): array
{
    $user = User::factory()->create();
    $type = UploadType::query()->where('slug', $uploadTypeSlug)->firstOrFail();
    $upload = ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(),
        'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $user->getKey(),
        'uploader_email' => $user->email,
        'r2_bucket' => 'test',
        'r2_prefix' => 'receiving/test',
        'file_count' => 1,
        'ai_status' => AiStatus::Extracted,
        'upload_completed_at' => $completedAt === null
            ? null
            : CarbonImmutable::parse($completedAt),
    ]);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'original_file_name' => $fileName,
        'sanitized_file_name' => $fileName,
        'stored_file_name' => $fileName,
        'file_extension' => 'pdf',
        'r2_bucket' => 'test',
        'r2_object_key' => "receiving/{$fileName}",
        'r2_staging_object_key' => "staging/{$upload->getKey()}/{$fileName}",
        'original_file_size' => 100,
        'final_file_size' => 100,
        'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf',
        'ai_status' => AiStatus::Extracted,
    ]);
    $extraction = AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(),
        'uploaded_file_id' => $file->getKey(),
        'document_type' => $data['document_type'],
        'raw_extracted_json' => $data,
        'corrected_json' => null,
        'ai_status' => AiStatus::Extracted,
    ]);

    return [$upload, $extraction];
}

function poReportingSchedule(
    string $sku,
    string $description,
    float $targetQuantity,
    string $unit,
    ?int $serialNumber = null,
): PurchaseOrderItemSchedule {
    $normalizer = app(PurchaseOrderDataNormalizer::class);

    return PurchaseOrderItemSchedule::query()->create([
        'serial_number' => $serialNumber,
        'sku_number' => $sku,
        'sku_number_normalized' => $normalizer->normalizeIdentifier($sku),
        'description' => $description,
        'description_normalized' => $normalizer->normalizeDescription($description),
        'target_quantity' => $normalizer->decimalString($targetQuantity),
        'unit' => $unit,
        'expected_week' => null,
        'is_special_order' => false,
        'is_active' => true,
    ]);
}

/** @param array<int, array<string, string>> $items @return array<string, mixed> */
function poReportingPoData(string $poNumber, string $poDate, array $items): array
{
    return [
        'document_type' => 'Purchase Order',
        'fields' => [
            ['label' => 'PO Number', 'value' => $poNumber],
            ['label' => 'PO Reference', 'value' => '[See image]'],
            ['label' => 'PO Date', 'value' => $poDate],
            ['label' => 'Buyer Company', 'value' => 'Bonita'],
            ['label' => 'Vendor Name', 'value' => 'Acme Supplier'],
            ['label' => 'Contact Person', 'value' => 'Jane'],
            ['label' => 'Payment Terms', 'value' => 'COD'],
        ],
        'items' => $items,
    ];
}

function poReportingFieldValue(?array $data, string $label): ?string
{
    $fields = $data['fields'] ?? null;
    if (! is_array($fields)) {
        return null;
    }

    foreach ($fields as $field) {
        if (is_array($field) && ($field['label'] ?? null) === $label) {
            return (string) ($field['value'] ?? '');
        }
    }

    return null;
}

function poReportingAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

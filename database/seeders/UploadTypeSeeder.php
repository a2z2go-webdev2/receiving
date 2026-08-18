<?php

namespace Database\Seeders;

use App\Enums\UploadWorkflow;
use App\Models\UploadType;
use Illuminate\Database\Seeder;

class UploadTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'A2Z2GO', 'slug' => 'a2z2go', 'workflow' => UploadWorkflow::Standard],
            ['name' => 'PINGCON', 'slug' => 'pingcon', 'workflow' => UploadWorkflow::Standard],
            ['name' => 'BONITA', 'slug' => 'bonita', 'workflow' => UploadWorkflow::Standard],
            ['name' => 'KEYSYS INC.', 'slug' => 'keysys', 'workflow' => UploadWorkflow::Standard],
            ['name' => 'Purchase Order', 'slug' => 'purchase-order', 'workflow' => UploadWorkflow::PurchaseOrder],
        ] as $type) {
            UploadType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'r2_prefix' => $type['slug'],
                    'workflow' => $type['workflow'],
                    'is_active' => true,
                ],
            );
        }
    }
}

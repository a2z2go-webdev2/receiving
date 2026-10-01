<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $poSheetId = config('services.google.purchase_orders_sheet_id') ?: env('SHEET_ID_PURCHASE_ORDERS');

        // Repair pingcon receiving sheet config
        DB::table('google_sheet_configs')
            ->where('slug', 'pingcon')
            ->update([
                'sheet_type' => 'receiving',
                'tab_name' => 'Receiving_Log',
                'spreadsheet_id' => '1rmFfuhA9mnRefNSt5_6O5yjJqxLUh-YFqQ-03yJmvp-M',
            ]);

        // Repair other receiving sheet configs
        foreach (['a2z2go', 'bonita', 'keysys'] as $slug) {
            $envVal = env('SHEET_ID_'.strtoupper($slug));
            $config = DB::table('google_sheet_configs')->where('slug', $slug)->first();
            if ($config) {
                $spreadsheetId = $config->spreadsheet_id;
                if ($spreadsheetId === '1tJ_7TZpJDv4hb-BVAvPHswYfxevMHJnQY61zeo9bbys' || ($poSheetId && $spreadsheetId === $poSheetId)) {
                    $spreadsheetId = $envVal ?: null;
                }
                DB::table('google_sheet_configs')
                    ->where('slug', $slug)
                    ->update([
                        'sheet_type' => 'receiving',
                        'tab_name' => 'Receiving_Log',
                        'spreadsheet_id' => $spreadsheetId,
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Irreversible data repair
    }
};

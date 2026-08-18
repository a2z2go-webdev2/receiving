<?php

/**
 * Clear all upload transactional data from the database.
 * Keeps configuration: upload_types, authorized_upload_accesses,
 * email_recipients, system_settings, and users.
 *
 * Usage:
 *   php scripts/clear-uploads.php          # Live run (deletes data)
 *   php scripts/clear-uploads.php --dry-run  # Preview only
 */
$isDryRun = in_array('--dry-run', $argv ?? [], true);

// Bootstrap Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "=== UPLOAD DATA CLEARER ===\n";
echo 'Mode: '.($isDryRun ? 'DRY RUN (no deletions)' : 'LIVE')."\n\n";

// Collect counts
$uploadCount = DB::table('receiving_uploads')->count();
$fileCount = DB::table('uploaded_files')->count();
$extractionCount = DB::table('ai_extractions')->count();
$reviewLinkCount = DB::table('review_links')->count();
$otpCount = DB::table('upload_otps')->count();
$activityLogCount = DB::table('activity_logs')->whereNotNull('receiving_upload_id')->count();

echo "Receiving uploads .......... $uploadCount\n";
echo "Uploaded files ............. $fileCount\n";
echo "AI extractions ............. $extractionCount\n";
echo "Review links ............... $reviewLinkCount\n";
echo "Upload OTPs ................ $otpCount\n";
echo "Upload activity logs ....... $activityLogCount\n";
echo "\n";

$total = $uploadCount + $otpCount + $activityLogCount;
if ($total === 0) {
    echo "No upload data to clear.\n";
    exit(0);
}

if ($isDryRun) {
    echo "DRY RUN complete. Run without --dry-run to actually delete.\n";
    exit(0);
}

// Ask for confirmation
echo "Delete $total records? Type 'yes' to confirm: ";
$handle = fopen('php://stdin', 'r');
$input = trim(fgets($handle));
fclose($handle);

if ($input !== 'yes') {
    echo "Cancelled.\n";
    exit(0);
}

// Perform deletion in correct order (respecting FK constraints)

echo "\nDeleting upload activity logs... ";
DB::table('activity_logs')->whereNotNull('receiving_upload_id')->delete();
echo "OK\n";

echo 'Deleting upload OTPs... ';
DB::table('upload_otps')->delete();
echo "OK\n";

echo 'Deleting receiving uploads (cascades to files, extractions, review links)... ';
DB::table('receiving_uploads')->delete();
echo "OK\n";

echo "\nDone! Upload data cleared. Configuration is untouched.\n";

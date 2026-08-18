<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->string('review_email_status')->default('pending')->index()->after('email_status');
            $table->text('review_email_failure_reason')->nullable()->after('failure_reason');
            $table->timestamp('review_notification_sent_at')->nullable()->after('notification_sent_at');
        });

        DB::table('receiving_uploads')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('activity_logs')
                ->whereColumn('activity_logs.receiving_upload_id', 'receiving_uploads.id')
                ->where('activity_logs.action', 'review_notification_sent')
                ->where('activity_logs.status', 'success'))
            ->update(['review_email_status' => 'sent']);

        DB::table('receiving_uploads')
            ->where('review_email_status', 'pending')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('activity_logs')
                ->whereColumn('activity_logs.receiving_upload_id', 'receiving_uploads.id')
                ->where('activity_logs.action', 'review_notification_failed'))
            ->update(['review_email_status' => 'failed']);
    }

    public function down(): void
    {
        Schema::table('receiving_uploads', function (Blueprint $table): void {
            $table->dropIndex(['review_email_status']);
            $table->dropColumn([
                'review_email_status',
                'review_email_failure_reason',
                'review_notification_sent_at',
            ]);
        });
    }
};

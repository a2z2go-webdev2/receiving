<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('r2_prefix');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('authorized_upload_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_type_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'upload_type_id']);
        });

        Schema::create('email_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('upload_type_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('type', 3);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['upload_type_id', 'email', 'type']);
        });

        Schema::create('upload_otps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_type_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('otp_hash');
            $table->timestamp('expires_at')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'upload_type_id', 'used_at']);
        });

        Schema::create('receiving_uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('submission_id')->unique();
            $table->foreignId('upload_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploader_user_id')->constrained('users')->restrictOnDelete();
            $table->string('uploader_email');
            $table->string('r2_bucket');
            $table->string('r2_prefix')->nullable();
            $table->unsignedSmallInteger('file_count');
            $table->string('processing_status')->default('staging')->index();
            $table->string('email_status')->default('pending')->index();
            $table->string('ai_status')->default('pending')->index();
            $table->string('review_status')->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('upload_completed_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();
            $table->index(['upload_type_id', 'created_at']);
            $table->index(['uploader_user_id', 'created_at']);
        });

        Schema::create('uploaded_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receiving_upload_id')->constrained()->cascadeOnDelete();
            $table->string('original_file_name');
            $table->string('sanitized_file_name');
            $table->string('stored_file_name');
            $table->string('file_extension', 8);
            $table->string('r2_bucket');
            $table->string('r2_object_key')->nullable();
            $table->string('r2_staging_object_key')->unique();
            $table->unsignedBigInteger('original_file_size');
            $table->unsignedBigInteger('compressed_file_size')->nullable();
            $table->unsignedBigInteger('final_file_size')->nullable();
            $table->string('declared_content_type');
            $table->string('content_type')->nullable();
            $table->string('file_hash', 64)->nullable()->index();
            $table->string('validation_status')->default('pending')->index();
            $table->string('virus_scan_status')->default('pending')->index();
            $table->string('compression_status')->default('pending')->index();
            $table->string('ai_status')->default('pending')->index();
            $table->string('review_status')->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_extractions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receiving_upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('document_type')->nullable()->index();
            $table->json('raw_extracted_json')->nullable();
            $table->json('corrected_json')->nullable();
            $table->string('ai_status')->default('pending')->index();
            $table->string('review_status')->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by_email')->nullable();
            $table->timestamps();
        });

        Schema::create('review_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receiving_upload_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('upload_type_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receiving_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_email')->nullable();
            $table->string('role')->default('system');
            $table->string('module')->index();
            $table->string('action')->index();
            $table->string('status')->index();
            $table->string('message');
            $table->text('error_details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('review_links');
        Schema::dropIfExists('ai_extractions');
        Schema::dropIfExists('uploaded_files');
        Schema::dropIfExists('receiving_uploads');
        Schema::dropIfExists('upload_otps');
        Schema::dropIfExists('email_recipients');
        Schema::dropIfExists('authorized_upload_accesses');
        Schema::dropIfExists('upload_types');
    }
};

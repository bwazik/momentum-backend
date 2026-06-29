<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('uploader_user_id')->constrained('users');
            $table->string('original_filename');
            $table->string('storage_path')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedTinyInteger('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedSmallInteger('version_number')->default(1);
            $table->foreignId('root_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('parent_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['root_document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

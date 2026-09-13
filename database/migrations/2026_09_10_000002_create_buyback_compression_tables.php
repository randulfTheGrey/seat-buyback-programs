<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_compression_mappings', function (Blueprint $table): void {
            $table->unsignedBigInteger('uncompressed_type_id');
            $table->unsignedBigInteger('compressed_type_id');

            $table->primary('uncompressed_type_id', 'bb_compression_mapping_pk');
            $table->unique('compressed_type_id', 'bb_compression_compressed_uq');
        });

        Schema::create('buyback_compression_metadata', function (Blueprint $table): void {
            $table->id();
            $table->string('active_sde_build')->nullable();
            $table->string('source')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_compression_metadata');
        Schema::dropIfExists('buyback_compression_mappings');
    }
};

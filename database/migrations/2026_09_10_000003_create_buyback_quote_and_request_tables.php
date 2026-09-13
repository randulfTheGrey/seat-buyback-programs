<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_quotes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('bb_quote_public_id_uq');
            $table->char('appraisal_token_hash', 64)->unique('bb_quote_appraisal_token_uq');
            $table->foreignId('program_id')
                ->constrained('buyback_programs', indexName: 'bb_quote_program_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('requester_user_id')->index('bb_quote_requester_idx');
            $table->string('requester_name_snapshot');
            $table->string('program_name_snapshot');
            $table->timestamp('pricing_completed_at');
            $table->timestamp('quoted_at');
            $table->timestamp('expires_at')->index('bb_quote_expires_idx');
            $table->decimal('payable_total', 30, 2);
        });

        Schema::create('buyback_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')
                ->constrained('buyback_quotes', indexName: 'bb_quote_item_quote_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('type_id');
            $table->string('type_name');
            $table->unsignedBigInteger('quantity');
            $table->string('compression_state', 24);
            $table->string('reference_mode', 16);
            $table->string('reference_resolution', 32);
            $table->decimal('reference_unit_price', 38, 18);
            $table->smallInteger('effective_modifier_bps');
            $table->decimal('final_unit_price', 30, 2);
            $table->decimal('line_total', 30, 2);
            $table->json('policy_snapshot');
            $table->json('pricing_snapshot');

            $table->unique(['quote_id', 'type_id'], 'bb_quote_item_type_uq');
        });

        Schema::create('buyback_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('bb_request_public_id_uq');
            $table->foreignId('quote_id')
                ->unique('bb_request_quote_uq')
                ->constrained('buyback_quotes', indexName: 'bb_request_quote_fk')
                ->restrictOnDelete();
            $table->string('status', 16)->default('PENDING');
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('eve_contract_id')->nullable();
            $table->text('requester_note')->nullable();
            $table->text('manager_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by_user_id')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->unsignedBigInteger('canceled_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at'], 'bb_request_status_submitted_idx');
            $table->index('eve_contract_id', 'bb_request_contract_idx');
            $table->index('completed_by_user_id', 'bb_request_completed_by_idx');
            $table->index('rejected_by_user_id', 'bb_request_rejected_by_idx');
            $table->index('canceled_by_user_id', 'bb_request_canceled_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_requests');
        Schema::dropIfExists('buyback_quote_items');
        Schema::dropIfExists('buyback_quotes');
    }
};

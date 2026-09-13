<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyback_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('DISABLED')->index('bb_program_status_idx');
            $table->string('default_acceptance', 16)->default('ACCEPT');
            $table->string('default_reference_mode', 16);
            $table->smallInteger('default_modifier_bps');
            $table->unsignedInteger('quote_validity_minutes');
            $table->text('contract_instructions')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index('bb_program_created_by_idx');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->index('bb_program_updated_by_idx');
            $table->timestamps();
        });

        Schema::create('buyback_program_price_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')
                ->constrained('buyback_programs', indexName: 'bb_price_ref_program_fk')
                ->cascadeOnDelete();
            $table->string('reference_mode', 16);
            $table->string('resolution', 32);
            $table->unsignedBigInteger('provider_instance_id')->nullable()->index('bb_price_ref_provider_idx');
            $table->timestamps();

            $table->unique(['program_id', 'reference_mode'], 'bb_program_ref_mode_uq');
        });

        Schema::create('buyback_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')
                ->constrained('buyback_programs', indexName: 'bb_rule_program_fk')
                ->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('compression_qualifier', 16)->default('ANY');
            $table->string('acceptance', 16)->default('INHERIT');
            $table->string('reference_mode_override', 16)->nullable();
            $table->string('modifier_operation', 16)->default('INHERIT');
            $table->smallInteger('modifier_bps')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index('bb_rule_created_by_idx');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->index('bb_rule_updated_by_idx');
            $table->timestamps();

            $table->unique(
                ['program_id', 'target_type', 'target_id', 'compression_qualifier'],
                'bb_rule_identity_uq',
            );
            $table->index(['target_type', 'target_id'], 'bb_rule_target_idx');
            $table->index(['program_id', 'enabled', 'archived_at'], 'bb_rule_active_idx');
        });

        $this->addRuleConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_rules');
        Schema::dropIfExists('buyback_program_price_references');
        Schema::dropIfExists('buyback_programs');
    }

    private function addRuleConstraints(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER bb_rule_target_insert_chk
                BEFORE INSERT ON buyback_rules
                WHEN NEW.target_type NOT IN ('GROUP', 'TYPE')
                    OR NEW.target_id <= 0
                    OR (NEW.target_type = 'TYPE' AND NEW.compression_qualifier != 'ANY')
                BEGIN
                    SELECT RAISE(ABORT, 'buyback rule target or qualifier is invalid');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER bb_rule_target_update_chk
                BEFORE UPDATE OF target_type, target_id, compression_qualifier ON buyback_rules
                WHEN NEW.target_type NOT IN ('GROUP', 'TYPE')
                    OR NEW.target_id <= 0
                    OR (NEW.target_type = 'TYPE' AND NEW.compression_qualifier != 'ANY')
                BEGIN
                    SELECT RAISE(ABORT, 'buyback rule target or qualifier is invalid');
                END
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE buyback_rules
            ADD CONSTRAINT bb_rule_target_chk
            CHECK (
                target_type IN ('GROUP', 'TYPE')
                AND target_id > 0
                AND (target_type != 'TYPE' OR compression_qualifier = 'ANY')
            )
            SQL);
    }
};

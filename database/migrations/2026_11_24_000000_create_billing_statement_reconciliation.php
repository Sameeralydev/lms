<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('account_code', 32);
            $table->string('display_name', 120);
            $table->char('currency', 3);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_bank_account_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'account_code'], 'bill_bank_account_code_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status'], 'bill_bank_account_scope_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_bank_account_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_bank_account_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('billing_reconciliation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('batch_number', 40);
            $table->char('statement_sha256', 64);
            $table->string('original_filename', 180);
            $table->char('currency', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('review');
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_recon_batch_tenant_id_uq');
            $table->unique(['tenant_id', 'batch_number'], 'bill_recon_batch_number_uq');
            $table->unique(['tenant_id', 'bank_account_id', 'statement_sha256'], 'bill_recon_statement_hash_uq');
            $table->index(['tenant_id', 'institution_id', 'campus_id', 'status', 'period_start'], 'bill_recon_batch_scope_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'bill_recon_batch_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'bill_recon_batch_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'bank_account_id'], 'bill_recon_batch_account_fk')->references(['tenant_id', 'id'])->on('billing_bank_accounts')->restrictOnDelete();
        });

        Schema::create('billing_reconciliation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('row_number');
            $table->char('row_fingerprint', 64);
            $table->date('transaction_date');
            $table->string('reference', 120);
            $table->string('description', 240)->nullable();
            $table->string('direction', 8);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('match_status', 16)->default('unmatched');
            $table->unsignedBigInteger('suggested_payment_id')->nullable();
            $table->unsignedBigInteger('suggested_refund_id')->nullable();
            $table->unsignedBigInteger('matched_payment_id')->nullable();
            $table->unsignedBigInteger('matched_refund_id')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'bill_recon_line_tenant_id_uq');
            $table->unique(['tenant_id', 'batch_id', 'row_fingerprint'], 'bill_recon_line_fingerprint_uq');
            $table->unique(['tenant_id', 'batch_id', 'row_number'], 'bill_recon_line_row_uq');
            $table->unique(['tenant_id', 'matched_payment_id'], 'bill_recon_matched_payment_uq');
            $table->unique(['tenant_id', 'matched_refund_id'], 'bill_recon_matched_refund_uq');
            $table->index(['tenant_id', 'batch_id', 'match_status'], 'bill_recon_line_status_idx');
            $table->foreign(['tenant_id', 'batch_id'], 'bill_recon_line_batch_fk')->references(['tenant_id', 'id'])->on('billing_reconciliation_batches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'suggested_payment_id'], 'bill_recon_line_sug_payment_fk')->references(['tenant_id', 'id'])->on('billing_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'suggested_refund_id'], 'bill_recon_line_sug_refund_fk')->references(['tenant_id', 'id'])->on('billing_payment_refunds')->restrictOnDelete();
            $table->foreign(['tenant_id', 'matched_payment_id'], 'bill_recon_line_payment_fk')->references(['tenant_id', 'id'])->on('billing_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'matched_refund_id'], 'bill_recon_line_refund_fk')->references(['tenant_id', 'id'])->on('billing_payment_refunds')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reconciliation_lines');
        Schema::dropIfExists('billing_reconciliation_batches');
        Schema::dropIfExists('billing_bank_accounts');
    }
};

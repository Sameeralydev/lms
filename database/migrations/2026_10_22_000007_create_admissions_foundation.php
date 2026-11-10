<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add tenant identity and a composite key used by tenant-safe references. */
    private function tenantRecord(Blueprint $table, string $prefix): void
    {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
        $table->unique(['tenant_id', 'id'], $prefix.'_tenant_id_uq');
        $table->timestamps();
    }

    public function up(): void
    {
        Schema::create('admissions_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->boolean('enabled')->default(false);
            $table->boolean('inquiries_enabled')->default(false);
            $table->boolean('public_applications_enabled')->default(false);
            $table->string('registration_mode', 24)->default('existing_account');
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 16)->default('en');
            $table->json('policy')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id'], 'adm_settings_inst_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'adm_settings_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('admission_intakes', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_intake');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->foreignId('coordinator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 48);
            $table->string('name', 160);
            $table->string('cycle', 48)->nullable();
            $table->string('delivery_mode', 24)->default('on_campus');
            $table->string('locale', 16)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 24)->default('draft')->index();
            $table->boolean('is_public')->default(false);
            $table->dateTime('applications_open_at')->nullable();
            $table->dateTime('application_deadline')->nullable();
            $table->dateTime('documents_deadline')->nullable();
            $table->dateTime('evaluation_starts_at')->nullable();
            $table->dateTime('evaluation_ends_at')->nullable();
            $table->dateTime('offer_deadline')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('reserved_capacity')->default(0);
            $table->decimal('application_fee_amount', 12, 2)->nullable();
            $table->char('application_fee_currency', 3)->nullable();
            $table->json('eligibility_rules')->nullable();
            $table->text('public_description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'institution_id', 'code'], 'adm_intake_code_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'application_deadline'], 'adm_intake_status_deadline_idx');
            $table->index(['tenant_id', 'campus_id', 'status'], 'adm_intake_campus_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'adm_intake_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'adm_intake_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'adm_intake_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'adm_intake_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
        });

        Schema::create('admission_form_templates', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_form_tpl');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('intake_id');
            $table->string('code', 48);
            $table->string('name', 160);
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'intake_id', 'code'], 'adm_form_tpl_code_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'adm_form_tpl_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'intake_id'], 'adm_form_tpl_intake_fk')->references(['tenant_id', 'id'])->on('admission_intakes')->restrictOnDelete();
        });

        Schema::create('admission_form_versions', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_form_ver');
            $table->unsignedBigInteger('form_template_id');
            $table->unsignedSmallInteger('version_number');
            $table->json('definition');
            $table->string('status', 24)->default('draft')->index();
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->unique(['tenant_id', 'form_template_id', 'version_number'], 'adm_form_ver_num_uq');
            $table->foreign(['tenant_id', 'form_template_id'], 'adm_form_ver_tpl_fk')->references(['tenant_id', 'id'])->on('admission_form_templates')->restrictOnDelete();
        });

        Schema::create('admission_inquiries', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_inquiry');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('intake_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('name', 180);
            $table->string('email')->index();
            $table->string('phone', 40)->nullable();
            $table->string('status', 24)->default('new')->index();
            $table->string('source', 32)->default('public');
            $table->text('message')->nullable();
            $table->foreignId('qualified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('qualified_at')->nullable();
            $table->foreign(['tenant_id', 'institution_id'], 'adm_inquiry_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'intake_id'], 'adm_inquiry_intake_fk')->references(['tenant_id', 'id'])->on('admission_intakes')->restrictOnDelete();
        });

        Schema::create('admission_applicant_profiles', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_app_profile');
            $table->unsignedBigInteger('institution_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('given_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('family_name', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('guardian_name', 180)->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('guardian_relationship', 48)->nullable();
            $table->dateTime('guardian_consent_at')->nullable();
            $table->unique(['tenant_id', 'user_id'], 'adm_app_profile_user_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'adm_app_profile_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('admission_applications', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_application');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedBigInteger('applicant_profile_id');
            $table->unsignedBigInteger('form_version_id');
            $table->foreignId('applicant_user_id')->constrained('users')->restrictOnDelete();
            $table->string('application_number', 40);
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->json('answers')->nullable();
            $table->string('eligibility_status', 24)->default('pending')->index();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->unsignedSmallInteger('submission_count')->default(0);
            $table->string('idempotency_key', 64)->nullable();
            $table->unique(['tenant_id', 'application_number'], 'adm_application_number_uq');
            $table->unique(['tenant_id', 'intake_id', 'applicant_user_id'], 'adm_application_applicant_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'adm_application_idem_uq');
            $table->index(['tenant_id', 'intake_id', 'status', 'created_at'], 'adm_application_queue_idx');
            $table->index(['tenant_id', 'applicant_user_id', 'status'], 'adm_application_user_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'adm_application_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'intake_id'], 'adm_application_intake_fk')->references(['tenant_id', 'id'])->on('admission_intakes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'applicant_profile_id'], 'adm_application_profile_fk')->references(['tenant_id', 'id'])->on('admission_applicant_profiles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'form_version_id'], 'adm_application_form_fk')->references(['tenant_id', 'id'])->on('admission_form_versions')->restrictOnDelete();
        });

        Schema::create('admission_application_revisions', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_app_revision');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('form_version_id');
            $table->unsignedInteger('revision_number');
            $table->json('answers');
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('submitted_snapshot')->default(false);
            $table->unique(['tenant_id', 'application_id', 'revision_number'], 'adm_app_revision_num_uq');
            $table->foreign(['tenant_id', 'application_id'], 'adm_app_revision_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'form_version_id'], 'adm_app_revision_form_fk')->references(['tenant_id', 'id'])->on('admission_form_versions')->restrictOnDelete();
        });

        Schema::create('admission_lifecycle_events', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_lifecycle');
            $table->unsignedBigInteger('application_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 48)->nullable();
            $table->text('reason')->nullable();
            $table->json('safe_metadata')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->foreign(['tenant_id', 'application_id'], 'adm_lifecycle_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_eligibility_evaluations', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_elig_eval');
            $table->unsignedBigInteger('application_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('result', 24)->default('pending');
            $table->json('rule_results');
            $table->text('human_review_note')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('evaluated_at')->nullable();
            $table->unique(['tenant_id', 'application_id', 'version_number'], 'adm_elig_eval_ver_uq');
            $table->foreign(['tenant_id', 'application_id'], 'adm_elig_eval_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_document_requirements', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_doc_req');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('intake_id');
            $table->string('code', 48);
            $table->string('name', 160);
            $table->text('instructions')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('allowed_mime_types');
            $table->unsignedInteger('maximum_bytes')->default(10485760);
            $table->unsignedSmallInteger('maximum_files')->default(1);
            $table->unique(['tenant_id', 'intake_id', 'code'], 'adm_doc_req_code_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'adm_doc_req_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'intake_id'], 'adm_doc_req_intake_fk')->references(['tenant_id', 'id'])->on('admission_intakes')->restrictOnDelete();
        });

        Schema::create('admission_documents', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_document');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('requirement_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('current_version')->default(1);
            $table->string('review_note', 1000)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'application_id', 'requirement_id'], 'adm_document_req_app_uq');
            $table->index(['tenant_id', 'application_id', 'status'], 'adm_document_app_status_idx');
            $table->foreign(['tenant_id', 'application_id'], 'adm_document_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requirement_id'], 'adm_document_req_fk')->references(['tenant_id', 'id'])->on('admission_document_requirements')->restrictOnDelete();
        });

        Schema::create('admission_document_versions', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_doc_version');
            $table->unsignedBigInteger('document_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('private_path', 500)->unique();
            $table->string('original_name', 180);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'document_id', 'version_number'], 'adm_doc_version_num_uq');
            $table->foreign(['tenant_id', 'document_id'], 'adm_doc_version_doc_fk')->references(['tenant_id', 'id'])->on('admission_documents')->restrictOnDelete();
        });

        Schema::create('admission_document_reviews', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_doc_review');
            $table->unsignedBigInteger('document_id');
            $table->unsignedSmallInteger('document_version');
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 24);
            $table->string('note', 1000)->nullable();
            $table->dateTime('reviewed_at');
            $table->foreign(['tenant_id', 'document_id'], 'adm_doc_review_doc_fk')->references(['tenant_id', 'id'])->on('admission_documents')->restrictOnDelete();
        });

        Schema::create('admission_reviewer_assignments', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_reviewer');
            $table->unsignedBigInteger('application_id');
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('assignment_type', 32)->default('review');
            $table->string('status', 24)->default('assigned')->index();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->dateTime('due_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'application_id', 'reviewer_user_id', 'assignment_type'], 'adm_reviewer_assignment_uq');
            $table->index(['tenant_id', 'reviewer_user_id', 'status', 'due_at'], 'adm_reviewer_workload_idx');
            $table->foreign(['tenant_id', 'application_id'], 'adm_reviewer_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_assessments', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_assessment');
            $table->unsignedBigInteger('application_id');
            $table->foreignId('assessor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assessment_type', 32);
            $table->string('status', 24)->default('scheduled')->index();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('score', 8, 3)->nullable();
            $table->decimal('maximum_score', 8, 3)->nullable();
            $table->text('private_notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->index(['tenant_id', 'application_id', 'status'], 'adm_assessment_app_status_idx');
            $table->foreign(['tenant_id', 'application_id'], 'adm_assessment_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_decisions', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_decision');
            $table->unsignedBigInteger('application_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('decision', 24);
            $table->text('applicant_reason')->nullable();
            $table->text('private_reason')->nullable();
            $table->foreignId('recommended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->unique(['tenant_id', 'application_id', 'version_number'], 'adm_decision_version_uq');
            $table->foreign(['tenant_id', 'application_id'], 'adm_decision_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_merit_lists', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_merit_list');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedSmallInteger('version_number');
            $table->json('formula');
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('calculated_at')->nullable();
            $table->unique(['tenant_id', 'intake_id', 'version_number'], 'adm_merit_list_ver_uq');
            $table->foreign(['tenant_id', 'intake_id'], 'adm_merit_list_intake_fk')->references(['tenant_id', 'id'])->on('admission_intakes')->restrictOnDelete();
        });

        Schema::create('admission_merit_entries', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_merit_entry');
            $table->unsignedBigInteger('merit_list_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedInteger('rank');
            $table->decimal('score', 10, 4);
            $table->json('score_breakdown');
            $table->boolean('manual_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'merit_list_id', 'application_id'], 'adm_merit_entry_app_uq');
            $table->unique(['tenant_id', 'merit_list_id', 'rank'], 'adm_merit_entry_rank_uq');
            $table->foreign(['tenant_id', 'merit_list_id'], 'adm_merit_entry_list_fk')->references(['tenant_id', 'id'])->on('admission_merit_lists')->restrictOnDelete();
            $table->foreign(['tenant_id', 'application_id'], 'adm_merit_entry_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_offers', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_offer');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('decision_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('offer_number', 40)->unique();
            $table->string('status', 24)->default('draft')->index();
            $table->text('conditions')->nullable();
            $table->dateTime('accept_by')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreign(['tenant_id', 'application_id'], 'adm_offer_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'decision_id'], 'adm_offer_decision_fk')->references(['tenant_id', 'id'])->on('admission_decisions')->restrictOnDelete();
            $table->unique(['tenant_id', 'application_id', 'version_number'], 'adm_offer_app_version_uq');
        });

        Schema::create('admission_seat_reservations', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_seat');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->dateTime('reserved_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('override_reason', 500)->nullable();
            $table->unique(['tenant_id', 'application_id'], 'adm_seat_app_uq');
            $table->index(['tenant_id', 'intake_id', 'status'], 'adm_seat_capacity_idx');
            $table->foreign(['tenant_id', 'intake_id'], 'adm_seat_intake_fk')->references(['tenant_id', 'id'])->on('admission_intakes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'application_id'], 'adm_seat_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'offer_id'], 'adm_seat_offer_fk')->references(['tenant_id', 'id'])->on('admission_offers')->restrictOnDelete();
        });

        Schema::create('admission_fee_obligations', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_fee');
            $table->unsignedBigInteger('application_id');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 24)->default('due')->index();
            $table->string('payment_reference', 120)->nullable();
            $table->string('waiver_reason', 500)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreign(['tenant_id', 'application_id'], 'adm_fee_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->unique(['tenant_id', 'application_id'], 'adm_fee_app_uq');
        });

        Schema::create('admission_outbox_events', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_outbox');
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('event_type', 80);
            $table->string('channel', 24)->default('local');
            $table->json('safe_payload')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->index(['tenant_id', 'status', 'available_at'], 'adm_outbox_ready_idx');
            $table->foreign(['tenant_id', 'application_id'], 'adm_outbox_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
        });

        Schema::create('admission_sis_handoffs', function (Blueprint $table): void {
            $this->tenantRecord($table, 'adm_handoff');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('offer_id');
            $table->uuid('correlation_id')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->json('approved_snapshot');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_summary')->nullable();
            $table->unique(['tenant_id', 'application_id'], 'adm_handoff_app_uq');
            $table->foreign(['tenant_id', 'application_id'], 'adm_handoff_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'offer_id'], 'adm_handoff_offer_fk')->references(['tenant_id', 'id'])->on('admission_offers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'admission_sis_handoffs', 'admission_outbox_events', 'admission_fee_obligations',
            'admission_seat_reservations', 'admission_offers', 'admission_merit_entries',
            'admission_merit_lists', 'admission_decisions', 'admission_assessments',
            'admission_reviewer_assignments', 'admission_document_reviews',
            'admission_document_versions', 'admission_documents', 'admission_document_requirements',
            'admission_eligibility_evaluations', 'admission_lifecycle_events',
            'admission_application_revisions', 'admission_applications',
            'admission_applicant_profiles', 'admission_inquiries', 'admission_form_versions',
            'admission_form_templates', 'admission_intakes', 'admissions_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

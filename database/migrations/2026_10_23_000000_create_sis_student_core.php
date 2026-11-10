<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sis_registry_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('student_label', 32)->default('Student');
            $table->string('number_prefix', 16)->default('STU');
            $table->unsignedTinyInteger('number_width')->default(6);
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id'], 'sis_registry_inst_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_registry_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('sis_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('home_campus_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('admission_handoff_id')->nullable();
            $table->unsignedBigInteger('admission_application_id')->nullable();
            $table->unsignedBigInteger('admission_offer_id')->nullable();
            $table->string('student_number', 40);
            $table->string('registration_reference', 80);
            $table->string('legal_first_name', 100);
            $table->string('legal_middle_name', 100)->nullable();
            $table->string('legal_last_name', 100);
            $table->string('preferred_name', 120)->nullable();
            $table->json('localized_names')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('admission_date');
            $table->string('status', 24)->default('admitted')->index();
            $table->string('student_type', 48)->default('standard');
            $table->text('government_identifier')->nullable();
            $table->string('emergency_contact_name', 180)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('emergency_contact_relationship', 48)->nullable();
            $table->string('previous_institution_name', 180)->nullable();
            $table->string('previous_institution_reference', 120)->nullable();
            $table->string('profile_photo_path', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'sis_student_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'student_number'], 'sis_student_number_uq');
            $table->unique(['tenant_id', 'institution_id', 'registration_reference'], 'sis_student_ref_uq');
            $table->unique(['tenant_id', 'user_id'], 'sis_student_user_uq');
            $table->unique(['tenant_id', 'admission_handoff_id'], 'sis_student_handoff_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'admission_date'], 'sis_student_status_idx');
            $table->index(['tenant_id', 'institution_id', 'legal_last_name', 'legal_first_name'], 'sis_student_name_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_student_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'home_campus_id'], 'sis_student_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_handoff_id'], 'sis_student_handoff_fk')->references(['tenant_id', 'id'])->on('admission_sis_handoffs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_application_id'], 'sis_student_app_fk')->references(['tenant_id', 'id'])->on('admission_applications')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_offer_id'], 'sis_student_offer_fk')->references(['tenant_id', 'id'])->on('admission_offers')->restrictOnDelete();
        });

        Schema::create('sis_student_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->string('event_type', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->dateTime('effective_at');
            $table->string('reason_code', 48)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('source_type', 48)->nullable();
            $table->string('source_reference', 120)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'student_id', 'effective_at'], 'sis_lifecycle_student_idx');
            $table->foreign(['tenant_id', 'student_id'], 'sis_lifecycle_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('sis_student_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->unsignedBigInteger('academic_program_id')->nullable();
            $table->unsignedBigInteger('academic_level_id')->nullable();
            $table->unsignedBigInteger('academic_class_id')->nullable();
            $table->unsignedBigInteger('academic_section_id')->nullable();
            $table->string('placement_type', 24)->default('placement');
            $table->string('status', 24)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'student_id', 'effective_from'], 'sis_place_history_idx');
            $table->index(['tenant_id', 'institution_id', 'academic_year_id', 'academic_class_id', 'academic_section_id'], 'sis_place_roster_idx');
            $table->index(['tenant_id', 'student_id', 'effective_until'], 'sis_place_current_idx');
            $table->foreign(['tenant_id', 'student_id'], 'sis_place_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'institution_id'], 'sis_place_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'sis_place_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'sis_place_year_fk')->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_period_id'], 'sis_place_period_fk')->references(['tenant_id', 'id'])->on('academic_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_program_id'], 'sis_place_program_fk')->references(['tenant_id', 'id'])->on('academic_programs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_level_id'], 'sis_place_level_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_class_id'], 'sis_place_class_fk')->references(['tenant_id', 'id'])->on('academic_classes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_section_id'], 'sis_place_section_fk')->references(['tenant_id', 'id'])->on('academic_sections')->restrictOnDelete();
        });

        Schema::create('sis_student_guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->foreignId('guardian_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('admission_authorization_id')->nullable();
            $table->string('guardian_name', 180);
            $table->string('guardian_email', 254)->nullable();
            $table->string('guardian_phone', 40)->nullable();
            $table->string('relationship', 48);
            $table->string('authority_status', 24)->default('pending');
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->boolean('can_view_profile')->default(false);
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'student_id', 'authority_status'], 'sis_guardian_student_idx');
            $table->index(['tenant_id', 'guardian_user_id', 'authority_status'], 'sis_guardian_user_idx');
            $table->foreign(['tenant_id', 'student_id'], 'sis_guardian_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_authorization_id'], 'sis_guardian_adm_fk')->references(['tenant_id', 'id'])->on('admission_guardian_authorizations')->restrictOnDelete();
        });

        Schema::create('sis_family_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('family_reference', 60);
            $table->string('label', 120)->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'sis_family_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'family_reference'], 'sis_family_ref_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_family_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('sis_family_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('family_group_id');
            $table->unsignedBigInteger('student_id');
            $table->string('relationship_label', 48)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'family_group_id', 'student_id'], 'sis_family_student_uq');
            $table->foreign(['tenant_id', 'family_group_id'], 'sis_family_member_group_fk')->references(['tenant_id', 'id'])->on('sis_family_groups')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'sis_family_member_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
        });

        Schema::create('sis_custom_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->string('field_key', 48);
            $table->string('label', 120);
            $table->string('field_type', 24);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->json('options')->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'field_key'], 'sis_custom_field_key_uq');
            $table->unique(['tenant_id', 'id'], 'sis_custom_field_tenant_id_uq');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_custom_field_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('sis_student_custom_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('field_definition_id');
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'student_id', 'field_definition_id'], 'sis_custom_value_uq');
            $table->foreign(['tenant_id', 'student_id'], 'sis_custom_value_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'field_definition_id'], 'sis_custom_value_field_fk')->references(['tenant_id', 'id'])->on('sis_custom_field_definitions')->restrictOnDelete();
        });

        Schema::create('sis_student_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('admission_document_id');
            $table->string('category', 48);
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'student_id', 'admission_document_id'], 'sis_student_document_uq');
            $table->foreign(['tenant_id', 'student_id'], 'sis_student_document_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'admission_document_id'], 'sis_student_document_adm_fk')->references(['tenant_id', 'id'])->on('admission_documents')->restrictOnDelete();
        });

        Schema::create('sis_student_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->uuid('batch_reference')->unique();
            $table->char('file_sha256', 64);
            $table->string('status', 24)->default('previewed');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->json('safe_report');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'institution_id', 'file_sha256'], 'sis_import_digest_uq');
            $table->index(['tenant_id', 'institution_id', 'status', 'created_at'], 'sis_import_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'sis_import_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'sis_student_import_batches', 'sis_student_documents', 'sis_student_custom_values',
            'sis_custom_field_definitions', 'sis_family_students', 'sis_family_groups',
            'sis_student_guardians', 'sis_student_placements', 'sis_student_lifecycle_events',
            'sis_students', 'sis_registry_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

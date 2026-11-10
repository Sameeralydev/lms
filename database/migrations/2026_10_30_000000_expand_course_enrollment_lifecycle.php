<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addColumnIfMissing('enrollment_policies', 'require_prerequisites', fn (Blueprint $table) => $table->boolean('require_prerequisites')->default(false));
        $this->addColumnIfMissing('enrollment_policies', 'require_withdrawal_approval', fn (Blueprint $table) => $table->boolean('require_withdrawal_approval')->default(true));
        $this->addColumnIfMissing('enrollment_policies', 'allow_section_transfer', fn (Blueprint $table) => $table->boolean('allow_section_transfer')->default(true));
        $this->addColumnIfMissing('enrollment_policies', 'allow_late_registration', fn (Blueprint $table) => $table->boolean('allow_late_registration')->default(false));
        $this->addColumnIfMissing('enrollment_policies', 'waitlist_ordering', fn (Blueprint $table) => $table->string('waitlist_ordering', 16)->default('fifo'));

        $this->addColumnIfMissing('course_offerings', 'late_registration_closes_at', fn (Blueprint $table) => $table->dateTime('late_registration_closes_at')->nullable());
        $this->addColumnIfMissing('course_offerings', 'add_deadline_at', fn (Blueprint $table) => $table->dateTime('add_deadline_at')->nullable());
        $this->addColumnIfMissing('course_offerings', 'drop_deadline_at', fn (Blueprint $table) => $table->dateTime('drop_deadline_at')->nullable());
        $this->addColumnIfMissing('course_offerings', 'withdrawal_deadline_at', fn (Blueprint $table) => $table->dateTime('withdrawal_deadline_at')->nullable());
        $this->addColumnIfMissing('course_offerings', 'institution_timezone', fn (Blueprint $table) => $table->string('institution_timezone', 64)->default('UTC'));
        $this->addColumnIfMissing('course_offerings', 'cancellation_reason', fn (Blueprint $table) => $table->text('cancellation_reason')->nullable());
        $this->addColumnIfMissing('course_offerings', 'archived_at', fn (Blueprint $table) => $table->dateTime('archived_at')->nullable());

        $this->addColumnIfMissing('course_enrollments', 'credits_snapshot', fn (Blueprint $table) => $table->decimal('credits_snapshot', 8, 3)->nullable());
        $this->addColumnIfMissing('course_enrollments', 'withdrawal_reason', fn (Blueprint $table) => $table->text('withdrawal_reason')->nullable());
        $this->addColumnIfMissing('course_enrollments', 'withdrawn_effective_at', fn (Blueprint $table) => $table->dateTime('withdrawn_effective_at')->nullable());
        $this->addColumnIfMissing('course_enrollments', 'archived_at', fn (Blueprint $table) => $table->dateTime('archived_at')->nullable());

        if (! Schema::hasTable('enrollment_policy_academic_levels')) {
            Schema::create('enrollment_policy_academic_levels', function (Blueprint $table): void {
                $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('policy_id');
                $table->unsignedBigInteger('academic_level_id');
                $table->timestamps();
                $table->primary(['tenant_id', 'policy_id', 'academic_level_id'], 'enroll_policy_level_pk');
                $table->foreign(['tenant_id', 'policy_id'], 'enroll_policy_level_policy_fk')->references(['tenant_id', 'id'])->on('enrollment_policies')->cascadeOnDelete();
                $table->foreign(['tenant_id', 'academic_level_id'], 'enroll_policy_level_academic_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
            });
        } else {
            $foreignKeys = Schema::getForeignKeys('enrollment_policy_academic_levels');
            $hasAcademicLevelForeignKey = collect($foreignKeys)->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['tenant_id', 'academic_level_id']
                && $foreignKey['foreign_table'] === 'academic_levels'
                && $foreignKey['foreign_columns'] === ['tenant_id', 'id']
            );
            if (! $hasAcademicLevelForeignKey) {
                Schema::table('enrollment_policy_academic_levels', function (Blueprint $table): void {
                    $table->foreign(['tenant_id', 'academic_level_id'], 'enroll_policy_level_academic_fk')->references(['tenant_id', 'id'])->on('academic_levels')->restrictOnDelete();
                });
            }
        }

        if (! Schema::hasTable('course_prerequisite_rules')) {
            Schema::create('course_prerequisite_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
                $table->foreignId('institution_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('course_id');
                $table->unsignedBigInteger('prerequisite_course_id');
                $table->string('relation_type', 16)->default('prerequisite');
                $table->boolean('is_required')->default(true);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['tenant_id', 'course_id', 'prerequisite_course_id', 'relation_type'], 'enroll_prereq_rule_uq');
                $table->index(['tenant_id', 'institution_id', 'course_id'], 'enroll_prereq_course_idx');
                $table->foreign(['tenant_id', 'institution_id'], 'enroll_prereq_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
                $table->foreign(['tenant_id', 'course_id'], 'enroll_prereq_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
                $table->foreign(['tenant_id', 'prerequisite_course_id'], 'enroll_prereq_required_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            });
        }
        $this->ensureCompositeForeignKey('course_prerequisite_rules', ['tenant_id', 'institution_id'], 'institutions', ['tenant_id', 'id'], 'enroll_prereq_inst_fk');
        $this->ensureCompositeForeignKey('course_prerequisite_rules', ['tenant_id', 'course_id'], 'academic_courses', ['tenant_id', 'id'], 'enroll_prereq_course_fk');
        $this->ensureCompositeForeignKey('course_prerequisite_rules', ['tenant_id', 'prerequisite_course_id'], 'academic_courses', ['tenant_id', 'id'], 'enroll_prereq_required_fk');

        Schema::create('course_prerequisite_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('prerequisite_course_id');
            $table->string('exception_type', 16);
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->string('evidence_reference', 180)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'student_id', 'course_id', 'status'], 'enroll_prereq_exception_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'enroll_prqex_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'student_id'], 'enroll_prqex_student_fk')->references(['tenant_id', 'id'])->on('sis_students')->restrictOnDelete();
            $table->foreign(['tenant_id', 'course_id'], 'enroll_prqex_course_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'prerequisite_course_id'], 'enroll_prqex_prereq_fk')->references(['tenant_id', 'id'])->on('academic_courses')->restrictOnDelete();
        });

        Schema::create('course_enrollment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('enrollment_id');
            $table->string('event_type', 48);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('safe_metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'enrollment_id', 'occurred_at'], 'enroll_event_history_idx');
            $table->foreign(['tenant_id', 'enrollment_id'], 'enroll_evt_enrl_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
        });

        Schema::create('course_section_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('source_section_id');
            $table->unsignedBigInteger('destination_section_id');
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'enroll_transfer_queue_idx');
            $table->foreign(['tenant_id', 'enrollment_id'], 'enroll_trn_enrl_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'source_section_id'], 'enroll_trn_src_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'destination_section_id'], 'enroll_trn_dst_fk')->references(['tenant_id', 'id'])->on('course_sections')->restrictOnDelete();
        });

        Schema::create('course_enrollment_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 80);
            $table->string('source_sha256', 64);
            $table->string('status', 20)->default('previewed');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('safe_summary')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'enroll_batch_tenant_id_uq');
            $table->unique(['tenant_id', 'idempotency_key'], 'enroll_batch_idem_uq');
            $table->index(['tenant_id', 'status', 'created_at'], 'enroll_batch_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'enroll_batch_inst_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
        });

        Schema::create('course_enrollment_batch_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('row_number');
            $table->string('student_number', 64);
            $table->string('offering_code', 48);
            $table->string('section_code', 32);
            $table->string('status', 16);
            $table->string('error_code', 48)->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'batch_id', 'row_number'], 'enroll_batch_row_uq');
            $table->index(['tenant_id', 'batch_id', 'status'], 'enroll_batch_row_status_idx');
            $table->foreign(['tenant_id', 'batch_id'], 'enroll_brow_batch_fk')->references(['tenant_id', 'id'])->on('course_enrollment_batches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'enrollment_id'], 'enroll_brow_enrl_fk')->references(['tenant_id', 'id'])->on('course_enrollments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_enrollment_batch_rows');
        Schema::dropIfExists('course_enrollment_batches');
        Schema::dropIfExists('course_section_transfers');
        Schema::dropIfExists('course_enrollment_events');
        Schema::dropIfExists('course_prerequisite_exceptions');
        Schema::dropIfExists('course_prerequisite_rules');
        Schema::dropIfExists('enrollment_policy_academic_levels');
        Schema::table('course_enrollments', function (Blueprint $table): void {
            $table->dropColumn(['credits_snapshot', 'withdrawal_reason', 'withdrawn_effective_at', 'archived_at']);
        });
        Schema::table('course_offerings', function (Blueprint $table): void {
            $table->dropColumn(['late_registration_closes_at', 'add_deadline_at', 'drop_deadline_at', 'withdrawal_deadline_at', 'institution_timezone', 'cancellation_reason', 'archived_at']);
        });
        Schema::table('enrollment_policies', function (Blueprint $table): void {
            $table->dropColumn(['require_prerequisites', 'require_withdrawal_approval', 'allow_section_transfer', 'allow_late_registration', 'waitlist_ordering']);
        });
    }

    private function addColumnIfMissing(string $tableName, string $columnName, Closure $definition): void
    {
        if (Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $definition($table));
    }

    /** @param list<string> $columns
     * @param  list<string>  $referencedColumns
     */
    private function ensureCompositeForeignKey(string $tableName, array $columns, string $referencedTable, array $referencedColumns, string $name): void
    {
        $foreignKeys = Schema::getForeignKeys($tableName);
        $exists = collect($foreignKeys)->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === $columns
            && $foreignKey['foreign_table'] === $referencedTable
            && $foreignKey['foreign_columns'] === $referencedColumns
        );
        if ($exists) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->foreign($columns, $name)->references($referencedColumns)->on($referencedTable)->restrictOnDelete());
    }
};

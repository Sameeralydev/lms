<?php

use App\Domain\Identity\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->string('name', 120);
            $table->string('code', 40);
            $table->unsignedSmallInteger('loan_days')->default(14);
            $table->unsignedTinyInteger('max_active_loans')->default(5);
            $table->unsignedTinyInteger('renewal_limit')->default(1);
            $table->unsignedTinyInteger('reservation_limit')->default(3);
            $table->boolean('allow_renewals')->default(true);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'library_locations_tenant_id_uq');
            $table->unique(['tenant_id', 'institution_id', 'code'], 'library_locations_code_uq');
            $table->index(['tenant_id', 'campus_id', 'status'], 'library_locations_campus_status_idx');
            $table->foreign(['tenant_id', 'institution_id'], 'library_locations_institution_fk')->references(['tenant_id', 'id'])->on('institutions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'campus_id'], 'library_locations_campus_fk')->references(['tenant_id', 'id'])->on('campuses')->restrictOnDelete();
        });

        Schema::create('library_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('title', 240);
            $table->string('subtitle', 240)->nullable();
            $table->string('authors', 500)->nullable();
            $table->string('isbn', 20)->nullable();
            $table->string('publisher', 180)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('edition', 80)->nullable();
            $table->string('language', 64)->nullable();
            $table->string('category', 120)->nullable();
            $table->string('subject', 160)->nullable();
            $table->string('classification', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'library_resources_tenant_id_uq');
            $table->index(['tenant_id', 'title'], 'library_resources_title_idx');
            $table->index(['tenant_id', 'category'], 'library_resources_category_idx');
            $table->index(['tenant_id', 'isbn'], 'library_resources_isbn_idx');
        });

        Schema::create('library_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('library_location_id');
            $table->string('accession_number', 64);
            $table->string('barcode', 80)->nullable();
            $table->string('shelf_location', 80)->nullable();
            $table->string('condition', 24)->default('good');
            $table->string('status', 24)->default('available');
            $table->date('acquired_on')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'library_copies_tenant_id_uq');
            $table->unique(['tenant_id', 'accession_number'], 'library_copies_accession_uq');
            $table->unique(['tenant_id', 'barcode'], 'library_copies_barcode_uq');
            $table->index(['tenant_id', 'library_location_id', 'status'], 'library_copies_location_status_idx');
            $table->index(['tenant_id', 'resource_id', 'status'], 'library_copies_resource_status_idx');
            $table->foreign(['tenant_id', 'resource_id'], 'library_copies_resource_fk')->references(['tenant_id', 'id'])->on('library_resources')->restrictOnDelete();
            $table->foreign(['tenant_id', 'library_location_id'], 'library_copies_location_fk')->references(['tenant_id', 'id'])->on('library_locations')->restrictOnDelete();
        });

        Schema::create('library_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('library_location_id');
            $table->string('member_number', 64);
            $table->string('status', 16)->default('active');
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'library_memberships_tenant_id_uq');
            $table->unique(['tenant_id', 'membership_id'], 'library_memberships_member_uq');
            $table->unique(['tenant_id', 'member_number'], 'library_memberships_number_uq');
            $table->foreign(['membership_id', 'tenant_id'], 'library_memberships_account_fk')->references(['id', 'tenant_id'])->on('tenant_memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'library_location_id'], 'library_memberships_location_fk')->references(['tenant_id', 'id'])->on('library_locations')->restrictOnDelete();
        });

        Schema::create('library_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('copy_id');
            $table->unsignedBigInteger('library_membership_id');
            $table->unsignedBigInteger('issued_by');
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->dateTime('checked_out_at');
            $table->dateTime('due_at');
            $table->dateTime('returned_at')->nullable();
            $table->unsignedTinyInteger('renewal_count')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'id'], 'library_loans_tenant_id_uq');
            $table->index(['tenant_id', 'status', 'due_at'], 'library_loans_due_idx');
            $table->index(['tenant_id', 'library_membership_id', 'status'], 'library_loans_member_status_idx');
            $table->foreign(['tenant_id', 'copy_id'], 'library_loans_copy_fk')->references(['tenant_id', 'id'])->on('library_copies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'library_membership_id'], 'library_loans_member_fk')->references(['tenant_id', 'id'])->on('library_memberships')->restrictOnDelete();
            $table->foreign('issued_by', 'library_loans_issued_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('returned_by', 'library_loans_returned_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('library_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('library_membership_id');
            $table->unsignedBigInteger('copy_id')->nullable();
            $table->string('status', 16)->default('queued');
            $table->dateTime('placed_at');
            $table->dateTime('fulfilled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'resource_id', 'status', 'placed_at'], 'library_reservations_queue_idx');
            $table->index(['tenant_id', 'library_membership_id', 'status'], 'library_reservations_member_idx');
            $table->foreign(['tenant_id', 'resource_id'], 'library_reservations_resource_fk')->references(['tenant_id', 'id'])->on('library_resources')->restrictOnDelete();
            $table->foreign(['tenant_id', 'library_membership_id'], 'library_reservations_member_fk')->references(['tenant_id', 'id'])->on('library_memberships')->restrictOnDelete();
            $table->foreign(['tenant_id', 'copy_id'], 'library_reservations_copy_fk')->references(['tenant_id', 'id'])->on('library_copies')->restrictOnDelete();
        });

        Schema::create('library_circulation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('event_type', 24);
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'loan_id', 'occurred_at'], 'library_events_loan_idx');
            $table->foreign(['tenant_id', 'loan_id'], 'library_events_loan_fk')->references(['tenant_id', 'id'])->on('library_loans')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reservation_id'], 'library_events_reservation_fk')->references(['tenant_id', 'id'])->on('library_reservations')->restrictOnDelete();
            $table->foreign('actor_id', 'library_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $keys = ['library.view', 'library.manage', 'library.inventory.manage', 'library.circulate', 'library.members.manage', 'library.policy.manage', 'library.member.view-own'];
            $catalog = PermissionCatalog::tenantPermissions();
            $rows = array_map(static fn (string $key): array => [
                'key' => $key,
                'description' => $catalog[$key],
                'scope' => 'tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ], $keys);
            DB::table('permissions')->upsert($rows, ['key'], ['description', 'scope', 'updated_at']);
            $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');
            $grants = [
                'tenant.owner' => $keys,
                'tenant.admin' => $keys,
                'campus.admin' => ['library.view', 'library.inventory.manage', 'library.circulate', 'library.members.manage'],
                'library.manager' => ['library.view', 'library.manage', 'library.inventory.manage', 'library.circulate', 'library.members.manage', 'library.policy.manage'],
                'teacher' => ['library.view', 'library.member.view-own'],
                'student' => ['library.view', 'library.member.view-own'],
            ];
            foreach (DB::table('roles')->where('scope', 'tenant')->get(['id', 'key']) as $role) {
                foreach ($grants[$role->key] ?? [] as $key) {
                    DB::table('permission_role')->insertOrIgnore([
                        'role_id' => $role->id,
                        'permission_id' => $ids[$key],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_circulation_events');
        Schema::dropIfExists('library_reservations');
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_memberships');
        Schema::dropIfExists('library_copies');
        Schema::dropIfExists('library_resources');
        Schema::dropIfExists('library_locations');
        // Permissions may have been assigned after this migration; they are intentionally retained.
    }
};

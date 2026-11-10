<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicDocument;
use App\Models\AcademicDocumentApproval;
use App\Models\AcademicUnit;
use App\Models\EducationCorrectiveAction;
use App\Models\EducationQualityEvidence;
use App\Models\EducationQualityObjective;
use App\Models\EducationQualityReview;
use App\Models\Institution;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicQualityController extends Controller
{
    public function index(TenantContext $context): Response
    {
        $institution = Institution::query()->firstOrFail();

        return Inertia::render('workspace/academics/quality', [
            'tenant' => ['slug' => $context->tenant()->slug],
            'objectives' => EducationQualityObjective::query()->with(['academicUnit:id,name', 'reviews' => fn ($query) => $query->orderByDesc('review_number')->limit(5), 'correctiveActions' => fn ($query) => $query->orderByDesc('created_at')->limit(20), 'evidence' => fn ($query) => $query->latest()->limit(20)])->withCount(['reviews', 'correctiveActions', 'evidence'])->orderBy('name')->paginate(15, ['*'], 'objectives_page'),
            'documents' => AcademicDocument::query()->with('approvals')->orderByDesc('created_at')->paginate(15, ['*'], 'documents_page'),
            'units' => AcademicUnit::query()->where('institution_id', $institution->getKey())->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'members' => TenantMembership::query()->with('user:id,name')->where('status', 'active')->get()->map(fn (TenantMembership $membership) => ['id' => $membership->user_id, 'name' => $membership->user->name]),
        ]);
    }

    public function storeObjective(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('education_quality_objectives', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'name' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:5000'], 'quality_area' => ['nullable', 'string', 'max:80'], 'metric_definition' => ['required', 'string', 'max:5000'], 'metric_unit' => ['nullable', 'string', 'max:48'], 'target_value' => ['nullable', 'numeric', 'decimal:0,4'],
            'review_every_months' => ['required', 'integer', 'min:1', 'max:120'], 'next_review_on' => ['nullable', 'date'],
            'academic_unit_id' => ['nullable', 'integer', Rule::exists('academic_units', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('institution_id', $institution->getKey()))],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('status', 'active'))],
        ]);
        $objective = EducationQualityObjective::query()->create([...$data, 'institution_id' => $institution->getKey(), 'status' => 'draft', 'created_by' => $request->user()->getKey(), 'updated_by' => $request->user()->getKey()]);
        $audit->record('academic.quality_objective.created', $request->user(), (int) $context->tenant()->getKey(), $objective, ['code' => $objective->code]);

        return $this->qualityRedirect($context, "Quality objective {$objective->name} created as a draft.");
    }

    public function activateObjective(Request $request, Tenant $tenant, int $objectiveId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $objective = EducationQualityObjective::query()->findOrFail($objectiveId);
        abort_unless($objective->status === 'draft', 409);
        $objective->forceFill(['status' => 'active', 'updated_by' => $request->user()->getKey()])->save();
        $audit->record('academic.quality_objective.activated', $request->user(), (int) $context->tenant()->getKey(), $objective, ['code' => $objective->code]);

        return $this->qualityRedirect($context, 'Quality objective activated.');
    }

    public function recordReview(Request $request, Tenant $tenant, int $objectiveId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $objective = EducationQualityObjective::query()->findOrFail($objectiveId);
        abort_unless($objective->status === 'active', 409, 'Only active quality objectives can be reviewed.');
        $data = $request->validate(['due_on' => ['nullable', 'date'], 'measured_value' => ['nullable', 'numeric', 'decimal:0,4'], 'summary' => ['required', 'string', 'max:8000'], 'decision' => ['required', Rule::in(['met', 'partially_met', 'not_met', 'not_applicable'])]]);
        $review = DB::transaction(function () use ($objective, $data, $request, $context, $audit): EducationQualityReview {
            EducationQualityObjective::query()->lockForUpdate()->findOrFail($objective->getKey());
            $number = (int) EducationQualityReview::query()->where('quality_objective_id', $objective->getKey())->max('review_number') + 1;
            $review = EducationQualityReview::query()->create([...$data, 'quality_objective_id' => $objective->getKey(), 'review_number' => $number, 'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now(), 'approved_by' => null, 'approved_at' => null]);
            $audit->record('academic.quality_review.recorded', $request->user(), (int) $context->tenant()->getKey(), $review, ['objective_id' => $objective->getKey(), 'review_number' => $number, 'decision' => $review->decision]);

            return $review;
        });

        return $this->qualityRedirect($context, "Quality review {$review->review_number} recorded and awaiting approval.");
    }

    public function approveReview(Request $request, Tenant $tenant, int $reviewId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['approval_note' => ['nullable', 'string', 'max:5000']]);
        $review = EducationQualityReview::query()->findOrFail($reviewId);
        if ($review->approved_at || (int) $review->reviewed_by === (int) $request->user()->getKey()) {
            throw ValidationException::withMessages(['review' => 'A review cannot be approved twice or by its own reviewer.']);
        }
        $review->forceFill(['approved_by' => $request->user()->getKey(), 'approved_at' => now(), 'approval_note' => $data['approval_note'] ?? null])->save();
        $objective = EducationQualityObjective::query()->findOrFail($review->quality_objective_id);
        if ($review->decision === 'not_met') {
            $objective->forceFill(['next_review_on' => $review->due_on ?? now()->addMonths($objective->review_every_months)->toDateString()])->save();
        }
        $audit->record('academic.quality_review.approved', $request->user(), (int) $context->tenant()->getKey(), $review, ['objective_id' => $objective->getKey(), 'review_number' => $review->review_number]);

        return $this->qualityRedirect($context, 'Quality review approved.');
    }

    public function storeQualityEvidence(Request $request, Tenant $tenant, int $objectiveId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $objective = EducationQualityObjective::query()->findOrFail($objectiveId);
        $data = $request->validate([
            'quality_review_id' => ['nullable', 'integer', Rule::exists('education_quality_reviews', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('quality_objective_id', $objective->getKey()))],
            'title' => ['required', 'string', 'max:180'], 'reference_url' => ['nullable', 'url', 'max:500', 'starts_with:https://'], 'note' => ['nullable', 'string', 'max:5000'], 'file' => ['nullable', 'file', 'mimes:pdf,docx,xlsx,png,jpg,jpeg', 'max:20480'],
        ]);
        $path = null;
        $hash = null;
        if ($request->file('file')) {
            $file = $request->file('file');
            $path = $file->storeAs('academic/tenants/'.$context->tenant()->getKey().'/quality-evidence', Str::uuid().'.'.$file->extension(), 'local');
            if (! $path) {
                throw ValidationException::withMessages(['file' => 'Evidence could not be stored securely.']);
            }
            $hash = hash_file('sha256', Storage::disk('local')->path($path)) ?: null;
        }
        try {
            $evidence = EducationQualityEvidence::query()->create(['quality_objective_id' => $objective->getKey(), 'quality_review_id' => $data['quality_review_id'] ?? null, 'title' => $data['title'], 'private_file_path' => $path, 'file_sha256' => $hash, 'reference_url' => $data['reference_url'] ?? null, 'note' => $data['note'] ?? null, 'uploaded_by' => $request->user()->getKey()]);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        $audit->record('academic.quality_evidence.created', $request->user(), (int) $context->tenant()->getKey(), $evidence, ['objective_id' => $objective->getKey(), 'file_attached' => $path !== null]);

        return $this->qualityRedirect($context, 'Quality evidence recorded.');
    }

    public function storeCorrectiveAction(Request $request, Tenant $tenant, int $objectiveId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $objective = EducationQualityObjective::query()->findOrFail($objectiveId);
        $data = $request->validate([
            'quality_review_id' => ['nullable', 'integer', Rule::exists('education_quality_reviews', 'id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('quality_objective_id', $objective->getKey()))],
            'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('education_corrective_actions', 'code')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('quality_objective_id', $objective->getKey()))],
            'title' => ['required', 'string', 'max:180'], 'description' => ['required', 'string', 'max:5000'], 'assigned_to' => ['nullable', 'integer', Rule::exists('tenant_memberships', 'user_id')->where(fn ($query) => $query->where('tenant_id', $context->tenant()->getKey())->where('status', 'active'))], 'due_on' => ['nullable', 'date'],
        ]);
        $action = EducationCorrectiveAction::query()->create([...$data, 'quality_objective_id' => $objective->getKey(), 'status' => 'open']);
        $audit->record('academic.corrective_action.created', $request->user(), (int) $context->tenant()->getKey(), $action, ['objective_id' => $objective->getKey(), 'code' => $action->code]);

        return $this->qualityRedirect($context, 'Corrective action recorded.');
    }

    public function closeCorrectiveAction(Request $request, Tenant $tenant, int $actionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $action = EducationCorrectiveAction::query()->findOrFail($actionId);
        abort_unless(in_array($action->status, ['open', 'in_progress'], true), 409);
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:5000']]);
        $action->forceFill(['status' => 'closed', 'resolution_note' => $data['resolution_note'], 'closed_by' => $request->user()->getKey(), 'closed_at' => now()])->save();
        $audit->record('academic.corrective_action.closed', $request->user(), (int) $context->tenant()->getKey(), $action, ['objective_id' => $action->quality_objective_id]);

        return $this->qualityRedirect($context, 'Corrective action closed with resolution evidence.');
    }

    public function updateCorrectiveAction(Request $request, Tenant $tenant, int $actionId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $action = EducationCorrectiveAction::query()->findOrFail($actionId);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress'])]]);
        abort_unless($action->status === 'open', 409);
        $action->forceFill(['status' => $data['status']])->save();
        $audit->record('academic.corrective_action.started', $request->user(), (int) $context->tenant()->getKey(), $action, ['objective_id' => $action->quality_objective_id]);

        return $this->qualityRedirect($context, 'Corrective action marked in progress.');
    }

    public function storeDocument(Request $request, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $institution = Institution::query()->firstOrFail();
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:48'], 'code' => ['required', 'string', 'max:48', 'regex:/^[A-Za-z0-9._-]+$/'], 'title' => ['required', 'string', 'max:200'], 'summary' => ['nullable', 'string', 'max:5000'],
            'effective_from' => ['nullable', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'], 'file' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:30720'],
        ]);
        $file = $request->file('file');
        $path = $file->storeAs('academic/tenants/'.$context->tenant()->getKey().'/documents', Str::uuid().'.'.$file->extension(), 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'Document could not be stored securely.']);
        }
        $hash = hash_file('sha256', Storage::disk('local')->path($path));
        try {
            $document = DB::transaction(function () use ($data, $institution, $context, $request, $audit, $file, $path, $hash): AcademicDocument {
                Institution::query()->lockForUpdate()->findOrFail($institution->getKey());
                $version = (int) AcademicDocument::query()->where('institution_id', $institution->getKey())->where('code', $data['code'])->max('version_number') + 1;
                $document = AcademicDocument::query()->create([...$data, 'institution_id' => $institution->getKey(), 'version_number' => $version, 'private_file_path' => $path, 'file_sha256' => $hash, 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'file_size' => $file->getSize(), 'status' => 'draft', 'created_by' => $request->user()->getKey()]);
                $audit->record('academic.document.created', $request->user(), (int) $context->tenant()->getKey(), $document, ['code' => $document->code, 'version' => $version, 'mime_type' => $document->mime_type, 'file_size' => $document->file_size]);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $this->qualityRedirect($context, "Controlled document {$document->title} version {$document->version_number} stored privately.");
    }

    public function submitDocument(Request $request, Tenant $tenant, int $documentId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $document = AcademicDocument::query()->findOrFail($documentId);
        abort_unless($document->status === 'draft', 409);
        $document->forceFill(['status' => 'in_review'])->save();
        AcademicDocumentApproval::query()->create(['academic_document_id' => $document->getKey(), 'decision' => 'submitted', 'actor_user_id' => $request->user()->getKey(), 'occurred_at' => now()]);
        $audit->record('academic.document.submitted', $request->user(), (int) $context->tenant()->getKey(), $document, ['code' => $document->code, 'version' => $document->version_number]);

        return $this->qualityRedirect($context, 'Document submitted for approval.');
    }

    public function decideDocument(Request $request, Tenant $tenant, int $documentId, TenantContext $context, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'comment' => ['nullable', 'string', 'max:5000']]);
        $document = AcademicDocument::query()->findOrFail($documentId);
        if ($document->status !== 'in_review' || (int) $document->created_by === (int) $request->user()->getKey()) {
            throw ValidationException::withMessages(['document' => 'A document must be in review and the submitting author cannot approve it.']);
        }
        DB::transaction(function () use ($document, $data, $request, $context, $audit): void {
            $locked = AcademicDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            if ($locked->status !== 'in_review') {
                throw ValidationException::withMessages(['document' => 'This document is no longer awaiting a decision.']);
            }
            $locked->forceFill(['status' => $data['decision'] === 'approved' ? 'approved' : 'draft'])->save();
            AcademicDocumentApproval::query()->create(['academic_document_id' => $locked->getKey(), 'decision' => $data['decision'], 'comment' => $data['comment'] ?? null, 'actor_user_id' => $request->user()->getKey(), 'occurred_at' => now()]);
            $audit->record('academic.document.'.$data['decision'], $request->user(), (int) $context->tenant()->getKey(), $locked, ['code' => $locked->code, 'version' => $locked->version_number]);
        });

        return $this->qualityRedirect($context, "Document {$data['decision']}.");
    }

    public function downloadDocument(Tenant $tenant, int $documentId): mixed
    {
        $document = AcademicDocument::query()->findOrFail($documentId);
        abort_if($document->status !== 'approved', 404);
        abort_unless(Storage::disk('local')->exists($document->private_file_path), 404);

        return Storage::disk('local')->download($document->private_file_path, basename($document->private_file_path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function downloadQualityEvidence(Tenant $tenant, int $evidenceId): mixed
    {
        $evidence = EducationQualityEvidence::query()->findOrFail($evidenceId);
        abort_unless($evidence->private_file_path && Storage::disk('local')->exists($evidence->private_file_path), 404);

        return Storage::disk('local')->download($evidence->private_file_path, basename($evidence->private_file_path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function qualityRedirect(TenantContext $context, string $message): RedirectResponse
    {
        return to_route('tenant.academics.quality', ['tenant' => $context->tenant()])->with('status', $message);
    }
}

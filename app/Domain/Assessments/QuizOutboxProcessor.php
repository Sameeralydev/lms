<?php

namespace App\Domain\Assessments;

use App\Domain\Tenancy\TenantContext;
use App\Models\QuizAttempt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class QuizOutboxProcessor
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array{claimed:int,delivered:int,retried:int,failed:int} */
    public function dispatchAvailable(int $limit = 50): array
    {
        $totals = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];
        $tenant = $this->context->tenant();
        $events = DB::table('quiz_outbox_events')->where('tenant_id', $tenant->getKey())
            ->whereIn('status', ['queued', 'retry'])->where('available_at', '<=', now())->orderBy('id')->limit(max(1, min(200, $limit)))->get();
        foreach ($events as $candidate) {
            try {
                $result = DB::transaction(function () use ($candidate, $tenant): string {
                    $event = DB::table('quiz_outbox_events')->where('tenant_id', $tenant->getKey())->where('id', $candidate->id)->lockForUpdate()->first();
                    if (! $event || ! in_array($event->status, ['queued', 'retry'], true) || now()->lt($event->available_at)) {
                        return 'skipped';
                    }
                    DB::table('quiz_outbox_events')->where('id', $event->id)->update(['status' => 'processing', 'locked_at' => now(), 'attempts' => (int) $event->attempts + 1, 'updated_at' => now()]);
                    $users = $this->recipients($event->event_type, json_decode((string) $event->safe_payload, true, 512, JSON_THROW_ON_ERROR), (int) $tenant->getKey());
                    if ($users === null) {
                        DB::table('quiz_outbox_events')->where('id', $event->id)->update(['status' => 'failed', 'last_error_code' => 'unsupported_event', 'updated_at' => now()]);

                        return 'failed';
                    }
                    foreach (array_unique(array_filter(array_map('intval', $users))) as $userId) {
                        $recipientKey = substr((string) $event->event_key, 0, 96).':u:'.$userId;
                        [$title, $message] = $this->copy((string) $event->event_type);
                        DB::table('quiz_notifications')->insertOrIgnore([
                            'tenant_id' => $tenant->getKey(), 'user_id' => $userId, 'event_key' => $recipientKey,
                            'event_type' => $event->event_type, 'title' => $title, 'message' => $message,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    DB::table('quiz_outbox_events')->where('id', $event->id)->update(['status' => 'delivered', 'delivered_at' => now(), 'locked_at' => null, 'last_error_code' => null, 'updated_at' => now()]);

                    return 'delivered';
                }, 3);
                if ($result === 'skipped') {
                    continue;
                }
                $totals['claimed']++;
                $totals[$result]++;
            } catch (Throwable) {
                $attempts = (int) $candidate->attempts + 1;
                DB::table('quiz_outbox_events')->where('tenant_id', $tenant->getKey())->where('id', $candidate->id)->update([
                    'status' => $attempts >= 8 ? 'failed' : 'retry', 'attempts' => $attempts,
                    'available_at' => now()->addSeconds(min(3600, 15 * (2 ** min($attempts, 7)))),
                    'locked_at' => null, 'last_error_code' => 'delivery_failed', 'updated_at' => now(),
                ]);
                $totals['claimed']++;
                $totals[$attempts >= 8 ? 'failed' : 'retried']++;
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>|null
     */
    private function recipients(string $type, array $payload, int $tenantId): ?array
    {
        if (in_array($type, ['academic.record.issued', 'academic.record.revoked'], true)) {
            $record = DB::table('academic_record_issues')->where('tenant_id', $tenantId)->where('id', (int) ($payload['record_issue_id'] ?? 0))->first(['student_id', 'institution_id']);
            if ($record === null) {
                return [];
            }
            $student = DB::table('sis_students')->where('tenant_id', $tenantId)->where('institution_id', $record->institution_id)->where('id', $record->student_id)->where('status', 'active')->first(['user_id', 'home_campus_id']);
            if ($student === null) {
                return [];
            }
            $guardianIds = DB::table('sis_student_guardians')->where('tenant_id', $tenantId)->where('student_id', $record->student_id)->where('authority_status', 'verified')->whereNull('revoked_at')->where('can_view_profile', true)->whereNotNull('guardian_user_id')->pluck('guardian_user_id');

            return $this->academicScopedRecipients(
                $tenantId, (int) $record->institution_id, [(int) $student->user_id],
                $guardianIds->map(static fn ($id): int => (int) $id)->all(),
                $student->home_campus_id === null ? null : (int) $student->home_campus_id,
            );
        }
        if ($type === 'academic.grade.published') {
            $publicationId = (int) ($payload['publication_id'] ?? 0);
            $entries = DB::table('academic_grade_publication_entries as entries')->join('course_enrollments as enrollments', function ($join): void {
                $join->on('enrollments.id', '=', 'entries.enrollment_id')->on('enrollments.tenant_id', '=', 'entries.tenant_id');
            })->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'enrollments.student_id')->on('students.tenant_id', '=', 'enrollments.tenant_id');
            })->join('academic_grade_publications as publications', function ($join): void {
                $join->on('publications.id', '=', 'entries.publication_id')->on('publications.tenant_id', '=', 'entries.tenant_id');
            })->join('academic_gradebooks as books', function ($join): void {
                $join->on('books.id', '=', 'entries.gradebook_id')->on('books.tenant_id', '=', 'entries.tenant_id');
            })->join('course_sections as sections', function ($join): void {
                $join->on('sections.id', '=', 'books.section_id')->on('sections.tenant_id', '=', 'books.tenant_id');
            })->where('entries.tenant_id', $tenantId)->where('entries.publication_id', $publicationId)->where('books.status', 'published')->where('students.status', 'active')->whereNotNull('students.user_id')->get(['students.id as student_id', 'students.user_id', 'books.institution_id', 'sections.campus_id']);
            $recipientIds = [];
            foreach ($entries as $entry) {
                $guardianIds = DB::table('sis_student_guardians')->where('tenant_id', $tenantId)->where('student_id', $entry->student_id)->where('authority_status', 'verified')->whereNull('revoked_at')->where('can_view_profile', true)->whereNotNull('guardian_user_id')->pluck('guardian_user_id')->map(static fn ($id): int => (int) $id)->all();
                $recipientIds = [...$recipientIds, ...$this->academicScopedRecipients($tenantId, (int) $entry->institution_id, [(int) $entry->user_id], $guardianIds, $entry->campus_id === null ? null : (int) $entry->campus_id)];
            }

            return array_values(array_unique($recipientIds));
        }
        if (in_array($type, ['academic.gradebook.submitted', 'academic.gradebook.reviewed'], true)) {
            $book = DB::table('academic_gradebooks as books')->join('course_sections as sections', function ($join): void {
                $join->on('sections.id', '=', 'books.section_id')->on('sections.tenant_id', '=', 'books.tenant_id');
            })->where('books.tenant_id', $tenantId)->where('books.id', (int) ($payload['gradebook_id'] ?? 0))
                ->first(['books.institution_id', 'books.created_by', 'sections.campus_id']);
            if ($book === null) {
                return [];
            }

            return $type === 'academic.gradebook.submitted'
                ? $this->academicPermissionRecipients($tenantId, (int) $book->institution_id, $book->campus_id === null ? null : (int) $book->campus_id, 'academic.grades.review')
                : $this->activeScopedUsers($tenantId, (int) $book->institution_id, $book->campus_id === null ? null : (int) $book->campus_id, [(int) $book->created_by]);
        }
        if (in_array($type, ['academic.grade_override.requested', 'academic.grade_override.decided'], true)) {
            $override = DB::table('academic_grade_override_requests as overrides')->join('academic_gradebooks as books', function ($join): void {
                $join->on('books.id', '=', 'overrides.gradebook_id')->on('books.tenant_id', '=', 'overrides.tenant_id');
            })->join('course_sections as sections', function ($join): void {
                $join->on('sections.id', '=', 'books.section_id')->on('sections.tenant_id', '=', 'books.tenant_id');
            })->where('overrides.tenant_id', $tenantId)->where('overrides.id', (int) ($payload['override_id'] ?? 0))
                ->first(['books.institution_id', 'overrides.requested_by', 'sections.campus_id']);
            if ($override === null) {
                return [];
            }

            return $type === 'academic.grade_override.requested'
                ? $this->academicPermissionRecipients($tenantId, (int) $override->institution_id, $override->campus_id === null ? null : (int) $override->campus_id, 'academic.grades.review')
                : $this->activeScopedUsers($tenantId, (int) $override->institution_id, $override->campus_id === null ? null : (int) $override->campus_id, [(int) $override->requested_by]);
        }
        if (in_array($type, ['academic.record.submitted', 'academic.record.decided'], true)) {
            $record = DB::table('academic_record_issues as records')->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'records.student_id')->on('students.tenant_id', '=', 'records.tenant_id');
            })->where('records.tenant_id', $tenantId)->where('records.id', (int) ($payload['record_issue_id'] ?? 0))
                ->first(['records.institution_id', 'records.created_by', 'students.home_campus_id']);
            if ($record === null) {
                return [];
            }

            return $type === 'academic.record.submitted'
                ? $this->academicPermissionRecipients($tenantId, (int) $record->institution_id, $record->home_campus_id === null ? null : (int) $record->home_campus_id, 'academic.records.review')
                : $this->activeScopedUsers($tenantId, (int) $record->institution_id, $record->home_campus_id === null ? null : (int) $record->home_campus_id, [(int) $record->created_by]);
        }
        if (in_array($type, ['academic.report_card_comment.submitted', 'academic.report_card_comment.decided'], true)) {
            $comment = DB::table('academic_report_card_comments as comments')->join('course_sections as sections', function ($join): void {
                $join->on('sections.id', '=', 'comments.section_id')->on('sections.tenant_id', '=', 'comments.tenant_id');
            })->where('comments.tenant_id', $tenantId)->where('comments.id', (int) ($payload['comment_id'] ?? 0))
                ->first(['comments.institution_id', 'comments.teacher_id', 'sections.campus_id']);
            if ($comment === null) {
                return [];
            }

            return $type === 'academic.report_card_comment.submitted'
                ? $this->academicPermissionRecipients($tenantId, (int) $comment->institution_id, $comment->campus_id === null ? null : (int) $comment->campus_id, 'academic.records.review')
                : $this->activeScopedUsers($tenantId, (int) $comment->institution_id, $comment->campus_id === null ? null : (int) $comment->campus_id, [(int) $comment->teacher_id]);
        }
        if ($type === 'quiz.published') {
            $sectionIds = DB::table('quiz_sections')->where('tenant_id', $tenantId)->where('quiz_id', (int) ($payload['quiz_id'] ?? 0))->pluck('section_id');

            return DB::table('course_enrollments')->join('sis_students', function ($join) use ($tenantId): void {
                $join->on('sis_students.id', '=', 'course_enrollments.student_id')->where('sis_students.tenant_id', '=', $tenantId);
            })->where('course_enrollments.tenant_id', $tenantId)->whereIn('course_enrollments.section_id', $sectionIds)->where('course_enrollments.status', 'confirmed')->where('sis_students.status', 'active')->whereNotNull('sis_students.user_id')->distinct()->pluck('sis_students.user_id')->map(static fn ($id): int => (int) $id)->all();
        }
        if (in_array($type, ['quiz.attempt_submitted', 'quiz.attempt.expired'], true)) {
            $attempt = QuizAttempt::query()->with('quiz')->find((int) ($payload['attempt_id'] ?? 0));
            if (! $attempt) {
                return [];
            }
            $sectionIds = DB::table('quiz_sections')->where('tenant_id', $tenantId)->where('quiz_id', $attempt->quiz_id)->pluck('section_id');

            return DB::table('course_section_instructors')->where('tenant_id', $tenantId)->whereIn('section_id', $sectionIds)->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        }
        if (in_array($type, ['exam.attempt_submitted', 'exam.attempt_expired'], true)) {
            $binding = DB::table('exam_attempt_bindings as bindings')
                ->join('exam_sessions as sessions', function ($join): void {
                    $join->on('sessions.id', '=', 'bindings.session_id')->on('sessions.tenant_id', '=', 'bindings.tenant_id');
                })
                ->join('exam_session_deliveries as deliveries', function ($join): void {
                    $join->on('deliveries.session_id', '=', 'bindings.session_id')->on('deliveries.tenant_id', '=', 'bindings.tenant_id');
                })
                ->join('quiz_attempts as attempts', function ($join): void {
                    $join->on('attempts.id', '=', 'bindings.attempt_id')->on('attempts.tenant_id', '=', 'bindings.tenant_id');
                })
                ->where('bindings.tenant_id', $tenantId)
                ->where('bindings.session_id', (int) ($payload['session_id'] ?? 0))
                ->where('bindings.attempt_id', (int) ($payload['attempt_id'] ?? 0))
                ->whereNotNull('bindings.submitted_at')
                ->whereColumn('deliveries.quiz_id', 'attempts.quiz_id')
                ->whereIn('attempts.status', ['graded', 'needs_manual_review'])
                ->first(['sessions.campus_id', 'deliveries.quiz_id']);
            if ($binding === null) {
                return [];
            }

            $sectionIds = DB::table('quiz_sections')->where('tenant_id', $tenantId)->where('quiz_id', $binding->quiz_id)->pluck('section_id');

            return DB::table('course_section_instructors as instructors')
                ->join('tenant_memberships as memberships', function ($join) use ($tenantId): void {
                    $join->on('memberships.user_id', '=', 'instructors.user_id')->where('memberships.tenant_id', '=', $tenantId);
                })
                ->where('instructors.tenant_id', $tenantId)->whereIn('instructors.section_id', $sectionIds)
                ->where('memberships.status', 'active')->whereNull('memberships.revoked_at')
                ->where(function ($query) use ($binding): void {
                    $query->whereNull('memberships.campus_id')->when($binding->campus_id !== null, fn ($scoped) => $scoped->orWhere('memberships.campus_id', (int) $binding->campus_id));
                })
                ->distinct()->pluck('instructors.user_id')->map(static fn ($id): int => (int) $id)->all();
        }
        if ($type === 'quiz.regrade.requested') {
            $attempt = QuizAttempt::query()->with('quiz')->find((int) ($payload['attempt_id'] ?? 0));
            if (! $attempt) {
                return [];
            }
            $sectionIds = DB::table('quiz_sections')->where('tenant_id', $tenantId)->where('quiz_id', $attempt->quiz_id)->pluck('section_id');

            return DB::table('course_section_instructors')->where('tenant_id', $tenantId)->whereIn('section_id', $sectionIds)->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        }
        if (in_array($type, ['quiz.manual_grade.completed', 'quiz.regrade.decided'], true)) {
            $membershipId = QuizAttempt::query()->whereKey((int) ($payload['attempt_id'] ?? 0))->value('membership_id');

            if ($membershipId === null) {
                return [];
            }
            $userId = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('id', $membershipId)->value('user_id');

            return is_numeric($userId) && (int) $userId > 0 ? [(int) $userId] : [];
        }
        if ($type === 'quiz.result_released') {
            $attempt = QuizAttempt::query()->find((int) ($payload['attempt_id'] ?? 0));
            if (! $attempt) {
                return [];
            }

            return [(int) DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('id', $attempt->membership_id)->value('user_id')];
        }
        if ($type === 'exam.result_released') {
            $result = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', (int) ($payload['result_id'] ?? 0))->where('status', 'released')->first(['candidate_id']);
            if (! $result) {
                return [];
            }
            $userId = DB::table('exam_candidates')->join('sis_students', function ($join): void {
                $join->on('sis_students.id', '=', 'exam_candidates.student_id')->on('sis_students.tenant_id', '=', 'exam_candidates.tenant_id');
            })->where('exam_candidates.tenant_id', $tenantId)->where('exam_candidates.id', $result->candidate_id)->where('sis_students.status', 'active')->value('sis_students.user_id');
            if (! is_numeric($userId) || (int) $userId < 1) {
                return [];
            }

            return DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $userId] : [];
        }
        if (in_array($type, ['exam.date_sheet_published', 'exam.schedule_changed'], true)) {
            $candidate = DB::table('exam_candidates as candidates')
                ->join('exam_date_sheet_revisions as revisions', 'revisions.tenant_id', '=', 'candidates.tenant_id')
                ->join('exam_date_sheets as sheets', function ($join): void {
                    $join->on('sheets.id', '=', 'revisions.date_sheet_id')->on('sheets.tenant_id', '=', 'revisions.tenant_id');
                })
                ->join('sis_students as students', function ($join): void {
                    $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
                })
                ->where('candidates.tenant_id', $tenantId)->where('candidates.id', (int) ($payload['candidate_id'] ?? 0))
                ->whereColumn('sheets.cycle_id', 'candidates.cycle_id')
                ->where('revisions.id', (int) ($payload['revision_id'] ?? 0))->where('revisions.status', 'published')
                ->whereColumn('sheets.current_revision', 'revisions.revision')
                ->where('candidates.status', 'registered')->whereNull('candidates.withdrawn_at')->where('students.status', 'active')
                ->first(['students.user_id', 'students.id as student_id', 'candidates.cycle_id', 'revisions.schedule_snapshot']);
            if ($candidate === null || ! is_numeric($candidate->user_id) || (int) $candidate->user_id < 1) {
                return [];
            }

            $schedule = json_decode((string) $candidate->schedule_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $publishedSessionIds = is_array($schedule) ? array_filter(array_map(
                static fn ($item): int => is_array($item) && is_numeric($item['session_id'] ?? null) ? (int) $item['session_id'] : 0,
                $schedule,
            )) : [];
            $requestedSessionIds = is_array($payload['session_ids'] ?? null) ? array_map('intval', $payload['session_ids']) : [];
            $sessionIncluded = $requestedSessionIds !== [] && ($type === 'exam.schedule_changed'
                ? DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('cycle_id', $candidate->cycle_id)->whereIn('id', $requestedSessionIds)->count() === count(array_unique($requestedSessionIds))
                : array_diff($requestedSessionIds, $publishedSessionIds) === []);
            $userId = (int) $candidate->user_id;

            return $sessionIncluded && DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [$userId] : [];
        }
        if (in_array($type, ['exam.retake_registered', 'exam.makeup_registered'], true)) {
            $candidate = DB::table('exam_candidates as candidates')->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
            })->join('exam_cycles as cycles', function ($join): void {
                $join->on('cycles.id', '=', 'candidates.cycle_id')->on('cycles.tenant_id', '=', 'candidates.tenant_id');
            })->where('candidates.tenant_id', $tenantId)->where('candidates.id', (int) ($payload['candidate_id'] ?? 0))
                ->where('candidates.cycle_id', (int) ($payload['cycle_id'] ?? 0))->where('candidates.status', 'registered')->whereNull('candidates.withdrawn_at')
                ->where('students.status', 'active')->where('cycles.category', $type === 'exam.retake_registered' ? 'retake' : 'makeup')
                ->whereNotNull('cycles.source_cycle_id')->first(['students.user_id']);
            if ($candidate === null || ! is_numeric($candidate->user_id) || (int) $candidate->user_id < 1) {
                return [];
            }

            $userId = (int) $candidate->user_id;

            return DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [$userId] : [];
        }
        if (in_array($type, ['exam.recheck_requested', 'exam.appeal_requested'], true)) {
            $recheck = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', (int) ($payload['recheck_id'] ?? 0))->first(['result_id']);
            if (! $recheck) {
                return [];
            }
            $sessionId = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $recheck->result_id)->value('session_id');
            if ($sessionId === null) {
                return [];
            }

            return $this->permissionRecipients($tenantId, (int) $sessionId, 'exams.recheck.review');
        }
        if (in_array($type, ['exam.recheck_decided', 'exam.appeal_decided'], true)) {
            $userId = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', (int) ($payload['recheck_id'] ?? 0))->value('student_user_id');
            if (! is_numeric($userId) || (int) $userId < 1) {
                return [];
            }

            return DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $userId] : [];
        }
        if (in_array($type, ['exam.appeal_escalated', 'exam.appeal_closed', 'exam.appeal_reopened'], true)) {
            $appeal = DB::table('exam_recheck_requests')->where('tenant_id', $tenantId)->where('id', (int) ($payload['recheck_id'] ?? 0))->first(['student_user_id', 'result_id']);
            if ($appeal === null) {
                return [];
            }
            if ($type === 'exam.appeal_escalated') {
                $sessionId = DB::table('exam_results')->where('tenant_id', $tenantId)->where('id', $appeal->result_id)->value('session_id');

                return $sessionId === null ? [] : $this->permissionRecipients($tenantId, (int) $sessionId, 'exams.recheck.review');
            }

            return DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $appeal->student_user_id)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $appeal->student_user_id] : [];
        }
        if (in_array($type, ['exam.accommodation.requested', 'exam.eligibility_exception.requested'], true)) {
            $accommodation = $type === 'exam.accommodation.requested';
            $query = DB::table($accommodation ? 'exam_accommodation_requests as requests' : 'exam_eligibility_exceptions as requests')
                ->where('requests.tenant_id', $tenantId)->where('requests.id', (int) ($payload[$accommodation ? 'request_id' : 'exception_id'] ?? 0));
            if ($accommodation) {
                $query->join('exam_candidates as candidates', function ($join): void {
                    $join->on('candidates.id', '=', 'requests.candidate_id')->on('candidates.tenant_id', '=', 'requests.tenant_id');
                });
                $row = $query->first(['candidates.cycle_id']);
            } else {
                $row = $query->first(['requests.cycle_id']);
            }
            if (! $row) {
                return [];
            }
            $campusId = DB::table('exam_cycles')->where('tenant_id', $tenantId)->where('id', $row->cycle_id)->value('campus_id');

            return $this->permissionRecipientsForScope($tenantId, $campusId === null ? null : (int) $campusId, $accommodation ? 'exams.accommodation.manage' : 'exams.eligibility.manage');
        }
        if (in_array($type, ['exam.accommodation.decided', 'exam.eligibility_exception.decided'], true)) {
            $accommodation = $type === 'exam.accommodation.decided';
            $userId = DB::table($accommodation ? 'exam_accommodation_requests' : 'exam_eligibility_exceptions')->where('tenant_id', $tenantId)
                ->where('id', (int) ($payload[$accommodation ? 'request_id' : 'exception_id'] ?? 0))->value('requested_by');

            return is_numeric($userId) && DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $userId] : [];
        }
        if ($type === 'exam.admit_document.issued') {
            $userId = DB::table('exam_admit_documents as docs')->join('exam_candidates as candidates', function ($join): void {
                $join->on('candidates.id', '=', 'docs.candidate_id')->on('candidates.tenant_id', '=', 'docs.tenant_id');
            })->join('sis_students as students', function ($join): void {
                $join->on('students.id', '=', 'candidates.student_id')->on('students.tenant_id', '=', 'candidates.tenant_id');
            })->where('docs.tenant_id', $tenantId)->where('docs.id', (int) ($payload['document_id'] ?? 0))->whereNull('docs.revoked_at')->where('students.status', 'active')->value('students.user_id');

            return is_numeric($userId) && DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $userId] : [];
        }
        if ($type === 'exam.invigilator_assigned') {
            $userId = DB::table('exam_invigilator_assignments as assignments')
                ->join('exam_sessions as sessions', function ($join): void {
                    $join->on('sessions.id', '=', 'assignments.session_id')->on('sessions.tenant_id', '=', 'assignments.tenant_id');
                })
                ->where('assignments.tenant_id', $tenantId)
                ->where('assignments.id', (int) ($payload['assignment_id'] ?? 0))
                ->where('assignments.status', 'assigned')
                ->value('assignments.user_id');

            return is_numeric($userId) && DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $userId] : [];
        }
        if (in_array($type, ['exam.invigilator_duty_accept', 'exam.invigilator_duty_decline'], true)) {
            $assignment = DB::table('exam_invigilator_assignments')->where('tenant_id', $tenantId)
                ->where('id', (int) ($payload['assignment_id'] ?? 0))->first(['session_id', 'status', 'accepted_at']);
            if ($assignment === null || ($type === 'exam.invigilator_duty_accept' && $assignment->accepted_at === null) || ($type === 'exam.invigilator_duty_decline' && $assignment->status !== 'declined')) {
                return [];
            }

            return $this->permissionRecipients($tenantId, (int) $assignment->session_id, 'exams.invigilators.manage');
        }
        if ($type === 'exam.incident.reported') {
            $incident = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', (int) ($payload['incident_id'] ?? 0))->first(['session_id']);
            if ($incident === null) {
                return [];
            }

            return $this->permissionRecipients($tenantId, (int) $incident->session_id, 'exams.incidents.review');
        }
        if ($type === 'exam.incident.reviewed') {
            $userId = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', (int) ($payload['incident_id'] ?? 0))->value('reported_by');

            return is_numeric($userId) && DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', (int) $userId)->where('status', 'active')->whereNull('revoked_at')->exists() ? [(int) $userId] : [];
        }
        if ($type === 'exam.incident.updated') {
            $incident = DB::table('exam_incidents')->where('tenant_id', $tenantId)->where('id', (int) ($payload['incident_id'] ?? 0))->first(['session_id', 'reported_by', 'assigned_to']);
            if ($incident === null) {
                return [];
            }
            $recipients = array_filter([(int) $incident->reported_by, $incident->assigned_to === null ? null : (int) $incident->assigned_to]);
            $active = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->whereIn('user_id', array_unique($recipients))->where('status', 'active')->whereNull('revoked_at')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();

            return array_values(array_unique([...$active, ...$this->permissionRecipients($tenantId, (int) $incident->session_id, 'exams.incidents.review')]));
        }

        return null;
    }

    /** @return list<int> */
    private function permissionRecipients(int $tenantId, int $sessionId, string $permission): array
    {
        $campusId = DB::table('exam_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->value('campus_id');
        if ($campusId === null) {
            return [];
        }

        return $this->permissionRecipientsForScope($tenantId, (int) $campusId, $permission);
    }

    /** @return list<int> */
    private function permissionRecipientsForScope(int $tenantId, ?int $campusId, string $permission): array
    {
        return DB::table('tenant_memberships')->join('membership_role', function ($join): void {
            $join->on('membership_role.tenant_id', '=', 'tenant_memberships.tenant_id')->on('membership_role.membership_id', '=', 'tenant_memberships.id');
        })->join('permission_role', 'permission_role.role_id', '=', 'membership_role.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('tenant_memberships.tenant_id', $tenantId)->where('tenant_memberships.status', 'active')->whereNull('tenant_memberships.revoked_at')->where(function ($query) use ($campusId): void {
            $query->whereNull('tenant_memberships.campus_id')->when($campusId !== null, fn ($scoped) => $scoped->orWhere('tenant_memberships.campus_id', $campusId));
        })->where('permissions.key', $permission)->distinct()->pluck('tenant_memberships.user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $studentUserIds
     * @param  list<int>  $guardianUserIds
     * @return list<int>
     */
    private function academicScopedRecipients(int $tenantId, int $institutionId, array $studentUserIds, array $guardianUserIds, ?int $campusId): array
    {
        return DB::table('tenant_memberships as memberships')
            ->join('membership_role as mr', function ($join): void {
                $join->on('mr.tenant_id', '=', 'memberships.tenant_id')->on('mr.membership_id', '=', 'memberships.id');
            })->join('permission_role as pr', 'pr.role_id', '=', 'mr.role_id')
            ->join('permissions', 'permissions.id', '=', 'pr.permission_id')
            ->where('memberships.tenant_id', $tenantId)->where('memberships.institution_id', $institutionId)
            ->where('memberships.status', 'active')->whereNull('memberships.revoked_at')
            ->where(function ($query) use ($studentUserIds, $guardianUserIds): void {
                if ($studentUserIds !== []) {
                    $query->where(fn ($students) => $students->whereIn('memberships.user_id', $studentUserIds)->whereIn('permissions.key', ['academic.grades.view-own', 'academic.records.view-own']));
                }
                if ($guardianUserIds !== []) {
                    $query->orWhere(fn ($guardians) => $guardians->whereIn('memberships.user_id', $guardianUserIds)->where('permissions.key', 'academic.records.guardian.view'));
                }
            })->where(function ($query) use ($campusId): void {
                $query->whereNull('memberships.campus_id')->when($campusId !== null, fn ($scoped) => $scoped->orWhere('memberships.campus_id', $campusId));
            })->distinct()->pluck('memberships.user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function academicPermissionRecipients(int $tenantId, int $institutionId, ?int $campusId, string $permission): array
    {
        return DB::table('tenant_memberships as memberships')
            ->join('membership_role as mr', function ($join): void {
                $join->on('mr.tenant_id', '=', 'memberships.tenant_id')->on('mr.membership_id', '=', 'memberships.id');
            })->join('permission_role as pr', 'pr.role_id', '=', 'mr.role_id')
            ->join('permissions', 'permissions.id', '=', 'pr.permission_id')
            ->where('memberships.tenant_id', $tenantId)->where('memberships.institution_id', $institutionId)
            ->where('memberships.status', 'active')->whereNull('memberships.revoked_at')->where('permissions.key', $permission)
            ->where(function ($query) use ($campusId): void {
                $query->whereNull('memberships.campus_id')->when($campusId !== null, fn ($scope) => $scope->orWhere('memberships.campus_id', $campusId));
            })->distinct()->pluck('memberships.user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @param list<int> $userIds
     * @return list<int>
     */
    private function activeScopedUsers(int $tenantId, int $institutionId, ?int $campusId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('institution_id', $institutionId)
            ->whereIn('user_id', $userIds)->where('status', 'active')->whereNull('revoked_at')
            ->where(function ($query) use ($campusId): void {
                $query->whereNull('campus_id')->when($campusId !== null, fn ($scope) => $scope->orWhere('campus_id', $campusId));
            })->distinct()->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @return array{string,string} */
    private function copy(string $type): array
    {
        return match ($type) {
            'academic.record.issued' => ['Academic record issued', 'An approved academic record is available in your institution workspace.'],
            'academic.record.revoked' => ['Academic record status changed', 'An academic record has been revoked. Review the institution workspace or contact the registrar.'],
            'academic.grade.published' => ['Official grades published', 'New official academic results are available in your institution workspace.'],
            'academic.gradebook.submitted' => ['Grades ready for review', 'A scoped academic gradebook was submitted for independent review.'],
            'academic.gradebook.reviewed' => ['Gradebook review recorded', 'An authorized reviewer recorded a decision on your submitted gradebook.'],
            'academic.grade_override.requested' => ['Grade correction ready for review', 'A learner grade correction was submitted for authorized review.'],
            'academic.grade_override.decided' => ['Grade correction decision recorded', 'An authorized reviewer recorded a decision on your grade correction request.'],
            'academic.record.submitted' => ['Academic record ready for review', 'An official academic record was submitted for independent review.'],
            'academic.record.decided' => ['Academic record review recorded', 'An authorized operator recorded a decision on your official academic record.'],
            'academic.report_card_comment.submitted' => ['Report-card comment ready for review', 'A teacher submitted a report-card comment for independent review.'],
            'academic.report_card_comment.decided' => ['Report-card comment review recorded', 'An authorized reviewer recorded a decision on your comment.'],
            'quiz.published' => ['New quiz available', 'A new assessment is available in one of your enrolled course sections.'],
            'quiz.result_released' => ['Quiz result released', 'A quiz result is ready to view in your assessment workspace.'],
            'quiz.regrade.requested' => ['Regrade request received', 'A learner requested an authorized review of a released quiz result.'],
            'quiz.regrade.decided' => ['Regrade request decided', 'A reviewer has recorded a decision on your quiz regrade request.'],
            'quiz.manual_grade.completed' => ['Quiz marking complete', 'Your quiz response has been reviewed and the instructor can release the result.'],
            'exam.attempt_submitted' => ['Examination attempt submitted', 'A candidate submitted an online examination attempt for authorized review.'],
            'exam.attempt_expired' => ['Examination attempt expired', 'A candidate reached the time limit for an online examination attempt.'],
            'exam.result_released' => ['Examination result released', 'An examination result is ready to view in your examination workspace.'],
            'exam.date_sheet_published' => ['Examination date sheet published', 'An approved examination schedule has been published. Review the current schedule in your examination workspace.'],
            'exam.schedule_changed' => ['Examination schedule changed', 'An approved examination schedule revision changed one or more of your examination dates. Review the current schedule in your examination workspace.'],
            'exam.retake_registered' => ['Retake examination registration', 'You have been registered for an examination retake. Review its schedule in your examination workspace.'],
            'exam.makeup_registered' => ['Makeup examination registration', 'You have been registered for a makeup examination. Review its schedule in your examination workspace.'],
            'exam.recheck_requested' => ['Examination recheck requested', 'A learner requested an authorized review of an examination result.'],
            'exam.recheck_decided' => ['Examination recheck decided', 'An authorized reviewer has recorded a decision on your examination recheck request.'],
            'exam.appeal_requested' => ['Examination appeal received', 'Your examination appeal was received for independent review.'],
            'exam.appeal_decided' => ['Examination appeal decision recorded', 'An authorized reviewer has recorded a decision on your examination appeal.'],
            'exam.appeal_escalated' => ['Examination appeal escalated', 'An examination appeal was escalated for authorized senior review.'],
            'exam.appeal_closed' => ['Examination appeal closed', 'Your examination appeal has been closed following its recorded decision.'],
            'exam.appeal_reopened' => ['Examination appeal reopened', 'Your examination appeal has been reopened within the permitted review period.'],
            'exam.accommodation.requested' => ['Accommodation request received', 'A confidential examination accommodation request is awaiting authorized review.'],
            'exam.accommodation.decided' => ['Accommodation request decided', 'An authorized reviewer has updated your confidential examination accommodation request.'],
            'exam.eligibility_exception.requested' => ['Eligibility exception received', 'An examination eligibility exception is awaiting independent review.'],
            'exam.eligibility_exception.decided' => ['Eligibility exception decided', 'An authorized reviewer has recorded a decision on your examination eligibility request.'],
            'exam.admit_document.issued' => ['Examination admit document issued', 'An examination admit document is available in your examination workspace.'],
            'exam.invigilator_assigned' => ['Examination duty assigned', 'You have been assigned an examination duty. Review the session and respond in your examination workspace.'],
            'exam.invigilator_duty_accept' => ['Examination duty accepted', 'An assigned invigilator accepted an examination duty. Review the roster in your examination workspace.'],
            'exam.invigilator_duty_decline' => ['Examination duty declined', 'An assigned invigilator declined an examination duty. Review the roster and arrange coverage in your examination workspace.'],
            'exam.incident.reported' => ['Examination incident requires review', 'A reported examination incident is awaiting authorized review.'],
            'exam.incident.reviewed' => ['Examination incident reviewed', 'An authorized reviewer has recorded a decision on your examination incident report.'],
            'exam.incident.updated' => ['Examination incident updated', 'An authorized examination officer updated the incident lifecycle. Review the restricted incident workspace for details.'],
            default => ['Quiz submitted', 'A learner submitted a quiz attempt for review.'],
        };
    }
}

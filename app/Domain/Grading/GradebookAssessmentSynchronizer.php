<?php

namespace App\Domain\Grading;

use App\Domain\Academic\AcademicAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Tenancy\TenantContext;
use App\Models\AcademicGradebook;
use App\Models\AcademicGradeEntry;
use App\Models\AcademicGradeSourceImport;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Imports only approved, released assessment results into a draft gradebook. */
final class GradebookAssessmentSynchronizer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AcademicAccess $academicAccess,
        private readonly GradebookWorkflow $gradebooks,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{imported:int,updated:int,unchanged:int,unmatched:int} */
    public function synchronize(int $gradebookId, int $itemId, User $actor): array
    {
        $membership = $this->context->membership();
        abort_unless($membership->hasPermission('academic.grades.book.manage'), 403);

        return DB::transaction(function () use ($gradebookId, $itemId, $actor, $membership): array {
            $book = AcademicGradebook::query()->with('section.offering')->lockForUpdate()->findOrFail($gradebookId);
            $this->gradebooks->assertSectionAccess($book->section, $actor);
            abort_unless((int) $book->tenant_id === (int) $membership->tenant_id
                && (int) $book->institution_id === (int) $membership->institution_id, 404);
            abort_unless($book->status === 'draft', 409, 'Assessment results can only be synchronized into a draft gradebook.');

            $item = $book->items()->lockForUpdate()->findOrFail($itemId);
            abort_unless(in_array($item->source_type, ['assignment', 'quiz', 'examination'], true)
                && $item->source_key !== null && ctype_digit((string) $item->source_key), 409,
                'Choose a supported assessment source and source record before synchronization.');

            $rows = match ($item->source_type) {
                'assignment' => $this->assignmentRows($book, (int) $item->source_key),
                'quiz' => $this->quizRows($book, (int) $item->source_key),
                'examination' => $this->examinationRows($book, (int) $item->source_key),
            };
            $enrollments = CourseEnrollment::query()->where('tenant_id', $membership->tenant_id)
                ->where('section_id', $book->section_id)->where('status', 'confirmed')->lockForUpdate()->get()->keyBy('student_id');
            $existingImports = AcademicGradeSourceImport::query()->where('tenant_id', $membership->tenant_id)
                ->where('item_id', $item->getKey())->lockForUpdate()->get()->keyBy('enrollment_id');
            $resolvedEnrollmentIds = [];
            $counts = ['imported' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0];

            foreach ($rows as $row) {
                $enrollment = $enrollments->get((int) $row['student_id']);
                if ($enrollment === null) {
                    $counts['unmatched']++;

                    continue;
                }
                $enrollmentId = (int) $enrollment->getKey();
                $resolvedEnrollmentIds[] = $enrollmentId;
                $sourcePoints = $this->scalePoints((string) $row['score'], (string) $row['maximum'], (string) $item->maximum_points);
                $fingerprint = hash('sha256', json_encode([
                    'type' => $item->source_type,
                    'source_record_id' => (int) $row['source_record_id'],
                    'source_version' => (string) $row['source_version'],
                    'score' => (string) $row['score'],
                    'maximum' => (string) $row['maximum'],
                    'scaled_points' => $sourcePoints,
                ], JSON_THROW_ON_ERROR));
                $import = $existingImports->get($enrollmentId);
                $entry = AcademicGradeEntry::query()->where('tenant_id', $membership->tenant_id)
                    ->where('item_id', $item->getKey())->where('enrollment_id', $enrollmentId)->lockForUpdate()->first();

                if ($import !== null && ($entry === null || $entry->entry_status !== 'graded'
                    || $this->decimalToCents((string) $entry->earned_points) !== $this->decimalToCents((string) $import->imported_points))) {
                    throw new ConflictHttpException('A grade changed after its last source import. Review the entry before importing an amendment.');
                }
                if ($import !== null && hash_equals((string) $import->source_fingerprint, $fingerprint)) {
                    $counts['unchanged']++;

                    continue;
                }
                if ($import === null && $entry !== null && ($entry->entry_status !== 'missing' || $entry->earned_points !== null)) {
                    throw new ConflictHttpException('A manually entered grade exists. Source synchronization will not overwrite it.');
                }

                $previousPoints = $entry?->earned_points;
                $previousStatus = $entry?->entry_status;
                $revisionNumber = $entry === null ? 1 : (int) $entry->lock_version + 1;
                $entry ??= new AcademicGradeEntry(['gradebook_id' => $book->getKey(), 'item_id' => $item->getKey(), 'enrollment_id' => $enrollmentId]);
                $entry->forceFill(['earned_points' => $sourcePoints, 'entry_status' => 'graded', 'lock_version' => $revisionNumber, 'updated_by' => $actor->getKey()])->save();

                if ($import === null) {
                    $import = AcademicGradeSourceImport::query()->create([
                        'gradebook_id' => $book->getKey(), 'item_id' => $item->getKey(), 'enrollment_id' => $enrollmentId,
                        'source_type' => $item->source_type, 'source_record_id' => $row['source_record_id'],
                        'source_version' => (string) $row['source_version'], 'source_fingerprint' => $fingerprint,
                        'imported_points' => $sourcePoints, 'imported_by' => $actor->getKey(), 'imported_at' => now(),
                    ]);
                }
                $importRevision = (int) $import->revisions()->max('revision_number') + 1;
                $import->revisions()->create([
                    'revision_number' => $importRevision,
                    'source_record_id' => (int) $row['source_record_id'],
                    'source_version' => (string) $row['source_version'],
                    'source_fingerprint' => $fingerprint,
                    'previous_points' => $importRevision === 1 ? null : $import->imported_points,
                    'imported_points' => $sourcePoints,
                    'actor_id' => $actor->getKey(),
                    'imported_at' => now(),
                ]);
                if ($importRevision > 1) {
                    $import->forceFill([
                        'source_record_id' => (int) $row['source_record_id'],
                        'source_version' => (string) $row['source_version'], 'source_fingerprint' => $fingerprint,
                        'imported_points' => $sourcePoints, 'imported_by' => $actor->getKey(), 'imported_at' => now(),
                    ])->save();
                }
                $entry->revisions()->create([
                    'revision_number' => $revisionNumber, 'previous_points' => $previousPoints,
                    'new_points' => $sourcePoints, 'previous_status' => $previousStatus,
                    'new_status' => 'graded', 'reason' => 'Assessment source synchronization: '.$item->source_type.' #'.$row['source_record_id'].' version '.$row['source_version'],
                    'actor_id' => $actor->getKey(),
                ]);
                $this->audit->record('academic.grade_source.imported', $actor, (int) $membership->tenant_id, $import, [
                    'gradebook_id' => (int) $book->getKey(), 'item_id' => (int) $item->getKey(),
                    'source_type' => $item->source_type, 'source_record_id' => (int) $row['source_record_id'],
                    'source_version' => (string) $row['source_version'], 'entry_revision' => $revisionNumber,
                ]);
                $counts[$importRevision === 1 ? 'imported' : 'updated']++;
            }

            $resolved = array_fill_keys($resolvedEnrollmentIds, true);
            foreach ($existingImports as $existing) {
                if (! isset($resolved[(int) $existing->enrollment_id])) {
                    throw new ConflictHttpException('A previously imported source result is no longer released or no longer matches this section. No import changes were committed.');
                }
            }
            $this->audit->record('academic.grade_source.synchronized', $actor, (int) $membership->tenant_id, $item, [
                'gradebook_id' => (int) $book->getKey(), 'source_type' => $item->source_type,
                'imported' => $counts['imported'], 'updated' => $counts['updated'],
                'unchanged' => $counts['unchanged'], 'unmatched' => $counts['unmatched'],
            ]);

            return $counts;
        }, 3);
    }

    /** @return list<array{student_id:int,source_record_id:int,source_version:string,score:string,maximum:string}> */
    private function assignmentRows(AcademicGradebook $book, int $assignmentId): array
    {
        $assignment = DB::table('assignments')->where('tenant_id', $book->tenant_id)->where('id', $assignmentId)->lockForUpdate()->first();
        abort_unless($assignment !== null && in_array($assignment->status, ['published', 'closed', 'archived'], true), 404);
        $section = $book->section;
        abort_unless((int) $assignment->institution_id === (int) $book->institution_id
            && (int) $assignment->offering_id === (int) $section->offering_id, 404);
        abort_unless(DB::table('assignment_versions')->where('tenant_id', $book->tenant_id)
            ->where('assignment_id', $assignmentId)->where('version_number', $assignment->current_version)
            ->where('status', 'published')->exists(), 409,
            'The assignment has no currently published immutable source version.');
        abort_unless(($assignment->collaboration_mode ?? 'individual') === 'individual', 409,
            'Group assignment grades require a verified group-to-enrollment score allocation and cannot be imported as individual grades.');
        $rows = DB::table('assignment_grades as grade')
            ->join('assignment_attempts as attempt', function ($join): void {
                $join->on('attempt.id', '=', 'grade.attempt_id')->on('attempt.tenant_id', '=', 'grade.tenant_id');
            })
            ->join('assignment_submissions as submission', function ($join): void {
                $join->on('submission.id', '=', 'attempt.submission_id')->on('submission.tenant_id', '=', 'attempt.tenant_id');
            })
            ->join('assignment_recipients as recipient', function ($join): void {
                $join->on('recipient.id', '=', 'submission.recipient_id')->on('recipient.tenant_id', '=', 'submission.tenant_id');
            })
            ->where('grade.tenant_id', $book->tenant_id)->where('recipient.assignment_id', $assignmentId)
            ->where('recipient.section_id', $section->getKey())->where('recipient.status', 'eligible')
            ->where('grade.status', 'released')->whereNotNull('grade.released_at')->whereNotNull('grade.score_points')
            ->orderBy('recipient.student_id')->orderByDesc('attempt.attempt_number')->orderByDesc('grade.released_at')
            ->lockForUpdate()->get(['recipient.student_id', 'attempt.id as source_record_id', 'grade.lock_version as source_version', 'grade.score_points as score', 'grade.maximum_points as maximum']);
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $studentId = (int) $row->student_id;
            if (isset($seen[$studentId])) {
                continue;
            }
            if ($row->maximum === null || (float) $row->maximum <= 0) {
                throw new ConflictHttpException('A released assignment grade has no positive maximum score.');
            }
            $seen[$studentId] = true;
            $result[] = ['student_id' => $studentId, 'source_record_id' => (int) $row->source_record_id, 'source_version' => (string) $row->source_version, 'score' => (string) $row->score, 'maximum' => (string) $row->maximum];
        }

        return $result;
    }

    /** @return list<array{student_id:int,source_record_id:int,source_version:string,score:string,maximum:string}> */
    private function quizRows(AcademicGradebook $book, int $quizId): array
    {
        $quiz = DB::table('quizzes')->where('tenant_id', $book->tenant_id)->where('id', $quizId)->lockForUpdate()->first();
        abort_unless($quiz !== null && in_array($quiz->status, ['published', 'closed', 'archived'], true), 404);
        $section = $book->section;
        abort_unless((int) $quiz->institution_id === (int) $book->institution_id
            && (int) $quiz->offering_id === (int) $section->offering_id
            && DB::table('quiz_sections')->where('tenant_id', $book->tenant_id)->where('quiz_id', $quizId)->where('section_id', $section->getKey())->exists(), 404);
        $rows = DB::table('quiz_attempts as attempt')
            ->join('quiz_versions as version', function ($join): void {
                $join->on('version.id', '=', 'attempt.quiz_version_id')->on('version.tenant_id', '=', 'attempt.tenant_id');
            })
            ->where('attempt.tenant_id', $book->tenant_id)->where('attempt.quiz_id', $quizId)
            ->where('attempt.status', 'graded')->whereNotNull('attempt.result_released_at')
            ->where('version.status', 'published')->whereNotNull('attempt.score_points')->whereNotNull('attempt.maximum_points')
            ->orderBy('attempt.student_id')->lockForUpdate()
            ->get(['attempt.id as source_record_id', 'attempt.student_id', 'attempt.lock_version as source_version', 'attempt.score_points as score', 'attempt.maximum_points as maximum']);
        $byStudent = $rows->groupBy('student_id');
        foreach ($byStudent as $attempts) {
            if ($attempts->count() > 1) {
                throw new ConflictHttpException('More than one quiz attempt has a released result. Configure and approve a result-selection policy before synchronizing this quiz.');
            }
        }

        return $rows->map(fn ($row): array => ['student_id' => (int) $row->student_id, 'source_record_id' => (int) $row->source_record_id, 'source_version' => (string) $row->source_version, 'score' => (string) $row->score, 'maximum' => (string) $row->maximum])->all();
    }

    /** @return list<array{student_id:int,source_record_id:int,source_version:string,score:string,maximum:string}> */
    private function examinationRows(AcademicGradebook $book, int $sessionId): array
    {
        $section = $book->section;
        $session = DB::table('exam_sessions')->join('exam_cycles', function ($join): void {
            $join->on('exam_cycles.id', '=', 'exam_sessions.cycle_id')->on('exam_cycles.tenant_id', '=', 'exam_sessions.tenant_id');
        })->where('exam_sessions.tenant_id', $book->tenant_id)->where('exam_sessions.id', $sessionId)->lockForUpdate()
            ->first(['exam_sessions.id', 'exam_sessions.academic_course_id', 'exam_sessions.campus_id', 'exam_cycles.institution_id', 'exam_cycles.academic_period_id']);
        abort_unless($session !== null && (int) $session->institution_id === (int) $book->institution_id
            && (int) $session->academic_course_id === (int) $section->offering->academic_course_id
            && (int) $session->academic_period_id === (int) $book->academic_period_id
            && ($section->campus_id === null || (int) $section->campus_id === (int) $session->campus_id), 404);
        $this->academicAccess->assertCampus((int) $session->campus_id);
        $rows = DB::table('exam_results as result')
            ->join('exam_candidates as candidate', function ($join): void {
                $join->on('candidate.id', '=', 'result.candidate_id')->on('candidate.tenant_id', '=', 'result.tenant_id');
            })
            ->where('result.tenant_id', $book->tenant_id)->where('result.session_id', $sessionId)
            ->where('result.status', 'released')->whereNotNull('result.approved_by')->whereNotNull('result.approved_at')->whereNotNull('result.released_at')
            ->whereNotNull('result.total_marks')->where('result.maximum_marks', '>', 0)->lockForUpdate()
            ->get(['candidate.student_id', 'result.id as source_record_id', 'result.version as source_version', 'result.total_marks as score', 'result.maximum_marks as maximum']);

        return $rows->map(fn ($row): array => ['student_id' => (int) $row->student_id, 'source_record_id' => (int) $row->source_record_id, 'source_version' => (string) $row->source_version, 'score' => (string) $row->score, 'maximum' => (string) $row->maximum])->all();
    }

    private function scalePoints(string $earned, string $maximum, string $itemMaximum): string
    {
        $earnedCents = $this->decimalToCents($earned);
        $maximumCents = $this->decimalToCents($maximum);
        $targetCents = $this->decimalToCents($itemMaximum);
        if ($maximumCents < 1 || $earnedCents > $maximumCents) {
            throw new ConflictHttpException('A released source score is outside its maximum.');
        }

        return $this->formatCents(intdiv(($earnedCents * $targetCents) + intdiv($maximumCents, 2), $maximumCents));
    }

    private function decimalToCents(string $value): int
    {
        if (preg_match('/^(?:0|[1-9]\d{0,6})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new ConflictHttpException('A source or target score has an unsupported numeric representation.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}

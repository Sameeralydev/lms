<?php

namespace App\Domain\Assessments;

use App\Domain\Tenancy\TenantContext;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptResponse;
use App\Models\QuizNotification;
use App\Models\QuizRegradeRequest;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QuizAssessmentQueries
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return LengthAwarePaginator<int, QuizAttemptResponse> */
    public function gradingQueue(User $actor): LengthAwarePaginator
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('assessment.grade.manage'), 403);
        $sectionIds = $this->assignedSectionIds($membership, $actor);
        $quizIds = DB::table('quiz_sections')->where('tenant_id', $membership->tenant_id)->whereIn('section_id', $sectionIds)->pluck('quiz_id');
        $attempts = QuizAttempt::query()->whereIn('quiz_id', $quizIds)->where('status', 'needs_manual_review')->get(['id', 'status']);
        $attemptIds = $attempts->modelKeys();
        $manualReviewIds = $attempts->where('status', 'needs_manual_review')->modelKeys();

        return QuizAttemptResponse::query()->with([
            'attempt.student', 'attempt.quizVersion', 'questionVersion', 'gradingReviews',
        ])->whereIn('attempt_id', $attemptIds)->where(function ($query) use ($manualReviewIds): void {
            $query->where('grading_status', 'pending_review');
            if ($manualReviewIds !== []) {
                $query->orWhereIn('attempt_id', $manualReviewIds);
            }
        })
            ->orderBy('grading_status')->orderBy('created_at')->paginate(25)->withQueryString();
    }

    /** @return LengthAwarePaginator<int, QuizRegradeRequest> */
    public function regradeQueue(User $actor): LengthAwarePaginator
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('assessment.regrade.review'), 403);
        $sectionIds = $this->assignedSectionIds($membership, $actor);

        return QuizRegradeRequest::query()->with(['attempt.student', 'attempt.quizVersion'])
            ->where('status', 'open')
            ->whereHas('attempt.quiz', fn ($query) => $query->whereIn('id', DB::table('quiz_sections')->where('tenant_id', $membership->tenant_id)->whereIn('section_id', $sectionIds)->select('quiz_id')))
            ->orderBy('created_at')->paginate(25)->withQueryString();
    }

    /** @return array<string, mixed> */
    public function analytics(User $actor, ?int $quizId = null, ?int $sectionId = null): array
    {
        $membership = $this->membership();
        abort_unless($membership->hasPermission('assessment.analytics.view'), 403);
        $sectionIds = $this->assignedSectionIds($membership, $actor);
        if ($sectionId !== null && ! in_array($sectionId, $sectionIds, true)) {
            throw new NotFoundHttpException;
        }
        $quizQuery = Quiz::query()->whereIn('id', DB::table('quiz_sections')->where('tenant_id', $membership->tenant_id)->whereIn('section_id', $sectionIds)->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))->select('quiz_id'));
        if ($quizId !== null) {
            $quizQuery->whereKey($quizId);
        }
        $quizzes = $quizQuery->with('versions')->orderByDesc('updated_at')->limit(100)->get();
        if ($quizId !== null && $quizzes->isEmpty()) {
            throw new NotFoundHttpException;
        }
        $quizIds = $quizzes->modelKeys();
        $attempts = QuizAttempt::query()->whereIn('quiz_id', $quizIds)->get(['id', 'quiz_id', 'status', 'score_points', 'maximum_points', 'percentage', 'result_released_at', 'manual_review_pending']);
        $submitted = $attempts->whereIn('status', ['graded', 'needs_manual_review']);
        $releasedScores = $submitted->whereNotNull('result_released_at')->pluck('percentage')->filter(fn ($value): bool => $value !== null)->map(fn ($value): float => (float) $value)->sort()->values();
        $size = $releasedScores->count();
        $visible = $size >= 5;
        $median = null;
        $average = null;
        $distribution = [];
        if ($visible) {
            $average = round($releasedScores->avg(), 2);
            $middle = intdiv($size, 2);
            $median = round($size % 2 === 1 ? $releasedScores[$middle] : ($releasedScores[$middle - 1] + $releasedScores[$middle]) / 2, 2);
            $distribution = ['0-49' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-89' => 0, '90-100' => 0];
            foreach ($releasedScores as $score) {
                $bucket = $score < 50 ? '0-49' : ($score < 60 ? '50-59' : ($score < 70 ? '60-69' : ($score < 80 ? '70-79' : ($score < 90 ? '80-89' : '90-100'))));
                $distribution[$bucket]++;
            }
        }

        return [
            'filters' => ['quizId' => $quizId, 'sectionId' => $sectionId],
            'sections' => DB::table('course_sections')
                ->join('course_offerings', function ($join): void {
                    $join->on('course_offerings.id', '=', 'course_sections.offering_id')
                        ->on('course_offerings.tenant_id', '=', 'course_sections.tenant_id');
                })
                ->join('academic_courses', 'academic_courses.id', '=', 'course_offerings.academic_course_id')
                ->where('course_sections.tenant_id', $membership->tenant_id)
                ->whereIn('course_sections.id', $sectionIds)
                ->orderBy('academic_courses.title')
                ->orderBy('course_sections.section_code')
                ->get(['course_sections.id', 'course_sections.section_code', 'academic_courses.title'])
                ->map(static fn (object $section): array => [
                    'id' => (int) $section->id,
                    'label' => $section->title.' · '.$section->section_code,
                ])->values()->all(),
            'quizzes' => $quizzes->map(fn (Quiz $quiz): array => ['id' => (int) $quiz->getKey(), 'title' => $quiz->versions->first()->title, 'status' => $quiz->status])->values(),
            'metrics' => [
                'quizCount' => count($quizIds), 'attempts' => $attempts->count(),
                'submitted' => $submitted->count(), 'completionRate' => $attempts->count() > 0 ? round(($submitted->count() / $attempts->count()) * 100, 1) : null,
                'pendingManualReview' => $attempts->where('manual_review_pending', true)->count(),
                'releasedCohortSize' => $visible ? $size : null, 'suppressedForSmallCohort' => $size > 0 && ! $visible,
                'averagePercentage' => $average, 'medianPercentage' => $median, 'scoreDistribution' => $distribution,
            ],
        ];
    }

    /** @return LengthAwarePaginator<int, QuizNotification> */
    public function notifications(User $actor): LengthAwarePaginator
    {
        $membership = $this->membership();
        abort_unless($membership->user_id === $actor->getKey(), 403);

        return QuizNotification::query()->where('user_id', $actor->getKey())->orderByDesc('created_at')->paginate(30);
    }

    public function markNotificationRead(int $notificationId, User $actor): void
    {
        $notification = QuizNotification::query()->where('user_id', $actor->getKey())->findOrFail($notificationId);
        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();
    }

    /** @return list<int> */
    private function assignedSectionIds(TenantMembership $membership, User $actor): array
    {
        $query = DB::table('course_sections')
            ->join('course_offerings', function ($join): void {
                $join->on('course_offerings.id', '=', 'course_sections.offering_id')
                    ->on('course_offerings.tenant_id', '=', 'course_sections.tenant_id');
            })
            ->where('course_sections.tenant_id', $membership->tenant_id)
            ->where('course_offerings.institution_id', $membership->institution_id);
        if ($membership->campus_id !== null) {
            $query->where('course_sections.campus_id', $membership->campus_id);
        }
        if (! $membership->hasPermission('assessment.quiz.manage-all')) {
            $query->whereIn('course_sections.id', DB::table('course_section_instructors')->where('tenant_id', $membership->tenant_id)->where('user_id', $actor->getKey())->select('section_id'));
        }

        return $query->pluck('course_sections.id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function membership(): TenantMembership
    {
        abort_unless($this->context->hasMembership(), 403);

        return $this->context->membership();
    }
}

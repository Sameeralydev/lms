# Phase 12 Frontend and Route Inventory

Command php artisan route:list --path=assessments --except-vendor showed 30 routes, including one admissions assessment route. Assessment workspace routes use Workspace AssessmentController.

Route families: overview/analytics; attempt read, answer update, review mark, start and submit; question-bank/question create/update/review; quiz create/review/publish/close/archive; pool create; accommodation update; response grading/result release; regrade request/decision; notification/read state; policy update.

React pages: resources/js/pages/workspace/assessments/{index,attempt,grading,regrades,analytics,notifications}.tsx

Inventory does not prove all required role screens; not every route/role was browser-tested.

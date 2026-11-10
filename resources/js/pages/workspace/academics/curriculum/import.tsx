import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type PreviewRow = {
    row: number;
    code: string | null;
    parent_code: string | null;
    type: string;
    title: string;
    description: string | null;
    is_required: boolean;
    estimated_minutes: number | null;
    workload_unit: string;
    planned_starts_on: string | null;
    planned_ends_on: string | null;
};
type Preview = {
    format: string;
    rows: PreviewRow[];
    errors: { row: number; errors: string[] }[];
    valid_count: number;
    total_count: number;
};
type Props = {
    curriculum: { id: number; code: string; title: string };
    version: { id: number; version_number: number; status: string; lock_version: number };
    preview: Preview;
    previewChecksum: string | null;
    templateUrl: string;
    jsonTemplateUrl: string;
    previewUrl: string;
    confirmUrl: string;
    errorReportUrl: string;
    importBatch: {
        id: number;
        status: 'queued' | 'processing' | 'completed' | 'failed';
        total_count: number;
        processed_count: number;
        imported_count: number;
        attempt_count: number;
        manual_retry_count: number;
        failure_code: string | null;
    } | null;
    retryUrl: string | null;
    backUrl: string;
};

const fieldClass = 'border-border bg-surface min-h-11 w-full rounded-lg border px-3 text-sm';
const cardClass = 'border-border bg-surface min-w-0 rounded-2xl border p-5 shadow-sm';

export default function CurriculumImport({
    curriculum,
    version,
    preview,
    previewChecksum,
    templateUrl,
    jsonTemplateUrl,
    previewUrl,
    confirmUrl,
    errorReportUrl,
    importBatch,
    retryUrl,
    backUrl,
}: Props) {
    const form = useForm<{ file: File | null; version_lock: number; preview_checksum: string }>({
        file: null,
        version_lock: version.lock_version,
        preview_checksum: previewChecksum ?? '',
    });
    const hasPreview = preview.total_count > 0 && previewChecksum !== null;
    const canConfirm =
        hasPreview && preview.errors.length === 0 && preview.valid_count > 0 && form.data.file !== null;
    const importBatchId = importBatch?.id ?? null;
    const importBatchStatus = importBatch?.status ?? null;

    useEffect(() => {
        if (
            importBatchId === null ||
            (importBatchStatus !== 'queued' && importBatchStatus !== 'processing')
        ) {
            return;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['importBatch'] });
        }, 1500);

        return () => window.clearInterval(timer);
    }, [importBatchId, importBatchStatus]);

    const submit = (event: FormEvent, confirm: boolean) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, preview_checksum: confirm ? (previewChecksum ?? '') : '' }));
        form.post(confirm ? confirmUrl : previewUrl, { forceFormData: true, preserveScroll: true });
    };

    return (
        <WorkspaceLayout title="Import curriculum outline" area="tenant">
            <Head title={`Import · ${curriculum.title}`} />
            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-6">
                <PageHeader
                    title="Import curriculum outline"
                    description={`${curriculum.code} · ${curriculum.title} · draft version ${version.version_number}`}
                />
                <Link
                    href={backUrl}
                    className="text-brand-link min-h-10 w-fit py-2 text-sm font-semibold underline underline-offset-4"
                >
                    Back to curriculum draft
                </Link>

                {importBatch && (
                    <section className={cardClass} aria-labelledby="import-progress-heading">
                        <h2 id="import-progress-heading" className="text-lg font-semibold">
                            Curriculum import status
                        </h2>
                        {importBatch.status === 'queued' || importBatch.status === 'processing' ? (
                            <>
                                <p className="text-text-secondary mt-2 text-sm" role="status">
                                    {importBatch.status === 'queued'
                                        ? 'Your validated import is waiting for a worker.'
                                        : 'The curriculum import is processing in one version-locked transaction.'}
                                </p>
                                <div
                                    className="bg-surface-muted mt-4 h-3 overflow-hidden rounded-full"
                                    role="progressbar"
                                    aria-label="Curriculum import progress"
                                    aria-valuemin={0}
                                    aria-valuemax={importBatch.total_count}
                                    aria-valuenow={importBatch.processed_count}
                                >
                                    <div
                                        className="bg-brand-action h-full rounded-full transition-[width] duration-300 motion-reduce:transition-none"
                                        style={{
                                            width: `${importBatch.total_count > 0 ? (importBatch.processed_count / importBatch.total_count) * 100 : 100}%`,
                                        }}
                                    />
                                </div>
                                <p className="text-text-muted mt-2 text-xs">
                                    {importBatch.processed_count} of {importBatch.total_count} items
                                    committed. The import can be retried safely if transient processing fails.
                                </p>
                            </>
                        ) : importBatch.status === 'completed' ? (
                            <p className="text-text-secondary mt-2 text-sm" role="status">
                                Import complete: {importBatch.imported_count} syllabus items were committed.
                            </p>
                        ) : (
                            <div className="mt-2 grid gap-3">
                                <p className="text-danger text-sm" role="alert">
                                    The import did not complete (
                                    {importBatch.failure_code ?? 'processing_failed'}). No partial curriculum
                                    changes were committed.
                                </p>
                                {retryUrl &&
                                    importBatch.failure_code === 'processing_failed' &&
                                    importBatch.manual_retry_count < 3 && (
                                        <button
                                            type="button"
                                            onClick={() => router.post(retryUrl)}
                                            className="bg-brand-action min-h-11 w-fit rounded-lg px-4 text-sm font-semibold text-white"
                                        >
                                            Retry import safely
                                        </button>
                                    )}
                            </div>
                        )}
                    </section>
                )}

                <section className={cardClass} aria-labelledby="import-format-heading">
                    <h2 id="import-format-heading" className="text-lg font-semibold">
                        Bounded, validated import
                    </h2>
                    <p className="text-text-secondary mt-2 max-w-3xl text-sm">
                        Upload a CSV or version 1 JSON outline. Files are limited to 2 MB and 500 items. The
                        dry-run checks codes, hierarchy, dates, academic period references and workload
                        fields. Nothing is written until you confirm the same file against the unchanged draft
                        version.
                    </p>
                    <p className="mt-3 text-sm">
                        <a
                            href={templateUrl}
                            className="text-brand-link font-semibold underline underline-offset-4"
                        >
                            Download the CSV template
                        </a>
                        <span className="text-text-muted"> · </span>
                        <a
                            href={jsonTemplateUrl}
                            className="text-brand-link font-semibold underline underline-offset-4"
                        >
                            Download the JSON template
                        </a>
                    </p>
                    <form className="mt-5 grid max-w-2xl gap-4" onSubmit={(event) => submit(event, false)}>
                        <input type="hidden" value={form.data.version_lock} readOnly />
                        <label className="grid gap-2 text-sm font-medium">
                            Curriculum file
                            <input
                                type="file"
                                accept=".csv,.json,text/csv,application/json"
                                className={fieldClass}
                                aria-describedby="import-file-help"
                                onChange={(event) =>
                                    form.setData('file', event.currentTarget.files?.[0] ?? null)
                                }
                            />
                        </label>
                        <p id="import-file-help" className="text-text-muted text-xs">
                            CSV columns: code, parent_code, type, title, description, is_required,
                            estimated_minutes, workload_unit, period_code, planned_starts_on, planned_ends_on.
                        </p>
                        {form.errors.file && (
                            <p role="alert" className="text-danger text-sm">
                                {form.errors.file}
                            </p>
                        )}
                        <button
                            type="submit"
                            disabled={form.processing || form.data.file === null}
                            className="bg-brand-action min-h-11 w-fit rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            {form.processing ? 'Validating…' : 'Run dry-run preview'}
                        </button>
                    </form>
                </section>

                {hasPreview && (
                    <section className={cardClass} aria-labelledby="preview-heading">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 id="preview-heading" className="text-lg font-semibold">
                                    Dry-run preview
                                </h2>
                                <p className="text-text-secondary mt-1 text-sm">
                                    {preview.valid_count} valid items of {preview.total_count}. No data has
                                    been changed.
                                </p>
                            </div>
                            <span className="border-border rounded-full border px-3 py-1 text-xs font-semibold uppercase">
                                {preview.format}
                            </span>
                        </div>
                        {preview.errors.length > 0 && (
                            <div
                                className="border-danger/30 bg-danger/5 mt-4 rounded-xl border p-4"
                                role="alert"
                            >
                                <h3 className="font-semibold">
                                    Resolve these row errors, then run the preview again
                                </h3>
                                <ul className="mt-2 grid gap-2 text-sm">
                                    {preview.errors.map((issue, index) => (
                                        <li key={`${issue.row}-${index}`}>
                                            <strong>Row {issue.row}:</strong> {issue.errors.join(' ')}
                                        </li>
                                    ))}
                                </ul>
                                <a
                                    href={errorReportUrl}
                                    className="text-brand-link mt-3 inline-flex min-h-10 items-center font-semibold underline underline-offset-4"
                                >
                                    Download validation error report
                                </a>
                            </div>
                        )}
                        {preview.rows.length > 0 && (
                            <div
                                className="border-border mt-4 overflow-x-auto rounded-xl border"
                                role="region"
                                aria-label="Scrollable curriculum import preview table"
                                tabIndex={0}
                            >
                                <table className="w-full min-w-[720px] text-left text-sm">
                                    <caption className="sr-only">Curriculum import dry-run rows</caption>
                                    <thead className="bg-surface-muted">
                                        <tr>
                                            <th className="p-3">Row</th>
                                            <th className="p-3">Code</th>
                                            <th className="p-3">Parent</th>
                                            <th className="p-3">Type</th>
                                            <th className="p-3">Title</th>
                                            <th className="p-3">Required</th>
                                            <th className="p-3">Workload</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.rows.slice(0, 100).map((row) => (
                                            <tr key={row.row} className="border-border border-t">
                                                <td className="p-3">{row.row}</td>
                                                <td className="p-3">{row.code ?? '—'}</td>
                                                <td className="p-3">{row.parent_code ?? '—'}</td>
                                                <td className="p-3 capitalize">{row.type}</td>
                                                <td className="p-3">{row.title}</td>
                                                <td className="p-3">
                                                    {row.is_required ? 'Required' : 'Optional'}
                                                </td>
                                                <td className="p-3">
                                                    {row.estimated_minutes === null
                                                        ? '—'
                                                        : `${row.estimated_minutes} ${row.workload_unit}`}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {preview.rows.length > 100 && (
                                    <p className="text-text-muted p-3 text-xs">
                                        Showing first 100 items. All {preview.rows.length} items are validated
                                        before confirmation.
                                    </p>
                                )}
                            </div>
                        )}
                        <form className="mt-4 grid max-w-xl gap-3" onSubmit={(event) => submit(event, true)}>
                            <label className="grid gap-2 text-sm font-medium">
                                Re-select the exact same file to confirm
                                <input
                                    type="file"
                                    accept=".csv,.json,text/csv,application/json"
                                    className={fieldClass}
                                    onChange={(event) =>
                                        form.setData('file', event.currentTarget.files?.[0] ?? null)
                                    }
                                />
                            </label>
                            {form.errors.preview_checksum && (
                                <p role="alert" className="text-danger text-sm">
                                    {form.errors.preview_checksum}
                                </p>
                            )}
                            <button
                                type="submit"
                                disabled={!canConfirm || form.processing}
                                className="bg-brand-action min-h-11 w-fit rounded-lg px-4 text-sm font-semibold text-white disabled:opacity-60"
                            >
                                {form.processing
                                    ? 'Importing…'
                                    : `Confirm import of ${preview.valid_count} items`}
                            </button>
                            <p className="text-text-muted text-xs">
                                Confirmation rechecks the file, draft version, hierarchy and duplicate
                                constraints inside one database transaction.
                            </p>
                        </form>
                    </section>
                )}
            </div>
        </WorkspaceLayout>
    );
}

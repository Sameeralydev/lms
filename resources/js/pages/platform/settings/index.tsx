import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

export default function PlatformSettings({ runtime }: { runtime: Record<string, string | boolean> }) {
    return (
        <WorkspaceLayout area="platform" title="Operations">
            <PageHeader
                title="Security & operations"
                description="Read-only runtime diagnostics. Secrets, host paths, and connection credentials are never returned to the browser."
            />
            <div className="border-border bg-surface rounded-2xl border p-5">
                <h2 className="font-semibold">Application runtime</h2>
                <dl className="divide-border mt-4 divide-y">
                    {Object.entries(runtime).map(([key, value]) => (
                        <div key={key} className="flex flex-wrap justify-between gap-3 py-3">
                            <dt className="text-text-secondary text-sm capitalize">
                                {key.replaceAll(/([A-Z])/g, ' $1')}
                            </dt>
                            <dd className="text-text-primary text-sm font-medium">{String(value)}</dd>
                        </div>
                    ))}
                </dl>
                <p className="text-text-secondary mt-5 text-xs leading-5">
                    Runtime values are supplied by deployment configuration. Database write credentials and
                    operational secrets must be managed outside the application by the deployment operator.
                </p>
            </div>
        </WorkspaceLayout>
    );
}

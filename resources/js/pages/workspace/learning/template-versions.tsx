import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/ui/page-header';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Props = {
    tenant: { slug: string };
    template: {
        id: number;
        name: string;
        versions: {
            version: number;
            source_workspace_id: number;
            checksum: string;
            published_at: string | null;
        }[];
    };
};

export default function TemplateVersions({ tenant, template }: Props) {
    return (
        <WorkspaceLayout title="Template version history" area="tenant">
            <Head title={`${template.name} versions`} />
            <PageHeader
                title={`${template.name} version history`}
                description="Published template versions are immutable snapshots. Later versions preserve their own source and content checksum."
                actions={
                    <Link
                        className="focus-ring rounded-lg px-3 py-2 text-sm font-semibold underline"
                        href={`/t/${tenant.slug}/learning/templates`}
                    >
                        Back to templates
                    </Link>
                }
            />
            <div className="bg-surface border-border overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[640px] text-left text-sm">
                    <caption className="sr-only">Published versions of {template.name}</caption>
                    <thead className="bg-surface-soft text-text">
                        <tr>
                            <th scope="col" className="px-4 py-3">
                                Version
                            </th>
                            <th scope="col" className="px-4 py-3">
                                Source workspace
                            </th>
                            <th scope="col" className="px-4 py-3">
                                Content checksum
                            </th>
                            <th scope="col" className="px-4 py-3">
                                Published
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {template.versions.map((version) => (
                            <tr key={version.version} className="border-border border-t">
                                <td className="px-4 py-3 font-semibold">Version {version.version}</td>
                                <td className="px-4 py-3">{version.source_workspace_id}</td>
                                <td className="px-4 py-3 font-mono text-xs">{version.checksum}</td>
                                <td className="px-4 py-3">
                                    {version.published_at
                                        ? new Date(version.published_at).toLocaleString()
                                        : 'Pending'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </WorkspaceLayout>
    );
}

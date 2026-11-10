import { router } from '@inertiajs/react';
import { AdmissionsPublicShell } from '@/components/admissions/public-shell';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    tenant: { slug: string; name: string };
};

export default function GuardianInvitation({ tenant }: Props) {
    const accept = () => {
        const token = window.location.hash.slice(1);
        if (!/^[a-f0-9]{64}$/i.test(token)) return;
        const url = `${window.location.pathname}${window.location.search}`;
        window.history.replaceState(null, '', url);
        router.post(url, { token }, { preserveScroll: true });
    };

    return (
        <AdmissionsPublicShell title="Guardian authorization" tenant={tenant}>
            <PageHeader
                title="Review guardian request"
                description="Verify your email identity and review the request before accepting."
            />
            <section className="border-border bg-surface mx-auto mt-6 max-w-2xl rounded-2xl border p-5 sm:p-7">
                <p className="text-text-secondary text-sm leading-6">
                    An applicant at {tenant.name} requested guardian authorization. This link is tied to the
                    verified email account that received it.
                </p>
                <p className="text-text-secondary mt-3 text-sm leading-6">
                    Accepting confirms access to view the application after the institution separately
                    verifies the relationship. It does not grant editing, submission, or offer-response
                    authority. The applicant may revoke your access at any time.
                </p>
                <button
                    type="button"
                    onClick={accept}
                    className="bg-brand-action mt-6 min-h-11 rounded-lg px-4 text-sm font-semibold text-white"
                >
                    Verify and accept request
                </button>
            </section>
        </AdmissionsPublicShell>
    );
}

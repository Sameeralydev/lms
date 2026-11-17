import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { FormErrors } from '@/components/ui/form-errors';
import { PageHeader } from '@/components/ui/page-header';
import { TextField } from '@/components/ui/text-field';
import { WorkspaceLayout } from '@/layouts/workspace-layout';

type Plan = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    entitlements: Record<string, boolean>;
    limits: Record<string, number>;
    is_active: boolean;
    subscriptions_count: number;
};
type FormData = {
    key: string;
    name: string;
    description: string;
    entitlements: Record<string, boolean>;
    limits: Record<string, number>;
    is_active: boolean;
};

export default function PlansIndex({ plans, features }: { plans: Plan[]; features: Record<string, string> }) {
    const [selected, setSelected] = useState<number | null>(null);
    const [limitsText, setLimitsText] = useState('{}');
    const form = useForm<FormData>({
        key: '',
        name: '',
        description: '',
        entitlements: Object.fromEntries(Object.keys(features).map((key) => [key, false])),
        limits: {},
        is_active: true,
    });
    const loadPlan = (plan: Plan | null) => {
        setSelected(plan?.id ?? null);
        setLimitsText(JSON.stringify(plan?.limits ?? {}, null, 2));
        form.clearErrors();
        form.setData({
            key: plan?.key ?? '',
            name: plan?.name ?? '',
            description: plan?.description ?? '',
            entitlements:
                plan?.entitlements ?? Object.fromEntries(Object.keys(features).map((key) => [key, false])),
            limits: plan?.limits ?? {},
            is_active: plan?.is_active ?? true,
        });
    };
    return (
        <WorkspaceLayout area="platform" title="Plans and entitlements">
            <PageHeader
                title="Plans and entitlements"
                description="Configure feature access for tenants. Plans do not set prices, collect payments, or retroactively alter stored records."
            />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(20rem,0.8fr)]">
                <section className="border-border bg-surface overflow-hidden rounded-2xl border">
                    <div className="flex items-center justify-between gap-3 p-5">
                        <div>
                            <h2 className="font-semibold">Configured plans</h2>
                            <p className="text-text-secondary mt-1 text-sm">
                                Tenants without a plan retain legacy access.
                            </p>
                        </div>
                        <Button type="button" onClick={() => loadPlan(null)}>
                            New plan
                        </Button>
                    </div>
                    {plans.length ? (
                        <div className="divide-border divide-y">
                            {plans.map((plan) => (
                                <article
                                    key={plan.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                >
                                    <div>
                                        <h3 className="font-medium">
                                            {plan.name}{' '}
                                            <span className="text-text-muted text-xs">({plan.key})</span>
                                        </h3>
                                        <p className="text-text-secondary mt-1 text-sm">
                                            {plan.subscriptions_count} assigned ·{' '}
                                            {Object.values(plan.entitlements).filter(Boolean).length} enabled
                                            features ·{' '}
                                            {plan.is_active ? 'Available for assignment' : 'Inactive'}
                                        </p>
                                    </div>
                                    <Button type="button" variant="secondary" onClick={() => loadPlan(plan)}>
                                        Edit
                                    </Button>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <p className="text-text-secondary px-5 pb-5 text-sm">
                            No plans are configured. Current tenants continue using legacy access.
                        </p>
                    )}
                </section>
                <form
                    className="border-border bg-surface grid content-start gap-4 rounded-2xl border p-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        let limits: Record<string, number>;
                        try {
                            limits = JSON.parse(limitsText) as Record<string, number>;
                        } catch {
                            form.setError(
                                'limits',
                                'Limits must be valid JSON containing non-negative integer values.',
                            );
                            return;
                        }
                        form.transform((data) => ({ ...data, limits }));
                        if (selected) form.put(`/platform/plans/${selected}`);
                        else form.post('/platform/plans');
                    }}
                >
                    <div>
                        <h2 className="font-semibold">{selected ? 'Edit plan' : 'Create plan'}</h2>
                        <p className="text-text-secondary mt-1 text-sm">
                            Entitlements control module access for assigned tenants. Limits are stored as
                            configuration for modules to enforce at their own data boundaries.
                        </p>
                    </div>
                    <FormErrors />
                    <TextField
                        label="Plan key"
                        name="key"
                        value={form.data.key}
                        onChange={(event) => form.setData('key', event.currentTarget.value)}
                        error={form.errors.key}
                        hint="Lowercase key used for integrations; changing it does not rename tenant records."
                    />
                    <TextField
                        label="Plan name"
                        name="name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.currentTarget.value)}
                        error={form.errors.name}
                    />
                    <label className="grid gap-2 text-sm font-medium">
                        Description
                        <textarea
                            className="border-border bg-surface min-h-20 rounded-lg border px-3 py-2"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.currentTarget.value)}
                        />
                    </label>
                    <fieldset className="grid gap-2">
                        <legend className="text-sm font-semibold">Feature access</legend>
                        {Object.entries(features).map(([key, label]) => (
                            <label
                                key={key}
                                className="border-border flex items-start gap-3 rounded-lg border p-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    checked={form.data.entitlements[key] ?? false}
                                    onChange={(event) =>
                                        form.setData('entitlements', {
                                            ...form.data.entitlements,
                                            [key]: event.currentTarget.checked,
                                        })
                                    }
                                />
                                <span>
                                    {label}
                                    <span className="text-text-muted block text-xs">{key}</span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <label className="grid gap-2 text-sm font-medium">
                        Optional limits (JSON)
                        <textarea
                            className="border-border bg-surface min-h-24 rounded-lg border px-3 py-2 font-mono text-xs"
                            value={limitsText}
                            onChange={(event) => setLimitsText(event.currentTarget.value)}
                            aria-describedby="limits-help"
                        />
                        <span id="limits-help" className="text-text-secondary font-normal">
                            Example: {'{ "campuses": 3 }'}. No limit values are prefilled.
                        </span>
                        {form.errors.limits ? (
                            <span className="text-danger" role="alert">
                                {form.errors.limits}
                            </span>
                        ) : null}
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.currentTarget.checked)}
                        />
                        Available for new assignments
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" loading={form.processing}>
                            {selected ? 'Save plan' : 'Create plan'}
                        </Button>
                        {selected ? (
                            <Button type="button" variant="secondary" onClick={() => loadPlan(null)}>
                                Cancel edit
                            </Button>
                        ) : null}
                    </div>
                </form>
            </div>
        </WorkspaceLayout>
    );
}

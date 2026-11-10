import type { ReactNode } from 'react';

type StatePanelProps = {
    title: string;
    description?: string;
    kind: 'loading' | 'empty' | 'error';
    action?: ReactNode;
};

export function StatePanel({ action, description, kind, title }: StatePanelProps) {
    const role = kind === 'error' ? 'alert' : 'status';

    return (
        <section
            className="border-border bg-surface rounded-xl border p-6"
            role={role}
            aria-live={kind === 'error' ? 'assertive' : 'polite'}
        >
            <div className="flex items-start gap-4">
                {kind === 'loading' ? (
                    <span
                        aria-hidden="true"
                        className="border-brand-blue mt-1 size-5 animate-spin rounded-full border-2 border-r-transparent"
                    />
                ) : null}
                <div className="grid gap-2">
                    <h2 className="text-text-primary font-semibold">{title}</h2>
                    {description ? (
                        <p className="text-text-secondary text-sm leading-6">{description}</p>
                    ) : null}
                    {action ? <div className="pt-2">{action}</div> : null}
                </div>
            </div>
        </section>
    );
}

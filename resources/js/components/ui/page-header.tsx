import type { ReactNode } from 'react';

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
}: {
    eyebrow?: string;
    title: string;
    description: string;
    actions?: ReactNode;
}) {
    return (
        <div className="mb-7 flex min-w-0 flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                {eyebrow ? (
                    <p className="text-text-secondary mb-2 text-xs font-semibold tracking-[0.16em] uppercase">
                        {eyebrow}
                    </p>
                ) : null}
                <h1 className="text-text-primary text-3xl font-semibold tracking-tight sm:text-4xl">
                    {title}
                </h1>
                <p className="text-text-secondary mt-2 max-w-3xl text-sm leading-6">{description}</p>
            </div>
            {actions ? <div className="flex shrink-0 flex-wrap gap-2">{actions}</div> : null}
        </div>
    );
}

import type { InputHTMLAttributes } from 'react';

type TextFieldProps = InputHTMLAttributes<HTMLInputElement> & {
    label: string;
    error?: string;
    hint?: string;
};

export function TextField({ error, hint, id, label, ...props }: TextFieldProps) {
    const inputId = id ?? props.name;
    const descriptionId = `${inputId}-description`;
    const errorId = `${inputId}-error`;
    const describedBy =
        [hint ? descriptionId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="grid gap-2">
            <label className="text-text-primary text-sm font-medium" htmlFor={inputId}>
                {label}
            </label>
            <input
                {...props}
                id={inputId}
                aria-describedby={describedBy}
                aria-invalid={error ? true : undefined}
                className="border-border bg-surface text-text-primary placeholder:text-text-muted focus-visible:border-focus focus-visible:ring-focus/25 disabled:bg-surface-soft min-h-11 rounded-lg border px-3 py-2 shadow-sm outline-none focus-visible:ring-2 disabled:cursor-not-allowed"
            />
            {hint ? (
                <p id={descriptionId} className="text-text-secondary text-sm">
                    {hint}
                </p>
            ) : null}
            {error ? (
                <p id={errorId} className="text-danger text-sm font-medium" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

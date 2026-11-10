import type { SelectHTMLAttributes } from 'react';

type Option = { value: string; label: string };
export function SelectField({
    label,
    options,
    error,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; options: Option[]; error?: string }) {
    const id = props.id ?? props.name;
    return (
        <div className="grid gap-2">
            <label className="text-text-primary text-sm font-medium" htmlFor={id}>
                {label}
            </label>
            <select
                {...props}
                id={id}
                aria-invalid={error ? true : undefined}
                className="border-border bg-surface text-text-primary focus-visible:outline-focus min-h-11 rounded-lg border px-3 shadow-sm focus-visible:outline-2"
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {error ? (
                <p role="alert" className="text-danger text-sm font-medium">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

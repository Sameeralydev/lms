import type { ButtonHTMLAttributes, ReactNode } from 'react';

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    children: ReactNode;
    variant?: 'primary' | 'secondary' | 'quiet';
    loading?: boolean;
};

const variants: Record<NonNullable<ButtonProps['variant']>, string> = {
    primary: 'border-transparent bg-brand-action text-white hover:bg-brand-action-hover',
    secondary: 'border-border bg-surface text-text-primary hover:bg-surface-soft',
    quiet: 'border-transparent bg-transparent text-text-secondary hover:bg-surface-soft hover:text-text-primary',
};

export function Button({
    children,
    className = '',
    disabled,
    loading = false,
    type = 'button',
    variant = 'primary',
    ...props
}: ButtonProps) {
    return (
        <button
            className={`focus-visible:outline-focus inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:pointer-events-none disabled:opacity-60 ${variants[variant]} ${className}`}
            disabled={disabled || loading}
            type={type}
            aria-busy={loading || undefined}
            {...props}
        >
            {loading ? (
                <span
                    aria-hidden="true"
                    className="size-4 animate-spin rounded-full border-2 border-current border-r-transparent"
                />
            ) : null}
            {children}
        </button>
    );
}

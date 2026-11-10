import { usePage } from '@inertiajs/react';

export function FormErrors({ errors: formErrors }: { errors?: Record<string, string> } = {}) {
    const sharedErrors = usePage<{ errors: Record<string, string> }>().props.errors;
    const errors = formErrors ?? sharedErrors;
    const messages = Object.values(errors ?? {});
    return messages.length ? (
        <div
            role="alert"
            className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            <p className="font-semibold">Please review these fields.</p>
            <ul className="mt-2 list-inside list-disc">
                {messages.map((message, index) => (
                    <li key={`${index}-${message}`}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}

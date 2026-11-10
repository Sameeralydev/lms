import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

type Verification = {
    valid: boolean;
    record_type: string | null;
    issued_on: string | null;
    institution: string | null;
};
export default function VerifyAcademicRecord({ verification }: { verification: Verification }) {
    const form = useForm({ verification_code: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/academic-records/verify', { preserveState: true });
    };
    return (
        <main className="bg-page text-text-primary min-h-screen p-6">
            <Head title="Verify academic certificate" />
            <section className="border-border bg-surface mx-auto mt-16 max-w-xl rounded-2xl border p-6">
                <h1 className="text-2xl font-semibold">Verify academic certificate</h1>
                <p className="text-text-secondary mt-2">
                    Only validity, record type, issue date, and institution are disclosed. Student identity is
                    private.
                </p>
                <form onSubmit={submit} className="mt-5 space-y-3">
                    <label className="block text-sm font-medium">
                        Verification code
                        <input
                            className="border-border focus-ring mt-1 min-h-11 w-full rounded-lg border bg-white px-3"
                            value={form.data.verification_code}
                            onChange={(event) => form.setData('verification_code', event.target.value)}
                            required
                        />
                    </label>
                    <button className="bg-brand-action focus-ring rounded-lg px-4 py-2.5 font-semibold text-white">
                        Verify
                    </button>
                </form>
                {verification && (
                    <div role="status" className="mt-5 rounded-lg border p-4">
                        {verification.valid ? (
                            <p>
                                Valid {verification.record_type} issued {verification.issued_on} by{' '}
                                {verification.institution}.
                            </p>
                        ) : (
                            <p>This certificate could not be verified or has been revoked.</p>
                        )}
                    </div>
                )}
            </section>
        </main>
    );
}

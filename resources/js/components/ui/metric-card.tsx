export function MetricCard({
    label,
    value,
    detail,
}: {
    label: string;
    value: number | string;
    detail: string;
}) {
    return (
        <section className="border-border bg-surface shadow-card rounded-2xl border p-5">
            <p className="text-text-secondary text-sm font-medium">{label}</p>
            <p className="text-text-primary mt-3 text-3xl font-semibold tracking-tight">{value}</p>
            <p className="text-text-muted mt-2 text-xs">{detail}</p>
        </section>
    );
}

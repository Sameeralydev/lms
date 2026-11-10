export function EmptyState({ title, description }: { title: string; description: string }) {
    return (
        <div className="border-border bg-surface rounded-2xl border border-dashed px-6 py-12 text-center">
            <h2 className="text-text-primary text-base font-semibold">{title}</h2>
            <p className="text-text-secondary mx-auto mt-2 max-w-lg text-sm leading-6">{description}</p>
        </div>
    );
}

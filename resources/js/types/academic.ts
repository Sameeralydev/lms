export type AcademicWorkspaceConfig = {
    operatingModel: string;
    enabledFeatures: Record<string, boolean>;
    terminology: Record<string, string>;
};

export function academicTerm(terminology: Record<string, string>, key: string, fallback: string): string {
    return terminology[key]?.trim() || fallback;
}

export function academicPlural(value: string): string {
    if (value.endsWith('s')) return `${value}es`;
    if (value.toLowerCase().endsWith('y')) return `${value.slice(0, -1)}ies`;

    return `${value}s`;
}

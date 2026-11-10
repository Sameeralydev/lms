import { Head, Link } from '@inertiajs/react';

type CurriculumNode = {
    id: number;
    parent_id: number | null;
    node_type: string;
    code: string | null;
    title: string;
    description: string | null;
    is_required: boolean;
    estimated_minutes: number | null;
    workload_unit: string;
    instructional_strategy: string | null;
    activity_specification: string | null;
    assessment_blueprint: string | null;
    planned_starts_on: string | null;
    planned_ends_on: string | null;
    outcomes: { learning_outcome: { code: string; name: string } | null }[];
    prerequisites: {
        prerequisite_node: { title: string; code: string | null } | null;
        dependency_type: string;
    }[];
};

type Props = {
    backUrl: string;
    curriculum: {
        id: number;
        code: string;
        title: string;
        description: string | null;
        program: {
            code: string;
            title: string;
            credit_bearing: boolean;
            required_credits: string | number | null;
        };
        framework: { code: string; title: string } | null;
        academic_year: { name: string; code: string } | null;
    };
    version: {
        version_number: number;
        title: string;
        revision_note: string | null;
        effective_from: string | null;
        effective_until: string | null;
        published_at: string | null;
    };
    nodes: CurriculumNode[];
    completionRules: {
        id: number;
        code: string;
        title: string;
        rule_type: string;
        target_value: string | number | null;
        unit: string | null;
        is_required: boolean;
        description: string | null;
        academic_period: { code: string; name: string } | null;
    }[];
};

function NodeBranch({
    node,
    nodes,
    depth = 0,
}: {
    node: CurriculumNode;
    nodes: CurriculumNode[];
    depth?: number;
}) {
    const children = nodes.filter((candidate) => candidate.parent_id === node.id);

    return (
        <li className="curriculum-print-node" style={{ marginInlineStart: `${depth * 1.25}rem` }}>
            <div className="curriculum-print-node-heading">
                <h3>
                    <span className="curriculum-print-node-type">{node.node_type}</span> {node.title}
                </h3>
                <span>{node.is_required ? 'Required' : 'Optional'}</span>
            </div>
            {node.code && <p className="curriculum-print-muted">Code: {node.code}</p>}
            {node.description && <p>{node.description}</p>}
            {(node.planned_starts_on || node.planned_ends_on || node.estimated_minutes !== null) && (
                <p className="curriculum-print-muted">
                    {node.planned_starts_on && node.planned_ends_on
                        ? `${node.planned_starts_on} to ${node.planned_ends_on}`
                        : null}
                    {node.estimated_minutes !== null
                        ? ` · ${node.estimated_minutes} ${node.workload_unit}`
                        : null}
                </p>
            )}
            {node.outcomes.length > 0 && (
                <p className="curriculum-print-muted">
                    Learning outcomes:{' '}
                    {node.outcomes
                        .map((item) => item.learning_outcome?.code)
                        .filter(Boolean)
                        .join(', ')}
                </p>
            )}
            {node.prerequisites.length > 0 && (
                <p className="curriculum-print-muted">
                    Prerequisites:{' '}
                    {node.prerequisites
                        .map((item) => item.prerequisite_node?.title)
                        .filter(Boolean)
                        .join(', ')}
                </p>
            )}
            {node.instructional_strategy && (
                <p>
                    <strong>Instruction:</strong> {node.instructional_strategy}
                </p>
            )}
            {node.activity_specification && (
                <p>
                    <strong>Learning activity:</strong> {node.activity_specification}
                </p>
            )}
            {node.assessment_blueprint && (
                <p>
                    <strong>Assessment:</strong> {node.assessment_blueprint}
                </p>
            )}
            {children.length > 0 && (
                <ol className="curriculum-print-children">
                    {children.map((child) => (
                        <NodeBranch key={child.id} node={child} nodes={nodes} depth={depth + 1} />
                    ))}
                </ol>
            )}
        </li>
    );
}

export default function CurriculumPrint({ curriculum, version, nodes, completionRules, backUrl }: Props) {
    const roots = nodes.filter((node) => node.parent_id === null);

    return (
        <>
            <Head title={`${curriculum.title} syllabus`} />
            <main className="curriculum-print-page">
                <div className="curriculum-print-actions no-print">
                    <Link href={backUrl}>Back to curriculum</Link>
                    <button type="button" onClick={() => window.print()}>
                        Print or save as PDF
                    </button>
                </div>
                <header className="curriculum-print-header">
                    <p className="curriculum-print-kicker">TechMentors.PK · Academic curriculum</p>
                    <h1>{curriculum.title}</h1>
                    <p className="curriculum-print-muted">
                        {curriculum.code} · Version {version.version_number}
                    </p>
                    {curriculum.description && <p>{curriculum.description}</p>}
                    <dl className="curriculum-print-metadata">
                        <div>
                            <dt>Program</dt>
                            <dd>
                                {curriculum.program.title} ({curriculum.program.code})
                            </dd>
                        </div>
                        {curriculum.framework && (
                            <div>
                                <dt>Framework</dt>
                                <dd>
                                    {curriculum.framework.title} ({curriculum.framework.code})
                                </dd>
                            </div>
                        )}
                        {curriculum.academic_year && (
                            <div>
                                <dt>Academic year</dt>
                                <dd>{curriculum.academic_year.name}</dd>
                            </div>
                        )}
                        <div>
                            <dt>Program requirement</dt>
                            <dd>
                                {curriculum.program.credit_bearing &&
                                curriculum.program.required_credits !== null
                                    ? `${curriculum.program.required_credits} credits`
                                    : 'Institution-defined completion requirements'}
                            </dd>
                        </div>
                        {version.effective_from && (
                            <div>
                                <dt>Effective from</dt>
                                <dd>{version.effective_from}</dd>
                            </div>
                        )}
                        {version.effective_until && (
                            <div>
                                <dt>Effective until</dt>
                                <dd>{version.effective_until}</dd>
                            </div>
                        )}
                        {version.published_at && (
                            <div>
                                <dt>Published</dt>
                                <dd>{version.published_at}</dd>
                            </div>
                        )}
                    </dl>
                </header>
                <section aria-labelledby="completion-requirements-heading">
                    <h2 id="completion-requirements-heading">Program completion requirements</h2>
                    {completionRules.length === 0 ? (
                        <p>No additional version-specific completion rules are configured.</p>
                    ) : (
                        <ul className="curriculum-print-outline">
                            {completionRules.map((rule) => (
                                <li key={rule.id} className="curriculum-print-node">
                                    <div className="curriculum-print-node-heading">
                                        <h3>{rule.title}</h3>
                                        <span>{rule.is_required ? 'Required' : 'Optional'}</span>
                                    </div>
                                    <p className="curriculum-print-muted">
                                        {rule.code} · {rule.rule_type.replaceAll('_', ' ')}
                                        {rule.target_value !== null
                                            ? ` · ${rule.target_value} ${rule.unit ?? ''}`
                                            : ''}
                                        {rule.academic_period
                                            ? ` · ${rule.academic_period.code} ${rule.academic_period.name}`
                                            : ''}
                                    </p>
                                    {rule.description && <p>{rule.description}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <section aria-labelledby="syllabus-heading">
                    <h2 id="syllabus-heading">Syllabus and learning plan</h2>
                    {roots.length === 0 ? (
                        <p>No syllabus items are included in this published version.</p>
                    ) : (
                        <ol className="curriculum-print-outline">
                            {roots.map((node) => (
                                <NodeBranch key={node.id} node={node} nodes={nodes} />
                            ))}
                        </ol>
                    )}
                </section>
                {version.revision_note && (
                    <footer className="curriculum-print-footer">
                        <strong>Revision note:</strong> {version.revision_note}
                    </footer>
                )}
            </main>
        </>
    );
}

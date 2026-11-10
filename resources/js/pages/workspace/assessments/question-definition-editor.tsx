import type { ChangeEvent } from 'react';

export type QuestionContent = {
    prompt?: string;
    passage?: string;
    image_alt?: string;
    options?: { id: string; text: string }[];
    pairs?: { id: string; left: string; right: string }[];
    items?: { id: string; text: string }[];
    blank_keys?: string[];
    [key: string]: unknown;
};

export type QuestionAnswerKey = {
    correct_option_ids?: string[];
    value?: string | boolean;
    matches?: Record<string, string>;
    order?: string[];
    values?: Record<string, string[]>;
    accepted_answers?: string[];
    [key: string]: unknown;
};

export type QuestionScoringRules = {
    partial_credit?: boolean;
    case_sensitive?: boolean;
    negative_marking?: boolean;
    penalty_points?: string;
    tolerance?: string;
    [key: string]: unknown;
};

type Props = {
    questionType: string;
    content: QuestionContent;
    answerKey: QuestionAnswerKey;
    scoringRules: QuestionScoringRules;
    onContentChange: (content: QuestionContent) => void;
    onAnswerKeyChange: (answerKey: QuestionAnswerKey) => void;
    onScoringRulesChange: (rules: QuestionScoringRules) => void;
};

const inputClass = 'border-border bg-canvas mt-1 min-h-11 w-full rounded-lg border px-3 py-2 text-sm';

export function QuestionDefinitionEditor({
    questionType,
    content,
    answerKey,
    scoringRules,
    onContentChange,
    onAnswerKeyChange,
    onScoringRulesChange,
}: Props) {
    const options = Array.isArray(content.options) ? content.options : [];
    const pairs = Array.isArray(content.pairs) ? content.pairs : [];
    const items = Array.isArray(content.items) ? content.items : [];
    const blanks = Array.isArray(content.blank_keys) ? content.blank_keys : [];
    const choiceQuestion = ['single_choice', 'multiple_choice', 'image_based'].includes(questionType);

    function updateOptions(next: { id: string; text: string }[]) {
        onContentChange({ ...content, options: next });
    }

    function updatePairs(next: { id: string; left: string; right: string }[]) {
        onContentChange({ ...content, pairs: next });
    }

    function updateItems(next: { id: string; text: string }[]) {
        onContentChange({ ...content, items: next });
    }

    function updateBlankKeys(next: string[]) {
        const oldValues = (answerKey.values ?? {}) as Record<string, string[]>;
        const nextValues = Object.fromEntries(next.map((key) => [key, oldValues[key] ?? ['']]));
        onContentChange({ ...content, blank_keys: next });
        onAnswerKeyChange({ ...answerKey, values: nextValues });
    }

    function updateAcceptedAnswers(event: ChangeEvent<HTMLTextAreaElement>) {
        onAnswerKeyChange({
            ...answerKey,
            accepted_answers: event.target.value.split('\n').map((value) => value.trim()),
        });
    }

    return (
        <fieldset className="space-y-4">
            <legend className="text-sm font-semibold">Question definition</legend>
            <label className="block text-sm font-medium">
                Question prompt
                <textarea
                    className={inputClass + ' min-h-24'}
                    value={String(content.prompt ?? '')}
                    onChange={(event) => onContentChange({ ...content, prompt: event.target.value })}
                    maxLength={20000}
                    required={questionType !== 'case_passage'}
                />
            </label>

            {questionType === 'case_passage' && (
                <label className="block text-sm font-medium">
                    Case or passage
                    <textarea
                        className={inputClass + ' min-h-40'}
                        value={String(content.passage ?? '')}
                        onChange={(event) => onContentChange({ ...content, passage: event.target.value })}
                        maxLength={30000}
                        required
                    />
                </label>
            )}

            {questionType === 'image_based' && (
                <div className="space-y-2">
                    <label className="block text-sm font-medium">
                        Image accessibility description
                        <textarea
                            className={inputClass + ' min-h-20'}
                            value={String(content.image_alt ?? '')}
                            onChange={(event) =>
                                onContentChange({ ...content, image_alt: event.target.value })
                            }
                            maxLength={4000}
                            required
                        />
                    </label>
                    <p className="text-text-muted text-xs">
                        Attach image files through the bank&apos;s private media workflow. Learners can access
                        an image only after its scan is clean.
                    </p>
                </div>
            )}

            {choiceQuestion && (
                <section aria-labelledby="question-options-heading" className="space-y-3">
                    <h3 id="question-options-heading" className="text-sm font-medium">
                        Answer options
                    </h3>
                    {options.map((option, index) => {
                        const selected = (answerKey.correct_option_ids ?? []).includes(option.id);
                        return (
                            <div key={option.id} className="grid gap-2 sm:grid-cols-[8rem_1fr_auto]">
                                <label className="text-xs font-medium">
                                    Option identifier
                                    <input
                                        className={inputClass}
                                        value={option.id}
                                        onChange={(event) => {
                                            const next = [...options];
                                            const oldId = next[index].id;
                                            next[index] = { ...next[index], id: event.target.value };
                                            updateOptions(next);
                                            if (selected) {
                                                onAnswerKeyChange({
                                                    ...answerKey,
                                                    correct_option_ids: (
                                                        answerKey.correct_option_ids ?? []
                                                    ).map((id) => (id === oldId ? event.target.value : id)),
                                                });
                                            }
                                        }}
                                        maxLength={40}
                                        required
                                    />
                                </label>
                                <label className="text-xs font-medium">
                                    Option text
                                    <input
                                        className={inputClass}
                                        value={option.text}
                                        onChange={(event) => {
                                            const next = [...options];
                                            next[index] = { ...next[index], text: event.target.value };
                                            updateOptions(next);
                                        }}
                                        maxLength={4000}
                                        required
                                    />
                                </label>
                                <label className="flex min-h-11 items-center gap-2 self-end text-xs">
                                    <input
                                        type={questionType === 'single_choice' ? 'radio' : 'checkbox'}
                                        name="correct-options"
                                        checked={selected}
                                        onChange={(event) => {
                                            const current = answerKey.correct_option_ids ?? [];
                                            const next =
                                                questionType === 'single_choice'
                                                    ? [option.id]
                                                    : event.target.checked
                                                      ? [...current, option.id]
                                                      : current.filter((id) => id !== option.id);
                                            onAnswerKeyChange({ ...answerKey, correct_option_ids: next });
                                        }}
                                    />
                                    Correct answer for {option.id}
                                </label>
                            </div>
                        );
                    })}
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="focus-ring border-border min-h-10 rounded-lg border px-3 text-sm"
                            onClick={() =>
                                updateOptions([
                                    ...options,
                                    { id: 'option-' + (options.length + 1), text: '' },
                                ])
                            }
                            disabled={options.length >= 20}
                        >
                            Add option
                        </button>
                        <button
                            type="button"
                            className="focus-ring border-border min-h-10 rounded-lg border px-3 text-sm"
                            onClick={() => {
                                const removed = options.at(-1);
                                const next = options.slice(0, -1);
                                updateOptions(next);
                                if (removed) {
                                    onAnswerKeyChange({
                                        ...answerKey,
                                        correct_option_ids: (answerKey.correct_option_ids ?? []).filter(
                                            (id) => id !== removed.id,
                                        ),
                                    });
                                }
                            }}
                            disabled={options.length <= 2}
                        >
                            Remove last option
                        </button>
                    </div>
                </section>
            )}

            {questionType === 'true_false' && (
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">Correct answer</legend>
                    {[true, false].map((value) => (
                        <label key={String(value)} className="flex min-h-10 items-center gap-2">
                            <input
                                type="radio"
                                name="true-false-answer"
                                checked={answerKey.value === value}
                                onChange={() => onAnswerKeyChange({ value })}
                            />
                            {value ? 'True' : 'False'}
                        </label>
                    ))}
                </fieldset>
            )}

            {questionType === 'matching' && (
                <section className="space-y-3">
                    <h3 className="text-sm font-medium">Matching pairs</h3>
                    {pairs.map((pair, index) => (
                        <div key={pair.id} className="grid gap-2 sm:grid-cols-3">
                            <label className="text-xs font-medium">
                                Left item
                                <input
                                    className={inputClass}
                                    value={pair.left}
                                    onChange={(event) => {
                                        const next = [...pairs];
                                        next[index] = { ...next[index], left: event.target.value };
                                        updatePairs(next);
                                    }}
                                    required
                                />
                            </label>
                            <label className="text-xs font-medium">
                                Right item
                                <input
                                    className={inputClass}
                                    value={pair.right}
                                    onChange={(event) => {
                                        const next = [...pairs];
                                        next[index] = { ...next[index], right: event.target.value };
                                        updatePairs(next);
                                    }}
                                    required
                                />
                            </label>
                            <label className="text-xs font-medium">
                                Correct match for left item
                                <select
                                    className={inputClass}
                                    value={String(answerKey.matches?.[pair.id] ?? '')}
                                    onChange={(event) =>
                                        onAnswerKeyChange({
                                            ...answerKey,
                                            matches: {
                                                ...(answerKey.matches ?? {}),
                                                [pair.id]: event.target.value,
                                            },
                                        })
                                    }
                                >
                                    <option value="">Choose right item</option>
                                    {pairs.map((candidate) => (
                                        <option key={candidate.id} value={candidate.id}>
                                            {candidate.right || candidate.id}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    ))}
                    <button
                        type="button"
                        className="focus-ring border-border min-h-10 rounded-lg border px-3 text-sm"
                        onClick={() =>
                            updatePairs([
                                ...pairs,
                                {
                                    id: 'pair-' + (pairs.length + 1),
                                    left: '',
                                    right: '',
                                },
                            ])
                        }
                        disabled={pairs.length >= 30}
                    >
                        Add matching pair
                    </button>
                </section>
            )}

            {questionType === 'ordering' && (
                <section className="space-y-3">
                    <h3 className="text-sm font-medium">Items and correct sequence</h3>
                    {items.map((item, index) => (
                        <div key={item.id} className="grid gap-2 sm:grid-cols-[1fr_12rem]">
                            <label className="text-xs font-medium">
                                Item {index + 1}
                                <input
                                    className={inputClass}
                                    value={item.text}
                                    onChange={(event) => {
                                        const next = [...items];
                                        next[index] = { ...next[index], text: event.target.value };
                                        updateItems(next);
                                    }}
                                    required
                                />
                            </label>
                            <label className="text-xs font-medium">
                                Correct position
                                <select
                                    className={inputClass}
                                    value={Math.max(
                                        0,
                                        (answerKey.order ?? items.map((row) => row.id)).indexOf(item.id),
                                    )}
                                    onChange={(event) => {
                                        const order = [...(answerKey.order ?? items.map((row) => row.id))];
                                        const oldIndex = order.indexOf(item.id);
                                        const newIndex = Number(event.target.value);
                                        const displaced = order[newIndex];
                                        order[newIndex] = item.id;
                                        order[oldIndex] = displaced;
                                        onAnswerKeyChange({ order });
                                    }}
                                >
                                    {items.map((_, position) => (
                                        <option key={position} value={position}>
                                            {position + 1}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    ))}
                    <button
                        type="button"
                        className="focus-ring border-border min-h-10 rounded-lg border px-3 text-sm"
                        onClick={() => {
                            const id = 'item-' + (items.length + 1);
                            updateItems([...items, { id, text: '' }]);
                            onAnswerKeyChange({
                                order: [...(answerKey.order ?? items.map((row) => row.id)), id],
                            });
                        }}
                        disabled={items.length >= 30}
                    >
                        Add sequence item
                    </button>
                </section>
            )}

            {['fill_blank', 'multiple_blanks'].includes(questionType) && (
                <section className="space-y-3">
                    <h3 className="text-sm font-medium">Blank keys and accepted answers</h3>
                    {blanks.map((key) => (
                        <label key={key} className="block text-sm font-medium">
                            {key}
                            <textarea
                                className={inputClass + ' min-h-20'}
                                aria-label={'Accepted answers for ' + key}
                                value={(answerKey.values?.[key] ?? []).join('\n')}
                                onChange={(event) =>
                                    onAnswerKeyChange({
                                        ...answerKey,
                                        values: {
                                            ...(answerKey.values ?? {}),
                                            [key]: event.target.value.split('\n'),
                                        },
                                    })
                                }
                                placeholder="One accepted answer per line"
                                required
                            />
                        </label>
                    ))}
                    <button
                        type="button"
                        className="focus-ring border-border min-h-10 rounded-lg border px-3 text-sm"
                        onClick={() => updateBlankKeys([...blanks, 'blank-' + (blanks.length + 1)])}
                        disabled={blanks.length >= 20}
                    >
                        Add blank
                    </button>
                </section>
            )}

            {questionType === 'numeric' && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="block text-sm font-medium">
                        Expected number
                        <input
                            className={inputClass}
                            inputMode="decimal"
                            value={String(answerKey.value ?? '')}
                            onChange={(event) =>
                                onAnswerKeyChange({ ...answerKey, value: event.target.value })
                            }
                            required
                        />
                    </label>
                    <label className="block text-sm font-medium">
                        Allowed tolerance
                        <input
                            className={inputClass}
                            inputMode="decimal"
                            value={String(scoringRules.tolerance ?? '0')}
                            onChange={(event) =>
                                onScoringRulesChange({ ...scoringRules, tolerance: event.target.value })
                            }
                            required
                        />
                    </label>
                </div>
            )}

            {questionType === 'short_text' && (
                <label className="block text-sm font-medium">
                    Accepted short answers
                    <textarea
                        className={inputClass + ' min-h-24'}
                        value={(answerKey.accepted_answers ?? []).join('\n')}
                        onChange={updateAcceptedAnswers}
                        placeholder="One accepted answer per line; leave blank for instructor review"
                    />
                </label>
            )}

            <fieldset className="grid gap-2 border-t pt-3 sm:grid-cols-2">
                <legend className="text-sm font-medium">Scoring rules</legend>
                <label className="flex min-h-10 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(scoringRules.partial_credit)}
                        onChange={(event) =>
                            onScoringRulesChange({ ...scoringRules, partial_credit: event.target.checked })
                        }
                    />
                    Allow configured partial credit
                </label>
                <label className="flex min-h-10 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(scoringRules.case_sensitive)}
                        onChange={(event) =>
                            onScoringRulesChange({ ...scoringRules, case_sensitive: event.target.checked })
                        }
                    />
                    Case-sensitive text matching
                </label>
                <label className="flex min-h-10 items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={Boolean(scoringRules.negative_marking)}
                        onChange={(event) =>
                            onScoringRulesChange({ ...scoringRules, negative_marking: event.target.checked })
                        }
                    />
                    Apply an incorrect-answer penalty
                </label>
                {Boolean(scoringRules.negative_marking) && (
                    <label className="block text-sm font-medium">
                        Penalty points
                        <input
                            className={inputClass}
                            inputMode="decimal"
                            value={String(scoringRules.penalty_points ?? '0')}
                            onChange={(event) =>
                                onScoringRulesChange({
                                    ...scoringRules,
                                    penalty_points: event.target.value,
                                })
                            }
                        />
                    </label>
                )}
            </fieldset>
        </fieldset>
    );
}

import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import {
    QuestionDefinitionEditor,
    type QuestionAnswerKey,
    type QuestionContent,
    type QuestionScoringRules,
} from '@/pages/workspace/assessments/question-definition-editor';

const examples: Record<string, QuestionContent> = {
    single_choice: {
        prompt: 'Choose one',
        options: [
            { id: 'a', text: 'A' },
            { id: 'b', text: 'B' },
        ],
    },
    multiple_choice: {
        prompt: 'Choose all',
        options: [
            { id: 'a', text: 'A' },
            { id: 'b', text: 'B' },
        ],
    },
    true_false: { prompt: 'Statement' },
    matching: {
        prompt: 'Match each pair',
        pairs: [
            { id: 'one', left: 'Left one', right: 'Right one' },
            { id: 'two', left: 'Left two', right: 'Right two' },
        ],
    },
    ordering: {
        prompt: 'Sequence',
        items: [
            { id: 'one', text: 'One' },
            { id: 'two', text: 'Two' },
        ],
    },
    fill_blank: { prompt: 'Complete it', blank_keys: ['answer'] },
    short_text: { prompt: 'Short answer' },
    long_response: { prompt: 'Explain' },
    numeric: { prompt: 'Calculate' },
    multiple_blanks: { prompt: 'Complete all', blank_keys: ['first', 'second'] },
    case_passage: { prompt: 'Respond to the case', passage: 'A case description.' },
    image_based: {
        prompt: 'Use the image',
        image_alt: 'A described diagram',
        options: [
            { id: 'a', text: 'A' },
            { id: 'b', text: 'B' },
        ],
    },
    file_response: { prompt: 'Upload a response' },
    instructor_review: { prompt: 'Write for review' },
};

const answerKeys: Record<string, QuestionAnswerKey> = {
    single_choice: { correct_option_ids: ['a'] },
    multiple_choice: { correct_option_ids: ['a'] },
    true_false: { value: true },
    matching: { matches: { one: 'one', two: 'two' } },
    ordering: { order: ['one', 'two'] },
    fill_blank: { values: { answer: ['accepted'] } },
    short_text: { accepted_answers: ['accepted'] },
    long_response: {},
    numeric: { value: '2' },
    multiple_blanks: { values: { first: ['one'], second: ['two'] } },
    case_passage: {},
    image_based: { correct_option_ids: ['a'] },
    file_response: {},
    instructor_review: {},
};

const formatControls: Record<string, string> = {
    single_choice: 'Answer options',
    multiple_choice: 'Answer options',
    true_false: 'Correct answer',
    matching: 'Matching pairs',
    ordering: 'Items and correct sequence',
    fill_blank: 'Blank keys and accepted answers',
    short_text: 'Accepted short answers',
    long_response: 'Question prompt',
    numeric: 'Expected number',
    multiple_blanks: 'Blank keys and accepted answers',
    case_passage: 'Case or passage',
    image_based: 'Image accessibility description',
    file_response: 'Question prompt',
    instructor_review: 'Question prompt',
};

function editorProps(questionType: string) {
    return {
        questionType,
        content: examples[questionType],
        answerKey: answerKeys[questionType],
        scoringRules: { tolerance: '0' } as QuestionScoringRules,
        onContentChange: vi.fn(),
        onAnswerKeyChange: vi.fn(),
        onScoringRulesChange: vi.fn(),
    };
}

describe('type-specific question authoring', () => {
    it.each(Object.keys(examples))('renders accessible fields for %s', (questionType) => {
        render(<QuestionDefinitionEditor {...editorProps(questionType)} />);

        expect(screen.getByRole('textbox', { name: 'Question prompt' })).toBeInTheDocument();
        expect(screen.getByText(formatControls[questionType])).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Allow configured partial credit' })).toBeInTheDocument();
        expect(
            screen.getByRole('checkbox', { name: 'Apply an incorrect-answer penalty' }),
        ).toBeInTheDocument();
    });

    it('adds a stable answer option and exposes the correct-answer selector', async () => {
        const user = userEvent.setup();
        const props = editorProps('single_choice');
        render(<QuestionDefinitionEditor {...props} />);

        await user.click(screen.getByRole('button', { name: 'Add option' }));

        expect(props.onContentChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                options: [
                    { id: 'a', text: 'A' },
                    { id: 'b', text: 'B' },
                    { id: 'option-3', text: '' },
                ],
            }),
        );
        expect(screen.getByRole('radio', { name: 'Correct answer for a' })).toBeChecked();
    });

    it('updates the numeric expected value and tolerance independently', async () => {
        const props = editorProps('numeric');
        render(<QuestionDefinitionEditor {...props} />);

        fireEvent.change(screen.getByRole('textbox', { name: 'Expected number' }), {
            target: { value: '12.5' },
        });
        fireEvent.change(screen.getByRole('textbox', { name: 'Allowed tolerance' }), {
            target: { value: '0.25' },
        });

        expect(props.onAnswerKeyChange).toHaveBeenLastCalledWith({ value: '12.5' });
        expect(props.onScoringRulesChange).toHaveBeenLastCalledWith({ tolerance: '0.25' });
    });

    it('updates the correct true/false answer through a keyboard-accessible radio group', async () => {
        const user = userEvent.setup();
        const props = editorProps('true_false');
        render(<QuestionDefinitionEditor {...props} />);

        await user.click(screen.getByRole('radio', { name: 'False' }));

        expect(props.onAnswerKeyChange).toHaveBeenCalledWith({ value: false });
    });
});

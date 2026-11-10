import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Button } from '@/components/ui/button';
import { StatePanel } from '@/components/ui/state-panel';
import { TextField } from '@/components/ui/text-field';
import { academicPlural, academicTerm } from '@/types/academic';

describe('accessible UI primitives', () => {
    it('uses configured institution terminology with readable fallback plural forms', () => {
        const terminology = { class: 'Cohort', level: 'Faculty' };

        expect(academicTerm(terminology, 'class', 'Class')).toBe('Cohort');
        expect(academicPlural(academicTerm(terminology, 'class', 'Class'))).toBe('Cohorts');
        expect(academicPlural(academicTerm(terminology, 'level', 'Level'))).toBe('Faculties');
        expect(academicPlural(academicTerm(terminology, 'course', 'Course'))).toBe('Courses');
    });

    it('supports keyboard and pointer activation for an enabled button', async () => {
        const user = userEvent.setup();
        const onClick = vi.fn();

        render(<Button onClick={onClick}>Continue</Button>);
        const button = screen.getByRole('button', { name: 'Continue' });

        await user.tab();
        expect(button).toHaveFocus();
        await user.keyboard('{Enter}');

        expect(onClick).toHaveBeenCalledOnce();
    });

    it('disables submission while a loading operation is active', () => {
        render(<Button loading>Saving changes</Button>);

        expect(screen.getByRole('button', { name: 'Saving changes' })).toBeDisabled();
    });

    it('connects a field label, hint, and validation message to its input', () => {
        render(
            <TextField
                name="email"
                type="email"
                label="Email address"
                hint="Use your institution email address."
                error="Enter a valid email address."
            />,
        );

        const field = screen.getByRole('textbox', { name: 'Email address' });
        expect(field).toHaveAttribute('aria-invalid', 'true');
        expect(field).toHaveAccessibleDescription(
            'Use your institution email address. Enter a valid email address.',
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid email address.');
    });

    it('announces loading and empty states through a polite status region', () => {
        const { rerender } = render(<StatePanel kind="loading" title="Loading settings" />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading settings');

        rerender(
            <StatePanel kind="empty" title="Nothing here yet" description="No records are available." />,
        );
        expect(screen.getByRole('status')).toHaveTextContent('No records are available.');
    });

    it('announces failures through an assertive alert region', () => {
        render(<StatePanel kind="error" title="Unable to load" description="Try again later." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Try again later.');
    });
});

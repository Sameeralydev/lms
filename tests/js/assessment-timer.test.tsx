import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AssessmentTimer } from '@/components/assessment-timer';

describe('assessment timer accessibility', () => {
    it('announces minute boundaries and expiry without announcing each second', () => {
        const { rerender } = render(<AssessmentTimer remaining={301} />);
        const announcement = screen.getByRole('status');

        expect(screen.getByRole('timer')).toHaveTextContent('Time remaining: 5:01');
        expect(announcement).toBeEmptyDOMElement();

        rerender(<AssessmentTimer remaining={300} />);
        expect(announcement).toHaveTextContent('5 minutes remaining.');

        rerender(<AssessmentTimer remaining={299} />);
        expect(announcement).toHaveTextContent('5 minutes remaining.');

        rerender(<AssessmentTimer remaining={240} />);
        expect(announcement).toHaveTextContent('4 minutes remaining.');

        rerender(<AssessmentTimer remaining={60} />);
        expect(announcement).toHaveTextContent('1 minute remaining.');

        rerender(<AssessmentTimer remaining={0} />);
        expect(announcement).toHaveTextContent('Time expired. Your saved responses are being submitted.');
    });
});

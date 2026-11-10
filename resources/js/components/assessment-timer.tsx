type AssessmentTimerProps = {
    remaining: number | null;
};

export function AssessmentTimer({ remaining }: AssessmentTimerProps) {
    if (remaining === null) return null;

    const minutes = Math.ceil(remaining / 60);
    const announcement =
        remaining === 0
            ? 'Time expired. Your saved responses are being submitted.'
            : minutes <= 5
              ? `${minutes} ${minutes === 1 ? 'minute' : 'minutes'} remaining.`
              : '';

    return (
        <>
            <p role="timer" aria-live="off" className="mt-4 font-semibold">
                Time remaining: {Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, '0')}
            </p>
            <p role="status" aria-live="polite" aria-atomic="true" className="sr-only">
                {announcement}
            </p>
        </>
    );
}

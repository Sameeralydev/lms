import '../css/app.css';
import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob<{ default: ResolvedComponent }>('./pages/**/*.tsx');

void createInertiaApp({
    title: (title) => (title ? `${title} · TechMentors.PK` : 'TechMentors.PK'),
    resolve: async (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return (await page()).default;
    },
    progress: { color: '#176bff' },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});

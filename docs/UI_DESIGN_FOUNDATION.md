# UI design foundation

## Brand presentation

Use a temporary text wordmark, “TechMentors.PK”, with deep navy, electric blue, cyan, and silver neutrals. No final logo asset was provided, so no logo has been fabricated.

## Semantic tokens

`resources/css/app.css` declares semantic Tailwind v4 tokens for canvas, surfaces, borders, primary/secondary text, brand colors, focus, danger, typography, and card shadow. Future themes should remap these semantic names instead of introducing theme-specific colors throughout components. The six-theme engine is reserved for Phase 05.

## Structure and behavior

- `AppLayout` provides a responsive header, primary navigation region, skip link, main content landmark, and footer.
- `Button`, `TextField`, and `StatePanel` provide reusable button, labeled input/error, and loading/empty/error patterns.
- Use semantic HTML and accessible names. Keep visible focus rings. Do not communicate status by color alone.
- Mobile navigation is a real keyboard-operable button with `aria-expanded` and `aria-controls`.
- Motion is restrained and reduced when `prefers-reduced-motion: reduce` is requested.
- No external font service or icon dependency is required; system fonts and a small inline check mark keep the shell self-contained.
- The home page describes the implementation foundation and does not show invented student or institution data.

## Responsive conventions

Use mobile-first layout, a 320 CSS-pixel minimum viewport, readable line lengths, and touch targets of at least 44 CSS pixels where practical. Validate keyboard behavior and overflow at narrow and desktop widths in Playwright.

# Hawii.tech — Landing Page

Bilingual (English LTR / Arabic RTL) landing page for Hawii.tech, an IT & AI solutions company.

## Files
- `index.html` — the page (open directly in any browser)
- `styles.css` — pre-compiled Tailwind CSS (bundled, no CDN required)

## Features
- **Bilingual**: header toggle switches between English and العربية; full RTL/LTR mirroring; choice persisted in `localStorage`.
- **Light & dark mode**: header toggle, respects `prefers-color-scheme`, persisted.
- **Design system**: "Trust & Authority" enterprise palette (brand blue + orange CTA accent), IBM Plex Sans Arabic typography (covers both scripts).
- **Sections**: hero + trust stats, client logos, IT & AI solutions (two pillars), why-us with live ops card, industries, process, testimonial, contact form, footer.
- **Accessible & responsive**: skip link, visible focus states, keyboard nav, `prefers-reduced-motion` support, mobile-first down to 375px.

## Editing styles
The CSS is compiled with Tailwind v3. To rebuild after changing classes in `index.html`:

```bash
npx tailwindcss@3 -c tailwind.config.js -i input.css -o styles.css --minify
```

> The contact form is a front-end demo — wire it to your backend/email service to receive submissions.

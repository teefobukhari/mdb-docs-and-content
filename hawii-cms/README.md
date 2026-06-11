# Hawii.tech CMS

Bilingual (English LTR + Arabic RTL) marketing site for **Hawii.tech** — IT & AI
solutions — with a full admin control panel. Built with **Laravel 13 + Filament v4
+ MySQL**.

## What's included

### Public site (all content admin-managed, EN/AR)
| Route | Page |
|-------|------|
| `/` | Home — hero, stats, IT & AI solutions, industries, testimonial, CTA |
| `/about` | About (editable page) |
| `/services`, `/services/{slug}` | Solutions overview + per-service detail |
| `/industries` | Industries grid |
| `/case-studies`, `/case-studies/{slug}` | Case studies + detail |
| `/blog`, `/blog/{slug}` | Blog index + post |
| `/contact` | Contact form (saves to the admin inbox) |
| `/p/{slug}` | Standalone pages (privacy, terms, …) |

Language switches with `?lang=ar` / `?lang=en` (persisted in session); dark mode toggle.

### Admin panel (`/admin`)
- **Content**: Services, Industries, Stats, Testimonials, Case Studies, Posts, Pages — full CRUD with side-by-side English/Arabic fields and rich-text editors.
- **Inbox**: Contact Submissions (read-only, unread badge).
- **Settings** (admin only): Site Settings (brand name, colors, hero copy, contact, social, footer) and Users.
- **Roles**: `admin` (full access incl. Users + Settings) and `editor` (content only).

## Local / server setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# edit .env DB_* for your MySQL database
php artisan migrate --seed     # creates tables + demo content + accounts
php artisan storage:link       # for uploaded images
php artisan serve              # or point your web server at /public
```

### Seeded accounts (change in production!)
| Role | Email | Password |
|------|-------|----------|
| Admin | `admin@hawii.tech` | `password` |
| Editor | `editor@hawii.tech` | `password` |

## Front-end CSS

The public site uses a pre-compiled Tailwind stylesheet at `public/css/app.css`
(committed, so no build step is required to deploy). To rebuild after editing
Blade classes:

```bash
npm install -D tailwindcss@3 @tailwindcss/typography
npx tailwindcss -c tailwind.config.js -i resources/css/tw-input.css -o public/css/app.css --minify
```

## Bilingual model

Translatable columns are stored as JSON `{"en": "...", "ar": "..."}` and read via
the `HasTranslations` trait's `->t('field')` helper (falls back en→ar→first).
Settings use a cached key/value store (`App\Models\Setting`).

## Deploying to cPanel / shared hosting
1. Upload the project; point the domain's document root at `public/`.
2. Create a MySQL database + user, set them in `.env`.
3. Run `composer install --no-dev`, `php artisan migrate --seed`, `php artisan storage:link`.
4. `php artisan config:cache route:cache view:cache` for production.

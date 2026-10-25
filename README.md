# Training Planner

A personal training planner built as **one Laravel monolith**. Laravel owns authentication, authorization, validation, uploads, and MySQL. Inertia connects normal Laravel web routes to React. Vite compiles the frontend to `public/build`; production needs only PHP and MySQL.

## What is included

- Registration, immediate login, login with remember-me, and logout. No email verification.
- Private training plans, reusable training/rest days, and ordered exercises.
- Sets, free-text reps/duration, notes, and simple supersets using matching group letters.
- Optional JPG, PNG, or WebP thumbnails, up to 2 MB each, with preview, replacement, removal, and cleanup.
- FullCalendar month, week, and list views; date clicks; session details; local status/type filters.
- Multiple sessions on the same date and standalone rest days.
- Planned, completed, and skipped statuses, including undoing completion. Only deletion asks for confirmation.
- Dashboard with today's sessions, the next seven days, and this week's workout counts. Rest days and skipped sessions never count as completed workouts.
- Responsive sidebar/navigation, empty states, validation errors, and loading states.

## Stack and requirements

- PHP **8.2 or newer**, Composer 2, Laravel 12, Inertia 2, React 19, Vite 6, FullCalendar 6.
- MySQL 8+ or a compatible MariaDB database with foreign-key support.
- PHP extensions: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, PDO MySQL, Session, Tokenizer, and XML. GD is needed for the image-generating test fixtures; PDO SQLite is needed for the default tests.
- Node.js 22 LTS and npm for developing/building assets. No Node process is needed on the host.
- Apache/LiteSpeed with rewrite support, or a correctly configured PHP web server.

The Composer lockfile targets PHP 8.2 so installing on a newer development machine does not silently introduce PHP 8.4/8.5-only production dependencies. Run `composer check-platform-reqs --no-dev` on the actual host.

## Local setup

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Create a MySQL database named `training_planner`, then configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. The example uses local MySQL. Set `APP_URL` to your local URL and `APP_TIMEZONE` to the timezone your calendar should use (default: `Asia/Riyadh`). Weeks for dashboard statistics run Monday–Sunday; dates have no times in this MVP.

```sh
php artisan migrate
php artisan storage:link
npm ci
npm run build
php artisan serve
```

Open `http://127.0.0.1:8000`. With built assets, **PHP alone serves the entire app**. For hot reloading, run `npm run dev` in another terminal instead of rebuilding after each edit. `composer dev` starts the PHP server and Vite together; it does not start a queue worker.

### Optional local demo

```sh
php artisan db:seed
```

This creates the following **local-only demo account**:

- Email: `demo@example.com`
- Password: `Training123!`

The demo includes Beginner Weekly Plan, Leg Day, Shoulder Day, Cardio, Rest Day, Back Day, exercises, supersets, and sessions relative to the current date. It is safe to run again without duplicating the demo plan. Both seeders refuse to insert demo data outside `APP_ENV=local`. Never copy your development database into production.

## Database structure

| Table | Purpose and relationships |
| --- | --- |
| `users` | Standard session-authenticated users. |
| `training_plans` | Belongs to a user; name and optional description. |
| `training_days` | Belongs to a plan; title, training/rest type, focus, notes, position. |
| `training_exercises` | Belongs to a day; name, sets, text reps, superset group, thumbnail path, notes, position. |
| `schedule_entries` | Belongs to a user; nullable day link, title/type/focus snapshot, date, status, notes. Indexed on user/date, **not unique**. |

Laravel's standard authentication migration also contains session and password-reset-token tables; no password-reset flow is exposed in this MVP. The configured session driver is `file`.

Plan/day deletion cascades to exercises and removes their thumbnails. Scheduled entries survive: the day foreign key becomes null and title/type/focus remain readable. Exercises are displayed from the current linked template, **not snapshotted per session**. Deleting a template removes its exercise detail from past sessions, while keeping the session history.

## Important files

- `routes/web.php`: application and authentication routes; all planner routes require authentication.
- `app/Http/Controllers/`: auth, dashboard, plan/day CRUD, transactional exercise saving, calendar and session actions.
- `app/Http/Requests/`: server validation and authorization of submitted data.
- `app/Policies/`: ownership checks for plans, days, and sessions.
- `app/Models/`: the five core models and relationships.
- `database/migrations/2026_10_25_000001_create_training_tables.php`: training schema.
- `resources/js/Pages/`: Inertia page components.
- `resources/js/Features/`: exercise, plan, schedule, and status forms.
- `resources/js/Components/`: layout, modal, and shared UI.
- `resources/css/app.css`: responsive application styling.
- `tests/Feature/TrainingPlannerTest.php`: focused regression and ownership tests.

Exercises are edited in local React state, then submitted together with the training day in one transaction. Thumbnails use generated filenames under `storage/app/public/exercise-thumbnails`. Superset letters are normalized to uppercase. Reorder endpoints accept only a complete, unique list of IDs from the authorized parent.

## Testing and production build

```sh
php artisan test
npm run build
```

The default test suite uses an isolated in-memory SQLite database. To check MySQL, create a **dedicated disposable test database**, then run:

```sh
DB_CONNECTION=mysql DB_DATABASE=training_planner_test php artisan test
```

Never point the test suite at your development or production database: `RefreshDatabase` recreates test tables. Tests cover authentication, ownership on reads/writes/status changes, forged relationship IDs, exercise validation, uploads/replacement/removal, ordering, calendar snapshots, multiple same-date sessions, completion counts, and reversible statuses.

Verified on 25 October 2026: **30 tests, 254 assertions** passed on both SQLite and MySQL. The production Vite build and Laravel production cache commands passed. Composer/npm audits reported no known vulnerabilities. Browser checks covered login, plan creation/editing, workout creation with image preview/upload, scheduling training and standalone rest days, dashboard completion, status undo/skip/complete, calendar navigation/views/filters, and mobile layouts. The browser QA records were removed afterward; only the optional demo remains locally.

## Hostinger / shared hosting deployment

Deployment is intentionally pending until the hosting details are available. Confirm the PHP version, SSH/Composer access, database credentials, domain, and permitted document root before deployment. Hostinger panel paths and features differ by hosting plan.

### Preferred: point the domain to Laravel's `public` directory

Keep the application outside the public web root:

```text
training-planner/
  app/ bootstrap/ config/ database/ resources/ routes/
  storage/ vendor/ .env
  public/                 <- domain document root
    index.php .htaccess build/ images/ storage -> ../storage/app/public
```

1. Build assets locally with `npm ci && npm run build`.
2. Upload source plus `public/build` and the static files in `public`. Exclude `.env`, `.git`, `node_modules`, tests, local logs/sessions, and local demo uploads. Include the `storage` directory structure and its writable subdirectories. Never upload `public/hot`.
3. On the host, run `composer install --no-dev --prefer-dist --optimize-autoloader`. If Composer is unavailable there, prepare `vendor` on a machine compatible with the host and upload it. Verify the host PHP version and extensions.
4. Create a fresh production `.env` and configure the real domain/database:

```dotenv
APP_NAME="Training Planner"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_TIMEZONE=Asia/Riyadh
DB_CONNECTION=mysql
DB_HOST=your-database-host
DB_PORT=3306
DB_DATABASE=your-database-name
DB_USERNAME=your-database-user
DB_PASSWORD=your-database-password
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_LEVEL=warning
```

5. Run the following, generating an app key only on the **first** deployment. Preserve that key on every update.

```sh
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan optimize
composer check-platform-reqs --no-dev
```

6. Give the PHP user write access to `storage` and `bootstrap/cache` (typically `775` directories and `664` files when ownership is correct; do not use blanket `777`). Keep the provided `public/.htaccess`.
7. Enable HTTPS, verify registration/login, create a plan, upload a thumbnail, schedule a session, and mark it complete. Verify protected files such as `/.env` are not served. Use a new real account; no demo seeding in production.

PHP settings should permit the intended uploads. Each file is limited by the app to 2 MB; `post_max_size` must also accommodate all thumbnails submitted with a day, and `max_file_uploads` limits how many new images can be uploaded in one save. A practical starting point is `upload_max_filesize=2M`, `post_max_size=32M`, `max_file_uploads=20` within the host's limits. Keep `max_input_vars` at least 2000 for large exercise lists. If saving many new images exceeds the host limit, save in smaller batches.

No `npm run dev`, Node server, Docker, Redis, Supervisor, PM2, queue worker, cron, or SSR service is required.

### Fallback: fixed `public_html` document root

If the host cannot point the domain at `training-planner/public`, use sibling directories:

```text
site-directory/
  training-planner/        <- complete application, private
  public_html/             <- only contents of training-planner/public
```

Copy only public files (including `.htaccess`, built assets, images, and favicon) into `public_html`. In `public_html/index.php`, use:

```php
<?php
use Illuminate\Http\Request;
define('LARAVEL_START', microtime(true));
if (file_exists($maintenance = __DIR__.'/../training-planner/storage/framework/maintenance.php')) {
    require $maintenance;
}
require __DIR__.'/../training-planner/vendor/autoload.php';
$app = require_once __DIR__.'/../training-planner/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
```

The default `storage:link` targets the project's `public` directory. For the fallback layout, also create the link from the actual web root, running from `site-directory`:

```sh
ln -s ../training-planner/storage/app/public public_html/storage
```

Check an existing path before creating a link; do not replace a real directory containing uploads. Confirm symlinks are allowed on the plan. Preserve `storage/app/public` across deployments. Keep the same compiled assets in the project's `public/build` for CLI cache commands and in `public_html/build` for browser requests.

Do **not** move `.env`, `vendor`, application source, or private storage into `public_html`. Do not rely on `.htaccess` to protect an application uploaded wholesale into the public directory.

### Updates

Back up the database and uploaded files. Upload changed source and rebuilt assets, preserve `.env`/`APP_KEY` and uploads, run `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, then `php artisan optimize:clear` and `php artisan optimize`. Keep the previous release available for rollback; review migrations before applying them to existing data.

## MVP boundaries

Completion is tracked at the session level. There are no timers, per-set completion, weights, recurrence, coach/admin roles, social features, notifications, payments, AI, or separate API/frontend. Scheduling uses dates only. Account recovery/password reset is outside the requested login/register/logout scope. Calendar loading is bounded to the visible month plus one week on either side; the plans index is paginated.

## Credits and framework references

The bundled gym photograph is from [Unsplash](https://images.unsplash.com/photo-1534438327276-14e5300c3a48). DM Sans and Manrope load from Google Fonts with system-font fallbacks. App operation does not depend on the font service. Framework setup follows [Laravel's installation guidance](https://laravel.com/docs/12.x/installation); production should follow the installed Laravel version's [deployment guidance](https://laravel.com/docs/12.x/deployment).

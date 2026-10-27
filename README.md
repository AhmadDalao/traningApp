# Training Planner

A personal training planner built as **one Laravel monolith**. Laravel owns authentication, authorization, validation, uploads, and MySQL. Inertia connects normal Laravel web routes to React. Vite compiles the frontend to `public/build`; production needs only PHP and MySQL.

## Release baseline

**v1.0.0 — 27 October 2026** captures the application deployed to Hostinger on 26 October, including exercise-library supersets, weekday scheduling, and training history. Source: [AhmadDalao/traningApp](https://github.com/AhmadDalao/traningApp); release tag: [v1.0.0](https://github.com/AhmadDalao/traningApp/tree/v1.0.0). See [CHANGELOG.md](CHANGELOG.md) and the [project report](docs/PROJECT-REPORT-2026-10-27.html) for the feature baseline and future update plan.

Release verification on 27 October: **64 PHP tests / 1,049 assertions**, **13 JavaScript tests**, and the production build passed. The repository includes source, dependency lockfiles, tests, documentation, and bundled library images. Environment credentials, user uploads, database backups, deployment helpers, installed dependencies, and generated build assets are excluded.

## What is included

- Registration, immediate login, login with remember-me, and logout. No email verification.
- Private training plans, reusable training/rest days, and ordered exercises.
- A searchable library of 876 exercises with muscle/equipment filters, automatic icons, and 873 bundled photos. Three entries use an icon because the source has no photo.
- An **Other / create custom** option saves private, reusable exercises with optional thumbnails and custom icons.
- Optional plan start/end dates and workout weekdays, with a schedule preview before adding matching dates to Calendar.
- Sets, free-text reps/duration, notes, and supersets using an existing workout exercise or a partner selected directly from the library. Selecting a partner updates both exercises; choosing no superset unlinks them.
- Searchable pickers with 19 built-in icons for training days and exercises, plus automatic icons based on focus/type. Day icons also appear on scheduled sessions and the calendar.
- Optional JPG, PNG, or WebP thumbnails, up to 2 MB each, with preview, replacement, removal, and cleanup.
- FullCalendar month, week, and list views; date clicks; session details; local status/type filters.
- Multiple sessions on the same date and standalone rest days.
- Planned, completed, and skipped statuses, including undoing completion. Only deletion asks for confirmation.
- Optional target weight per exercise and actual weight per completed session exercise, in kilograms. Blank weights never block saving or completion.
- One-click completion saves its timestamp and a frozen workout, including exercise images. Later template edits/deletion do not change that record.
- Training History with past sessions, summary counts, date-period/status/type/plan filters, selectable page sizes, and shared session details.
- Optional session time, shown in the calendar and session details.
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

Create a MySQL database named `training_planner`, then configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. The example uses local MySQL. Set `APP_URL` to your local URL and `APP_TIMEZONE` to the timezone your calendar should use (default: `Asia/Riyadh`). Weeks for dashboard statistics run Monday–Sunday. Optional session times and the Training History cutoff use the application timezone; all users share that timezone.

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

### Populated preview for layout checks

```sh
php artisan db:seed --class=PreviewSeeder
```

This local-only seeder adds sample data without replacing existing accounts, plans, or sessions. It can be run again without duplicating the sample batch or overwriting edits.

| Local account | Password | Added sample data |
| --- | --- | --- |
| `demo@example.com` | `Training123!` | 13 plans, 148 sessions spanning past months and the next three weeks |
| `maya.demo@example.com` | `Training123!` | 4 plans, 82 sessions |

Samples include completed, skipped, planned, and rest sessions; snapshots with partial/full/blank actual weights; icons, images, supersets, an empty plan, long titles and notes, a 24-exercise workout, and busy calendar dates. The existing main demo therefore has 14 plans and 157 sessions after this batch. Sample plans start with **Sample ·** and session notes identify the data as fictional. Use Training History pagination, the calendar's **more** popup, and the long-workout plan to inspect crowded layouts. These accounts and records are not added to production.

The populated preview exposed and fixed clipped exercise names, overwide weight/image forms, crowded Overview actions at 320px, oversized builder columns, and calendar list/popover overflow. Checks covered 320, 390, 820, 1024, and 1440px widths, filtered history pagination, both accounts, and all calendar views. Long exercise tables scroll inside their panel on smaller screens; calendar popovers wrap their titles and scroll internally when needed.

## Database structure

| Table | Purpose and relationships |
| --- | --- |
| `users` | Standard session-authenticated users. |
| `training_plans` | Belongs to a user; name, optional description and start/end dates. |
| `training_days` | Belongs to a plan; title, training/rest type, focus, icon, notes, position, optional weekdays and usual time. |
| `training_exercises` | Belongs to a day; name, icon, sets, text reps, optional decimal target weight, superset group, thumbnail/custom-icon paths, library key, notes, position. |
| `exercise_library_items` | Private custom exercises belonging to a user, with name, focus, icon, and independent media copies. Built-in exercises use bundled JSON instead. |
| `schedule_entries` | Belongs to a user; nullable day link, title/type/focus/icon, date, optional time, status, notes, nullable completion timestamp and JSON workout snapshot. Indexed on user/date, **not unique**. Nullable unique plan-occurrence key prevents duplicate generated sessions. |

Laravel's standard authentication migration also contains session and password-reset-token tables; no password-reset flow is exposed in this MVP. The configured session driver is `file`.

Plan/day deletion cascades to exercises and removes their template thumbnails. Scheduled entries survive: the day foreign key becomes null and title/type/focus/icon remain readable. Completed workouts keep their snapshot and separate thumbnail copies. Uncompleted sessions without a snapshot show the linked template; deleting that template leaves their session metadata available.

### Exercise library and weekday schedules

Open **Add exercise** to search the built-in library or your private custom exercises. Filter by muscle/focus and equipment, select a card, then enter sets, reps/duration, optional target weight, and optional superset partner. The name, photo, and suggested icon are filled automatically. You can replace the photo, select a different built-in icon, or upload a square JPG/PNG/WebP icon: **128 × 128 px recommended, 64–512 px accepted, maximum 512 KB**. Exercise thumbnails still accept JPG/PNG/WebP up to 2 MB. **Other / create custom** lets you type a name; saving the training day also saves that exercise to your private library. Other accounts cannot select it. Library, workout-template, and completed-history media have independent copies so deleting a template preserves reusable custom exercises and completed records.

Edit a plan to set inclusive start/end dates. In each training/rest day, choose weekdays and an optional usual time; shortcuts are **Weekdays** (Sunday–Thursday), **Weekends** (Friday–Saturday), **Every day**, and **Clear**. On the plan page, **Preview schedule** lists matching dates, then **Add sessions to Calendar** creates them. Saving dates or weekdays alone does not create sessions. Only today and future dates are generated; existing sessions are kept, repeat submissions do not duplicate them, and manually moved generated sessions remain moved. Later plan changes do not delete or reschedule existing calendar entries. Manual scheduling remains available. A preview supports a period of up to one year and at most 1,000 sessions.

`2026_10_26_000004_add_exercise_library_and_plan_schedule.php` is additive and is applied locally and on Hostinger. The exercise-library, direct library supersets, and weekday-schedule release was deployed on 26 October 2026 with its migration, application changes, rebuilt assets, `resources/data/exercise-library.json`, and `public/exercise-library`. The production database, environment, and uploads were preserved.

Built-in metadata/photos come from [Free Exercise DB](https://github.com/yuhonas/free-exercise-db), released into the public domain under the Unlicense. The import is pinned to commit `f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5`; provenance and license are included in `public/exercise-library/SOURCE.json` and `LICENSE.md`. Photos total approximately 49 MB and load lazily from this application, without a runtime third-party image service. Run `python3 scripts/import-exercise-library.py` to reproduce the catalog and download missing images from that pinned revision.

### Completion, weights, and history

`2026_10_26_000003_add_workout_history.php` is an additive migration. Run `php artisan migrate` on an existing installation; it preserves accounts, plans, sessions, and uploads. No backfill invents snapshots or completion timestamps for older completed sessions. Those records remain visible with an explicit message that their original exercise record is unavailable.

The first completion copies the exercise names, ordering, sets, reps, target weights, supersets, notes, icons, and thumbnails into a versioned JSON record. Actual weights start blank. **Edit actual weights** updates only the completed session's saved exercises, never the training template. Target and actual weights accept 0–9999.99 kg with up to two decimal places; every field may be blank.

Changing back to Planned or Skipped clears `completed_at` and retains the saved workout and actual weights. Completing again sets a new completion timestamp while keeping that original record. Repeated completion requests do not replace an existing record or timestamp. A session with a saved workout can still change its title, date, time, notes, or status, but choosing another training day requires a new session. Snapshot thumbnails live under `storage/app/public/workout-thumbnails` and are removed when their session is deleted.

Training History includes dates before today and today's entries whose optional time has passed. Date-only entries for today are included. Future entries are excluded regardless of status. Completed and Skipped totals count training sessions only; Rest Days count separately. Summary cards follow period, plan, and session type filters, while status filters narrow the list. Periods are All time (default), Today, Yesterday, Last 7 days, Last 30 days, This month, and Custom with inclusive From/To dates. Presets use the application timezone; the last 7/30 days include today. Deleted plans' records remain under All training plans; deleted training days still match their surviving plan through the saved plan ID. Page sizes are 10 (default), 25, 50, 100, or All, and pagination keeps every active filter. Reset clears filters while keeping the selected page size. History period presets apply immediately on selection. Custom From/To dates use Apply filters, as do training-plan and session-type changes. All periods filter the same scheduled dates used by Calendar; they do not change Calendar’s displayed month/week or its filters.

Overview's Today’s sessions and Up next panels have matching 520px scroll areas, sized for roughly three standard Today cards. Their headings stay visible while the lists scroll, and keyboard users can focus either region to scroll it. Up next includes every session in the next seven days rather than stopping at eight. These layout and filter updates are available locally and on Hostinger.

## Important files

- `routes/web.php`: application and authentication routes; all planner routes require authentication.
- `app/Http/Controllers/`: auth, dashboard, plan/day CRUD, transactional exercise saving, calendar and session actions.
- `app/Http/Requests/`: server validation and authorization of submitted data.
- `app/Policies/`: ownership checks for plans, days, and sessions.
- `app/Models/`: planner, private exercise-library, and user models and relationships.
- `app/Services/ExerciseLibrary.php` and `PlanSchedule.php`: owned library selection/media copying and calendar schedule previews.
- `database/migrations/2026_10_25_000001_create_training_tables.php`: training schema.
- `resources/js/Pages/`: Inertia page components.
- `resources/js/Features/`: exercise, plan, schedule, and status forms.
- `resources/js/Components/`: layout, modal, and shared UI.
- `resources/css/app.css`: responsive application styling.
- `tests/Feature/TrainingPlannerTest.php`: focused regression and ownership tests.
- `tests/Feature/TrainingHistoryTest.php`: history filters/ownership, optional weights, completion timestamps, snapshot preservation, and legacy records.
- `tests/Feature/ExerciseLibraryAndPlanScheduleTest.php`: exercise selection, custom-library ownership, icon validation and preservation, plan dates, weekday previews, stale submissions, and duplicate prevention.

Exercises are edited in local React state, then submitted together with the training day in one transaction. Thumbnails use generated filenames under `storage/app/public/exercise-thumbnails`. Supersets still use a simple group string in storage; the dropdown assigns it automatically, supports joining an existing group, and removes single-member groups when an exercise is unlinked or removed. **Choose partner from library** opens the existing library and exercise form, where the partner gets its own sets, reps/duration, weight, images, and notes. **Use this partner** returns to the original exercise without losing its draft. **Add linked exercises** inserts both rows together, with the partner immediately after the first exercise. Cancelling either step does not add a stray workout exercise. The dropdown remains available for existing workout exercises. Icon IDs are validated against the shared catalog in `resources/data/training-icons.json`. Reorder endpoints accept only a complete, unique list of IDs from the authorized parent.

## Testing and production build

```sh
php artisan test
npm test
npm run build
```

The default test suite uses an isolated in-memory SQLite database. To check MySQL, create a **dedicated disposable test database**, then run:

```sh
DB_CONNECTION=mysql DB_DATABASE=training_planner_test php artisan test
```

Never point the test suite at your development or production database: `RefreshDatabase` recreates test tables. Tests cover authentication, ownership on reads/writes/status changes, forged relationship IDs, exercise validation, uploads/replacement/removal, ordering, calendar snapshots, multiple same-date sessions, completion counts, and reversible statuses.

The automated suite covers authentication, ownership, existing planner behavior, optional weights, completion timestamps, history filters and pagination, snapshot preservation after template edits/deletion, image preservation/cleanup, and status reversal. JavaScript tests cover the superset dropdown and direct library partners, including independent prescriptions/media, ordering, unlinking, unused partners, and duplicate prevention. Browser verification includes desktop and mobile layouts, the exercise builder, scheduling, completion, history filters, and recording actual weights.

Verified on 26 October 2026: the history and library/scheduling suites pass on SQLite and MySQL; the full MySQL run has **64 tests / 1,049 assertions**. The 13 JavaScript superset tests and production Vite build also pass. Browser checks cover library search/selection, custom photo/icon uploads, private exercise reuse, weekday selection, schedule preview, calendar insertion, and duplicate prevention. The new picker, custom editor, and schedule preview have no horizontal overflow at 320px; earlier history/calendar checks also cover 390px and 820px. The local **Sample · Exercise Library & Weekly Schedule** plan contains three training/rest days and six generated sessions for reviewing this flow, including **Sample · Superset from Library**. Existing accounts and sample records remain available.

## Hostinger / shared hosting deployment

Deployed on 26 October 2026 at **https://training.ahmaddalao.com** using PHP 8.3 and MariaDB. The public web root is `/home/u867436826/domains/ahmaddalao.com/public_html/training`; Laravel and its production environment live outside it at `/home/u867436826/domains/ahmaddalao.com/training-planner`. Public uploads use a symlink to the private application storage. The production build includes the exercise library, custom icons, direct library supersets, plan scheduling, history filters, and weekday shortcuts. At the user's request, a separate preview account was created with 2 clearly labelled sample plans, 45 sessions, and 17 history records; the local demo seeders were not run in production. Its password is kept out of source control.

The FTP account is restricted to the training web root. Future releases must preserve the existing private `.env`, `APP_KEY`, database, and uploads; do not rerun a fresh installer over the live application. Use Hostinger File Manager or SSH to manage files outside the FTP root. The guidance below documents the two supported layouts; this deployment uses the fallback public-root layout.

Live verification passed for registration, login/logout, plan/workout creation, icon selection, superset pairing, image uploads, calendar scheduling, completion totals, and mobile workout/calendar layouts at 390px. Temporary QA data, the installer, and the upload archive were removed afterward. Production debug mode is off and session cookies require HTTPS. The private application directory uses mode `0711` so the web server can traverse to public uploads; `.env` stays `0600`. Preserve these permissions when deploying updates.

The 26 October history update is live. Its additive migration, production caches, optional target/actual weights, saved thumbnails, timestamps, history month/plan filters, and 390px session layout were verified on Hostinger. A database dump, uploads archive, previous source files, and environment backup are kept privately under `storage/app/private/deploy-backups/history-20261026-103603-r2`. Existing-data fingerprints matched before and after the update. The temporary QA account, updater endpoint, and upload archive were removed. The hosting database requires `REPEATABLE READ` for a consistent-snapshot backup transaction.

The later 26 October release, `planner-20261026-7487adec`, deployed the complete library/scheduling update and **Weekdays** (Sunday–Thursday), **Weekends** (Friday–Saturday), **Every day**, and **Clear** shortcuts. Before deployment, 64 PHP tests / 1,049 assertions, 13 JavaScript tests, and the production build passed. Live checks verified login, 876 library entries, library partner creation and saving, independent superset prescriptions, preserved completed-workout snapshots, schedule preview without duplicates, all four shortcuts, history's default 10 items, and Today showing only 26 October records. Both dashboard lists have a 520px scroll region. The deployed frontend bundles and origin exercise photos match the local release. A fresh database dump, uploads archive, previous files, and environment backup are private under `storage/app/private/deploy-backups/planner-20261026-7487adec`; existing-data fingerprints matched across deployment before adding the requested preview account. The temporary updater and upload archive were removed and return 404; the login and health routes return 200. The local deployment connection file was removed after verification.

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

Completion is tracked at the session level. Weights are optional per exercise, never per set, and use kilograms only. Weekday scheduling creates a finite set of calendar entries within plan dates; it is not an ongoing background recurrence service. There are no timers, per-set completion, weight progression analytics, coach/admin roles, social features, notifications, payments, AI, or separate API/frontend. Account recovery/password reset is outside the requested login/register/logout scope. Calendar loading is bounded to the visible month plus one week on either side; plans and history are paginated.

## Credits and framework references

The bundled gym photograph is from [Unsplash](https://images.unsplash.com/photo-1534438327276-14e5300c3a48). DM Sans and Manrope load from Google Fonts with system-font fallbacks. App operation does not depend on the font service. Framework setup follows [Laravel's installation guidance](https://laravel.com/docs/12.x/installation); production should follow the installed Laravel version's [deployment guidance](https://laravel.com/docs/12.x/deployment).

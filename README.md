# Training Planner

A personal training and coaching planner built as **one Laravel monolith**. Laravel owns authentication, authorization, validation, uploads, and MySQL. Inertia connects normal Laravel web routes to React. Vite compiles the frontend to `public/build`; production needs only PHP and MySQL.

## v2.1.1 Full Lifecycle Stabilization — deployed and verified

Full personal and coach lifecycle QA fixes held History actions, paused-workout completion rates, silent stale Start errors and invalid History redirects after reopening early-completed sessions. Program pages put the next workout first, make Resume primary and keep End secondary. Coach schedule review now uses 34 queries for both 12 and 156 workouts, with shared authorization checks and unchanged ownership rules.

**READY — deployed and verified on Hostinger, 30 October 2026. Architecture Health: GOOD.** Deployed directly from v2.0.1 using immutable tag `v2.1.1` (`b8fad5e`), including the existing additive v2.1 migration. Fresh backups, 252 file hashes, 150 live HTTP checks and 428 Chromium/WebKit checks pass. All 13 live QA accounts/media and temporary deployment files are removed; the original 19 production table fingerprints, 60 media files and environment match the backup. No dependency change.

Source QA: **504 tests / 10,877 assertions per database**, **46 concurrency checks**, **28 JavaScript tests**, **862 browser checks** and **133 local HTTP checks**. Build and formatting pass. All 19 original local table fingerprints and 44 local media hashes match after removing 17 disposable accounts and 1,252 QA images.

[Full 50-section stabilization report](docs/V2.1.1-FULL-LIFECYCLE-STABILIZATION-REPORT.html) · [Verification evidence and findings](docs/v2.1.1-qa/verification.json). Browser fixtures and credentials are private; run shared-account browser suites and database suites sequentially.

## v2.1.0 Program Control & Flexibility — source release, deployment held

Pause with a known return date or indefinitely, explicitly resume on a chosen date, shift the remaining schedule, recover missed workouts and end a run early. Signed previews preserve completed/skipped/saved workouts, manual exceptions, logical weeks, block identity and frozen prescriptions. Coaches retain existing assignment/subscription boundaries; trainees cannot self-enable Coach Workspace.

**Initially source only; subsequently deployed as part of v2.1.1 on 30 October 2026.** v2.1.0 was not deployed separately. One additive migration extends `program_runs`; no rebuild or dependency changes. Verification: 499 tests / 10,635 assertions on each database, 46 concurrency checks, 28 JavaScript tests, 742 browser checks, build and formatting pass. Five local QA accounts and 554 generated images removed; 19 original table fingerprints and 44 media hashes match.

[Full 45-section v2.1 report](docs/V2.1-PROGRAM-CONTROL-FLEXIBILITY-REPORT.html) · [Verification evidence](docs/v2.1.0-qa/verification.json). Run shared-account browser flows and the two database suites sequentially.

## v2.0.1 Program Lifecycle QA & Stabilization — deployed and verified

Seven confirmed defects fixed: coach locks on current/resolved blocks and day ordering, protected-session preview accuracy, future edits into elapsed times, early-completed program History access, and repeated summary metadata queries. Existing architecture, simple plans, actual workout data and authentication are preserved. No migration or dependency changes.

Verification: **478 PHP tests / 10,265 assertions on each of SQLite and isolated MySQL**, **31 concurrency checks**, **28 JavaScript tests**, and **536 browser checks across Chromium/WebKit**. Build, Pint, scoped Prettier and whitespace checks pass. Four disposable accounts and 452 QA images were removed; all 19 original table fingerprints and 44 original media hashes match.

[Full v2.0.1 lifecycle stabilization report](docs/V2.0.1-PROGRAM-LIFECYCLE-STABILIZATION-REPORT.html). **READY — deployed and verified on Hostinger** after the subsequent request to push the update. Tag `v2.0.1` remains on `5751231`. Six PHP files updated, all 112 release hashes verified, 141 live HTTP checks and 150 live browser checks passed. No migration; original production data, uploads and settings are preserved. Three live QA accounts and temporary deployment files are removed. The v2.0.0 tag is unchanged. Browser scripts require disposable local fixtures and private credentials; run shared-account workout flows sequentially. End Program Early remains intentionally deferred.

## v2.0.0 Structured Programs, Blocks & Lifecycle

Finite programs now sit alongside existing simple plans: ordered normal/deload blocks, independent prescriptions, an explicit schedule preview, logical week advancement, missed-workout handling, completion summaries and separate repeat runs. Coaches can assign independent trainee programs, start reviewed schedules and edit eligible future blocks. Four authored programs join the existing plan catalog.

Verification: **454 PHP tests / 9,715 assertions** on SQLite and isolated MySQL, **28 JS tests**, **28 concurrency checks**, **133 local HTTP checks**, **90 personal + 60 coach browser checks in each of Chromium and WebKit**, and **37 existing mobile regressions in each engine**. Seven widths from 320–1280px pass. Three local QA accounts and 524 QA images were removed; all 19 table fingerprints and 44 original media hashes match. One additive migration; existing simple plans and workout records are preserved. End Program Early is deferred under the brief's allowance.

[Full v2.0 report, architecture and verification evidence](docs/V2.0-PROGRAMS-BLOCKS-LIFECYCLE-REPORT.html). **READY — deployed and verified on Hostinger.** Tag `v2.0.0` identifies `464c07c`. All 112 runtime/build hashes, 133 live HTTP checks, 300 live program/coach browser checks and 37 existing mobile regressions pass. The additive migration is applied; original production data, uploads and settings are preserved. Three live QA accounts and temporary deployment files are removed.

## v1.9.1 Mobile QA & Stabilization

Eight confirmed UI defects fixed: mobile text and touch targets, crowded session actions, tablet History, long-name wrapping, modal focus, short-screen navigation and repeated workspace selection. Existing features, authentication, coaching ownership and stored results are preserved.

**434 responsive checks**, **37 Chromium + 37 WebKit regressions**, **434 PHP tests / 8,778 assertions on SQLite and MySQL**, **27 JS tests**, **21 concurrency checks** and **119 local HTTP workflow checks** pass. Five QA accounts and 130 images were removed; original data/media fingerprints match. Viewport emulation and native preview were used; physical phones were not tested.

[Full v1.9.1 report and before/after evidence](docs/V1.9.1-MOBILE-QA-STABILIZATION-REPORT.html). **READY — deployed and verified on Hostinger.** Tag `v1.9.1` identifies `f9e650d`. All 64 deployed file hashes, 119 live HTTP checks, 252 live responsive checks and 37 live browser regressions pass. Three production QA accounts and temporary deployment tools are removed; original production data, uploads and settings match their pre-release fingerprints. The browser regression script is [tests/Browser/mobile-stabilization.mjs](tests/Browser/mobile-stabilization.mjs); its header documents private fixture inputs and optional Playwright module selection.

## v1.9.0 Plan Discovery & Personalization

Three ordered training goals, a 19-plan curated library, deterministic recommendations, combined filters/search, full exercise previews and independent personal-plan copies. Returning users can update the original preference steps without resetting onboarding or duplicating unchanged weight entries. Advanced plans remain selectable by every experience level. Existing Calendar, workouts, History, Progress and coaching ownership are preserved.

**434 PHP tests / 8,778 assertions** pass on SQLite and isolated MySQL, plus **27 JS tests**, **21 concurrency checks**, **119 authenticated local workflow checks** and **20 responsive checks** at 320/360/390/430/1280px. Build and formatting pass. Four temporary accounts and 118 QA images are removed; all 17 original table fingerprints and 44 media hashes match. No schema or dependency changes.

[Full v1.9 report](docs/V1.9-PLAN-DISCOVERY-PERSONALIZATION-REPORT.html). **Deployed to Hostinger on 29 October 2026.** All 72 deployed file hashes, 119 live HTTP checks and 20 live responsive checks pass. Production data, uploads and settings match the private backup; temporary QA accounts and deployment files are removed. Tag `v1.9.0` identifies `cdbb856`. The standing finish → verify → commit → tag → push → deploy rule is saved in [AGENTS.md](AGENTS.md).

## v1.8.0 Smart Calendar — deployed and verified

Built on v1.7.2, preserving the calendar system already shipped in v1.7.0/v1.7.1. This release bounds automatic rolling-refresh queries without losing moved occurrences, removes the invalid standalone rest-day option from coach scheduling, and corrects stopped-schedule status text. Fixed/ongoing plans, date-click scheduling, confirmed drag/drop, mobile Change date, recurring exceptions, inclusive stop dates and coaching permissions are verified end to end.

**419 PHP tests / 5,386 assertions** pass on both SQLite and isolated MySQL, plus **24 JS tests**, **21 concurrency checks**, **79 authenticated local HTTP checks** and desktop/mobile browser workflows at 320/390/1280px. Build, formatting and whitespace checks pass. All 17 original data-table fingerprints and 44 media hashes match after removing disposable QA data. No schema or dependency changes.

[Full v1.8 report](docs/V1.8-SMART-CALENDAR-RECURRING-SCHEDULES-REPORT.html). **Deployed to Hostinger on 29 October 2026 after explicit authorization.** All 58 deployed file hashes, 79 authenticated live checks and 15 live responsive checks pass. Original production data, uploads and settings are unchanged; temporary QA accounts and deployment files are removed. The v1.8.0 tag remains on `0f88fc3`.

## v1.7.2 Profile Card Spacing

Deployed on 29 October 2026. The Profile Training Planner ID card now has 16px between its heading, badge and actions, plus 24px before the cards below. Verified locally and live at 320/390/1280px. This patch changes only markup and styling; existing data and permissions are unchanged.

## v1.7.1 Trainee Account Restrictions

Trainees can no longer enable Coach Workspace. Profile and My Coaching show the coach-profile link only to active coaches. Both GET and POST `/profile/coach` reject trainee accounts with 403; POST now edits an existing active coach profile without creating profiles, changing roles or reactivating access. Existing coach registration, workspace switching and trainee coaching connections are preserved.

Verification: **414 PHP tests / 5,197 assertions** on SQLite and isolated MySQL, **24 JS tests**, **15 responsive page checks** at 320/390/1280px, and browser profile-save/workspace-switch checks. Build, formatting and whitespace checks pass. Temporary QA accounts are removed; all 17 original data-table hashes and 44 media hashes match.

[Full v1.7.1 report](docs/V1.7.1-COACH-ACCESS-REPORT.html). **Deployed and verified on Hostinger on 29 October 2026**, including the pending v1.7.0 calendar update and additive migration. All 86 deployed file hashes, 79 authenticated live workflow checks and 15 live responsive page checks pass. Original data, environment and uploads are preserved; temporary QA accounts and public deployment files are removed. The v1.7.1 tag remains on runtime commit `35f1d23`.

## v1.7.0 Smart Calendar & Recurring Schedules

Source release **v1.7.0** adds ongoing plans, confirmed rolling generation through today + 90 days, date-first scheduling, desktop drag/drop, mobile Change date, this-occurrence / this-and-future previews, persistent cancellation markers and Stop Schedule. Existing fixed schedules, manual sessions, saved workouts and coaching ownership remain protected.

Verification: **409 PHP tests / 5,160 assertions** on SQLite and isolated MySQL, **24 JS tests**, **21 two-connection checks**, and **42 responsive page checks** at 320/390/1280px. Build, Pint, changed-file Prettier and whitespace checks pass. Temporary local QA accounts are removed; all original rows and media match their fingerprints.

[Full v1.7 report](docs/V1.7.0-SMART-CALENDAR-REPORT.html). Initially released to GitHub, this calendar update was subsequently deployed to Hostinger as part of **v1.7.1 on 29 October 2026**, including its additive migration and frontend build. No queue or cron is required.

The v1.6 policy hold is resolved by retaining the accepted consent: connected coaches may read a trainee’s full workout history, including other-coach sessions, while mutations remain restricted to their own eligible assigned sessions. Earlier tags are preserved.

Additional recurring-schedule concurrency checks (isolated test database only):

```sh
DB_CONNECTION=mysql DB_DATABASE=training_planner_test php tests/Concurrency/RecurringScheduleConcurrency.php
```

## Post-v1.6 stabilization — 28 October 2026

Seven reproduced bugs are fixed and deployed: upcoming summaries, next/last workout selection, trainee-list repeated queries, subscription date serialization, media cleanup on transaction failure, and SQLite migration rollback. No new feature or production schema migration was added. These fixes are separately tagged as v1.6.1; v1.6.0 is unchanged.

Verification: **377 PHP tests / 4,853 assertions** on SQLite and isolated MySQL, **24 JS tests**, **17 concurrency checks**, **84 live checks**, and **102 responsive checks**. Original local/live rows and media match their pre-QA fingerprints; all temporary accounts were removed.

The [full QA and stabilization report](docs/V1.6-FULL-QA-STABILIZATION-REPORT.html) preserves its original results and includes a dated addendum resolving the prior next-release policy hold. Existing cross-coach read consent is retained; cross-coach edits remain blocked.

## v1.6.0 Coaching Workspace

**Deployed as v1.6.0.** See the [full 26-part implementation and release report](docs/V1.6.0-COACHING-REPORT.html).

- Registration supports trainee and coach accounts; coaches keep personal training and can switch workspaces without changing identity. The original v1.6 trainee upgrade option is removed as of v1.7.1.
- Permanent Training Planner IDs, exact lookup and locally rendered, expiring single-use QR links. Trainees explicitly accept requests and can disconnect at any time.
- Coaching packages and date-aware subscriptions control management. Accepted relationships retain read-only monitoring when service is paused or expired.
- Reuse the existing plan/day builder for templates, independent trainee-owned assignments and custom plans. Reuse Calendar, History, body/exercise Progress, PRs and progression calculations.
- Coach scheduling is limited to their active assignments and attributed eligible future sessions within service dates. Coaches cannot log actual results, change workout status, complete workouts or record body weight for trainees.
- One additive migration, six coaching tables, no new PHP dependency or background service. QR uses pinned `qrcode.react` 4.2.0; [license notices](public/licenses/qrcode-react.txt) are included.
- Verification: **362 PHP tests / 4,630 assertions** on SQLite and isolated MySQL, **24 JavaScript tests**, **17 two-connection MySQL checks**, **60 authenticated live workflow checks**, and 48 responsive page checks at 320/390/1280px.

Run the additional concurrency checks only against the isolated test database:

```sh
DB_CONNECTION=mysql DB_DATABASE=training_planner_test php tests/Concurrency/CoachingConcurrency.php
```

The runner refuses other database names. Personal policies remain owner-only; `CoachAccess` and explicit trainee route context provide coaching authorization. Future features should extend these shared services rather than impersonating trainees or duplicating training engines.

## v1.5.0 Smart Plan & Schedule Management

**Deployed and released as v1.5.0.** See the [full v1.5 report](docs/V1.5.0-PLAN-SCHEDULE-REPORT.html).

- Duplicate a complete plan or day, or copy a day to another owned plan. Copies keep prescriptions, supersets, stable exercise identity, weekdays/time and independent media; they start without sessions or history.
- **Manage Schedule** shows Add, Keep, Remove and Protected dates before confirmed add-missing, remove-future or regeneration actions. Time changes require explicit replacement; no automatic Calendar changes.
- Existing occurrence keys and their unique index remain the duplicate guard. One additive `schedule_managed` boolean protects older sessions whose edit history is unknown and individually edited generated sessions. Manage these individually in Calendar; new untouched generated sessions support bulk management.
- Completed, skipped, active, saved/structured, historical and manual sessions stay protected. v1.4 progression continues to change prescriptions only.
- Verification: **320 PHP tests / 4,084 assertions** on SQLite and MySQL, **24 JavaScript tests**, production build, formatters, 320px/390px/1280px browser checks, data-preservation checks and a two-connection concurrency check pass.

## Released update: v1.4.0 Progressive Overload Suggestions

**Deployed and verified on Hostinger; released as v1.4.0.** See the [v1.4 implementation report](docs/V1.4.0-PROGRESSION-REPORT.html) for rules, files, architecture and verification.

- Exercise Progress adds **Next target**, using the selected plan exercise's latest two completed structured occurrences. Unsuccessful recent workouts block an increase; extra sets do not make the planned set-count requirement stricter.
- Suggested increases: **+2.5 kg**, **+1 rep to each range bound**, or **+5 seconds**. Hold/build guidance explains incomplete sets, lower reps and weights. Strict numeric parsing preserves free-text prescriptions elsewhere.
- Select the target plan before calculation when multiple copies exist. Each copy uses its own history; legacy null tracking mode still means Reps. Deleted templates can show clearly labelled, read-only historical advice when their frozen prescription is unambiguous.
- **Apply suggestion** opens an editable confirmation modal. The server rechecks ownership, current targets and results; stale or repeated requests cannot apply an increment twice. Only the selected planned weight or prescription changes. Active workouts, snapshots, historical results and other plan copies remain intact.
- No schema changes, new indexes, dependencies, external services or background jobs. Existing identity/source-template indexes support the query.
- Verification: **287 PHP tests / 3,862 assertions on SQLite and MySQL**, **24 JavaScript tests**, Vite production build, Pint, React formatting and browser checks at **320px, 390px and 1280px** pass. Temporary QA data was removed; original row hashes match.

## Current release

**v2.1.1 — 30 October 2026** is deployed at [training.ahmaddalao.com](https://training.ahmaddalao.com). Source tag: [v2.1.1](https://github.com/AhmadDalao/traningApp/tree/v2.1.1). The [full stabilization and deployment report](docs/V2.1.1-FULL-LIFECYCLE-STABILIZATION-REPORT.html) records backups, live verification and data preservation. Existing login credentials are unchanged.

## Released update: v1.3.0 Exercise Progress & Personal Records

**Deployed and tagged v1.3.0.** The v1.2.0 baseline tag remains unchanged. See the [v1.3 implementation report](docs/V1.3.0-EXERCISE-PROGRESS-REPORT.html) for the full file inventory, metric definitions, query/index evidence and verification.

- Progress adds **Exercise Progress** beside the existing Body weight and Training stats tabs. Search performed exercises, view recent PRs, open exercise-specific charts and browse actual set history (10 workouts per page; exercise list: 12 per page).
- Only currently **completed training sessions**, non-skipped exercises, completed sets and valid actual reps/seconds qualify. Planned values and legacy snapshots are never converted into actual results. Reverting a session to Planned or Skipped removes its contribution; recompleting uses its final saved structured data.
- Identity is `source_exercise_key`, scoped to the signed-in user. Equal names never merge different keys. Reps and Duration stay separate when one identity was logged in both modes. Deleted templates/custom-library entries do not remove preserved results or media.
- Weighted metrics require **actual_weight_kg > 0** and actual reps. Null/zero weights still count for reps, but never create weight PRs or weighted chart points. Volume sums positive actual kg × actual reps per exercise per workout; no qualifying weighted sets means no weighted volume, not zero.
- Weighted PRs: heaviest weight, best set (highest weight, then most reps), max reps with its recorded positive weight where applicable, and best workout volume. Reps-only PRs: max set reps and most workout reps. Duration PRs: longest set and longest total workout duration. First performance establishes a baseline; ties create no new PR. A heavier best set is announced once as a Weight PR.
- PR cards are all-time. Charts/history default to 90 days, with 30 days, 6 calendar months, 1 year and All options. Finite periods end today and include their start date. Workout ordering uses scheduled date, optional time, then session ID in the app timezone, matching Training History; All includes every qualifying completed workout. Each exercise/mode/workout contributes one chart point. Latest working weight is the highest positive actual weight in the latest qualifying workout, or blank if none was recorded there.
- Completed workout details show a compact PR summary and links to Exercise Progress. History continues to use the existing workout details page. Existing quick completion, Active Workout, calories, body-weight progress, profiles and recommendations are preserved.
- One additive migration adds a single `source_exercise_key` index only if no suitable leftmost-key index already exists. It is applied locally and on Hostinger; subsequent deployments use normal `php artisan migrate --force`. No new tables, data backfill, dependency, cache or background worker.

Verification: **196 PHP tests / 3,569 assertions on both SQLite and MySQL**, **24 JavaScript tests**, production build, Pint, frontend formatting, desktop and 320px/390px browser checks pass. All temporary local QA records were removed; original data fingerprints match. All 39 live workflow checks and 62 deployed-file hash checks passed. The private database/uploads/environment/source backup is retained under `storage/app/private/deploy-backups/progress-v1.3.0-20261027-a64fa7b7` on Hostinger. The temporary QA account, updater and archive were removed; login and health return 200 and temporary endpoints return 404. Suggested weights, estimated 1RM, automatic plan changes and AI remain outside this release.

## Released patch: v1.1.1

The verified **v1.1.1** patch is deployed on Hostinger and tagged in GitHub. See the [patch report](docs/V1.1.1-GOALS-CALORIES-REPORT.html) and [changelog](CHANGELOG.md).

- Select zero, one, or two optional training goals. Ordered `training_goals` JSON is preferred; index 0 is Primary and index 1 Secondary. Legacy `training_goal` is the read fallback and is synchronized to Primary on new saves. Existing users do not redo onboarding.
- Recommendations reuse six presets. Primary drives the structure; Secondary affects ranking and supporting work. The existing Full Body preset can offer four bodyweight strength/conditioning days. BMI, body-weight goals, and equipment remain separate inputs/concepts.
- Completed training sessions can get a server-calculated estimate after actual duration is saved. The formula is `round(MET × 3.5 × weight_kg / 200 × duration_minutes)`. Strength METs: 3.0 / 3.5 / 6.0; Cardio: 4.0 / 6.0 / 8.0; Mobility: 2.0 / 2.5 / 3.0 (Light / Moderate / Vigorous). Cardio/Mobility use matching saved session focus; other focus areas use strength. These are approximate session assumptions, not measured expenditure.
- Weight comes from the latest owned body-weight entry dated on/before the session, with latest ID breaking same-day ties, then falls back to profile current weight. Missing weight/duration or a rest/planned/skipped session means no estimate.
- `calories_burned` remains reported calories; `calories_source` accepts `wearable`, `exercise_machine`, or `manual`. `estimated_calories` is independent and never accepted from the client. Reported values take priority; estimates always use ≈ and an Estimated label in details. History uses one compact number.
- Profile/weight changes and reads do not rewrite stored estimates. Explicit duration/intensity changes recalculate; explicit date/focus/type changes also update estimation. Reversing completion clears the estimate; completing again estimates from retained duration/intensity. Reported values and snapshots are preserved.
- One additive migration (`2026_10_27_000006_add_multiple_goals_and_calorie_estimates.php`) is applied locally and on Hostinger. No bulk backfill, snapshot format change, new dependency, new API route, or Progress calorie dashboard.

Patch verification: **118 PHP tests / 2,830 assertions on SQLite and MySQL**, **17 JavaScript tests**, production build, Pint, frontend formatting, and mobile workflow checks. The existing Vite notice about `/images/gym.jpg` is unchanged; the public asset exists and loads normally. All 23 live workflow checks passed, and all 56 deployed files match the release hashes. The database, uploads, environment, and existing records were preserved. Previous tags remain unchanged.

## Released update: v1.2.0 Active Workout

**Deployed and tagged v1.2.0.** The previous **v1.1.1** tag (`a976e65`) remains unchanged. See the [v1.2 report](docs/V1.2.0-ACTIVE-WORKOUT-REPORT.html) for the full implementation, schema, file inventory and checks.

- Planned training sessions offer **Start workout** alongside the existing **Mark completed**. Started sessions offer **Resume workout** and must finish/discard through the detailed flow.
- Workout progress saves on explicit set actions. Each set records reps or seconds, optional kg, and its completion time. Add/remove sets, skip/undo exercises, and a separate rest timer work on mobile.
- Starting freezes the prescription and media. Logging never changes the reusable plan. Finishing computes actual duration and reuses the existing calorie estimator; reported calories remain separate.
- Detailed History shows actual sets. Old/quick-completed sessions retain their original snapshot and per-exercise actual-weight form.
- The additive `2026_10_27_000007_add_active_workouts.php` migration is applied locally and on Hostinger. It adds `schedule_entries.started_at`, `training_exercises.tracking_mode`, `session_exercises` and `session_sets`. No historical backfill occurs.
- Verification: **158 PHP tests / 3,327 assertions on SQLite and MySQL**, **21 JavaScript tests**, production build, Pint, frontend formatting, 320px/390px mobile workflows, and a 1280px desktop check.

The Hostinger release preserved `.env`, `APP_KEY`, uploads and existing records, with a fresh private backup. Temporary QA data and deployment endpoints/archives were removed. Login credentials are unchanged. v1.2 introduces no dependencies, services, queues, or background workers.

## What is included

### Profile, Goals, Progress & Calories

The v1.1.0 release extends the `v1.0.0` baseline with optional onboarding, Profile via the avatar menu, deterministic plan suggestions, dated body-weight history, Progress, weekly weight check-ins, and optional completed-session duration/manual calories. Exercise prescription labels now read **Planned Weight**; the database still uses `target_weight`. Existing authentication, planner, calendar, supersets, actual weights, and frozen history remain in place.

One additive migration, `2026_10_27_000005_add_profiles_progress_and_session_details.php`, creates `user_profiles` and `body_weight_entries` and adds nullable `duration_minutes`, `calories_burned`, and `calories_source` to `schedule_entries`. It is applied locally and on Hostinger. The release preserves the production environment, uploads, and existing records. Use the existing Hostinger update procedure for future releases; do not seed or replace production data.

Verification: **84 PHP tests / 1,796 assertions** pass on isolated SQLite and MySQL, **13 JavaScript tests** pass, and the Vite production build passes. The [implementation notes](docs/PROFILE-PROGRESS-UPDATE-2026-10-27.html) include all new and changed files, profile fields, recommendation rules, progress calculations, manual checks, and follow-ups.

### Existing planner features

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
- Planned, completed, and skipped statuses, including undoing completion. Quick status changes are immediate; detailed workouts confirm destructive actions and incomplete completion.
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
| `training_exercises` | Belongs to a day; name, icon, sets, text reps, optional decimal target weight, superset group, thumbnail/custom-icon paths, library key, optional reps/duration tracking mode, notes, position. |
| `exercise_library_items` | Private custom exercises belonging to a user, with name, focus, icon, and independent media copies. Built-in exercises use bundled JSON instead. |
| `schedule_entries` | Belongs to a user; nullable day link, title/type/focus/icon, date, optional time, status, notes, nullable start/completion timestamps, elapsed duration, reported/estimated calories and JSON workout snapshot. Indexed on user/date, **not unique**. Nullable unique plan-occurrence key prevents duplicate generated sessions. |
| `session_exercises` | Frozen exercise identity, prescription, media and status for one scheduled workout; source template FK is nullable. |
| `session_sets` | Ordered planned targets and actual reps/seconds, optional kg and completion timestamp for each session exercise. |
| `user_profiles` | Optional personal measurements, ordered goals and training preferences. |
| `body_weight_entries` | Dated owned body-weight records for progress and calorie estimation. |

Laravel's standard authentication migration also contains session and password-reset-token tables; no password-reset flow is exposed in this MVP. The configured session driver is `file`.

Plan/day deletion cascades to exercises and removes their template thumbnails. Scheduled entries survive: the day foreign key becomes null and title/type/focus/icon remain readable. Completed workouts keep their snapshot and separate thumbnail copies. Uncompleted sessions without a snapshot show the linked template; deleting that template leaves their session metadata available.

### Exercise library and weekday schedules

Open **Add exercise** to search the built-in library or your private custom exercises. Filter by muscle/focus and equipment, select a card, then enter sets, reps/duration, optional target weight, and optional superset partner. The name, photo, and suggested icon are filled automatically. You can replace the photo, select a different built-in icon, or upload a square JPG/PNG/WebP icon: **128 × 128 px recommended, 64–512 px accepted, maximum 512 KB**. Exercise thumbnails still accept JPG/PNG/WebP up to 2 MB. **Other / create custom** lets you type a name; saving the training day also saves that exercise to your private library. Other accounts cannot select it. Library, workout-template, and completed-history media have independent copies so deleting a template preserves reusable custom exercises and completed records.

Edit a plan to set inclusive start/end dates. In each training/rest day, choose weekdays and an optional usual time; shortcuts are **Weekdays** (Sunday–Thursday), **Weekends** (Friday–Saturday), **Every day**, and **Clear**. On the plan page, **Preview schedule** lists matching dates, then **Add sessions to Calendar** creates them. Saving dates or weekdays alone does not create sessions. Only today and future dates are generated; existing sessions are kept, repeat submissions do not duplicate them, and manually moved generated sessions remain moved. Later plan changes do not delete or reschedule existing calendar entries. Manual scheduling remains available. A preview supports a period of up to one year and at most 1,000 sessions.

`2026_10_26_000004_add_exercise_library_and_plan_schedule.php` is additive and is applied locally and on Hostinger. The exercise-library, direct library supersets, and weekday-schedule release was deployed on 26 October 2026 with its migration, application changes, rebuilt assets, `resources/data/exercise-library.json`, and `public/exercise-library`. The production database, environment, and uploads were preserved.

Built-in metadata/photos come from [Free Exercise DB](https://github.com/yuhonas/free-exercise-db), released into the public domain under the Unlicense. The import is pinned to commit `f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5`; provenance and license are included in `public/exercise-library/SOURCE.json` and `LICENSE.md`. Photos total approximately 49 MB and load lazily from this application, without a runtime third-party image service. Run `python3 scripts/import-exercise-library.py` to reproduce the catalog and download missing images from that pinned revision.

### Completion, weights, and history

`2026_10_26_000003_add_workout_history.php` is an additive migration. Run `php artisan migrate` on an existing installation; it preserves accounts, plans, sessions, and uploads. No backfill invents snapshots or completion timestamps for older completed sessions. Those records remain visible with an explicit message that their original exercise record is unavailable.

For quick completion, the first completion copies the exercise names, ordering, sets, reps, target weights, supersets, notes, icons, and thumbnails into a versioned JSON record. Actual weights start blank. Quick/legacy sessions use **Edit actual weights**, which updates only the completed session's saved exercises, never the training template. Target and actual weights accept 0–9999.99 kg with up to two decimal places; every field may be blank. Detailed workouts instead freeze the prescription at Start and store results per set. Their saved set rows appear in History; the legacy per-exercise weight editor is not used for them.

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

The 27 October **v1.1.0** release, `profile-v1.1.0-20261027-ed592f83`, deploys the profile/progress/calories update. The additive migration completed and all original record fingerprints matched: 1 user, 2 plans, 4 days, 8 exercises, 45 sessions, and 1 custom-library item. Eighteen live workflow checks passed, covering onboarding, profile/BMI, recommendations, body-weight history, plan copying without automatic scheduling, one-click completion, optional duration/calories, actual weights, history filters, and ownership. Mobile Profile, Progress, and existing history were checked at 390px. All 76 deployed files match the local release hashes, including the public build manifest and application bundles. A database dump, uploads archive, previous files, and environment backup are private under `storage/app/private/deploy-backups/profile-v1.1.0-20261027-ed592f83`. The temporary QA account/data, updater endpoint, and upload archive were removed; login and health return 200, and the updater/archive return 404. Existing login credentials remain unchanged.

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

Sessions retain Planned/Completed/Skipped status. Optional Active Workout adds per-set completion, reps or duration, optional kilogram weights and a browser-only rest timer; quick completion still works without set logging. Weekday scheduling creates a finite set of calendar entries within plan dates; it is not an ongoing background recurrence service. There are no weight progression analytics, coach/admin roles, social features, notifications, payments, AI, or separate API/frontend. Account recovery/password reset is outside the requested login/register/logout scope. Calendar loading is bounded to the visible month plus one week on either side; plans and history are paginated.

## Credits and framework references

The bundled gym photograph is from [Unsplash](https://images.unsplash.com/photo-1534438327276-14e5300c3a48). DM Sans and Manrope load from Google Fonts with system-font fallbacks. App operation does not depend on the font service. Framework setup follows [Laravel's installation guidance](https://laravel.com/docs/12.x/installation); production should follow the installed Laravel version's [deployment guidance](https://laravel.com/docs/12.x/deployment).

The 27 October **v1.1.1** release (`goals-v1.1.1-20261027-e313840c`) adds multiple goals and calorie estimates. Its additive migration completed with matching existing-record fingerprints for 1 user, 3 plans, 8 days, 20 exercises, 45 sessions, 1 library item, 1 profile, and 2 body-weight entries. A fresh private database/files/uploads/environment backup was taken. Temporary QA data, updater and archive were removed after verification.

The 27 October **v1.2.0** release adds Active Workout and structured set history. All 37 live workflow checks passed; the final 72-file deployment (`active-v1.2.0-20261027-5c48cd41`) includes the mobile action-wrapping correction. Private backups are under `storage/app/private/deploy-backups/`: `active-v1.2.0-20261027-3e0917b3` preserves the pre-v1.2 application/database/uploads/environment, and `active-v1.2.0-20261027-5c48cd41` precedes the final CSS correction. Existing-record fingerprints matched on both deployments. Temporary QA data and both updater/archive sets were removed, login/health return 200, and removed updater/archive URLs return 404.

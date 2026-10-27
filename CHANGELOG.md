# Changelog

## v1.1.1 — 2026-10-27

### Added

- Up to two ordered training goals, labelled Primary and Secondary, with a simple priority swap and an inline selection limit.
- Combined-goal recommendations and explicit reasons based on selected goals, experience, equipment, available days, and session duration.
- A four-day bodyweight strength/conditioning variation of the existing Full Body preset; the recommendation library remains six presets.
- Automatic session-level calorie estimates for completed training workouts with an actual duration and usable body weight.
- Light, Moderate, and Vigorous intensity; historical weight selection on or before the session date.
- Separate estimated and reported calorie display, plus Wearable, Exercise machine, and Manual entry sources.

### Changed

- `training_goals` is the preferred ordered JSON field. Legacy `training_goal` is retained and synchronized with Primary on profile/onboarding saves.
- The manual calorie field is labelled **Reported Calories**; the existing `calories_burned` column remains intact.
- Reported values, including zero, take display priority. Session details also show the app estimate; History shows only the preferred number.
- Duration/intensity changes recalculate the estimate without changing reported calories. Explicit session date/category/type changes also update eligibility/estimation. Notes-only edits and weight-history/profile changes preserve stored estimates.
- Details PATCH requests preserve omitted values; sending null explicitly clears optional duration or reported calories.

### Preserved

- Authentication, layout, navigation, all existing planner modules, private custom exercises, supersets, completion timestamps, actual weights, and version-1 workout snapshots.
- Legacy single-goal profiles, manual calorie values, onboarding completion, History filters/default 10 rows, Progress, and weekly check-ins.
- One-click completion without any required duration, intensity, calorie, or actual-weight input.
- No automatic plan copying, scheduling, profile backfill, estimate backfill, or snapshot rewriting.

### Verification and release

- Baseline: 84 PHP tests / 1,796 assertions, 13 JavaScript tests, and production build passed before changes.
- Patch: 118 PHP tests / 2,830 assertions passed on isolated SQLite and MySQL; 17 JavaScript tests and production build passed. PHP Pint and changed-frontend Prettier checks passed.
- Browser checks cover onboarding, maximum-two selection, priority swapping/promotion, profile editing, bodyweight recommendations/copying, scheduling/completion, estimate/report/clear/intensity flows, History, and 320px/390px layouts.
- One additive migration adds `user_profiles.training_goals`, `schedule_entries.estimated_calories`, and `schedule_entries.intensity`; applied locally and on Hostinger.
- Deployed and tagged v1.1.1 after 23 live workflow checks and verification of 56 deployed file hashes. Existing production records were preserved, a private backup was taken, and temporary QA/deployment files were removed. The v1.1.0 tag remains unchanged.

### Not included

AI, wearable integrations, nutrition, daily calorie targets, BMR/TDEE, per-exercise estimates, per-set logging, or new Progress dashboards.

See [v1.1.1 implementation report](docs/V1.1.1-GOALS-CALORIES-REPORT.html) for the exact formula, MET assumptions, compatibility rules, file inventory, and release procedure.

## v1.1.0 — 2026-10-27

- Optional three-step onboarding and an editable Profile accessed from the avatar menu.
- Training preferences, separate body-weight goals, calculated age/BMI, and six simple recommendation presets; plans are copied only after explicit selection.
- Private dated body-weight records, a trend chart, target progress, monthly training statistics, and a dismissible weekly Overview check-in.
- Optional duration and manual/wearable calories on completed sessions, also displayed in the existing Training History.
- Exercise UI now says **Planned Weight**; the existing `target_weight` field and completed-workout snapshots are preserved.
- Additive profile/body-weight tables and nullable session-detail columns, applied locally and on Hostinger.
- 84 PHP tests / 1,796 assertions pass on isolated SQLite and MySQL, 13 JavaScript tests pass, and the production build passes.

See [implementation notes](docs/PROFILE-PROGRESS-UPDATE-2026-10-27.html) for rules, schema, changed files, verification, and deployment preparation. Deployed at https://training.ahmaddalao.com with a fresh backup and preserved production records. All 18 live workflow checks passed; temporary QA data and deployment files were removed. The previous `v1.0.0` tag remains available.

## v1.0.0 — 2026-10-27

First tagged release of the Training Planner application deployed on Hostinger on 26 October 2026.

- Session authentication with registration, login, remember-me, logout, and account ownership checks.
- Training plans, reusable training/rest days, exercise ordering, sets, reps/duration, and notes.
- Searchable library of 876 exercises with 873 photos, private custom exercises, and customizable icons.
- Supersets using existing workout exercises or partners chosen directly from the library.
- Optional target and actual weights, completion timestamps, and preserved workout snapshots.
- Plan dates, weekday choices, Friday/Saturday weekend shortcuts, and schedule preview before creating calendar entries.
- Calendar month/week/list views, manual scheduling, reversible session statuses, and duplicate prevention for generated sessions.
- Training History with date, status, type, and plan filters; 10 items per page by default.
- Responsive layouts and matching scrollable Today and Up next lists.
- Local demo and layout-test seeders, project report, and shared-hosting deployment documentation.

### Verification

- 64 PHP tests passed with 1,049 assertions on isolated SQLite on 27 October 2026.
- 13 JavaScript superset tests passed.
- Production Vite build passed.
- Live login and health endpoints returned HTTP 200 on 27 October.
- Full live workflow and MySQL test verification recorded on 26 October in the README.

Production credentials, account passwords, uploaded user files, databases/backups, temporary deployment tools, and generated dependencies/build assets are not part of this release's source tree.

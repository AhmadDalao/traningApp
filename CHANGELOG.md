# Changelog

## v1.3.0 — 27 October 2026

Deployed to Hostinger and tagged **v1.3.0**. The accepted v1.2.0 baseline and previous tags remain unchanged.

### Added

- Exercise Progress within the existing Progress section, with searchable performed exercises, pagination, recent Personal Records and links from completed structured workouts.
- Weight, weighted-volume, rep and duration charts using the existing SVG approach. One point per exercise/mode/workout; 90-day default with 30-day, 6-month, 1-year and All filters.
- Exercise-specific actual-set history, 10 workouts per page, linking to existing completed session details.
- Weighted PRs: Heaviest Weight, Best Set, Max Reps and Best Session Volume. Reps-only: Max Reps and Most Workout Reps. Duration: Longest Set and Longest Workout Duration.
- Compact completion summary. First recorded performance establishes a baseline; only strict improvements against prior workouts are announced. Weight increases do not produce a duplicate Best Set announcement.
- One inspected and measured, additive `source_exercise_key` index; existing session/position and set/number indexes are reused.

### Data rules and compatibility

- Uses actual results in completed sets from currently completed training sessions, excluding skipped exercises. Planned targets and old snapshots never fabricate structured history.
- Positive actual weight only for weighted metrics. Null/zero weights retain their actual reps/durations/history without weight PRs or zero-volume charts.
- Stable identity and ownership govern aggregation, never matching exercise names. Reps and Duration are separate for the same identity. Preserved records/media survive template/library deletion.
- Status reversals remove results immediately; recompletion restores the final saved data. PRs are derived, with no new historical-status system or records table.
- Existing Body weight, Training stats, Training History, Active Workout, quick completion, snapshots, profile, recommendations and calorie behavior remain intact.
- No new dependencies, 1RM estimates, suggested next weights, overload automation, plan adaptation, muscle analytics or AI.

### Verification

- 196 PHP tests / 3,569 assertions pass on SQLite and MySQL (38 new progress cases).
- 24 JavaScript tests pass (3 new formatting cases). Vite production build, Pint, relevant frontend formatting and whitespace checks pass.
- Desktop plus 320px and 390px browser checks cover real local weighted/bodyweight/timed workouts, search/pagination, date ranges, baseline/empty states, finish summaries, history links and completion reversal/recompletion. No console errors or warnings observed.
- Temporary local QA fixtures and the separate live QA account were removed; original content fingerprints match. The existing `gym.jpg` runtime asset notice remains, with the public asset present.
- All 39 live workflow checks passed on PHP 8.3 / MariaDB 11.8, with 62 deployed-file checksum matches. The single additive index migration is applied. A private database/uploads/environment/source backup was retained; deployment endpoints/archives were removed, returning 404. Login and health return 200. Credentials and existing accounts are unchanged.
- Full report: [v1.3 Exercise Progress](docs/V1.3.0-EXERCISE-PROGRESS-REPORT.html).


## v1.2.0 — 2026-10-27

### Added

- Optional Start / Resume / Finish Workout, with only one active workout per account and saved progress after refresh/navigation.
- Separate session exercise and set records: actual reps, optional decimal weight, and actual duration in seconds.
- Reps / Duration selection in the existing exercise editor; mm:ss input for actual timed sets.
- Complete, edit, mark incomplete, add and remove sets; skip / undo skip for exercises.
- Confirmation before deleting completed sets, skipping partially completed exercises, discarding progress, or finishing with incomplete sets.
- Automatic elapsed workout duration and integration with the existing calorie estimator.
- Independent 60/90/120-second rest timer, with start, pause and reset.
- Structured set history, a compact sets-completed summary, and stable built-in/custom exercise identity for future reporting.

### Preserved

- Quick Mark Completed for unstarted sessions; legacy per-exercise actual weights remain available when no detailed logs exist.
- Frozen workout snapshots, saved media, supersets, existing history filters/default pagination, Calendar, Profile, recommendations, Progress and all v1.1.1 calorie behavior.
- Existing templates and historical records are never rewritten by workout logging. No old sessions are converted into invented set history.
- Existing planned/completed/skipped statuses; active state uses nullable `started_at`.

### Implementation and verification

- One additive migration, applied locally and on Hostinger, adds nullable start time/tracking mode and two tables with normal foreign keys. Existing records were preserved.
- Snapshot/media are frozen on start; records are reused on resume. Parent row locks serialize logging/finish/discard, and a user row lock serializes workout starts.
- Planned weights are shown as editable suggestions; actual set rows begin empty in the database and completion is explicit. Unsaved row inputs survive adding/removing/renumbering other sets.
- 158 PHP tests / 3,327 assertions pass on isolated SQLite and MySQL; 21 JavaScript tests and the production build pass. Forty PHP cases and four JavaScript tests were added.
- Mobile workflow checks at 320px and 390px, desktop at 1280px, refresh/resume, both completion flows, editor mode, and draft-preservation checks passed. Temporary QA data/media removed; original data fingerprints match.
- Deployed and tagged v1.2.0 after 37 live workflow checks and 72 deployed-file hash checks. A mobile session-action overflow was fixed and verified at 320px and 390px. Private backups are retained; temporary QA data, updater endpoints and archives were removed. Existing login credentials and earlier tags are unchanged. No personal-record analytics, exercise charts, overload engine, AI or wearable integrations were added.

See [v1.2.0 implementation report](docs/V1.2.0-ACTIVE-WORKOUT-REPORT.html) for structure, behavior, file inventory, validation and deployment notes.

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

# Changelog

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

# Changelog

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

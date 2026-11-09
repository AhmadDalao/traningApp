import TrainingThumbnail from "../../Components/TrainingThumbnail";
import PersonAvatar from "../../Components/PersonAvatar";
import {
    Users,
    CalendarDays,
    CheckCircle2,
    AlertTriangle,
    Clock,
} from "lucide-react";
import { Head, Link } from "@inertiajs/react";
import { PageHeader, ButtonLink } from "../../Components/UI";
import { CoachStatus } from "../../Features/CoachingUI";
import { WorkoutDate } from "../../Features/CoachWorkspace";
function SessionList({ entries, empty, grouped = false, showTrainee = true }) {
    return (
        <div className="coach-daily-list">
            {entries.map((entry) => (
                <article
                    className="coach-session"
                    key={grouped ? entry.user_id : entry.id}
                >
                    {showTrainee && <PersonAvatar name={entry.trainee_name} />}
                    <div className="coach-session-copy">
                        {showTrainee && (
                            <Link href={`/coach/trainees/${entry.user_id}`}>
                                <strong>{entry.trainee_name}</strong>
                            </Link>
                        )}
                        <div className="thumbnail-identity">
                            <TrainingThumbnail item={entry} />
                            <Link
                                href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                            >
                                {entry.title}
                            </Link>
                        </div>
                        {entry.scheduled_date && (
                            <small>
                                <WorkoutDate entry={entry} />
                            </small>
                        )}
                    </div>
                    {grouped ? (
                        <div className="coach-upcoming-count">
                            <strong>{entry.session_count} workouts</strong>
                            <Link
                                href={`/coach/trainees/${entry.user_id}/calendar`}
                            >
                                Calendar →
                            </Link>
                        </div>
                    ) : (
                        <CoachStatus status={entry.status} />
                    )}
                </article>
            ))}
            {!entries.length && <p className="coach-quiet-empty">{empty}</p>}
        </div>
    );
}
function TodayList({ entries }) {
    const groups = new Map();
    for (const entry of entries)
        groups.set(entry.user_id, [
            ...(groups.get(entry.user_id) || []),
            entry,
        ]);
    return entries.length ? (
        [...groups].map(([user, rows]) => (
            <section className="coach-today-group" key={user}>
                <div className="person-identity">
                    <PersonAvatar name={rows[0].trainee_name} />
                    <Link href={`/coach/trainees/${user}`}>
                        <strong>{rows[0].trainee_name}</strong>
                    </Link>
                    <small>
                        {rows.length} workout{rows.length === 1 ? "" : "s"}
                    </small>
                </div>
                <SessionList entries={rows} showTrainee={false} />
            </section>
        ))
    ) : (
        <p className="coach-quiet-empty">No workouts scheduled today.</p>
    );
}
export default function Overview({
    stats,
    todaySessions = [],
    upcomingGroups = [],
    recent,
    attention = [],
    pending = [],
}) {
    return (
        <div className="coach-dashboard">
            <Head title="Coach Overview" />
            <PageHeader
                title="Coach Overview"
                description="Your coaching day, at a glance."
            >
                <ButtonLink href="/coach/trainees">Manage trainees</ButtonLink>
            </PageHeader>
            <div className="coach-stats coach-desktop-metrics">
                {[
                    ["Active trainees", stats.trainees, Users, "green"],
                    ["Training today", stats.today, CalendarDays, "blue"],
                    ["Completed today", stats.completed, CheckCircle2, "green"],
                    [
                        "Need attention",
                        stats.attention,
                        AlertTriangle,
                        "orange",
                    ],
                ].map(([label, count, Icon, color]) => (
                    <div className="panel coach-stat" key={label}>
                        <span className="metric-label">
                            {label}
                            <span className={`stat-icon ${color}`}>
                                <Icon size={18} aria-hidden="true" />
                            </span>
                        </span>
                        <strong>{count}</strong>
                    </div>
                ))}
            </div>
            <div className="coach-overview-columns">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <AlertTriangle size={19} aria-hidden="true" /> Needs
                            attention
                        </h2>
                        <Link href="/coach/trainees?status=attention">
                            View trainees →
                        </Link>
                    </div>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Trainees needing attention"
                    >
                        {attention.map((row) => (
                            <article className="coach-session" key={row.id}>
                                <PersonAvatar name={row.trainee.name} />
                                <div className="coach-session-copy">
                                    <Link
                                        href={`/coach/trainees/${row.trainee.id}`}
                                    >
                                        <strong>{row.trainee.name}</strong>
                                    </Link>
                                    <span>{row.reason}</span>
                                </div>
                                <ButtonLink
                                    variant="secondary"
                                    href={row.action.url}
                                >
                                    {row.action.label === "Continue Assignment"
                                        ? "Assign Training"
                                        : row.action.label}
                                </ButtonLink>
                            </article>
                        ))}
                        {!attention.length && (
                            <p className="on-track">
                                <CheckCircle2 size={18} aria-hidden="true" />{" "}
                                Everyone is on track.
                            </p>
                        )}
                        {pending.length > 0 && (
                            <div className="coach-pending-summary">
                                <Link href="/coach/trainees?status=pending">
                                    {stats.pending} pending requests →
                                </Link>
                            </div>
                        )}
                    </div>
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <CalendarDays size={19} aria-hidden="true" /> Today
                        </h2>
                        <span className="count-bubble">
                            {todaySessions.length}
                        </span>
                    </div>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Today’s workouts"
                    >
                        <TodayList entries={todaySessions} />
                    </div>
                </section>
            </div>
            <div className="coach-overview-columns">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>Upcoming · Next 7 days</h2>
                        <small>Next workout per trainee</small>
                    </div>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Upcoming workouts"
                    >
                        <SessionList
                            entries={upcomingGroups}
                            grouped
                            empty="No upcoming workouts in the next seven days."
                        />
                    </div>
                </section>
                <section className="panel coach-card">
                    <h2>
                        <Clock size={19} aria-hidden="true" /> Recent activity
                    </h2>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Recent activity"
                    >
                        <SessionList
                            entries={recent}
                            empty="Activity appears when trainees record their workouts."
                        />
                    </div>
                </section>
            </div>
        </div>
    );
}

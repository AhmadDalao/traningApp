import PersonAvatar from "../../Components/PersonAvatar";
import {
    Users,
    CalendarDays,
    CheckCircle2,
    AlertTriangle,
    Clock,
} from "lucide-react";
import { Head, Link } from "@inertiajs/react";
import {
    PageHeader,
    ButtonLink,
    DateLabel,
    TimeLabel,
} from "../../Components/UI";
import { CoachStatus, TraineeState } from "../../Features/CoachingUI";
function SessionList({ entries, empty }) {
    return (
        <div className="coach-bounded-list">
            {entries.slice(0, 6).map((entry) => (
                <Link
                    className="coach-session"
                    href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                    key={entry.id}
                >
                    <PersonAvatar name={entry.trainee_name} />
                    <div className="coach-session-copy">
                        <strong>{entry.trainee_name}</strong>
                        <span>{entry.title}</span>
                        {entry.scheduled_date && (
                            <small>
                                <DateLabel date={entry.scheduled_date} />{" "}
                                {entry.scheduled_time ? (
                                    <TimeLabel time={entry.scheduled_time} />
                                ) : (
                                    " · Anytime"
                                )}
                            </small>
                        )}
                    </div>
                    <CoachStatus status={entry.status} />
                </Link>
            ))}
            {!entries.length && <p>{empty}</p>}
        </div>
    );
}
export default function Overview({
    stats,
    upcoming,
    recent,
    attention = [],
    pending = [],
}) {
    return (
        <>
            <Head title="Coach Overview" />
            <PageHeader
                title="Coach Overview"
                description="Who needs your attention today?"
            >
                <ButtonLink href="/coach/trainees">Manage trainees</ButtonLink>
            </PageHeader>
            <div className="coach-operational">
                <section className="panel coach-card coach-attention">
                    <div className="coach-card-title">
                        <h2>
                            <AlertTriangle size={20} aria-hidden="true" /> Needs
                            attention
                        </h2>
                        <Link href="/coach/trainees?status=attention">
                            View trainees →
                        </Link>
                    </div>
                    <div className="coach-bounded-list">
                        {attention.slice(0, 5).map((row) => (
                            <Link
                                className="coach-session"
                                key={row.id}
                                href={row.action.url}
                            >
                                <PersonAvatar name={row.trainee.name} />
                                <div className="coach-session-copy">
                                    <strong>{row.trainee.name}</strong>
                                    <span>{row.reason}</span>
                                    <small>{row.action.label} →</small>
                                </div>
                                <TraineeState state={row.display_state} />
                            </Link>
                        ))}
                        {!attention.length && (
                            <p className="on-track">
                                <CheckCircle2 size={18} aria-hidden="true" />{" "}
                                Everyone is on track.
                            </p>
                        )}
                    </div>
                </section>
                <section className="panel coach-card coach-today">
                    <h2>
                        <CalendarDays size={20} aria-hidden="true" /> Today &
                        coming up
                    </h2>
                    <p>Upcoming sessions over the next seven days.</p>
                    <SessionList
                        entries={upcoming}
                        empty="No upcoming sessions. Open a trainee to schedule their next workout."
                    />
                </section>
                {pending.length > 0 && (
                    <section className="panel coach-card coach-pending">
                        <div className="coach-card-title">
                            <h2>Pending requests</h2>
                            <Link href="/coach/trainees?status=pending">
                                View requests →
                            </Link>
                        </div>
                        {pending.map((row) => (
                            <p key={row.id}>
                                <strong>{row.trainee.name}</strong> · Waiting
                                for acceptance
                            </p>
                        ))}
                    </section>
                )}
                <div className="coach-stats coach-summary">
                    {[
                        ["Active trainees", stats.trainees, Users, "green"],
                        ["Training today", stats.today, CalendarDays, "blue"],
                        [
                            "Completed today",
                            stats.completed,
                            CheckCircle2,
                            "green",
                        ],
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
                <section className="panel coach-card coach-recent">
                    <h2>
                        <Clock size={20} aria-hidden="true" /> Recent activity
                    </h2>
                    <SessionList
                        entries={recent}
                        empty="Activity appears when trainees record their workouts."
                    />
                </section>
            </div>
        </>
    );
}

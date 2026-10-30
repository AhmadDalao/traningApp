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
        <div className="coach-scroll">
            {entries.map((entry) => (
                <Link
                    className="coach-session"
                    href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                    key={entry.id}
                >
                    <div>
                        <strong>{entry.trainee_name}</strong>
                        <span>{entry.title}</span>
                        {entry.scheduled_date && (
                            <small>
                                <DateLabel date={entry.scheduled_date} />{" "}
                                {entry.scheduled_time && (
                                    <TimeLabel time={entry.scheduled_time} />
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
                        <h2>Needs attention</h2>
                        <Link href="/coach/trainees?status=attention">
                            View trainees →
                        </Link>
                    </div>
                    <div className="coach-scroll">
                        {attention.map((row) => (
                            <Link
                                className="coach-session"
                                key={row.id}
                                href={row.action.url}
                            >
                                <div>
                                    <strong>{row.trainee.name}</strong>
                                    <span>{row.reason}</span>
                                    <small>{row.action.label} →</small>
                                </div>
                                <TraineeState state={row.display_state} />
                            </Link>
                        ))}
                        {!attention.length && (
                            <p>
                                No outstanding coaching actions. Your trainees
                                are on track.
                            </p>
                        )}
                    </div>
                </section>
                <section className="panel coach-card coach-today">
                    <h2>Today & coming up</h2>
                    <p>Upcoming sessions over the next seven days.</p>
                    <SessionList
                        entries={upcoming}
                        empty="No upcoming sessions. Open a trainee to schedule their next workout."
                    />
                </section>
                <section className="panel coach-card coach-pending">
                    <div className="coach-card-title">
                        <h2>Pending requests</h2>
                        <Link href="/coach/trainees?status=pending">
                            View requests →
                        </Link>
                    </div>
                    {pending.map((row) => (
                        <p key={row.id}>
                            <strong>{row.trainee.name}</strong> · Waiting for
                            acceptance
                        </p>
                    ))}
                    {!pending.length && <p>No requests awaiting acceptance.</p>}
                </section>
                <div className="coach-stats coach-summary">
                    {[
                        ["Active trainees", stats.trainees],
                        ["Training today", stats.today],
                        ["Completed today", stats.completed],
                        ["Need attention", stats.attention],
                    ].map(([label, count]) => (
                        <div className="panel coach-stat" key={label}>
                            <span>{label}</span>
                            <strong>{count}</strong>
                        </div>
                    ))}
                </div>
                <section className="panel coach-card coach-recent">
                    <h2>Recent activity</h2>
                    <SessionList
                        entries={recent}
                        empty="Activity appears when trainees record their workouts."
                    />
                </section>
            </div>
        </>
    );
}

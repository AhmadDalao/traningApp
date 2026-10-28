import { Head, Link } from "@inertiajs/react";
import {
    PageHeader,
    ButtonLink,
    DateLabel,
    TimeLabel,
} from "../../Components/UI";
import { CoachStatus } from "../../Features/CoachingUI";
export default function Overview({
    stats,
    upcoming,
    recent,
    recentRecords = [],
}) {
    return (
        <>
            <Head title="Coach Overview" />
            <PageHeader
                title="Coach Overview"
                description="Your trainees, their sessions and what needs your attention."
            >
                <ButtonLink href="/coach/trainees">Manage trainees</ButtonLink>
            </PageHeader>
            <div className="coach-stats">
                {[
                    ["Active trainees", stats.trainees],
                    ["Sessions today", stats.today],
                    ["Pending requests", stats.pending],
                    ["Upcoming 7 days", stats.upcoming],
                ].map(([label, count]) => (
                    <div className="panel coach-stat" key={label}>
                        <span>{label}</span>
                        <strong>{count}</strong>
                    </div>
                ))}
            </div>
            <div className="coach-grid">
                <section className="panel coach-card">
                    <h2>Upcoming sessions</h2>
                    <p>Today and the next seven days · training and rest</p>
                    <div className="coach-scroll">
                        {upcoming.map((entry) => (
                            <Link
                                className="coach-session"
                                href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                                key={entry.id}
                            >
                                <div>
                                    <strong>{entry.trainee_name}</strong>
                                    <span>{entry.title}</span>
                                    <small>
                                        <DateLabel
                                            date={entry.scheduled_date}
                                        />{" "}
                                        {entry.scheduled_time && (
                                            <TimeLabel
                                                time={entry.scheduled_time}
                                            />
                                        )}
                                    </small>
                                </div>
                                <CoachStatus status={entry.status} />
                            </Link>
                        ))}
                        {!upcoming.length && <p>No upcoming sessions.</p>}
                    </div>
                </section>
                <section className="panel coach-card">
                    <h2>Recent activity</h2>
                    <p>Latest saved session statuses</p>
                    <div className="coach-scroll">
                        {recent.map((entry) => (
                            <Link
                                className="coach-session"
                                href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                                key={entry.id}
                            >
                                <div>
                                    <strong>{entry.trainee_name}</strong>
                                    <span>{entry.title}</span>
                                </div>
                                <CoachStatus status={entry.status} />
                            </Link>
                        ))}
                        {!recent.length && (
                            <p>
                                Activity appears after connected trainees record
                                sessions.
                            </p>
                        )}
                    </div>
                </section>
            </div>
            {recentRecords.length > 0 && (
                <section className="panel coach-card">
                    <h2>Recent personal records</h2>
                    {recentRecords.map((r, i) => (
                        <p key={i}>
                            <Link href={r.url}>
                                {r.trainee_name} · {r.name} · {r.label} →
                            </Link>
                        </p>
                    ))}
                </section>
            )}
        </>
    );
}

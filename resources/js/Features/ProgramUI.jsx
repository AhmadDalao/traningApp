import { Link } from "@inertiajs/react";
import { ButtonLink, DateLabel, StatusBadge } from "../Components/UI";
import { useTraining } from "./CoachingUI";

export function ProgramContext({ entry }) {
    const { url } = useTraining();
    if (!entry.program_run_id) return null;
    return (
        <p className="program-context">
            <Link href={url(`/program-runs/${entry.program_run_id}`)}>
                {entry.program_context?.name} · Week {entry.program_week} ·{" "}
                {entry.program_context?.block_name}
            </Link>
        </p>
    );
}
export function ProgramStats({ position }) {
    return (
        <section className="program-stats" aria-label="Program statistics">
            <div className="panel">
                <small>Program progress</small>
                <strong>
                    {position.resolved_weeks} / {position.duration_weeks} weeks
                </strong>
                <progress
                    max="100"
                    value={position.progress_percent}
                    aria-label="Resolved program weeks"
                />
                <p>Weeks with all training completed or skipped.</p>
            </div>
            <div className="panel">
                <small>Workout adherence</small>
                <strong>
                    {position.adherence === null
                        ? "No workouts due"
                        : `${position.adherence}%`}
                </strong>
                <p>
                    {position.due_completed} / {position.due} due workouts
                    completed. Future dates excluded.
                </p>
            </div>
            <div className="panel">
                <small>Due workouts</small>
                <strong>
                    {position.due_skipped} skipped · {position.missed} missed
                </strong>
                <p>
                    Missed workouts remain planned until you complete, skip or
                    reschedule them.
                </p>
            </div>
        </section>
    );
}
export function ProgramSessions({ sessions }) {
    const { url } = useTraining();
    return (
        <ul className="program-sessions">
            {sessions.map((entry) => (
                <li key={entry.id || entry.key}>
                    <div>
                        <strong>{entry.title}</strong>
                        <small>
                            <DateLabel date={entry.scheduled_date} />
                            {entry.scheduled_time
                                ? ` · ${entry.scheduled_time.slice(0, 5)}`
                                : ""}{" "}
                            ·{" "}
                            {entry.entry_type === "rest"
                                ? "Rest day"
                                : entry.program_context?.block_name}
                        </small>
                        {entry.reason && <small>{entry.reason}</small>}
                    </div>
                    {entry.id ? (
                        <ButtonLink
                            variant="secondary"
                            href={url(`/schedule-entries/${entry.id}`)}
                        >
                            {entry.missed
                                ? "Missed · Review"
                                : entry.started_at && entry.status === "planned"
                                  ? "In progress"
                                  : entry.status || "View session"}
                        </ButtonLink>
                    ) : (
                        <span>Week {entry.program_week}</span>
                    )}
                </li>
            ))}
        </ul>
    );
}
export function ProgramWeeks({ sessions, initialWeek = 1 }) {
    const weeks = [...new Set(sessions.map((row) => row.program_week))].sort(
        (a, b) => a - b,
    );
    return (
        <div className="program-stack">
            {weeks.map((week) => (
                <details
                    className="panel program-week"
                    key={week}
                    open={week === initialWeek}
                >
                    <summary>
                        Week {week} ·{" "}
                        {
                            sessions.find((row) => row.program_week === week)
                                ?.program_context?.block_name
                        }
                    </summary>
                    <ProgramSessions
                        sessions={sessions.filter(
                            (row) => row.program_week === week,
                        )}
                    />
                </details>
            ))}
        </div>
    );
}
export function BlockTimeline({ blocks, currentWeek }) {
    return (
        <ol className="program-timeline">
            {blocks.map((block, index) => (
                <li
                    className={`panel program-block ${block.type === "deload" ? "deload" : ""}`}
                    key={`${block.id || index}-${index}`}
                >
                    <span className="program-block-number">{index + 1}</span>
                    <div>
                        <h3>{block.name}</h3>
                        <p>
                            Weeks {block.start_week}–{block.end_week}
                            {block.type === "deload" ? " · Deload" : ""}
                        </p>
                        <p>{block.description}</p>
                        {currentWeek !== undefined && (
                            <span className="badge training">
                                {currentWeek === null ||
                                block.end_week < currentWeek
                                    ? "Resolved"
                                    : block.start_week <= currentWeek
                                      ? "Current block"
                                      : "Upcoming"}
                            </span>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}

export function ProgramRunCards({ runs = [] }) {
    const { url } = useTraining();
    if (!runs.length) return null;
    return (
        <section className="panel program-card">
            <div className="program-heading">
                <h2>Programs</h2>
                <ButtonLink variant="secondary" href={url("/program-runs")}>
                    All program runs
                </ButtonLink>
            </div>
            <div className="program-stack">
                {runs.map((run) => (
                    <Link
                        className="program-run-link"
                        key={run.id}
                        href={url(`/program-runs/${run.id}`)}
                    >
                        <strong>{run.name}</strong>
                        <span>
                            {run.current_week
                                ? `Week ${run.current_week} / ${run.duration_weeks} · ${run.block}`
                                : run.status}
                            {run.adherence !== null
                                ? ` · ${run.adherence}% adherence`
                                : ""}
                        </span>
                    </Link>
                ))}
            </div>
        </section>
    );
}

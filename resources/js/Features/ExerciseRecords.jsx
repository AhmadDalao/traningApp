import { Link } from "@inertiajs/react";
import { Trophy } from "lucide-react";
import { ButtonLink, DateLabel } from "../Components/UI";
import { metricText } from "./exerciseProgress";

export function ExerciseProgressTabs() {
    return (
        <nav className="progress-tabs" aria-label="Progress view">
            <ButtonLink variant="secondary" href="/progress">
                Body weight
            </ButtonLink>
            <ButtonLink href="/progress/exercises" aria-current="page">
                Exercise Progress
            </ButtonLink>
            <ButtonLink variant="secondary" href="/progress?tab=training">
                Training stats
            </ButtonLink>
        </nav>
    );
}

export function RecordValue({ record }) {
    return (
        <>
            {metricText(record.metric, record.value)}
            {record.metric === "max_reps" && record.weight > 0 && (
                <small> @ {metricText("heaviest_weight", record.weight)}</small>
            )}
        </>
    );
}

export function RecordList({ records }) {
    return (
        <ul className="exercise-record-list">
            {records.map((record) => (
                <li
                    key={`${record.source_exercise_key}-${record.tracking_mode}-${record.session_id}-${record.metric}`}
                >
                    <Trophy size={18} aria-hidden="true" />
                    <div>
                        <Link href={record.url} className="inline-link">
                            {record.name}
                        </Link>
                        <p>
                            New {record.label} · <RecordValue record={record} />
                        </p>
                        <small>
                            <DateLabel date={record.date} long />
                        </small>
                    </div>
                    <Link
                        href={`/schedule-entries/${record.session_id}`}
                        className="inline-link"
                    >
                        View workout
                    </Link>
                </li>
            ))}
        </ul>
    );
}

export function WorkoutRecords({ summary }) {
    if (
        !summary ||
        (!summary.records.length && !summary.firstPerformances.length)
    )
        return null;
    const exercises = {};
    for (const record of summary.records) {
        (exercises[record.url] ??= []).push(record);
    }
    return (
        <section className="panel exercise-pr-callout">
            <h2>
                <Trophy size={21} />
                {summary.records.length
                    ? `${summary.records.length} new personal record${summary.records.length === 1 ? "" : "s"}`
                    : "First recorded performance"}
            </h2>
            {summary.records.length > 0 && (
                <ul className="workout-record-list">
                    {Object.entries(exercises).map(([url, records]) => (
                        <li key={url}>
                            <Link href={url} className="inline-link">
                                {records[0].name}
                            </Link>
                            <ul>
                                {records.map((record) => (
                                    <li key={record.metric}>
                                        {record.label}:{" "}
                                        <RecordValue record={record} />
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
            {summary.firstPerformances.length > 0 && (
                <p>
                    First recorded performance:{" "}
                    {summary.firstPerformances.join(", ")}. This is your
                    starting point for future progress.
                </p>
            )}
        </section>
    );
}

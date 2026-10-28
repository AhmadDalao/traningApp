import { useTraining } from "../../Features/CoachingUI";
import { Head, Link, router } from "@inertiajs/react";
import {
    PageHeader,
    Breadcrumbs,
    Pagination,
    DateLabel,
    EmptyState,
    ButtonLink,
} from "../../Components/UI";
import { Thumbnail } from "../../Features/ExerciseEditor";
import {
    ExerciseProgressTabs,
    RecordValue,
} from "../../Features/ExerciseRecords";
import ExerciseProgressChart from "../../Features/ExerciseProgressChart";
import ExerciseProgression from "../../Features/ExerciseProgression";
import { metricText } from "../../Features/exerciseProgress";

export default function Show({
    exercise,
    records,
    charts,
    history,
    range,
    progression,
}) {
    const { url } = useTraining();
    const cards = records.filter(
        (record) => !exercise.weighted || record.metric !== "max_reps",
    );
    const reps = records.find((record) => record.metric === "max_reps");
    return (
        <>
            <Head title={`${exercise.name} progress`} />
            <Breadcrumbs
                items={[
                    {
                        label: "Exercise Progress",
                        href: url("/progress/exercises"),
                    },
                    { label: exercise.name },
                ]}
            />
            <PageHeader
                title={exercise.name}
                description="Your actual completed performance, saved independently of your training plans."
            />
            <ExerciseProgressTabs />
            <div className="exercise-progress-intro">
                <Thumbnail exercise={exercise} />
                <div>
                    <strong>
                        {exercise.tracking_mode === "duration"
                            ? "Duration"
                            : "Reps"}{" "}
                        progress
                    </strong>
                    <p>
                        Last performed{" "}
                        <DateLabel date={exercise.last_performed} long />
                        {exercise.weighted && (
                            <>
                                {" "}
                                · Latest working weight:{" "}
                                {metricText(
                                    "heaviest_weight",
                                    exercise.latest_weight,
                                )}
                            </>
                        )}
                    </p>
                    {exercise.workouts === 1 && (
                        <p>
                            First recorded performance. This is your starting
                            point.
                        </p>
                    )}
                </div>
            </div>
            <ExerciseProgression
                progression={progression}
                exercise={exercise}
                range={range}
            />
            <div className="exercise-progress-heading">
                <div>
                    <h2>Personal Records</h2>
                    <p>All time · Actual completed sets only</p>
                </div>
            </div>
            <div className="exercise-record-grid">
                {cards.map((record) => (
                    <article
                        className="stat-card exercise-record-card"
                        key={record.metric}
                    >
                        <span>{record.label}</span>
                        <strong>
                            <RecordValue record={record} />
                        </strong>
                        <Link
                            className="inline-link"
                            href={url(`/schedule-entries/${record.session_id}`)}
                        >
                            <DateLabel date={record.date} long />
                        </Link>
                    </article>
                ))}
                <article className="stat-card exercise-record-card">
                    <span>Completed workouts</span>
                    <strong>{exercise.workouts}</strong>
                    <small>With qualifying recorded sets</small>
                </article>
            </div>
            <p className="progress-explanation">
                {exercise.weighted ? (
                    <>
                        Best Set means the highest weight, then the most reps at
                        that weight.{" "}
                        {reps && (
                            <>
                                Max reps in one set:{" "}
                                <RecordValue record={reps} />.{" "}
                            </>
                        )}
                        Weighted volume includes only positive actual weights
                        and actual reps.
                    </>
                ) : exercise.tracking_mode === "duration" ? (
                    "Durations come from completed timed sets. Planned durations are not counted."
                ) : (
                    "Zero or missing external weight still counts toward reps. No weight or volume is invented."
                )}
            </p>
            <div className="exercise-progress-heading">
                <div>
                    <h2>Performance over time</h2>
                    <p>One point per completed workout</p>
                </div>
                <label className="exercise-range">
                    Chart and history period
                    <select
                        aria-label="Chart and history period"
                        value={range}
                        onChange={(event) =>
                            router.get(
                                url("/progress/exercises"),
                                {
                                    exercise: exercise.key,
                                    mode: exercise.tracking_mode,
                                    range: event.target.value,
                                    ...(progression.selected_id
                                        ? { template: progression.selected_id }
                                        : {}),
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <option value="30">30 Days</option>
                        <option value="90">90 Days</option>
                        <option value="6m">6 Months</option>
                        <option value="1y">1 Year</option>
                        <option value="all">All</option>
                    </select>
                </label>
            </div>
            <div className="exercise-charts">
                {charts.map((chart) => (
                    <ExerciseProgressChart
                        key={`${chart.metric}-${range}`}
                        chart={chart}
                    />
                ))}
            </div>
            <section aria-labelledby="exercise-history">
                <div className="exercise-progress-heading">
                    <div>
                        <h2 id="exercise-history">Exercise workout history</h2>
                        <p>Actual completed sets · 10 workouts per page</p>
                    </div>
                </div>
                {history.data.length ? (
                    <div className="exercise-history-list">
                        {history.data.map((workout) => (
                            <article
                                className="panel exercise-history-card"
                                key={workout.session_id}
                            >
                                <header>
                                    <div>
                                        <h3>
                                            <DateLabel
                                                date={workout.scheduled_date}
                                                long
                                            />
                                        </h3>
                                        <p>{workout.session_title}</p>
                                    </div>
                                    <ButtonLink
                                        variant="secondary"
                                        className="small"
                                        href={url(
                                            `/schedule-entries/${workout.session_id}`,
                                        )}
                                    >
                                        View workout
                                    </ButtonLink>
                                </header>
                                <ul className="exercise-result-sets">
                                    {workout.sets.map((set) => (
                                        <li key={set.set_id}>
                                            <span>Set {set.set_number}</span>
                                            <strong>
                                                {set.tracking_mode ===
                                                "duration"
                                                    ? metricText(
                                                          "longest_duration",
                                                          set.actual_duration_seconds,
                                                      )
                                                    : `${set.actual_reps} reps`}
                                                {Number(set.actual_weight_kg) >
                                                    0 && (
                                                    <>
                                                        {" "}
                                                        @{" "}
                                                        {metricText(
                                                            "heaviest_weight",
                                                            set.actual_weight_kg,
                                                        )}
                                                    </>
                                                )}
                                            </strong>
                                        </li>
                                    ))}
                                </ul>
                                <p className="exercise-history-total">
                                    {workout.tracking_mode === "duration" ? (
                                        <>
                                            Total duration:{" "}
                                            {metricText(
                                                "total_duration",
                                                workout.total_duration,
                                            )}
                                        </>
                                    ) : (
                                        <>
                                            Total reps: {workout.total_reps}
                                            {workout.weighted_volume !==
                                                null && (
                                                <>
                                                    {" "}
                                                    · Weighted volume:{" "}
                                                    {metricText(
                                                        "weighted_volume",
                                                        workout.weighted_volume,
                                                    )}
                                                </>
                                            )}
                                        </>
                                    )}
                                </p>
                            </article>
                        ))}
                    </div>
                ) : (
                    <section className="panel">
                        <EmptyState
                            title="No workouts in this period"
                            description="Select a wider period to see earlier results."
                        />
                    </section>
                )}
                <Pagination links={history.links} />
            </section>
        </>
    );
}

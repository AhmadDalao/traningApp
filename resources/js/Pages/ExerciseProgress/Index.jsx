import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { Search, ArrowUpRight } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    EmptyState,
    Pagination,
    DateLabel,
} from "../../Components/UI";
import { Thumbnail } from "../../Features/ExerciseEditor";
import {
    ExerciseProgressTabs,
    RecordList,
    RecordValue,
} from "../../Features/ExerciseRecords";

export default function Index({
    exercises,
    recentRecords,
    trackedCount,
    search,
    errors,
}) {
    const { url } = useTraining();
    const [query, setQuery] = useState(search);
    function submit(event) {
        event.preventDefault();
        router.get(
            url("/progress/exercises"),
            { search: query },
            { preserveState: true, preserveScroll: true },
        );
    }
    return (
        <>
            <Head title="Exercise Progress" />
            <PageHeader
                title="Progress"
                description="See how your recorded exercise performance changes over time."
            />
            <ExerciseProgressTabs />
            {errors.progression && (
                <p className="field-error" role="alert">
                    {errors.progression}
                </p>
            )}
            {trackedCount === 0 ? (
                <section className="panel">
                    <EmptyState
                        title="No detailed exercise progress yet."
                        description="Start logging workouts with Active Workout to build your exercise history."
                    >
                        <ButtonLink href={url("/calendar")}>
                            Open Calendar
                        </ButtonLink>
                    </EmptyState>
                </section>
            ) : (
                <>
                    <section className="panel exercise-pr-callout">
                        <h2>Recent Personal Records</h2>
                        {recentRecords.length ? (
                            <RecordList records={recentRecords} />
                        ) : (
                            <p>
                                Your first performances establish a starting
                                point. New records will appear when you improve
                                on them.
                            </p>
                        )}
                    </section>
                    <section aria-labelledby="tracked-exercises">
                        <div className="exercise-progress-heading">
                            <div>
                                <h2 id="tracked-exercises">
                                    Tracked exercises
                                </h2>
                                <p>
                                    Only completed sets from completed workouts
                                    count.
                                </p>
                            </div>
                            <form
                                onSubmit={submit}
                                className="exercise-progress-search"
                            >
                                <label
                                    className="sr-only"
                                    htmlFor="exercise-search"
                                >
                                    Search exercises
                                </label>
                                <input
                                    id="exercise-search"
                                    type="search"
                                    placeholder="Search exercises…"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    maxLength={150}
                                />
                                <Button type="submit" variant="secondary">
                                    <Search size={16} />
                                    Search
                                </Button>
                            </form>
                        </div>
                        {exercises.data.length ? (
                            <div className="exercise-progress-grid">
                                {exercises.data.map((exercise) => {
                                    const keyMetric =
                                        exercise.tracking_mode === "duration"
                                            ? "longest_duration"
                                            : exercise.weighted
                                              ? "best_set"
                                              : "max_reps";
                                    const record = exercise.records.find(
                                        (item) => item.metric === keyMetric,
                                    );
                                    return (
                                        <article
                                            className="panel exercise-progress-card"
                                            key={`${exercise.key}-${exercise.tracking_mode}`}
                                        >
                                            <div className="exercise-progress-identity">
                                                <Thumbnail
                                                    exercise={exercise}
                                                />
                                                <div>
                                                    <h3>
                                                        <Link
                                                            href={exercise.url}
                                                        >
                                                            {exercise.name}
                                                        </Link>
                                                    </h3>
                                                    <p>
                                                        {exercise.tracking_mode ===
                                                        "duration"
                                                            ? "Duration"
                                                            : exercise.weighted
                                                              ? "Reps · Weighted results"
                                                              : "Reps"}
                                                    </p>
                                                </div>
                                            </div>
                                            <dl>
                                                <div>
                                                    <dt>Completed workouts</dt>
                                                    <dd>{exercise.workouts}</dd>
                                                </div>
                                                <div>
                                                    <dt>Last performed</dt>
                                                    <dd>
                                                        <DateLabel
                                                            date={
                                                                exercise.last_performed
                                                            }
                                                        />
                                                    </dd>
                                                </div>
                                                {record && (
                                                    <div>
                                                        <dt>{record.label}</dt>
                                                        <dd>
                                                            <RecordValue
                                                                record={record}
                                                            />
                                                        </dd>
                                                    </div>
                                                )}
                                            </dl>
                                            {exercise.workouts === 1 && (
                                                <p className="progress-explanation">
                                                    First recorded performance
                                                </p>
                                            )}
                                            <ButtonLink
                                                className="small"
                                                variant="secondary"
                                                href={exercise.url}
                                            >
                                                View Progress
                                                <ArrowUpRight size={16} />
                                            </ButtonLink>
                                        </article>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="panel">
                                <EmptyState
                                    title="No exercises match your search"
                                    description="Try another name or clear the search."
                                >
                                    <ButtonLink
                                        variant="secondary"
                                        href={url("/progress/exercises")}
                                    >
                                        Clear search
                                    </ButtonLink>
                                </EmptyState>
                            </div>
                        )}
                        <Pagination links={exercises.links} />
                    </section>
                    <p className="progress-explanation">
                        Older workouts without a saved exercise identity stay in
                        Training History. Reps and duration records are kept
                        separate if an exercise was logged in both modes.
                    </p>
                </>
            )}
        </>
    );
}

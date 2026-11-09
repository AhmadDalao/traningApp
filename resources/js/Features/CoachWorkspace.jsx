import { Link } from "@inertiajs/react";
import { ArrowRight, CalendarDays, CheckCircle2, Dumbbell } from "lucide-react";
import TrainingThumbnail from "../Components/TrainingThumbnail";
import {
    ButtonLink,
    DateLabel,
    TimeLabel,
    StatusBadge,
} from "../Components/UI";
import { useTraining, CoachStatus } from "./CoachingUI";

export function WorkoutDate({ entry }) {
    return (
        <>
            <DateLabel date={entry.scheduled_date} /> ·{" "}
            {entry.scheduled_time ? (
                <TimeLabel time={entry.scheduled_time} />
            ) : (
                "Anytime"
            )}
        </>
    );
}

export function CurrentTraining({
    training,
    nextSession,
    title = "Current Training",
}) {
    const ctx = useTraining();
    return (
        <section className="panel coach-card coach-current-training">
            <div className="coach-card-title">
                <h2>{title}</h2>
                {training && ctx.canManage && (
                    <Link href={ctx.url("/setup?assign=1")}>
                        Assign training
                    </Link>
                )}
            </div>
            {training ? (
                <>
                    <div className="coach-training-identity">
                        <TrainingThumbnail item={training} />
                        <div>
                            <h3>{training.name}</h3>
                            <p>
                                {training.current_week
                                    ? `Week ${training.current_week} of ${training.duration_weeks}`
                                    : training.structure_type === "program"
                                      ? `${training.duration_weeks}-week program`
                                      : "Simple plan"}
                                {training.block ? ` · ${training.block}` : ""}
                            </p>
                            {training.started_on && (
                                <small>
                                    Starts{" "}
                                    <DateLabel date={training.started_on} />
                                </small>
                            )}
                            {training.progress_percent != null && (
                                <div className="program-mini-progress">
                                    <progress
                                        max="100"
                                        value={training.progress_percent}
                                        aria-label="Resolved program weeks"
                                    />
                                    <small>
                                        {training.progress_percent}% complete
                                    </small>
                                </div>
                            )}
                        </div>
                        <CoachStatus status={training.run_status || "active"} />
                    </div>
                    {nextSession && (
                        <div className="coach-next-workout">
                            <CalendarDays size={20} aria-hidden="true" />
                            <div>
                                <small>Next workout</small>
                                <Link
                                    href={ctx.url(
                                        `/schedule-entries/${nextSession.id}`,
                                    )}
                                >
                                    {nextSession.title}
                                </Link>
                                <small>
                                    <WorkoutDate entry={nextSession} />
                                </small>
                            </div>
                        </div>
                    )}
                    <div className="coach-actions">
                        <ButtonLink
                            variant="secondary"
                            href={ctx.url(`/training-plans/${training.id}`)}
                        >
                            View Training{" "}
                            <ArrowRight size={15} aria-hidden="true" />
                        </ButtonLink>
                        {ctx.canManage && (
                            <ButtonLink
                                variant="secondary"
                                href={ctx.url(
                                    training.structure_type === "program" &&
                                        training.run_id
                                        ? `/program-runs/${training.run_id}?schedule=1`
                                        : `/training-plans/${training.id}/schedule-preview`,
                                )}
                            >
                                Edit Schedule
                            </ButtonLink>
                        )}
                    </div>
                </>
            ) : (
                <div className="coach-empty">
                    <Dumbbell size={28} aria-hidden="true" />
                    <h3>No training assigned yet</h3>
                    <p>
                        Choose a program, review its schedule, and assign it
                        here.
                    </p>
                    <ButtonLink href={ctx.url("/setup")}>
                        Assign Training
                    </ButtonLink>
                </div>
            )}
        </section>
    );
}

export function WeeklyTraining({ entries = [] }) {
    const ctx = useTraining(),
        training = entries.filter((s) => s.entry_type === "training"),
        completed = training.filter((s) => s.status === "completed").length;
    return (
        <section className="panel coach-card coach-week">
            <div className="coach-card-title">
                <h2>This Week</h2>
                <Link href={ctx.url("/calendar")}>Calendar →</Link>
            </div>
            <ul className="coach-week-list">
                {entries.slice(0, 7).map((s) => (
                    <li key={s.id}>
                        <time dateTime={s.scheduled_date}>
                            {new Date(
                                `${s.scheduled_date}T12:00:00`,
                            ).toLocaleDateString("en-GB", { weekday: "short" })}
                        </time>
                        <div>
                            <Link href={ctx.url(`/schedule-entries/${s.id}`)}>
                                {s.title}
                            </Link>
                            <small>
                                {s.scheduled_time ? (
                                    <TimeLabel time={s.scheduled_time} />
                                ) : (
                                    "Anytime"
                                )}
                            </small>
                        </div>
                        {s.missed ? (
                            <span className="badge skipped">Missed</span>
                        ) : (
                            <StatusBadge
                                status={s.status}
                                active={
                                    s.status === "planned" && !!s.started_at
                                }
                            />
                        )}
                    </li>
                ))}
            </ul>
            {entries.length > 7 && (
                <Link className="text-link" href={ctx.url("/calendar")}>
                    View all {entries.length} workouts this week →
                </Link>
            )}
            {entries.length ? (
                <p className="coach-week-total">
                    <CheckCircle2 size={16} aria-hidden="true" /> {completed} of{" "}
                    {training.length} workouts completed
                </p>
            ) : (
                <p>No workouts scheduled this week.</p>
            )}
        </section>
    );
}

export function TrainingRow({ plan, children }) {
    const ctx = useTraining();
    return (
        <article className="panel coach-training-row">
            <TrainingThumbnail item={plan} />
            <div className="coach-row-copy">
                <Link href={ctx.url(`/training-plans/${plan.id}`)}>
                    <h3>{plan.name}</h3>
                </Link>
                <p>
                    {plan.structure_type === "program"
                        ? `${plan.duration_weeks} weeks`
                        : "Simple plan"}
                    {plan.weekly_frequency
                        ? ` · ${plan.weekly_frequency.min === plan.weekly_frequency.max ? plan.weekly_frequency.min : `${plan.weekly_frequency.min}–${plan.weekly_frequency.max}`} days/week`
                        : plan.workout_days_count != null
                          ? ` · ${plan.workout_days_count} workouts`
                          : ""}
                    {plan.run_status ? ` · ${plan.run_status}` : ""}
                </p>
                {plan.description && (
                    <p className="coach-row-description">{plan.description}</p>
                )}
                {plan.assigned_trainees_count != null && (
                    <small>
                        {plan.assigned_trainees_count} active{" "}
                        {plan.assigned_trainees_count === 1
                            ? "trainee"
                            : "trainees"}
                    </small>
                )}
            </div>
            <div className="coach-row-actions">{children}</div>
        </article>
    );
}

import { useTraining } from "../../Features/CoachingUI";
import { BlockTimeline } from "../../Features/ProgramUI";
import { Head, useForm } from "@inertiajs/react";
import { ArrowRight, Check } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Breadcrumbs,
} from "../../Components/UI";

export default function Show({ plan }) {
    const { url } = useTraining();
    const isProgram = plan.structure_type === "program";
    const form = useForm({ preset: plan.key });
    function usePlan() {
        form.post(url("/recommendations"));
    }
    return (
        <div className="discovery-page discovery-preview">
            <Head title={plan.name} />
            <Breadcrumbs
                items={[
                    {
                        label: "Find a training plan",
                        href: url("/recommendations"),
                    },
                    { label: plan.name },
                ]}
            />
            <PageHeader title={plan.name} description={plan.description}>
                <Button disabled={form.processing} onClick={usePlan}>
                    {form.processing
                        ? "Adding…"
                        : isProgram
                          ? "Use this program"
                          : "Use this plan"}
                    <ArrowRight size={16} />
                </Button>
            </PageHeader>
            {form.errors.preset && (
                <p className="field-error" role="alert">
                    {form.errors.preset}
                </p>
            )}
            <section
                className="panel discovery-overview"
                aria-label="Plan overview"
            >
                <dl>
                    <div>
                        <dt>Experience</dt>
                        <dd>{plan.level_label}</dd>
                    </div>
                    <div>
                        <dt>Weekly commitment</dt>
                        <dd>{plan.days_per_week} days</dd>
                    </div>
                    <div>
                        <dt>Typical session</dt>
                        <dd>About {plan.duration} minutes</dd>
                    </div>
                    <div>
                        <dt>Equipment</dt>
                        <dd>{plan.equipment_label}</dd>
                    </div>
                    <div className="wide-detail">
                        <dt>Supports</dt>
                        <dd>{plan.goal_labels.join(" · ")}</dd>
                    </div>
                </dl>
                {plan.level === "advanced" && (
                    <p className="discovery-level-note">
                        Advanced routine · Higher volume or more demanding
                        movements. You can choose this plan at any experience
                        level and adjust it to suit you.
                    </p>
                )}
                <div className="discovery-reasons">
                    <h2>How it fits your preferences</h2>
                    <ul>
                        {plan.reasons.map((reason) => (
                            <li key={reason}>
                                <Check size={15} aria-hidden="true" />
                                <span>{reason}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="field-hint">
                    This creates your own editable copy. Choose dates and
                    weekdays in the plan, then preview its schedule before
                    adding sessions to Calendar.
                </p>
            </section>
            {isProgram && (
                <section>
                    <h2>{plan.duration_weeks}-week program</h2>
                    <BlockTimeline blocks={plan.blocks} />
                    {plan.blocks.map((block, i) => (
                        <details
                            className="panel program-card"
                            key={i}
                            open={i === 0}
                        >
                            <summary>
                                {block.name} · Weeks {block.start_week}–
                                {block.end_week}
                            </summary>
                            {block.days.map((day, j) => (
                                <div className="program-day" key={j}>
                                    <h3>{day.title}</h3>
                                    <ul className="program-prescriptions">
                                        {day.exercises.map((ex, k) => (
                                            <li key={k}>
                                                <img
                                                    src={ex.thumbnail_url}
                                                    alt=""
                                                    width="48"
                                                    height="48"
                                                    loading="lazy"
                                                />
                                                <span>
                                                    <strong>{ex.name}</strong>
                                                    <small>
                                                        {ex.sets} × {ex.reps}
                                                    </small>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </details>
                    ))}
                </section>
            )}
            {!isProgram && (
                <section
                    aria-labelledby="weekly-split-title"
                    className="discovery-section"
                >
                    <div className="discovery-section-heading">
                        <h2 id="weekly-split-title">Your weekly split</h2>
                        <p>
                            Review each day’s exercises and starting
                            prescriptions. Working weights are left for you to
                            choose.
                        </p>
                    </div>
                    <ol className="discovery-split">
                        {plan.split.map((title, index) => (
                            <li key={index}>
                                <span>Day {index + 1}</span>
                                <a href={`#plan-day-${index + 1}`}>{title}</a>
                            </li>
                        ))}
                    </ol>
                    {plan.days.map((day, index) => (
                        <details
                            className="panel discovery-day"
                            key={index}
                            id={`plan-day-${index + 1}`}
                            open
                        >
                            <summary>
                                <span>
                                    <small>
                                        DAY {index + 1} · {day.focus_area}
                                    </small>
                                    <strong>{day.title}</strong>
                                </span>
                                <span>{day.exercises.length} exercises</span>
                            </summary>
                            <p className="discovery-day-note">{day.notes}</p>
                            <ol className="discovery-exercises">
                                {day.exercises.map((exercise, position) => (
                                    <li key={`${exercise.key}-${position}`}>
                                        <img
                                            src={exercise.thumbnail_url}
                                            alt=""
                                            width="56"
                                            height="56"
                                            loading="lazy"
                                        />
                                        <div>
                                            <h3>{exercise.name}</h3>
                                            <p>
                                                {exercise.sets}{" "}
                                                {exercise.sets === 1
                                                    ? "set"
                                                    : "sets"}{" "}
                                                × {exercise.reps}
                                                {exercise.tracking_mode ===
                                                "reps"
                                                    ? " reps"
                                                    : ""}
                                            </p>
                                            {exercise.superset_group && (
                                                <span className="badge training">
                                                    Superset{" "}
                                                    {exercise.superset_group}
                                                </span>
                                            )}
                                            {exercise.notes && (
                                                <p className="field-hint">
                                                    {exercise.notes}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </details>
                    ))}
                </section>
            )}
            <section className="panel discovery-use">
                <div>
                    <h2>Make this plan yours</h2>
                    <p>
                        Edit exercises, weights, and scheduling after adding it
                        to My plans.
                    </p>
                </div>
                <div>
                    <ButtonLink
                        href={url("/recommendations")}
                        variant="secondary"
                    >
                        Back to all plans
                    </ButtonLink>
                    <Button disabled={form.processing} onClick={usePlan}>
                        {form.processing
                            ? "Adding…"
                            : isProgram
                              ? "Use this program"
                              : "Use this plan"}
                    </Button>
                </div>
            </section>
        </div>
    );
}

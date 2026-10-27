import { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import { Check, ArrowRight } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    EmptyState,
    Modal,
} from "../../Components/UI";

export default function Index({ recommendations }) {
    const [selected, setSelected] = useState(null);
    const form = useForm({ preset: "" });
    function addPlan(key) {
        form.transform(() => ({ preset: key }));
        form.post("/recommendations");
    }
    return (
        <>
            <Head title="Recommended plans" />
            <PageHeader
                title="Recommended for you"
                description="Starting points based on your training preferences. Every plan can be customized."
            >
                <ButtonLink
                    href="/profile/edit#preferences"
                    variant="secondary"
                >
                    Edit preferences
                </ButtonLink>
            </PageHeader>
            {form.errors.preset && (
                <p className="field-error" role="alert">
                    {form.errors.preset}
                </p>
            )}
            {recommendations.length ? (
                <>
                    <div className="recommendation-grid">
                        {recommendations.map((plan) => (
                            <article
                                className="panel recommendation-card"
                                key={plan.key}
                            >
                                <img
                                    src={
                                        plan.days[0]?.exercises[0]
                                            ?.thumbnail_url || "/images/gym.jpg"
                                    }
                                    alt=""
                                />
                                <div className="recommendation-content">
                                    <h2>{plan.name}</h2>
                                    <div className="recommendation-tags">
                                        <span className="badge training">
                                            {plan.level}
                                        </span>
                                        <span className="badge planned">
                                            {plan.equipment}
                                        </span>
                                    </div>
                                    <p>{plan.description}</p>
                                    <h3>Why this fits you</h3>
                                    <ul>
                                        {plan.reasons.map((reason) => (
                                            <li key={reason}>
                                                <Check size={15} />
                                                {reason}
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="field-hint">{plan.reason}</p>
                                    <button
                                        className="text-link"
                                        onClick={() => setSelected(plan)}
                                    >
                                        View plan details
                                        <ArrowRight size={15} />
                                    </button>
                                    <Button
                                        disabled={form.processing}
                                        onClick={() => addPlan(plan.key)}
                                    >
                                        {form.processing
                                            ? "Adding…"
                                            : "Use this plan"}
                                    </Button>
                                </div>
                            </article>
                        ))}
                    </div>
                    <div className="recommendation-note">
                        <h3>Why these plans?</h3>
                        <p>
                            We use your primary and secondary goals, experience,
                            available days, equipment, and typical session
                            duration. BMI does not choose your goal. Nothing is
                            added until you select Use this plan. You can always
                            create your own plan.
                        </p>
                    </div>
                </>
            ) : (
                <section className="panel">
                    <EmptyState
                        title="Complete your training preferences to get recommendations."
                        description="Choose a goal, experience level, days, equipment, and typical session duration."
                    >
                        <ButtonLink href="/profile/edit#preferences">
                            Complete profile
                        </ButtonLink>
                    </EmptyState>
                </section>
            )}
            {selected && (
                <Modal
                    title={selected.name}
                    description="Review the routine before adding it to your Training plans."
                    onClose={() => !form.processing && setSelected(null)}
                    wide
                >
                    <div className="recommendation-details">
                        <h3>Why this fits you</h3>
                        <p className="progress-explanation">
                            {selected.reason}
                        </p>
                        <ul>
                            {selected.reasons.map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                        {selected.days.map((day) => (
                            <section key={day.title}>
                                <h3>{day.title}</h3>
                                <ul>
                                    {day.exercises.map((exercise) => (
                                        <li key={exercise.key}>
                                            <strong>{exercise.name}</strong>
                                            <span>
                                                {exercise.sets} ×{" "}
                                                {exercise.reps}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                    <p className="field-hint">
                        Customize the saved plan's exercises, dates, and
                        weekdays before previewing its calendar schedule.
                    </p>
                    <div className="form-actions">
                        <Button
                            variant="secondary"
                            disabled={form.processing}
                            onClick={() => setSelected(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={form.processing}
                            onClick={() => addPlan(selected.key)}
                        >
                            Use this plan & customize
                        </Button>
                    </div>
                </Modal>
            )}
        </>
    );
}

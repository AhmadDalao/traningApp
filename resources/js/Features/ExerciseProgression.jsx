import { useState } from "react";
import { router, useForm, usePage } from "@inertiajs/react";
import { ArrowRight, TrendingUp } from "lucide-react";
import { Button, Field, Modal } from "../Components/UI";

export default function ExerciseProgression({ progression, exercise, range }) {
    const [open, setOpen] = useState(false);
    const { errors } = usePage().props;
    const { templates, selected_id, suggestion } = progression;
    const selected = templates.find((item) => item.id === selected_id);
    return (
        <section
            className="panel progression-card"
            id="next-target"
            aria-labelledby="next-target-title"
        >
            <header>
                <h2 id="next-target-title">
                    <TrendingUp size={20} aria-hidden="true" /> Next target
                </h2>
                <p>
                    Based on your latest two detailed workouts for the selected
                    plan exercise.
                </p>
            </header>
            {templates.length > 1 ? (
                <Field
                    label="Choose a plan exercise"
                    hint="Each plan exercise has its own targets and workout history."
                >
                    <select
                        value={selected_id ?? ""}
                        onChange={(event) => {
                            setOpen(false);
                            router.get(
                                "/progress/exercises",
                                {
                                    exercise: exercise.key,
                                    mode: exercise.tracking_mode,
                                    range,
                                    ...(event.target.value
                                        ? { template: event.target.value }
                                        : {}),
                                },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <option value="">Select a plan and training day</option>
                        {templates.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </Field>
            ) : selected ? (
                <p className="progression-plan">{selected.label}</p>
            ) : null}
            {templates.length === 0 && (
                <p className="workout-notice">
                    This exercise is no longer part of one of your current
                    training plans. Add it to a current plan before progression
                    can be applied.
                </p>
            )}
            {templates.length > 1 && selected && (
                <p className="progression-plan">{selected.label}</p>
            )}
            {errors.progression && (
                <p className="field-error" role="alert">
                    {errors.progression}
                </p>
            )}
            {suggestion ? (
                <>
                    {suggestion.historical && (
                        <p className="eyebrow">
                            Historical suggestion · Based on your last recorded
                            prescription
                        </p>
                    )}
                    <div className="progression-result">
                        <div>
                            <h3>{suggestion.title}</h3>
                            <div className="progression-values">
                                <span>
                                    <small>Current target</small>
                                    <strong>{suggestion.current || "—"}</strong>
                                </span>
                                {suggestion.suggested && (
                                    <>
                                        <ArrowRight
                                            size={22}
                                            aria-hidden="true"
                                        />
                                        <span>
                                            <small>Suggested target</small>
                                            <strong>
                                                {suggestion.suggested}
                                            </strong>
                                        </span>
                                    </>
                                )}
                            </div>
                            <p>{suggestion.reason}</p>
                        </div>
                        {suggestion.can_apply && (
                            <Button onClick={() => setOpen(true)}>
                                Apply suggestion
                            </Button>
                        )}
                    </div>
                    {suggestion.can_apply && (
                        <p className="field-hint">
                            Your plan changes only when you apply a target. You
                            can edit the suggestion first.
                        </p>
                    )}
                </>
            ) : (
                <p className="progression-empty">
                    {templates.length
                        ? "Select a plan exercise to see its next target."
                        : "No current progression target is available."}
                </p>
            )}
            {open && suggestion?.can_apply && (
                <ApplyProgression
                    key={suggestion.token}
                    suggestion={suggestion}
                    template={selected}
                    exercise={exercise}
                    onClose={() => setOpen(false)}
                />
            )}
        </section>
    );
}

function ApplyProgression({ suggestion, template, exercise, onClose }) {
    const form = useForm({
        token: suggestion.token,
        value: suggestion.proposed_value,
        exercise: exercise.key,
        mode: exercise.tracking_mode,
    });
    const weighted = suggestion.field === "target_weight";
    const timed = suggestion.target.mode === "duration";
    return (
        <Modal
            title="Apply suggestion"
            description="Update this plan exercise for future workouts."
            onClose={() => !form.processing && onClose()}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(
                        `/training-exercises/${template.id}/progression`,
                        {
                            preserveScroll: true,
                            onSuccess: onClose,
                            onError: (errors) => {
                                if (errors.progression) onClose();
                            },
                        },
                    );
                }}
            >
                <p className="progression-plan">{template.label}</p>
                <div className="progression-values">
                    <span>
                        <small>Current target</small>
                        <strong>{suggestion.current}</strong>
                    </span>
                    <ArrowRight size={22} aria-hidden="true" />
                    <span>
                        <small>Suggested target</small>
                        <strong>{suggestion.suggested}</strong>
                    </span>
                </div>
                <Field
                    label={
                        weighted
                            ? "New planned weight (kg)"
                            : timed
                              ? "New duration target"
                              : "New reps target"
                    }
                    error={form.errors.value}
                    hint={
                        weighted
                            ? "Choose the weight that suits your equipment."
                            : timed
                              ? "For example: 50 sec or 1:30."
                              : "For example: 13 or 9-11."
                    }
                    required
                >
                    <input
                        autoFocus
                        type={weighted ? "number" : "text"}
                        min={weighted ? "0.01" : undefined}
                        step={weighted ? "0.01" : undefined}
                        required
                        maxLength={100}
                        value={form.data.value}
                        onChange={(event) =>
                            form.setData("value", event.target.value)
                        }
                    />
                </Field>
                <p className="field-hint">
                    Only this exercise's target changes. Active workouts and
                    saved history keep their original prescriptions.
                </p>
                <div className="form-actions">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button disabled={form.processing}>
                        {form.processing ? "Applying…" : "Apply to plan"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

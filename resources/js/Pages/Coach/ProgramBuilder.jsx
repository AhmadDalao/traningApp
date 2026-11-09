import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Plus, Pencil, Save, ArrowRight, Layers } from "lucide-react";
import { PageHeader, Field, Button, ButtonLink } from "../../Components/UI";
import TrainingThumbnail from "../../Components/TrainingThumbnail";
import PersonAvatar from "../../Components/PersonAvatar";
import { coachingJson } from "../../Features/CoachingUI";
import { weekdaySummary } from "../../Components/WeekdayPicker";

export default function ProgramBuilder({ plan: initial, assignTo }) {
    const [plan, setPlan] = useState(initial);
    const [data, setData] = useState({
        name: initial?.name || "",
        description: initial?.description || "",
        structure_type: initial?.structure_type || "program",
        duration_weeks: initial?.duration_weeks || 8,
        schedule_mode: initial?.schedule_mode || "fixed",
    });
    const [busy, setBusy] = useState(false),
        [errors, setErrors] = useState({});
    const change = (key, value) =>
        setData((current) => ({ ...current, [key]: value }));
    async function save(action, day = null) {
        setBusy(true);
        setErrors({});
        try {
            const result = await coachingJson(
                plan
                    ? `/coach/training-plans/${plan.id}`
                    : "/coach/training-plans",
                data,
                plan ? "PUT" : "POST",
            );
            setPlan(result.plan);
            if (action === "day") {
                router.visit(
                    day
                        ? `/coach/training-days/${day.id}/edit?builder=1${assignTo ? `&assign_to=${assignTo.id}` : ""}`
                        : `/coach/training-plans/${result.plan.id}/days/create?builder=1${assignTo ? `&assign_to=${assignTo.id}` : ""}`,
                );
            } else if (action === "assign")
                router.visit(
                    assignTo
                        ? `/coach/trainees/${assignTo.id}/setup?template=${result.plan.id}`
                        : `/coach/training-plans/${result.plan.id}/assign`,
                );
            else router.visit(`/coach/training-plans/${result.plan.id}`);
        } catch (error) {
            setErrors({ ...error.fields, save: error.message });
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="coach-program-builder">
            <Head
                title={
                    plan ? "Edit training program" : "Create training program"
                }
            />
            <PageHeader
                title={
                    plan ? "Edit Training Program" : "Create Training Program"
                }
                description="Build your reusable training, then assign it to a trainee."
            >
                <ButtonLink variant="secondary" href="/coach/training-plans">
                    Programs
                </ButtonLink>
            </PageHeader>
            {assignTo && (
                <section className="panel coach-card builder-trainee">
                    <PersonAvatar name={assignTo.name} />
                    <div>
                        <strong>Training for {assignTo.name}</strong>
                        <p>
                            {assignTo.training_code} · Return to their schedule
                            after saving.
                        </p>
                    </div>
                </section>
            )}
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    save("save");
                }}
            >
                <fieldset
                    disabled={busy}
                    className="setup-fields"
                    aria-busy={busy}
                >
                    {errors.save && (
                        <p className="field-error" role="alert">
                            {errors.save}
                        </p>
                    )}
                    <div className="coach-builder-grid">
                        <section className="panel coach-card">
                            <h2>
                                <Layers size={20} aria-hidden="true" /> Program
                                details
                            </h2>
                            <Field
                                label="Program name"
                                required
                                error={errors.name}
                            >
                                <input
                                    autoFocus
                                    required
                                    maxLength={150}
                                    value={data.name}
                                    onChange={(e) =>
                                        change("name", e.target.value)
                                    }
                                    placeholder="e.g. Full Body Strength"
                                />
                            </Field>
                            <div className="form-grid">
                                <Field
                                    label="Training format"
                                    error={errors.structure_type}
                                >
                                    <select
                                        disabled={!!plan}
                                        value={data.structure_type}
                                        onChange={(e) =>
                                            change(
                                                "structure_type",
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="program">
                                            Structured program
                                        </option>
                                        <option value="simple">
                                            Simple plan
                                        </option>
                                    </select>
                                </Field>
                                {data.structure_type === "program" && (
                                    <Field
                                        label="Duration (weeks)"
                                        required
                                        error={errors.duration_weeks}
                                    >
                                        <input
                                            type="number"
                                            min={1}
                                            max={52}
                                            required
                                            disabled={!!plan}
                                            value={data.duration_weeks}
                                            onChange={(e) =>
                                                change(
                                                    "duration_weeks",
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                            </div>
                            <Field
                                label="Description (optional)"
                                error={errors.description}
                            >
                                <textarea
                                    rows={4}
                                    maxLength={5000}
                                    value={data.description}
                                    onChange={(e) =>
                                        change("description", e.target.value)
                                    }
                                    placeholder="Goals, level, equipment and useful guidance for this training."
                                />
                            </Field>
                            <p className="field-hint">
                                Choose the trainee’s start date and schedule
                                when assigning.
                            </p>
                        </section>
                        <section className="panel coach-card builder-days">
                            <div className="coach-card-title">
                                <h2>Training Days</h2>
                                <span>
                                    {plan?.training_days?.length || 0}{" "}
                                    {plan?.training_days?.length === 1
                                        ? "day"
                                        : "days"}
                                </span>
                            </div>
                            {!plan?.training_days?.length && (
                                <p>
                                    Add your workouts using the exercise
                                    library, sets, reps and supersets.
                                </p>
                            )}
                            <ul className="builder-day-list">
                                {plan?.training_days?.map((day, index) => (
                                    <li key={day.id}>
                                        <span className="builder-day-number">
                                            {String(index + 1).padStart(2, "0")}
                                        </span>
                                        <TrainingThumbnail item={day} compact />
                                        <div>
                                            <strong>{day.title}</strong>
                                            <small>
                                                {day.exercises.length}{" "}
                                                {day.exercises.length === 1
                                                    ? "exercise"
                                                    : "exercises"}
                                                · {weekdaySummary(day.weekdays)}
                                                {plan.blocks?.length > 1
                                                    ? ` · ${plan.blocks.find((b) => b.id === day.training_block_id)?.name || ""}`
                                                    : ""}
                                            </small>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() => save("day", day)}
                                            aria-label={`Edit ${day.title}`}
                                        >
                                            <Pencil size={15} /> Edit
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => save("day")}
                            >
                                <Plus size={17} /> Add Training Day
                            </Button>
                            {plan?.structure_type === "program" && (
                                <p className="field-hint">
                                    {plan.blocks?.length || 1} training
                                    block(s).{" "}
                                    <a
                                        href={`/coach/training-plans/${plan.id}`}
                                    >
                                        View and manage blocks
                                    </a>
                                </p>
                            )}
                        </section>
                    </div>
                    <div className="form-actions builder-actions">
                        <Button type="submit" variant="secondary">
                            <Save size={17} /> Save Program
                        </Button>
                        <Button
                            type="button"
                            disabled={!plan?.training_days?.length}
                            onClick={() => save("assign")}
                        >
                            Save & Assign <ArrowRight size={17} />
                        </Button>
                    </div>
                </fieldset>
            </form>
        </div>
    );
}

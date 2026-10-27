import { useForm } from "@inertiajs/react";
import { Button, Field } from "../Components/UI";
import { calorieSources } from "./calories";

export default function WorkoutDetailsForm({ entry }) {
    const form = useForm({
        details_only: true,
        duration_minutes: entry.duration_minutes ?? "",
        intensity: entry.intensity ?? "moderate",
        calories_burned: entry.calories_burned ?? "",
        calories_source:
            entry.calories_source ||
            (entry.calories_burned != null ? "manual" : "wearable"),
    });
    const estimateInputsChanged =
        String(form.data.duration_minutes) !==
            String(entry.duration_minutes ?? "") ||
        form.data.intensity !== (entry.intensity ?? "moderate");
    return (
        <section className="panel profile-panel">
            <h2>Workout details</h2>
            <p className="progress-explanation">
                Optional. Record your session duration and calories now or
                later. Calories can come from a wearable, exercise machine, or
                your own estimate.
            </p>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(`/schedule-entries/${entry.id}`, {
                        preserveScroll: true,
                    });
                }}
            >
                {form.errors.details && (
                    <p className="field-error" role="alert">
                        {form.errors.details}
                    </p>
                )}
                <div className="form-grid">
                    <Field
                        label="Actual duration (optional)"
                        error={form.errors.duration_minutes}
                    >
                        <div className="input-with-unit">
                            <input
                                type="number"
                                min="1"
                                max="1440"
                                step="1"
                                inputMode="numeric"
                                value={form.data.duration_minutes}
                                onChange={(event) =>
                                    form.setData(
                                        "duration_minutes",
                                        event.target.value,
                                    )
                                }
                            />
                            <span>min</span>
                        </div>
                    </Field>
                    <Field
                        label="Workout intensity"
                        error={form.errors.intensity}
                    >
                        <select
                            value={form.data.intensity}
                            onChange={(event) =>
                                form.setData("intensity", event.target.value)
                            }
                        >
                            <option value="light">Light</option>
                            <option value="moderate">Moderate</option>
                            <option value="vigorous">Vigorous</option>
                        </select>
                    </Field>
                    <Field
                        label="Reported Calories (Optional)"
                        error={form.errors.calories_burned}
                    >
                        <div className="input-with-unit">
                            <input
                                type="number"
                                min="0"
                                max="20000"
                                step="1"
                                inputMode="numeric"
                                value={form.data.calories_burned}
                                onChange={(event) =>
                                    form.setData(
                                        "calories_burned",
                                        event.target.value,
                                    )
                                }
                            />
                            <span>kcal</span>
                        </div>
                    </Field>
                    <Field label="Source" error={form.errors.calories_source}>
                        <select
                            value={form.data.calories_source}
                            onChange={(event) =>
                                form.setData(
                                    "calories_source",
                                    event.target.value,
                                )
                            }
                        >
                            {Object.entries(calorieSources).map(
                                ([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </Field>
                </div>
                <div className="calorie-estimate" aria-live="polite">
                    <h3>Training Planner estimate</h3>
                    <strong>
                        {estimateInputsChanged
                            ? "Save details to update the estimate."
                            : entry.estimated_calories != null
                              ? `≈ ${entry.estimated_calories} kcal`
                              : "—"}
                    </strong>
                    <p className="field-hint">
                        Based on your body weight, workout duration, and
                        intensity. An estimate, not a measurement.
                    </p>
                    {!estimateInputsChanged &&
                        entry.estimated_calories == null && (
                            <p className="field-hint">
                                Add an actual duration and a body weight in
                                Profile or Progress, then save details.
                            </p>
                        )}
                </div>
                <div className="form-actions">
                    <Button disabled={form.processing}>
                        {form.processing ? "Saving…" : "Save details"}
                    </Button>
                </div>
            </form>
        </section>
    );
}

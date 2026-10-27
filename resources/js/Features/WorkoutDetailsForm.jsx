import { useForm } from "@inertiajs/react";
import { Button, Field } from "../Components/UI";

export default function WorkoutDetailsForm({ entry }) {
    const form = useForm({
        details_only: true,
        duration_minutes: entry.duration_minutes ?? "",
        calories_burned: entry.calories_burned ?? "",
    });
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
                        label="Calories burned (optional)"
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
                </div>
                <p className="field-hint">
                    Source: Manual / wearable. The app does not calculate
                    calories.
                </p>
                <div className="form-actions">
                    <Button disabled={form.processing}>
                        {form.processing ? "Saving…" : "Save details"}
                    </Button>
                </div>
            </form>
        </section>
    );
}

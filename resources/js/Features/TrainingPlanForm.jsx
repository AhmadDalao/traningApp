import PlanPeriodFields from "./PlanPeriodFields";
import { useTraining } from "./CoachingUI";
import { useForm } from "@inertiajs/react";
import { Button, Field } from "../Components/UI";
export default function TrainingPlanForm({ plan, onCancel, onSaved }) {
    const { url, templates } = useTraining();
    const form = useForm({
        structure_type: plan?.structure_type || "simple",
        duration_weeks: plan?.duration_weeks || 12,
        name: plan?.name || "",
        description: plan?.description || "",
        start_date: plan?.start_date || "",
        end_date: plan?.end_date || "",
        schedule_mode: plan?.schedule_mode || "fixed",
    });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                plan
                    ? form.put(url(`/training-plans/${plan.id}`), {
                          onSuccess: onSaved,
                          onSaved,
                      })
                    : form.post(url("/training-plans"), {
                          onSuccess: onSaved,
                          onSaved,
                      });
            }}
        >
            {!plan && (
                <Field label="Create">
                    <select
                        value={form.data.structure_type}
                        onChange={(e) =>
                            form.setData("structure_type", e.target.value)
                        }
                    >
                        <option value="simple">Simple plan</option>
                        <option value="program">Structured program</option>
                    </select>
                </Field>
            )}
            {form.data.structure_type === "program" && !plan && (
                <Field
                    label="Program duration (weeks)"
                    required
                    error={form.errors.duration_weeks}
                >
                    <input
                        type="number"
                        min="1"
                        max="52"
                        required
                        value={form.data.duration_weeks}
                        onChange={(e) =>
                            form.setData("duration_weeks", e.target.value)
                        }
                    />
                </Field>
            )}
            <Field label="Plan name" required error={form.errors.name}>
                <input
                    autoFocus
                    required
                    maxLength={150}
                    value={form.data.name}
                    onChange={(e) => form.setData("name", e.target.value)}
                    placeholder="e.g. Beginner Weekly Plan"
                />
            </Field>
            <Field label="Description" error={form.errors.description}>
                <textarea
                    rows={4}
                    maxLength={5000}
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData("description", e.target.value)
                    }
                    placeholder="What are you working towards?"
                />
            </Field>
            {!templates && form.data.structure_type === "simple" && (
                <PlanPeriodFields form={form} />
            )}
            <p className="field-hint">
                {form.data.structure_type === "program"
                    ? "Save your program definition, build its blocks, then preview a start date. No sessions are added until you confirm."
                    : templates
                      ? "Reusable template. Set the trainee’s dates when assigning a copy."
                      : "Optional. Set a period, choose weekdays in each workout, then preview the schedule before adding it to Calendar."}
            </p>
            <div className="form-actions">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
                <Button disabled={form.processing}>
                    {form.processing
                        ? "Saving…"
                        : plan
                          ? "Save changes"
                          : "Create plan"}
                </Button>
            </div>
        </form>
    );
}

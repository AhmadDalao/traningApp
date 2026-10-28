import PlanPeriodFields from "./PlanPeriodFields";
import { useTraining } from "./CoachingUI";
import { useForm } from "@inertiajs/react";
import { Button, Field } from "../Components/UI";
export default function TrainingPlanForm({ plan, onCancel, onSaved }) {
    const { url, templates } = useTraining();
    const form = useForm({
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
            {!templates && <PlanPeriodFields form={form} />}
            <p className="field-hint">
                {templates
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

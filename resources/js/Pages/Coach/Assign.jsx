import { Head, useForm } from "@inertiajs/react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    DateLabel,
} from "../../Components/UI";
import { useTraining, Errors } from "../../Features/CoachingUI";
export default function Assign({ templates, subscription }) {
    const ctx = useTraining();
    const form = useForm({
        training_plan_id: "",
        start_date: subscription?.starts_on || "",
        end_date: subscription?.ends_on || "",
        confirmed: true,
    });
    return (
        <>
            <Head title="Assign a training plan" />
            <PageHeader
                title="Assign plan to trainee"
                description="Create an independent copy. The trainee owns the assigned plan."
            />
            {!subscription ? (
                <section className="panel coach-card">
                    <p>Start an active subscription before assigning a plan.</p>
                    <ButtonLink href="/coach/subscriptions">
                        Manage subscriptions
                    </ButtonLink>
                </section>
            ) : (
                <form
                    className="panel coach-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(ctx.url("/assign"));
                    }}
                >
                    <p className="coach-callout">
                        {subscription.package_name} ·{" "}
                        <DateLabel date={subscription.starts_on} /> —{" "}
                        {subscription.ends_on ? (
                            <DateLabel date={subscription.ends_on} />
                        ) : (
                            "Open-ended"
                        )}
                    </p>
                    <Field label="Coach template" required>
                        <select
                            required
                            value={form.data.training_plan_id}
                            onChange={(e) =>
                                form.setData("training_plan_id", e.target.value)
                            }
                        >
                            <option value="">Choose a template</option>
                            {templates.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {!templates.length && (
                        <p>
                            Create a Coach Plan first, or build a custom plan
                            for this trainee.
                        </p>
                    )}
                    <div className="form-grid">
                        <Field label="Plan starts" required>
                            <input
                                type="date"
                                required
                                value={form.data.start_date}
                                onChange={(e) =>
                                    form.setData("start_date", e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Plan ends" required>
                            <input
                                type="date"
                                required
                                min={form.data.start_date}
                                value={form.data.end_date}
                                onChange={(e) =>
                                    form.setData("end_date", e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <p>
                        Days, exercises, icons and prescriptions are copied.
                        Workouts and results are not. After assigning, review
                        the schedule separately before adding Calendar sessions.
                    </p>
                    <Errors errors={form.errors} />
                    <div className="form-actions">
                        <ButtonLink
                            variant="secondary"
                            href={ctx.url("/training-plans")}
                        >
                            Cancel
                        </ButtonLink>
                        <Button disabled={form.processing || !templates.length}>
                            Confirm assignment
                        </Button>
                    </div>
                </form>
            )}
        </>
    );
}

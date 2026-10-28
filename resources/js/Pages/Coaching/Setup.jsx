import { Head, useForm } from "@inertiajs/react";
import { PageHeader, Field, Button, ButtonLink } from "../../Components/UI";
export default function Setup({ coachProfile }) {
    const form = useForm({
        display_name: coachProfile?.display_name || "",
        specialization: coachProfile?.specialization || "",
        bio: coachProfile?.bio || "",
    });
    return (
        <>
            <Head title="Coach setup" />
            <PageHeader
                title={
                    coachProfile ? "Coach profile" : "Enable Coach Workspace"
                }
                description="One account for your personal training and the people you coach."
            />
            <form
                className="panel coach-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/profile/coach");
                }}
            >
                <p className="coach-callout">
                    Your existing plans, workouts and progress stay exactly
                    where they are. Switch between workspaces anytime.
                </p>
                {[
                    ["display_name", "Coach display name", 100],
                    ["specialization", "Specialization (optional)", 150],
                    ["bio", "About your coaching (optional)", 2000],
                ].map(([key, label, max]) => (
                    <Field key={key} label={label} error={form.errors[key]}>
                        <input
                            required={key === "display_name"}
                            maxLength={max}
                            value={form.data[key]}
                            onChange={(e) => form.setData(key, e.target.value)}
                        />
                    </Field>
                ))}
                <div className="form-actions">
                    <ButtonLink variant="secondary" href="/profile">
                        Cancel
                    </ButtonLink>
                    <Button disabled={form.processing}>
                        {coachProfile
                            ? "Save coach profile"
                            : "Enable Coach Workspace"}
                    </Button>
                </div>
            </form>
        </>
    );
}

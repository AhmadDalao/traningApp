import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { PageHeader, Field, Button, Breadcrumbs } from "../../Components/UI";
import { coachingJson, useTraining } from "../../Features/CoachingUI";
import { ProgramWeeks } from "../../Features/ProgramUI";
export default function Start({ plan }) {
    const { url } = useTraining();
    const [date, setDate] = useState(""),
        [nonce] = useState(() => crypto.randomUUID()),
        [preview, setPreview] = useState(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    async function submit(start = false) {
        setBusy(true);
        setError("");
        try {
            const result = await coachingJson(
                url(
                    `/training-plans/${plan.id}/${start ? "program-start" : "program-preview"}`,
                ),
                {
                    start_date: date,
                    nonce,
                    ...(start
                        ? { signature: preview.signature, confirmed: true }
                        : {}),
                },
            );
            if (start) router.visit(result.url);
            else setPreview(result.preview);
        } catch (e) {
            setError(e.message);
            setPreview(null);
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="program-page">
            <Head title={`Start ${plan.name}`} />
            <Breadcrumbs
                items={[
                    {
                        label: plan.name,
                        href: url(`/training-plans/${plan.id}`),
                    },
                    { label: "Start program" },
                ]}
            />
            <PageHeader
                title={`Start ${plan.name}`}
                description="Week 1 begins on your start date and lasts seven days. Preview every workout before adding the schedule."
            />
            <form
                className="panel program-card"
                onSubmit={(e) => {
                    e.preventDefault();
                    submit();
                }}
            >
                <Field label="Start date" required>
                    <input
                        type="date"
                        required
                        value={date}
                        onChange={(e) => {
                            setDate(e.target.value);
                            setPreview(null);
                        }}
                    />
                </Field>
                <p>
                    This creates a new {plan.duration_weeks}-week run. Previous
                    runs stay intact.
                </p>
                <Button disabled={busy}>
                    {busy ? "Preparing…" : "Preview program schedule"}
                </Button>
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
            </form>
            {preview && (
                <>
                    <section className="panel program-card">
                        <h2>
                            {preview.count} sessions · {preview.start_date} to{" "}
                            {preview.ends_on}
                        </h2>
                        <p>
                            Review the weeks below. Saving a program definition
                            never adds these sessions automatically.
                        </p>
                        <Button disabled={busy} onClick={() => submit(true)}>
                            {busy ? "Starting…" : "Confirm & start program"}
                        </Button>
                    </section>
                    <ProgramWeeks sessions={preview.sessions} />
                </>
            )}
        </div>
    );
}

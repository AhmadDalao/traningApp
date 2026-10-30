import { Head, useForm, usePage, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    DateLabel,
} from "../../Components/UI";
import {
    useTraining,
    Errors,
    coachingJson,
    CoachStatus,
} from "../../Features/CoachingUI";
import { StartSubscription, PackageForm } from "../../Features/CoachingForms";
import PlanPeriodFields from "../../Features/PlanPeriodFields";
import { ProgramWeeks } from "../../Features/ProgramUI";
export default function SetupTrainee({
    state,
    plan,
    templates,
    packages,
    selectedTemplate,
    relationshipId,
}) {
    const ctx = useTraining(),
        { today } = usePage().props;
    const coachingReady = state.subscription?.effective_status === "active";
    const scheduled = state.has_schedule;
    const nextStep = !coachingReady ? 1 : !plan ? 2 : 3;
    const [step, setStep] = useState(nextStep),
        [creating, setCreating] = useState(false),
        [kind, setKind] = useState(
            templates.find((p) => p.id === selectedTemplate)?.structure_type ||
                "program",
        );
    const [date, setDate] = useState(today),
        [nonce] = useState(() => crypto.randomUUID()),
        [preview, setPreview] = useState(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    const form = useForm({
        training_plan_id: selectedTemplate || "",
        start_date: today,
        end_date: state.subscription?.ends_on || "",
        schedule_mode: state.subscription?.ends_on ? "fixed" : "ongoing",
        confirmed: true,
    });
    const finish = useForm({});
    useEffect(() => {
        setStep(nextStep);
        setPreview(null);
    }, [plan?.id, state.subscription?.id, nextStep]);
    useEffect(() => {
        form.setData((data) => ({
            ...data,
            end_date: state.subscription?.ends_on || "",
            schedule_mode: state.subscription?.ends_on ? "fixed" : "ongoing",
        }));
    }, [state.subscription?.id]);
    const selected = templates.find(
        (p) => p.id === Number(form.data.training_plan_id),
    );
    async function schedule(activate = false) {
        setBusy(true);
        setError("");
        try {
            const result = await coachingJson(
                ctx.url(`/setup/${activate ? "activate" : "preview"}`),
                {
                    training_plan_id: plan.id,
                    start_date: date,
                    nonce,
                    ...(activate
                        ? { signature: preview.signature, confirmed: true }
                        : {}),
                },
            );
            if (activate) router.visit(result.url);
            else setPreview(result.preview);
        } catch (e) {
            setError(e.message);
            setPreview(null);
        } finally {
            setBusy(false);
        }
    }
    const finishLater = () => finish.post(ctx.url("/setup/finish"));
    return (
        <div className="coach-setup">
            <Head title={`Set up ${ctx.trainee.name}`} />
            <PageHeader
                title="Set up coaching"
                description={`A clear plan for ${ctx.trainee.name}. Each completed step is saved.`}
            >
                <Button
                    variant="secondary"
                    disabled={busy || finish.processing}
                    onClick={finishLater}
                >
                    Save & finish later
                </Button>
            </PageHeader>
            <ol className="setup-steps" aria-label="Setup steps">
                {[
                    [1, "Coaching", coachingReady],
                    [2, "Training", !!plan],
                    [3, "Schedule", scheduled],
                ].map(([n, label, done]) => (
                    <li key={n}>
                        <button
                            type="button"
                            aria-current={step === n ? "step" : undefined}
                            disabled={n > nextStep}
                            onClick={() => setStep(n)}
                        >
                            <span>{done ? "✓" : n}</span>
                            <strong>{label}</strong>
                            <small>{done ? "Saved" : "Not configured"}</small>
                        </button>
                    </li>
                ))}
            </ol>
            {step === 1 && (
                <>
                    <h2>1. Coaching plan & dates</h2>
                    {state.subscription &&
                    ["active", "paused"].includes(state.subscription.status) ? (
                        <section className="panel coach-card">
                            <h3>{state.subscription.package_name}</h3>
                            <CoachStatus
                                status={state.subscription.effective_status}
                            />
                            <p>
                                <DateLabel
                                    date={state.subscription.starts_on}
                                />{" "}
                                —{" "}
                                {state.subscription.ends_on ? (
                                    <DateLabel
                                        date={state.subscription.ends_on}
                                    />
                                ) : (
                                    "Ongoing"
                                )}
                            </p>
                            {coachingReady ? (
                                <Button onClick={() => setStep(2)}>
                                    Next: Training
                                </Button>
                            ) : (
                                <>
                                    <p>
                                        Training management needs an active
                                        coaching period. Review these dates or
                                        resume coaching first.
                                    </p>
                                    <ButtonLink href={ctx.url("/coaching")}>
                                        Manage coaching
                                    </ButtonLink>
                                </>
                            )}
                        </section>
                    ) : (
                        <StartSubscription
                            inline
                            packages={packages}
                            relationship={{ id: relationshipId }}
                            endpoint={ctx.url("/setup/coaching")}
                            close={() => router.visit(ctx.url(""))}
                            onSaved={() => setStep(2)}
                        />
                    )}
                    <Button
                        variant="secondary"
                        onClick={() => setCreating(true)}
                    >
                        Create coaching plan
                    </Button>
                    {creating && (
                        <PackageForm close={() => setCreating(false)} />
                    )}
                </>
            )}
            {step === 2 && (
                <>
                    <h2>2. Choose training</h2>
                    {plan ? (
                        <section className="panel coach-card">
                            <h3>{plan.name}</h3>
                            <p>
                                This trainee-owned copy is already saved.
                                Reopening setup will keep it.
                            </p>
                            <Button onClick={() => setStep(3)}>
                                Next: Schedule
                            </Button>
                        </section>
                    ) : (
                        <form
                            className="panel coach-form"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(ctx.url("/setup/training"));
                            }}
                        >
                            <Field label="What would you like to assign?">
                                <select
                                    value={kind}
                                    onChange={(e) => {
                                        setKind(e.target.value);
                                        form.setData("training_plan_id", "");
                                    }}
                                >
                                    <option value="program">
                                        One of my programs
                                    </option>
                                    <option value="simple">
                                        One of my plans
                                    </option>
                                    <option value="create">
                                        Create training for this trainee
                                    </option>
                                    <option value="later">
                                        Set up training later
                                    </option>
                                </select>
                            </Field>
                            {["program", "simple"].includes(kind) && (
                                <>
                                    <Field label="Coach template" required>
                                        <select
                                            value={form.data.training_plan_id}
                                            required
                                            onChange={(e) =>
                                                form.setData(
                                                    "training_plan_id",
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Choose training
                                            </option>
                                            {templates
                                                .filter(
                                                    (p) =>
                                                        p.structure_type ===
                                                        kind,
                                                )
                                                .map((p) => (
                                                    <option
                                                        value={p.id}
                                                        key={p.id}
                                                    >
                                                        {p.name}
                                                        {p.duration_weeks
                                                            ? ` · ${p.duration_weeks} weeks`
                                                            : ""}
                                                    </option>
                                                ))}
                                        </select>
                                    </Field>
                                    {selected && (
                                        <div className="coach-callout">
                                            <strong>{selected.name}</strong>
                                            <p>{selected.description}</p>
                                            <a
                                                href={`/coach/training-plans/${selected.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Preview template (new tab) →
                                            </a>
                                        </div>
                                    )}
                                    {kind === "simple" && (
                                        <PlanPeriodFields
                                            form={form}
                                            required
                                        />
                                    )}
                                    {!templates.some(
                                        (p) => p.structure_type === kind,
                                    ) && (
                                        <p>
                                            No matching templates. Create
                                            training for this trainee, or save
                                            setup for later.
                                        </p>
                                    )}
                                    <Errors errors={form.errors} />
                                    <div className="form-actions">
                                        <Button
                                            variant="secondary"
                                            type="button"
                                            onClick={() => setStep(1)}
                                        >
                                            Back
                                        </Button>
                                        <Button
                                            disabled={
                                                form.processing || !selected
                                            }
                                        >
                                            Save training & continue
                                        </Button>
                                    </div>
                                </>
                            )}
                            {kind === "create" && (
                                <>
                                    <p>
                                        Your coaching period is saved. Build
                                        training, then return here to preview
                                        its schedule.
                                    </p>
                                    <ButtonLink
                                        href={ctx.url(
                                            "/training-plans/create?setup=1",
                                        )}
                                    >
                                        Create training
                                    </ButtonLink>
                                </>
                            )}
                            {kind === "later" && (
                                <Button
                                    type="button"
                                    disabled={finish.processing}
                                    onClick={finishLater}
                                >
                                    Save & finish later
                                </Button>
                            )}
                        </form>
                    )}
                </>
            )}
            {step === 3 && plan && (
                <>
                    <h2>3. Preview the schedule</h2>
                    {scheduled ? (
                        <section className="panel coach-card">
                            <h3>Setup is saved</h3>
                            <p>
                                {plan.name} already has a schedule. Changes
                                belong in the existing schedule review.
                            </p>
                            <ButtonLink href={ctx.url("")}>
                                View trainee
                            </ButtonLink>
                            <ButtonLink
                                variant="secondary"
                                href={ctx.url(
                                    state.plan.run_id
                                        ? `/program-runs/${state.plan.run_id}`
                                        : `/training-plans/${plan.id}/schedule-preview`,
                                )}
                            >
                                Manage future schedule
                            </ButtonLink>
                        </section>
                    ) : (
                        <>
                            <form
                                className="panel coach-form"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    schedule();
                                }}
                            >
                                <h3>{plan.name}</h3>
                                {plan.structure_type === "program" ? (
                                    <Field label="Start program on" required>
                                        <input
                                            type="date"
                                            min={today}
                                            required
                                            value={date}
                                            onChange={(e) => {
                                                setDate(e.target.value);
                                                setPreview(null);
                                            }}
                                        />
                                    </Field>
                                ) : (
                                    <p>
                                        The plan’s saved period, weekdays and
                                        times will be used.
                                    </p>
                                )}
                                <div className="coach-actions">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setStep(2)}
                                    >
                                        Back
                                    </Button>
                                    <Button disabled={busy}>
                                        {busy
                                            ? "Preparing…"
                                            : "Preview schedule"}
                                    </Button>
                                    <ButtonLink
                                        variant="secondary"
                                        href={ctx.url(
                                            `/training-plans/${plan.id}`,
                                        )}
                                    >
                                        Adjust training
                                    </ButtonLink>
                                </div>
                                {error && (
                                    <p className="field-error" role="alert">
                                        {error}
                                    </p>
                                )}
                            </form>
                            {preview && (
                                <>
                                    <section className="panel coach-card">
                                        <h3>
                                            {preview.sessions.length} scheduled
                                            sessions
                                        </h3>
                                        <p>
                                            {preview.message ||
                                                "Review the workouts below, then activate coaching."}
                                        </p>
                                        {preview.excluded?.length > 0 && (
                                            <p>
                                                {preview.excluded.length} dates
                                                outside the coaching period are
                                                excluded.
                                            </p>
                                        )}
                                        <Button
                                            disabled={
                                                busy || !preview.sessions.length
                                            }
                                            onClick={() => schedule(true)}
                                        >
                                            {busy
                                                ? "Activating…"
                                                : "Activate coaching"}
                                        </Button>
                                    </section>
                                    {plan.structure_type === "program" ? (
                                        <ProgramWeeks
                                            sessions={preview.sessions}
                                        />
                                    ) : (
                                        <section className="panel coach-card">
                                            <h3>Upcoming schedule</h3>
                                            <ul className="setup-schedule">
                                                {preview.sessions.map((s) => (
                                                    <li key={s.key}>
                                                        <DateLabel
                                                            date={
                                                                s.scheduled_date
                                                            }
                                                        />
                                                        <strong>
                                                            {s.title}
                                                        </strong>
                                                        <span>
                                                            {s.scheduled_time?.slice(
                                                                0,
                                                                5,
                                                            ) || "Time not set"}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                </>
                            )}
                        </>
                    )}
                </>
            )}
        </div>
    );
}

import TrainingThumbnail from "../../Components/TrainingThumbnail";
import {
    Check,
    Dumbbell,
    CalendarDays,
    ShieldCheck,
    ArrowRight,
} from "lucide-react";
import { Head, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    DateLabel,
    TimeLabel,
} from "../../Components/UI";
import { useTraining, coachingJson } from "../../Features/CoachingUI";
import WeekdayPicker from "../../Components/WeekdayPicker";

export default function SetupTrainee({
    state,
    draft: initial,
    templates,
    assignedPlans,
    packages,
}) {
    const ctx = useTraining(),
        { today, timezone } = usePage().props;
    const [draft, setDraft] = useState(initial),
        [choices, setChoices] = useState(initial.choices);
    const [reviewExpanded, setReviewExpanded] = useState(false);
    const [step, setStep] = useState(1),
        [preview, setPreview] = useState(null),
        [days, setDays] = useState([]);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(""),
        [scheduled, setScheduled] = useState(
            state.has_schedule && choices.selection_type === "assigned",
        );
    const [kind, setKind] = useState(
        choices.selection_type === "assigned"
            ? "assigned"
            : templates.find((p) => p.id === choices.training_plan_id)
                  ?.structure_type || "program",
    );
    const update = (key, value) => {
        setChoices((c) => ({ ...c, [key]: value }));
        setPreview(null);
    };
    const selected = (
        choices.selection_type === "assigned" ? assignedPlans : templates
    ).find((p) => p.id === Number(choices.training_plan_id));
    const categories = [
        ...(assignedPlans.length
            ? [
                  {
                      value: "assigned",
                      label: "Assigned training",
                      plans: assignedPlans,
                  },
              ]
            : []),
        {
            value: "program",
            label: "My Programs",
            plans: templates.filter((p) => p.structure_type === "program"),
        },
        {
            value: "simple",
            label: "My Plans",
            plans: templates.filter((p) => p.structure_type === "simple"),
        },
    ];
    const visiblePlans =
        categories.find((category) => category.value === kind)?.plans || [];
    function chooseCategory(value) {
        if (value === kind) return;
        setKind(value);
        setChoices((c) => ({
            ...c,
            selection_type: value === "assigned" ? "assigned" : "template",
            training_plan_id: null,
            day_options: {},
            time_overrides: {},
        }));
        setPreview(null);
    }
    async function save(next = choices) {
        const result = await coachingJson(
            ctx.url("/setup/draft"),
            { revision: draft.revision, choices: next, begin: draft.completed },
            "PATCH",
        );
        setDraft(result.draft);
        return result.draft;
    }
    async function review(target = 3, next = choices) {
        setBusy(true);
        setError("");
        try {
            const saved = await save(next);
            const result = await coachingJson(ctx.url("/setup/preview"), {
                revision: saved.revision,
                token: saved.token,
            });
            setPreview(result.preview);
            setDays(result.preview.days);
            setScheduled(result.preview.already_scheduled);
            setStep(target);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    async function finishLater() {
        setBusy(true);
        setError("");
        try {
            await save();
            router.visit(ctx.url(""));
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    async function activate() {
        setBusy(true);
        setError("");
        try {
            const result = await coachingJson(ctx.url("/setup/activate"), {
                revision: draft.revision,
                token: draft.token,
                signature: preview.signature,
                confirmed: true,
            });
            router.visit(result.url);
        } catch (e) {
            setError(e.message);
            setPreview(null);
        } finally {
            setBusy(false);
        }
    }
    const operation = preview?.subscription.operation;
    const label =
        operation === "resume"
            ? "Resume Coaching"
            : operation === "renew"
              ? "Renew Coaching"
              : operation === "reuse"
                ? "Complete Setup"
                : "Activate Coaching";
    if (initial.completed && state.subscription?.effective_status === "active")
        return (
            <>
                <Head title="Coaching setup complete" />
                <PageHeader
                    title="Coaching setup complete"
                    description={`Coaching for ${ctx.trainee.name} is active. The existing schedule is preserved.`}
                />
                <ButtonLink href={ctx.url("")}>View trainee</ButtonLink>
                <ButtonLink variant="secondary" href={ctx.url("/assign")}>
                    Assign different training
                </ButtonLink>
            </>
        );
    return (
        <div className="coach-setup setup-v231">
            <Head title={`Set up ${ctx.trainee.name}`} />
            <PageHeader
                title="Setup Coaching"
                description={`A training plan for ${ctx.trainee.name}. Review everything before activating.`}
            >
                <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={finishLater}
                >
                    Save & finish later
                </Button>
            </PageHeader>
            <fieldset className="setup-fields" disabled={busy} aria-busy={busy}>
                <ol className="setup-steps" aria-label="Setup steps">
                    {["Training", "Dates & Schedule", "Review & Activate"].map(
                        (name, i) => (
                            <li key={name}>
                                <button
                                    type="button"
                                    disabled={busy || i + 1 > step}
                                    aria-current={
                                        step === i + 1 ? "step" : undefined
                                    }
                                    onClick={() => setStep(i + 1)}
                                >
                                    <span>
                                        {step > i + 1 ? (
                                            <Check
                                                size={15}
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            i + 1
                                        )}
                                    </span>
                                    <strong>{name}</strong>
                                </button>
                            </li>
                        ),
                    )}
                </ol>
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
                {step === 1 && (
                    <section className="panel coach-card setup-training-panel">
                        <h2>1. Choose Training Program</h2>
                        <p>
                            Choose training first. Your selection stays a draft
                            until activation.
                        </p>
                        <div
                            className="setup-training-categories"
                            role="group"
                            aria-label="Choose training"
                        >
                            {categories.map((category) => (
                                <button
                                    type="button"
                                    key={category.value}
                                    aria-pressed={kind === category.value}
                                    onClick={() =>
                                        chooseCategory(category.value)
                                    }
                                >
                                    {category.label}
                                    <span>{category.plans.length}</span>
                                </button>
                            ))}
                        </div>
                        <div
                            className="setup-training-options"
                            role="radiogroup"
                            aria-label="Training programs"
                        >
                            {visiblePlans.map((p) => {
                                const checked =
                                    Number(choices.training_plan_id) === p.id;
                                return (
                                    <article
                                        className={`setup-training-option ${checked ? "selected" : ""}`}
                                        key={p.id}
                                    >
                                        <div className="setup-option-image">
                                            <TrainingThumbnail item={p} />
                                        </div>
                                        <label className="setup-training-select">
                                            <input
                                                type="radio"
                                                name="training"
                                                aria-label={p.name}
                                                checked={checked}
                                                onChange={() => {
                                                    setChoices((c) => ({
                                                        ...c,
                                                        training_plan_id: p.id,
                                                        day_options: {},
                                                        time_overrides: {},
                                                    }));
                                                    setPreview(null);
                                                }}
                                            />
                                            <span className="setup-training-content">
                                                <strong>{p.name}</strong>
                                                <span className="setup-training-meta">
                                                    {p.duration_weeks
                                                        ? `${p.duration_weeks} weeks · Structured Program`
                                                        : "Simple Plan"}
                                                    {p.training_days_count >
                                                        0 &&
                                                        ` · ${p.training_days_count} training ${p.training_days_count === 1 ? "day" : "days"}`}
                                                </span>
                                                {p.description && (
                                                    <span className="setup-training-description">
                                                        {p.description}
                                                    </span>
                                                )}
                                                {kind === "assigned" &&
                                                    p.created_at && (
                                                        <span className="setup-training-meta">
                                                            Assigned{" "}
                                                            {new Date(
                                                                p.created_at,
                                                            ).toLocaleString(
                                                                "en-GB",
                                                                {
                                                                    dateStyle:
                                                                        "medium",
                                                                    timeStyle:
                                                                        "short",
                                                                    timeZone:
                                                                        timezone,
                                                                },
                                                            )}
                                                        </span>
                                                    )}
                                                <span className="setup-training-status">
                                                    {checked
                                                        ? "Selected"
                                                        : "Select training"}
                                                </span>
                                            </span>
                                        </label>
                                        <div className="setup-training-footer">
                                            <a
                                                href={
                                                    kind === "assigned"
                                                        ? ctx.url(
                                                              `/training-plans/${p.id}`,
                                                          )
                                                        : `/coach/training-plans/${p.id}`
                                                }
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Preview training ↗
                                            </a>
                                            {kind === "assigned" &&
                                                state.plan?.id === p.id && (
                                                    <small>
                                                        Current assignment
                                                    </small>
                                                )}
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                        {!visiblePlans.length && (
                            <div className="setup-training-empty">
                                <strong>
                                    No{" "}
                                    {kind === "simple"
                                        ? "simple plans"
                                        : "programs"}{" "}
                                    yet
                                </strong>
                                <p>
                                    Create training below, or choose another
                                    category.
                                </p>
                            </div>
                        )}
                        <div className="form-actions">
                            <a
                                className="btn btn-secondary"
                                href="/coach/training-plans/create"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Create Training ↗
                            </a>
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={() =>
                                    router.reload({
                                        only: ["templates"],
                                        preserveState: true,
                                    })
                                }
                            >
                                Refresh training list
                            </Button>
                            <Button
                                disabled={busy || !selected}
                                onClick={() => review(2)}
                            >
                                Next: Dates & Schedule
                            </Button>
                        </div>
                    </section>
                )}
                {step === 2 && (
                    <section className="panel coach-card">
                        <h2>2. Dates & Schedule</h2>
                        <div className="setup-selected-training">
                            <TrainingThumbnail item={selected} />
                            <div>
                                <small>Training program</small>
                                <h3>{selected?.name}</h3>
                            </div>
                            <Button
                                variant="secondary"
                                onClick={() => setStep(1)}
                            >
                                Change training
                            </Button>
                        </div>
                        {scheduled ? (
                            <p className="coach-callout">
                                Schedule already active. Activation will
                                preserve its workouts and program week. Use the
                                trainee’s Calendar for later changes.
                            </p>
                        ) : (
                            <div className="setup-dates-layout">
                                <div>
                                    <div className="form-grid">
                                        <Field label="Training starts" required>
                                            <input
                                                type="date"
                                                min={today}
                                                value={choices.start_date || ""}
                                                onChange={(e) => {
                                                    setChoices((c) => ({
                                                        ...c,
                                                        start_date:
                                                            e.target.value,
                                                        time_overrides: {},
                                                    }));
                                                    setPreview(null);
                                                }}
                                            />
                                        </Field>
                                        {selected?.structure_type ===
                                            "simple" && (
                                            <>
                                                <Field label="Schedule mode">
                                                    <select
                                                        value={
                                                            choices.schedule_mode
                                                        }
                                                        onChange={(e) =>
                                                            update(
                                                                "schedule_mode",
                                                                e.target.value,
                                                            )
                                                        }
                                                    >
                                                        <option value="fixed">
                                                            Fixed
                                                        </option>
                                                        <option value="ongoing">
                                                            Ongoing
                                                        </option>
                                                    </select>
                                                </Field>
                                                {choices.schedule_mode ===
                                                    "fixed" && (
                                                    <Field
                                                        label="Training ends"
                                                        required
                                                    >
                                                        <input
                                                            type="date"
                                                            min={
                                                                choices.start_date ||
                                                                today
                                                            }
                                                            value={
                                                                choices.end_date ||
                                                                ""
                                                            }
                                                            onChange={(e) =>
                                                                update(
                                                                    "end_date",
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                )}
                                            </>
                                        )}
                                    </div>
                                    <p>
                                        Coaching will start today. Training may
                                        start later. Leave a workout time empty
                                        for Anytime.
                                    </p>
                                    {days.map((day) => {
                                        const option = choices.day_options[
                                            day.id
                                        ] || {
                                            weekdays: day.weekdays || [],
                                            time: day.time,
                                        };
                                        return (
                                            <div
                                                className="setup-day-option"
                                                key={day.id}
                                            >
                                                <h3>{day.title}</h3>
                                                <WeekdayPicker
                                                    value={option.weekdays}
                                                    onChange={(weekdays) => {
                                                        setChoices((c) => ({
                                                            ...c,
                                                            day_options: {
                                                                ...c.day_options,
                                                                [day.id]: {
                                                                    ...option,
                                                                    weekdays,
                                                                },
                                                            },
                                                            time_overrides: {},
                                                        }));
                                                        setPreview(null);
                                                    }}
                                                />
                                                <Field
                                                    label={`Time for ${day.title} (optional)`}
                                                >
                                                    <input
                                                        type="time"
                                                        value={
                                                            option.time || ""
                                                        }
                                                        onChange={(e) =>
                                                            update(
                                                                "day_options",
                                                                {
                                                                    ...choices.day_options,
                                                                    [day.id]: {
                                                                        ...option,
                                                                        time:
                                                                            e
                                                                                .target
                                                                                .value ||
                                                                            null,
                                                                    },
                                                                },
                                                            )
                                                        }
                                                    />
                                                </Field>
                                            </div>
                                        );
                                    })}
                                </div>
                                <aside
                                    className="setup-week-preview"
                                    aria-label="Weekly schedule preview"
                                >
                                    <h3>
                                        <CalendarDays
                                            size={18}
                                            aria-hidden="true"
                                        />{" "}
                                        Weekly schedule
                                    </h3>
                                    <p>
                                        Your weekly pattern. Review exact dates
                                        before activation.
                                    </p>
                                    <ul>
                                        {[1, 2, 3, 4, 5, 6, 0].map(
                                            (weekday) => {
                                                const workouts = days.filter(
                                                    (day) =>
                                                        (
                                                            choices.day_options[
                                                                day.id
                                                            ]?.weekdays ||
                                                            day.weekdays ||
                                                            []
                                                        ).includes(weekday),
                                                );
                                                return (
                                                    <li key={weekday}>
                                                        <span>
                                                            {
                                                                [
                                                                    "Sun",
                                                                    "Mon",
                                                                    "Tue",
                                                                    "Wed",
                                                                    "Thu",
                                                                    "Fri",
                                                                    "Sat",
                                                                ][weekday]
                                                            }
                                                        </span>
                                                        <div>
                                                            {workouts.length ? (
                                                                workouts.map(
                                                                    (day) => (
                                                                        <p
                                                                            key={
                                                                                day.id
                                                                            }
                                                                        >
                                                                            <strong>
                                                                                {
                                                                                    day.title
                                                                                }
                                                                            </strong>
                                                                            <small>
                                                                                {(
                                                                                    choices
                                                                                        .day_options[
                                                                                        day
                                                                                            .id
                                                                                    ] ||
                                                                                    day
                                                                                )
                                                                                    .time ||
                                                                                    "Anytime"}
                                                                            </small>
                                                                        </p>
                                                                    ),
                                                                )
                                                            ) : (
                                                                <small>
                                                                    No workout
                                                                </small>
                                                            )}
                                                        </div>
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ul>
                                    <p className="setup-preview-note">
                                        <ShieldCheck
                                            size={16}
                                            aria-hidden="true"
                                        />{" "}
                                        Coaching starts today. Your training
                                        dates stay separate.
                                    </p>
                                </aside>
                            </div>
                        )}
                        <div className="form-actions">
                            <Button
                                variant="secondary"
                                onClick={() => setStep(1)}
                            >
                                Back
                            </Button>
                            <Button disabled={busy} onClick={() => review()}>
                                Review subscription & calendar
                            </Button>
                        </div>
                    </section>
                )}
                {step === 3 && (
                    <div className="setup-review-grid">
                        <section className="panel coach-card setup-review-training">
                            <div className="coach-card-title">
                                <h2>
                                    <Dumbbell size={20} aria-hidden="true" />{" "}
                                    Training Program
                                </h2>
                                <Button
                                    variant="secondary"
                                    onClick={() => setStep(1)}
                                >
                                    Change training
                                </Button>
                            </div>
                            <h3>{preview?.training?.name || selected?.name}</h3>
                            {preview && (
                                <p>
                                    Training starts{" "}
                                    {preview.start_date && (
                                        <DateLabel date={preview.start_date} />
                                    )}
                                    {preview.ends_on && (
                                        <>
                                            {" "}
                                            · Through{" "}
                                            <DateLabel date={preview.ends_on} />
                                        </>
                                    )}
                                </p>
                            )}
                        </section>
                        <section className="panel coach-card setup-review-summary">
                            <h2>Coaching Subscription</h2>
                            <p>
                                {state.subscription?.effective_status ===
                                "active"
                                    ? "Existing coaching is active. Coverage will never be shortened."
                                    : state.subscription_action?.label ===
                                        "Resume Coaching"
                                      ? "Coaching resumes today"
                                      : "Coaching starts today"}{" "}
                                · <DateLabel date={today} />
                            </p>
                            {(!state.subscription ||
                                state.subscription_action?.label ===
                                    "Renew Coaching") && (
                                <>
                                    <Field label="Coaching Package">
                                        <select
                                            value={choices.package_id || ""}
                                            onChange={(e) =>
                                                update(
                                                    "package_id",
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : null,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Custom · match training coverage
                                            </option>
                                            {packages.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name} ·{" "}
                                                    {p.duration_days
                                                        ? `${p.duration_days} days`
                                                        : "Ongoing"}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    {!choices.package_id && (
                                        <>
                                            <Field label="Subscription name">
                                                <input
                                                    maxLength={100}
                                                    value={choices.package_name}
                                                    onChange={(e) =>
                                                        update(
                                                            "package_name",
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <label className="checkbox">
                                                <input
                                                    type="checkbox"
                                                    checked={choices.open_ended}
                                                    onChange={(e) =>
                                                        update(
                                                            "open_ended",
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                                Ongoing coaching
                                            </label>
                                            {!choices.open_ended && (
                                                <Field label="Coverage end (blank matches training)">
                                                    <input
                                                        type="date"
                                                        min={today}
                                                        value={
                                                            choices.coaching_ends_on ||
                                                            ""
                                                        }
                                                        onChange={(e) =>
                                                            update(
                                                                "coaching_ends_on",
                                                                e.target
                                                                    .value ||
                                                                    null,
                                                            )
                                                        }
                                                    />
                                                </Field>
                                            )}
                                        </>
                                    )}
                                </>
                            )}
                            {preview && (
                                <>
                                    <h3>{preview.subscription.package_name}</h3>
                                    <p>
                                        Starts{" "}
                                        <DateLabel
                                            date={
                                                preview.subscription.starts_on
                                            }
                                        />{" "}
                                        ·{" "}
                                        {preview.subscription.ends_on ? (
                                            <>
                                                Ends{" "}
                                                <DateLabel
                                                    date={
                                                        preview.subscription
                                                            .ends_on
                                                    }
                                                />
                                            </>
                                        ) : (
                                            "Ongoing"
                                        )}
                                    </p>
                                    {operation === "resume" && (
                                        <p className="coach-callout">
                                            Coaching Subscription will be
                                            resumed today.
                                        </p>
                                    )}
                                    {operation === "renew" && (
                                        <p className="coach-callout">
                                            A new Coaching Subscription starts
                                            today. The previous period is
                                            preserved.
                                        </p>
                                    )}
                                    {preview.subscription
                                        .extension_required && (
                                        <div className="coach-callout">
                                            <p>
                                                Current coverage:{" "}
                                                <DateLabel
                                                    date={
                                                        preview.subscription
                                                            .base_ends_on
                                                    }
                                                />
                                                <br />
                                                Required through:{" "}
                                                <DateLabel
                                                    date={
                                                        preview.subscription
                                                            .required_through
                                                    }
                                                />
                                                <br />
                                                Extension:{" "}
                                                {
                                                    preview.subscription
                                                        .extension_days
                                                }{" "}
                                                days
                                            </p>
                                            <label className="checkbox">
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        choices.include_extension
                                                    }
                                                    onChange={(e) => {
                                                        const next = {
                                                            ...choices,
                                                            include_extension:
                                                                e.target
                                                                    .checked,
                                                        };
                                                        setChoices(next);
                                                        review(3, next);
                                                    }}
                                                />
                                                Include this subscription
                                                extension
                                            </label>
                                            <small>
                                                The reusable Coaching Package
                                                stays unchanged.
                                            </small>
                                        </div>
                                    )}
                                </>
                            )}
                            {!preview && (
                                <p>
                                    Choices changed. Refresh the review before
                                    activating.
                                </p>
                            )}
                            <div className="setup-primary-actions">
                                <Button
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() => review()}
                                >
                                    {busy ? "Preparing…" : "Refresh review"}
                                </Button>
                                {preview && (
                                    <Button
                                        disabled={busy || !preview.valid}
                                        onClick={activate}
                                    >
                                        {busy ? "Saving…" : label}
                                    </Button>
                                )}
                            </div>
                        </section>
                        <section className="panel coach-card setup-review-schedule">
                            <div className="coach-card-title">
                                <h2>
                                    <CalendarDays
                                        size={20}
                                        aria-hidden="true"
                                    />{" "}
                                    Training Schedule
                                </h2>
                                <Button
                                    variant="secondary"
                                    onClick={() => setStep(2)}
                                >
                                    Change dates & schedule
                                </Button>
                            </div>
                            {preview && (
                                <>
                                    <h3>
                                        {preview.count} sessions ·{" "}
                                        {preview.already_scheduled
                                            ? "Schedule already active"
                                            : "Proposed schedule"}
                                    </h3>
                                    {preview.issues.length > 0 && (
                                        <div
                                            className="setup-issues"
                                            role="alert"
                                        >
                                            <h3>Review these items</h3>
                                            {preview.issues
                                                .filter(
                                                    (i) =>
                                                        i.code !==
                                                        "schedule_row",
                                                )
                                                .map((i, n) => (
                                                    <p key={n}>{i.message}</p>
                                                ))}
                                        </div>
                                    )}
                                    <ul className="setup-review-rows">
                                        {preview.sessions.map(
                                            (row, rowIndex) => {
                                                const key = `${row.training_day_id}:${row.scheduled_date}`;
                                                return (
                                                    <li
                                                        key={row.key}
                                                        hidden={
                                                            !reviewExpanded &&
                                                            rowIndex >= 8 &&
                                                            row.issues
                                                                .length === 0
                                                        }
                                                    >
                                                        <div>
                                                            <strong>
                                                                {row.title}
                                                            </strong>
                                                            {row.program_week && (
                                                                <small>
                                                                    Week{" "}
                                                                    {
                                                                        row.program_week
                                                                    }{" "}
                                                                    ·{" "}
                                                                    {
                                                                        row
                                                                            .program_context
                                                                            ?.block_name
                                                                    }
                                                                </small>
                                                            )}
                                                            <span>
                                                                <DateLabel
                                                                    date={
                                                                        row.scheduled_date
                                                                    }
                                                                />{" "}
                                                                ·{" "}
                                                                {row.scheduled_time ? (
                                                                    <TimeLabel
                                                                        time={
                                                                            row.scheduled_time
                                                                        }
                                                                    />
                                                                ) : row.scheduled_date ===
                                                                  today ? (
                                                                    "Anytime today"
                                                                ) : (
                                                                    "Anytime"
                                                                )}
                                                            </span>
                                                            {row.reused && (
                                                                <small>
                                                                    Existing ·
                                                                    preserved
                                                                </small>
                                                            )}
                                                        </div>
                                                        {row.issues.map(
                                                            (message) => (
                                                                <p
                                                                    className="field-error"
                                                                    key={
                                                                        message
                                                                    }
                                                                >
                                                                    {message}
                                                                </p>
                                                            ),
                                                        )}
                                                        {!row.reused &&
                                                            row.scheduled_date >=
                                                                today && (
                                                                <Field
                                                                    label={`Time for ${row.title} on ${row.scheduled_date}`}
                                                                >
                                                                    <div className="setup-time-correction">
                                                                        <input
                                                                            type="time"
                                                                            value={
                                                                                (Object.hasOwn(
                                                                                    choices.time_overrides,
                                                                                    key,
                                                                                )
                                                                                    ? choices
                                                                                          .time_overrides[
                                                                                          key
                                                                                      ]
                                                                                    : row.scheduled_time
                                                                                )?.slice(
                                                                                    0,
                                                                                    5,
                                                                                ) ||
                                                                                ""
                                                                            }
                                                                            onChange={(
                                                                                e,
                                                                            ) => {
                                                                                const next =
                                                                                    {
                                                                                        ...choices,
                                                                                        time_overrides:
                                                                                            {
                                                                                                ...choices.time_overrides,
                                                                                                [key]:
                                                                                                    e
                                                                                                        .target
                                                                                                        .value ||
                                                                                                    null,
                                                                                            },
                                                                                    };
                                                                                setChoices(
                                                                                    next,
                                                                                );
                                                                                review(
                                                                                    3,
                                                                                    next,
                                                                                );
                                                                            }}
                                                                        />
                                                                        <Button
                                                                            variant="secondary"
                                                                            disabled={
                                                                                busy
                                                                            }
                                                                            onClick={() => {
                                                                                const next =
                                                                                    {
                                                                                        ...choices,
                                                                                        time_overrides:
                                                                                            {
                                                                                                ...choices.time_overrides,
                                                                                                [key]: null,
                                                                                            },
                                                                                    };
                                                                                setChoices(
                                                                                    next,
                                                                                );
                                                                                review(
                                                                                    3,
                                                                                    next,
                                                                                );
                                                                            }}
                                                                        >
                                                                            {row.scheduled_date ===
                                                                            today
                                                                                ? "Anytime Today"
                                                                                : "Anytime"}
                                                                        </Button>
                                                                    </div>
                                                                </Field>
                                                            )}
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ul>
                                    {preview.sessions.length > 8 && (
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                setReviewExpanded(
                                                    !reviewExpanded,
                                                )
                                            }
                                        >
                                            {reviewExpanded
                                                ? "Show fewer sessions"
                                                : `View all ${preview.sessions.length} sessions`}
                                        </Button>
                                    )}
                                    {preview.valid && (
                                        <div className="setup-ready">
                                            <Check
                                                size={22}
                                                aria-hidden="true"
                                            />
                                            <div>
                                                <strong>
                                                    Ready to{" "}
                                                    {operation === "resume"
                                                        ? "resume"
                                                        : operation === "renew"
                                                          ? "renew"
                                                          : "activate"}
                                                </strong>
                                                <p>
                                                    {ctx.trainee.name} will have
                                                    access to the reviewed
                                                    training and schedule.
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={() => setStep(2)}
                            >
                                Adjust dates & schedule
                            </Button>
                            <div className="setup-primary-actions">
                                <Button
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() => review()}
                                >
                                    Refresh review
                                </Button>
                                {preview && (
                                    <Button
                                        disabled={busy || !preview.valid}
                                        onClick={activate}
                                    >
                                        {busy ? "Saving…" : label}
                                    </Button>
                                )}
                            </div>
                        </section>
                    </div>
                )}
            </fieldset>
        </div>
    );
}

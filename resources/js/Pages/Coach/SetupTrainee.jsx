import { useEffect, useRef, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import {
    Check,
    CalendarDays,
    ArrowRight,
    Plus,
    ShieldCheck,
} from "lucide-react";
import TrainingThumbnail from "../../Components/TrainingThumbnail";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    DateLabel,
    TimeLabel,
} from "../../Components/UI";
import { useTraining, coachingJson } from "../../Features/CoachingUI";
import { PackageForm } from "../../Features/CoachingForms";
import WeekdayPicker from "../../Components/WeekdayPicker";

export default function SetupTrainee({
    state,
    draft: initial,
    templates,
    assignedPlans,
    packages,
    existingAssignments = {},
    assigning = false,
    startAtSchedule = false,
}) {
    const ctx = useTraining(),
        { today } = usePage().props;
    const [draft, setDraft] = useState(initial);
    const [choices, setChoices] = useState(() => ({
        ...initial.choices,
        package_id:
            initial.choices.package_id ||
            (!initial.saved_at && packages.length === 1 && !state.subscription
                ? packages[0].id
                : null),
    }));
    const [step, setStep] = useState(startAtSchedule ? 2 : 1),
        [preview, setPreview] = useState(null),
        [dirty, setDirty] = useState(true);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(""),
        [acknowledged, setAcknowledged] = useState(false);
    const [expanded, setExpanded] = useState(false),
        [packageOpen, setPackageOpen] = useState(false);
    const [kind, setKind] = useState(
        initial.choices.selection_type === "assigned"
            ? "assigned"
            : templates.find((p) => p.id === initial.choices.training_plan_id)
                  ?.structure_type || "program",
    );
    const booted = useRef(false);
    const selected = (
        choices.selection_type === "assigned" ? assignedPlans : templates
    ).find((p) => p.id === Number(choices.training_plan_id));
    const days = preview?.days || [];
    const scheduled = preview?.already_scheduled || false;
    const categories = [
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
        ...(assignedPlans.length
            ? [
                  {
                      value: "assigned",
                      label: "Assigned training",
                      plans: assignedPlans,
                  },
              ]
            : []),
    ];
    function update(values) {
        setChoices((c) => ({ ...c, ...values }));
        setDirty(true);
        setAcknowledged(false);
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
        setAcknowledged(false);
        try {
            const saved = await save(next);
            const result = await coachingJson(ctx.url("/setup/preview"), {
                revision: saved.revision,
                token: saved.token,
            });
            setPreview(result.preview);
            setDirty(false);
            setStep(target);
        } catch (e) {
            setError(e.message);
            setDirty(true);
        } finally {
            setBusy(false);
        }
    }
    useEffect(() => {
        if (startAtSchedule && !booted.current) {
            booted.current = true;
            review(2);
        }
    }, []);
    async function finishLater(create = false) {
        setBusy(true);
        setError("");
        try {
            await save();
            router.visit(
                create
                    ? `/coach/training-plans/create?assign_to=${ctx.trainee.id}`
                    : ctx.url(""),
            );
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    async function activate() {
        if (
            !preview?.valid ||
            dirty ||
            busy ||
            (preview.subscription.operation !== "reuse" && !acknowledged)
        )
            return;
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
            setDirty(true);
        } finally {
            setBusy(false);
        }
    }
    const operation = preview?.subscription.operation;
    const finalEnabled =
        preview?.valid && !dirty && (operation === "reuse" || acknowledged);
    const coverage = (
        <CoachingCoverage
            state={state}
            preview={preview}
            choices={choices}
            packages={packages}
            today={today}
            dirty={dirty}
            acknowledged={acknowledged}
            setAcknowledged={setAcknowledged}
            update={update}
            createPackage={() => setPackageOpen(true)}
            refresh={(next) => {
                setChoices(next);
                review(step, next);
            }}
        />
    );
    if (
        initial.completed &&
        !assigning &&
        state.subscription?.effective_status === "active"
    )
        return (
            <>
                <Head title="Training ready" />
                <PageHeader
                    title="Training ready"
                    description={`${ctx.trainee.name}’s existing training and schedule are preserved.`}
                />
                <ButtonLink href={ctx.url("")}>View trainee</ButtonLink>{" "}
                <ButtonLink
                    variant="secondary"
                    href={ctx.url("/setup?assign=1")}
                >
                    Assign Training
                </ButtonLink>
            </>
        );
    return (
        <div className="coach-setup assignment-journey">
            <Head title={`Assign training to ${ctx.trainee.name}`} />
            <PageHeader
                title={`Assign Training to ${ctx.trainee.name}`}
                description="Choose training, set the schedule, then confirm everything together."
            >
                <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={() => finishLater()}
                >
                    Save & finish later
                </Button>
            </PageHeader>
            <fieldset className="setup-fields" disabled={busy} aria-busy={busy}>
                <ol className="setup-steps" aria-label="Assignment steps">
                    {["Training", "Schedule", "Review"].map((name, index) => (
                        <li key={name}>
                            <button
                                type="button"
                                disabled={index + 1 > step}
                                aria-current={
                                    step === index + 1 ? "step" : undefined
                                }
                                onClick={() => setStep(index + 1)}
                            >
                                <span>
                                    {step > index + 1 ? (
                                        <Check size={15} />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <strong>{name}</strong>
                            </button>
                        </li>
                    ))}
                </ol>
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
                {step === 1 && (
                    <section className="panel coach-card assignment-selection">
                        <div className="coach-card-title">
                            <div>
                                <h2>Choose a training program</h2>
                                <p>
                                    Select existing training or create something
                                    new for {ctx.trainee.name}.
                                </p>
                            </div>
                        </div>
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
                                    onClick={() => {
                                        setKind(category.value);
                                        update({
                                            selection_type:
                                                category.value === "assigned"
                                                    ? "assigned"
                                                    : "template",
                                            training_plan_id: null,
                                            day_options: {},
                                            time_overrides: {},
                                        });
                                    }}
                                >
                                    {category.label}
                                    <span>{category.plans.length}</span>
                                </button>
                            ))}
                        </div>
                        <div
                            className="assignment-program-options"
                            role="radiogroup"
                            aria-label="Training programs"
                        >
                            {categories
                                .find((c) => c.value === kind)
                                ?.plans.map((plan) => (
                                    <article
                                        key={plan.id}
                                        className={`assignment-program-option ${Number(choices.training_plan_id) === plan.id ? "selected" : ""}`}
                                    >
                                        <TrainingThumbnail item={plan} />
                                        <label>
                                            <input
                                                type="radio"
                                                disabled={
                                                    kind !== "assigned" &&
                                                    !!existingAssignments[
                                                        plan.id
                                                    ]
                                                }
                                                name="training"
                                                aria-label={plan.name}
                                                checked={
                                                    Number(
                                                        choices.training_plan_id,
                                                    ) === plan.id
                                                }
                                                onChange={() =>
                                                    update({
                                                        training_plan_id:
                                                            plan.id,
                                                        day_options: {},
                                                        time_overrides: {},
                                                    })
                                                }
                                            />
                                            <span>
                                                <strong>{plan.name}</strong>
                                                {kind !== "assigned" &&
                                                    existingAssignments[
                                                        plan.id
                                                    ] && (
                                                        <small className="assignment-success">
                                                            Already assigned
                                                        </small>
                                                    )}
                                                <small>
                                                    {plan.duration_weeks
                                                        ? `${plan.duration_weeks} weeks`
                                                        : "Simple plan"}{" "}
                                                    · {plan.training_days_count}{" "}
                                                    training days
                                                </small>
                                                {plan.description && (
                                                    <span className="assignment-description">
                                                        {plan.description}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                        <a
                                            href={
                                                kind !== "assigned" &&
                                                existingAssignments[plan.id]
                                                    ? ctx.url(
                                                          `/training-plans/${existingAssignments[plan.id]}`,
                                                      )
                                                    : kind === "assigned"
                                                      ? ctx.url(
                                                            `/training-plans/${plan.id}`,
                                                        )
                                                      : `/coach/training-plans/${plan.id}`
                                            }
                                            target="_blank"
                                            rel="noreferrer"
                                            className="btn btn-secondary"
                                        >
                                            {kind !== "assigned" &&
                                            existingAssignments[plan.id]
                                                ? "View training ↗"
                                                : "Preview ↗"}
                                        </a>
                                    </article>
                                ))}
                        </div>
                        {!categories.find((c) => c.value === kind)?.plans
                            .length && (
                            <p>
                                No training here yet. Create a program to get
                                started.
                            </p>
                        )}
                        <div className="form-actions">
                            <Button
                                variant="secondary"
                                onClick={() => finishLater(true)}
                            >
                                <Plus size={16} /> Create New Program
                            </Button>
                            <Button
                                disabled={!selected}
                                onClick={() => review(2)}
                            >
                                Continue <ArrowRight size={17} />
                            </Button>
                        </div>
                    </section>
                )}
                {step === 2 && (
                    <div className="assignment-schedule-grid">
                        <section className="panel coach-card">
                            <h2>Schedule Training</h2>
                            <TrainingSummary plan={selected} />
                            {scheduled ? (
                                <p className="coach-callout">
                                    Already scheduled. These workouts and the
                                    current program week will be kept. Use Edit
                                    Schedule from the trainee overview to change
                                    them.
                                </p>
                            ) : (
                                <>
                                    <div className="form-grid">
                                        <Field label="Start training" required>
                                            <input
                                                type="date"
                                                min={today}
                                                value={choices.start_date || ""}
                                                onChange={(e) =>
                                                    update({
                                                        start_date:
                                                            e.target.value,
                                                        time_overrides: {},
                                                    })
                                                }
                                            />
                                        </Field>
                                        {selected?.structure_type ===
                                            "program" && (
                                            <div className="field">
                                                <span className="field-label">
                                                    Estimated finish
                                                </span>
                                                <strong>
                                                    {choices.start_date ? (
                                                        <DateLabel
                                                            date={programEnd(
                                                                choices.start_date,
                                                                selected.duration_weeks,
                                                            )}
                                                        />
                                                    ) : (
                                                        "Choose a start date"
                                                    )}
                                                </strong>
                                            </div>
                                        )}
                                        {selected?.structure_type ===
                                            "simple" && (
                                            <Field label="Schedule mode">
                                                <select
                                                    value={
                                                        choices.schedule_mode
                                                    }
                                                    onChange={(e) =>
                                                        update({
                                                            schedule_mode:
                                                                e.target.value,
                                                            time_overrides: {},
                                                        })
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
                                        )}
                                        {selected?.structure_type ===
                                            "simple" &&
                                            choices.schedule_mode ===
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
                                                            update({
                                                                end_date:
                                                                    e.target
                                                                        .value,
                                                                time_overrides:
                                                                    {},
                                                            })
                                                        }
                                                    />
                                                </Field>
                                            )}
                                    </div>
                                    <h3>Weekly schedule</h3>
                                    <p>
                                        Choose the days each workout repeats.
                                        Time is optional.
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
                                                className="assignment-day"
                                                key={day.id}
                                            >
                                                <div className="thumbnail-identity">
                                                    <TrainingThumbnail
                                                        item={day}
                                                        compact
                                                    />
                                                    <h3>{day.title}</h3>
                                                </div>
                                                <WeekdayPicker
                                                    value={option.weekdays}
                                                    onChange={(weekdays) =>
                                                        update({
                                                            day_options: {
                                                                ...choices.day_options,
                                                                [day.id]: {
                                                                    ...option,
                                                                    weekdays,
                                                                },
                                                            },
                                                            time_overrides: {},
                                                        })
                                                    }
                                                />
                                                <div className="setup-time-correction">
                                                    <Field
                                                        label={`Time for ${day.title} (optional)`}
                                                    >
                                                        <input
                                                            type="time"
                                                            value={
                                                                option.time ||
                                                                ""
                                                            }
                                                            onChange={(e) =>
                                                                update({
                                                                    day_options:
                                                                        {
                                                                            ...choices.day_options,
                                                                            [day.id]:
                                                                                {
                                                                                    ...option,
                                                                                    time:
                                                                                        e
                                                                                            .target
                                                                                            .value ||
                                                                                        null,
                                                                                },
                                                                        },
                                                                    time_overrides:
                                                                        {},
                                                                })
                                                            }
                                                        />
                                                    </Field>
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            update({
                                                                day_options: {
                                                                    ...choices.day_options,
                                                                    [day.id]: {
                                                                        ...option,
                                                                        time: null,
                                                                    },
                                                                },
                                                                time_overrides:
                                                                    {},
                                                            })
                                                        }
                                                    >
                                                        Anytime
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {busy && !days.length && (
                                        <p role="status">
                                            Loading training days…
                                        </p>
                                    )}
                                </>
                            )}
                            <div className="form-actions">
                                <Button
                                    variant="secondary"
                                    onClick={() => setStep(1)}
                                >
                                    Change training
                                </Button>
                                <Button
                                    disabled={!selected || !choices.start_date}
                                    onClick={() => review(3)}
                                >
                                    Continue to Review <ArrowRight size={17} />
                                </Button>
                            </div>
                        </section>
                        <aside className="assignment-side">
                            {coverage}
                            <section className="panel coach-card">
                                <h2>
                                    <CalendarDays size={19} /> Weekly Schedule
                                </h2>
                                <ul className="assignment-week">
                                    {[1, 2, 3, 4, 5, 6, 0].map((weekday) => (
                                        <li key={weekday}>
                                            <strong>
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
                                            </strong>
                                            <div>
                                                {days
                                                    .filter((day) =>
                                                        (
                                                            choices.day_options[
                                                                day.id
                                                            ]?.weekdays ||
                                                            day.weekdays ||
                                                            []
                                                        )
                                                            .map(Number)
                                                            .includes(weekday),
                                                    )
                                                    .map((day) => (
                                                        <p key={day.id}>
                                                            <span>
                                                                {day.title}
                                                            </span>
                                                            <small>
                                                                {(
                                                                    choices
                                                                        .day_options[
                                                                        day.id
                                                                    ] || day
                                                                ).time ? (
                                                                    <TimeLabel
                                                                        time={
                                                                            (
                                                                                choices
                                                                                    .day_options[
                                                                                    day
                                                                                        .id
                                                                                ] ||
                                                                                day
                                                                            )
                                                                                .time
                                                                        }
                                                                    />
                                                                ) : (
                                                                    "Anytime"
                                                                )}
                                                            </small>
                                                        </p>
                                                    ))}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        </aside>
                    </div>
                )}
                {step === 3 && (
                    <div className="assignment-review-grid">
                        <section className="panel coach-card">
                            <h2>Review Assignment</h2>
                            <TrainingSummary plan={selected} />
                            {preview && (
                                <>
                                    <dl className="assignment-facts">
                                        <div>
                                            <dt>Training starts</dt>
                                            <dd>
                                                {preview.start_date ? (
                                                    <DateLabel
                                                        date={
                                                            preview.start_date
                                                        }
                                                    />
                                                ) : (
                                                    "Choose a start date"
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>
                                                {choices.schedule_mode ===
                                                    "ongoing" &&
                                                selected?.structure_type ===
                                                    "simple"
                                                    ? "Schedule preview through"
                                                    : "Training ends"}
                                            </dt>
                                            <dd>
                                                {preview.ends_on ? (
                                                    <DateLabel
                                                        date={preview.ends_on}
                                                    />
                                                ) : (
                                                    "Choose an end date"
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Schedule</dt>
                                            <dd>
                                                {preview.count} sessions
                                                {preview.already_scheduled
                                                    ? " · Already active"
                                                    : ""}
                                            </dd>
                                        </div>
                                    </dl>
                                    <div className="coach-card-title">
                                        <h3>Workouts</h3>
                                        <Button
                                            variant="secondary"
                                            onClick={() => setStep(2)}
                                        >
                                            Change schedule
                                        </Button>
                                    </div>
                                    {preview.issues.length > 0 && (
                                        <div
                                            className="setup-issues"
                                            role="alert"
                                        >
                                            <h3>Needs your attention</h3>
                                            {preview.issues
                                                .filter(
                                                    (issue) =>
                                                        issue.code !==
                                                        "schedule_row",
                                                )
                                                .map((issue, i) => (
                                                    <p key={i}>
                                                        {issue.message}
                                                    </p>
                                                ))}
                                        </div>
                                    )}
                                    <ul className="assignment-review-rows">
                                        {preview.sessions
                                            .filter(
                                                (row, i) =>
                                                    expanded ||
                                                    i < 8 ||
                                                    row.issues.length,
                                            )
                                            .map((row) => (
                                                <li key={row.key}>
                                                    <TrainingThumbnail
                                                        compact
                                                        item={
                                                            days.find(
                                                                (day) =>
                                                                    day.id ===
                                                                    row.training_day_id,
                                                            ) || row
                                                        }
                                                    />
                                                    <div>
                                                        <strong>
                                                            {row.title}
                                                        </strong>
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
                                                            ) : (
                                                                "Anytime"
                                                            )}
                                                        </span>
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
                                                        {row.reused && (
                                                            <small>
                                                                Already
                                                                scheduled · Kept
                                                            </small>
                                                        )}
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
                                                    </div>
                                                    {!row.reused &&
                                                        row.scheduled_date >=
                                                            today && (
                                                            <details className="assignment-correction">
                                                                <summary>
                                                                    Change time
                                                                </summary>
                                                                <Field
                                                                    label={`Time for ${row.title} on ${row.scheduled_date}`}
                                                                >
                                                                    <input
                                                                        type="time"
                                                                        defaultValue={
                                                                            row.scheduled_time?.slice(
                                                                                0,
                                                                                5,
                                                                            ) ||
                                                                            ""
                                                                        }
                                                                        onBlur={(
                                                                            e,
                                                                        ) => {
                                                                            if (
                                                                                e
                                                                                    .target
                                                                                    .value !==
                                                                                (row.scheduled_time?.slice(
                                                                                    0,
                                                                                    5,
                                                                                ) ||
                                                                                    "")
                                                                            ) {
                                                                                const next =
                                                                                    {
                                                                                        ...choices,
                                                                                        time_overrides:
                                                                                            {
                                                                                                ...choices.time_overrides,
                                                                                                [`${row.training_day_id}:${row.scheduled_date}`]:
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
                                                                            }
                                                                        }}
                                                                    />
                                                                </Field>
                                                                <Button
                                                                    variant="secondary"
                                                                    onClick={() => {
                                                                        const next =
                                                                            {
                                                                                ...choices,
                                                                                time_overrides:
                                                                                    {
                                                                                        ...choices.time_overrides,
                                                                                        [`${row.training_day_id}:${row.scheduled_date}`]:
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
                                                                >
                                                                    Anytime
                                                                    {row.scheduled_date ===
                                                                    today
                                                                        ? " Today"
                                                                        : ""}
                                                                </Button>
                                                            </details>
                                                        )}
                                                </li>
                                            ))}
                                    </ul>
                                    {preview.sessions.length > 8 && (
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                setExpanded(!expanded)
                                            }
                                        >
                                            {expanded
                                                ? "Show fewer workouts"
                                                : `View all ${preview.count} sessions`}
                                        </Button>
                                    )}
                                </>
                            )}
                        </section>
                        <aside className="assignment-side">
                            {coverage}
                            <section className="panel coach-card assignment-confirmation">
                                <h2>Ready to Assign?</h2>
                                <p>
                                    <strong>{ctx.trainee.name}</strong>
                                </p>
                                <p>{selected?.name}</p>
                                <p>
                                    {preview?.already_scheduled
                                        ? "Existing workouts and progress will be kept."
                                        : `${preview?.count || 0} sessions will be scheduled.`}
                                </p>
                                {finalEnabled && (
                                    <p className="assignment-success">
                                        <ShieldCheck size={19} /> Ready to train
                                    </p>
                                )}
                                {dirty && (
                                    <p role="status">
                                        Your choices changed. Refresh the review
                                        before confirming.
                                    </p>
                                )}
                                {(dirty || !preview) && (
                                    <Button
                                        variant="secondary"
                                        onClick={() => review(3)}
                                    >
                                        Refresh review
                                    </Button>
                                )}
                                <Button
                                    disabled={!finalEnabled}
                                    onClick={activate}
                                >
                                    {busy
                                        ? "Saving…"
                                        : preview?.already_scheduled
                                          ? "Confirm Training"
                                          : "Assign & Schedule"}
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => setStep(2)}
                                >
                                    Back to schedule
                                </Button>
                            </section>
                        </aside>
                    </div>
                )}
            </fieldset>
            {packageOpen && <PackageForm close={() => setPackageOpen(false)} />}
        </div>
    );
}
function TrainingSummary({ plan }) {
    return (
        <div className="assignment-training-summary">
            <TrainingThumbnail item={plan} />
            <div>
                <strong>{plan?.name || "Choose training"}</strong>
                <small>
                    {plan?.duration_weeks
                        ? `${plan.duration_weeks} weeks · `
                        : ""}
                    {plan?.training_days_count || 0} training days
                </small>
            </div>
        </div>
    );
}
function programEnd(start, weeks) {
    const date = new Date(`${start}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + weeks * 7 - 1);
    return date.toISOString().slice(0, 10);
}
function CoachingCoverage({
    state,
    preview,
    choices,
    packages,
    today,
    dirty,
    acknowledged,
    setAcknowledged,
    update,
    createPackage,
    refresh,
}) {
    const sub = preview?.subscription;
    const operation = sub?.operation;
    const healthy = operation === "reuse" && !sub.extension_required;
    const action =
        operation === "resume"
            ? "Resume coaching today"
            : operation === "renew"
              ? "Renew coaching today"
              : "Activate coaching today";
    return (
        <section
            className={`panel coach-card assignment-coverage ${healthy ? "coverage-healthy" : ""}`}
        >
            <h2>Coaching</h2>
            {healthy ? (
                <p className="assignment-success">
                    <ShieldCheck size={19} />
                    <span>
                        Coaching active
                        {sub.ends_on ? (
                            <>
                                {" "}
                                until <DateLabel date={sub.ends_on} />
                            </>
                        ) : (
                            " · Ongoing"
                        )}
                    </span>
                </p>
            ) : (
                <>
                    <p>
                        {operation === "resume"
                            ? "Coaching is paused."
                            : operation === "renew"
                              ? "Previous coaching has ended."
                              : operation === "reuse"
                                ? "Coaching ends before this training finishes."
                                : "Coaching starts today. Training may start later."}
                    </p>
                    {(!state.subscription ||
                        ["ended", "expired"].includes(
                            state.subscription.effective_status,
                        )) && (
                        <>
                            <Field label="Coaching Package">
                                <select
                                    value={choices.package_id || ""}
                                    onChange={(e) =>
                                        update({
                                            package_id: e.target.value
                                                ? Number(e.target.value)
                                                : null,
                                        })
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
                                <details>
                                    <summary>
                                        Customize coaching coverage
                                    </summary>
                                    <Field label="Coaching name">
                                        <input
                                            maxLength={100}
                                            value={choices.package_name}
                                            onChange={(e) =>
                                                update({
                                                    package_name:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <label className="checkbox">
                                        <input
                                            type="checkbox"
                                            checked={choices.open_ended}
                                            onChange={(e) =>
                                                update({
                                                    open_ended:
                                                        e.target.checked,
                                                })
                                            }
                                        />{" "}
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
                                                    update({
                                                        coaching_ends_on:
                                                            e.target.value ||
                                                            null,
                                                    })
                                                }
                                            />
                                        </Field>
                                    )}
                                </details>
                            )}
                            <Button variant="secondary" onClick={createPackage}>
                                <Plus size={15} /> Create Coaching Package
                            </Button>
                        </>
                    )}
                    {sub && (
                        <dl className="assignment-facts">
                            <div>
                                <dt>Coaching starts</dt>
                                <dd>
                                    <DateLabel date={sub.starts_on} />
                                </dd>
                            </div>
                            <div>
                                <dt>Current coverage</dt>
                                <dd>
                                    {sub.base_ends_on ? (
                                        <DateLabel date={sub.base_ends_on} />
                                    ) : (
                                        "Ongoing"
                                    )}
                                </dd>
                            </div>
                            {sub.extension_required && (
                                <>
                                    <div>
                                        <dt>Required through</dt>
                                        <dd>
                                            <DateLabel
                                                date={sub.required_through}
                                            />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Extension</dt>
                                        <dd>+{sub.extension_days} days</dd>
                                    </div>
                                </>
                            )}
                        </dl>
                    )}
                    {sub?.extension_required && (
                        <label className="checkbox">
                            <input
                                type="checkbox"
                                checked={choices.include_extension}
                                onChange={(e) =>
                                    refresh({
                                        ...choices,
                                        include_extension: e.target.checked,
                                    })
                                }
                            />
                            <span>
                                Extend coaching through{" "}
                                <DateLabel date={sub.required_through} />
                            </span>
                        </label>
                    )}
                    {sub && operation !== "reuse" && (
                        <label className="checkbox coaching-confirm">
                            <input
                                type="checkbox"
                                checked={acknowledged}
                                disabled={dirty}
                                onChange={(e) =>
                                    setAcknowledged(e.target.checked)
                                }
                            />
                            <span>
                                {action}
                                {sub.ends_on ? (
                                    <>
                                        {" "}
                                        · through{" "}
                                        <DateLabel date={sub.ends_on} />
                                    </>
                                ) : (
                                    " · Ongoing"
                                )}
                            </span>
                        </label>
                    )}
                    {sub?.extension_included && (
                        <p className="assignment-success">
                            Coverage will extend to{" "}
                            <DateLabel date={sub.ends_on} />.
                        </p>
                    )}
                </>
            )}
            {dirty && sub && (
                <p className="field-hint">
                    Coverage will be checked again when you review.
                </p>
            )}
        </section>
    );
}

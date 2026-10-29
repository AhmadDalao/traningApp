import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head, useForm } from "@inertiajs/react";
import {
    Plus,
    ArrowUp,
    ArrowDown,
    Pencil,
    Trash2,
    Coffee,
    Save,
} from "lucide-react";
import {
    PageHeader,
    Breadcrumbs,
    Field,
    Button,
    ButtonLink,
    EmptyState,
    formatWeight,
} from "../../Components/UI";
import ExerciseEditor, { Thumbnail } from "../../Features/ExerciseEditor";
import IconPicker from "../../Components/IconPicker";
import WeekdayPicker from "../../Components/WeekdayPicker";
import {
    clearSingleExerciseGroups,
    saveExerciseWithPartner,
    supersetPartners,
} from "../../Features/supersets";

const focusAreas = [
    "Chest",
    "Back",
    "Shoulders",
    "Arms",
    "Legs",
    "Core",
    "Cardio",
    "Full Body",
    "Mobility",
    "Other",
];
export default function Form({
    plan,
    day,
    selectedBlock,
    customExercises = [],
}) {
    const { url } = useTraining();
    const [exercises, setExercises] = useState(
        (day?.exercises || []).map((e) => ({
            ...e,
            thumbnail: null,
            remove_thumbnail: false,
            custom_icon: null,
            remove_custom_icon: false,
            localKey: e.id,
        })),
    );
    const [editor, setEditor] = useState(null);
    const form = useForm({
        training_block_id: day?.training_block_id || selectedBlock || "",
        icon: day?.icon || "",
        title: day?.title || "",
        type: day?.type || "training",
        focus_area: day?.focus_area || "",
        notes: day?.notes || "",
        weekdays: day?.weekdays || [],
        default_time: day?.default_time?.slice(0, 5) || "",
    });
    const updateExercise = (draft, partnerKey, libraryPartner) => {
        setExercises((items) =>
            saveExerciseWithPartner(items, draft, partnerKey, libraryPartner),
        );
        setEditor(null);
    };
    function move(index, offset) {
        setExercises((items) => {
            const copy = [...items];
            [copy[index], copy[index + offset]] = [
                copy[index + offset],
                copy[index],
            ];
            return copy;
        });
    }
    function submit(e) {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            exercises:
                data.type === "training"
                    ? exercises.map(
                          ({
                              id,
                              icon,
                              name,
                              sets,
                              reps,
                              tracking_mode,
                              target_weight,
                              superset_group,
                              notes,
                              thumbnail,
                              remove_thumbnail,
                              library_key,
                              save_to_library,
                              custom_icon,
                              remove_custom_icon,
                          }) => ({
                              id,
                              icon,
                              name,
                              sets,
                              reps,
                              tracking_mode,
                              target_weight,
                              superset_group,
                              notes,
                              thumbnail,
                              remove_thumbnail,
                              library_key,
                              save_to_library,
                              custom_icon,
                              remove_custom_icon,
                          }),
                      )
                    : [],
            ...(day ? { _method: "put" } : {}),
        }));
        form.post(
            day
                ? url(`/training-days/${day.id}`)
                : url(`/training-plans/${plan.id}/days`),
            { forceFormData: true },
        );
    }
    const exerciseErrors = Object.entries(form.errors).filter(([key]) =>
        key.startsWith("exercises"),
    );
    return (
        <>
            <Head title={day ? `Edit ${day.title}` : "Create training day"} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: url("/training-plans") },
                    {
                        label: plan.name,
                        href: url(`/training-plans/${plan.id}`),
                    },
                    { label: day ? "Edit day" : "Create day" },
                ]}
            />
            <PageHeader
                title={day ? `Edit ${day.title}` : "Create a training day"}
                description="Build a workout you can use again and again."
            />
            <form onSubmit={submit}>
                <section className="panel builder-settings">
                    <div className="section-label">
                        01 <span>THE BASICS</span>
                    </div>
                    {plan.structure_type === "program" && (
                        <Field
                            label="Program block"
                            required
                            error={form.errors.training_block_id}
                        >
                            <select
                                required
                                disabled={!!day}
                                value={form.data.training_block_id}
                                onChange={(e) =>
                                    form.setData(
                                        "training_block_id",
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">Choose a block</option>
                                {plan.blocks.map((block) => (
                                    <option key={block.id} value={block.id}>
                                        {block.name} · Weeks {block.start_week}–
                                        {block.end_week}
                                    </option>
                                ))}
                            </select>
                            <p className="field-hint">
                                Runs keep their saved prescriptions until you
                                review and apply future changes.
                            </p>
                        </Field>
                    )}
                    <div className="form-grid three">
                        <Field label="Title" required error={form.errors.title}>
                            <input
                                autoFocus
                                required
                                maxLength={150}
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData("title", e.target.value)
                                }
                                placeholder="e.g. Leg Day"
                            />
                        </Field>
                        <Field label="Type" required error={form.errors.type}>
                            <select
                                value={form.data.type}
                                onChange={(e) =>
                                    form.setData("type", e.target.value)
                                }
                            >
                                <option value="training">Training day</option>
                                <option value="rest">Rest day</option>
                            </select>
                        </Field>
                        <Field
                            label="Focus area"
                            error={form.errors.focus_area}
                        >
                            <select
                                disabled={form.data.type === "rest"}
                                value={form.data.focus_area}
                                onChange={(e) =>
                                    form.setData("focus_area", e.target.value)
                                }
                            >
                                <option value="">Select a focus</option>
                                {focusAreas.map((f) => (
                                    <option key={f}>{f}</option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    <IconPicker
                        label="Training day icon"
                        value={form.data.icon}
                        type={form.data.type}
                        focus={form.data.focus_area}
                        onChange={(value) => form.setData("icon", value)}
                        error={form.errors.icon}
                    />
                    <Field label="Notes" error={form.errors.notes}>
                        <textarea
                            rows={2}
                            maxLength={5000}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData("notes", e.target.value)
                            }
                            placeholder="Focus, goals, and reminders for this day"
                        />
                    </Field>
                    <div className="workout-schedule-settings">
                        <WeekdayPicker
                            value={form.data.weekdays}
                            onChange={(value) =>
                                form.setData("weekdays", value)
                            }
                            error={
                                form.errors.weekdays ||
                                Object.entries(form.errors).find(([key]) =>
                                    key.startsWith("weekdays."),
                                )?.[1]
                            }
                        />
                        <Field
                            label="Usual time (optional)"
                            error={form.errors.default_time}
                        >
                            <input
                                type="time"
                                value={form.data.default_time}
                                onInput={(event) =>
                                    form.setData(
                                        "default_time",
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        <p className="field-hint">
                            {plan.start_date && plan.end_date
                                ? `Plan period: ${plan.start_date} to ${plan.end_date}.`
                                : "Set plan start/end dates from Edit plan to use weekday scheduling."}
                        </p>
                    </div>
                </section>
                {form.data.type === "training" ? (
                    <section className="panel builder-exercises">
                        <div className="panel-heading">
                            <div>
                                <div className="section-label">
                                    02 <span>YOUR EXERCISES</span>
                                </div>
                                <h2>
                                    The workout{" "}
                                    <span className="count-bubble">
                                        {exercises.length}
                                    </span>
                                </h2>
                            </div>
                            <Button type="button" onClick={() => setEditor({})}>
                                <Plus size={16} />
                                Add exercise
                            </Button>
                        </div>
                        {exerciseErrors.length > 0 && (
                            <div className="error-summary" role="alert">
                                <strong>Check your exercises:</strong>
                                {exerciseErrors.map(([key, error]) => (
                                    <p key={key}>
                                        {key.match(/\d+/)
                                            ? `Exercise ${Number(key.match(/\d+/)[0]) + 1}: `
                                            : ""}
                                        {error}
                                    </p>
                                ))}
                            </div>
                        )}
                        {exercises.length ? (
                            <div className="table-scroll">
                                <table className="exercise-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Exercise</th>
                                            <th>Sets</th>
                                            <th>Reps / duration</th>
                                            <th>Planned Weight</th>
                                            <th>Superset</th>
                                            <th className="align-right">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {exercises.map((exercise, i) => (
                                            <tr key={exercise.localKey}>
                                                <td className="row-number">
                                                    {i + 1}
                                                </td>
                                                <td>
                                                    <div className="exercise-name-cell">
                                                        <Thumbnail
                                                            exercise={exercise}
                                                        />
                                                        <div>
                                                            <strong>
                                                                {exercise.name}
                                                            </strong>
                                                            {exercise.notes && (
                                                                <p>
                                                                    {
                                                                        exercise.notes
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>{exercise.sets}</td>
                                                <td>{exercise.reps}</td>
                                                <td className="weight-value">
                                                    {formatWeight(
                                                        exercise.target_weight,
                                                    )}
                                                </td>
                                                <td>
                                                    {supersetPartners(
                                                        exercise,
                                                        exercises,
                                                    ).length ? (
                                                        <span className="superset-badge superset-partners">
                                                            With{" "}
                                                            {supersetPartners(
                                                                exercise,
                                                                exercises,
                                                            )
                                                                .map(
                                                                    (item) =>
                                                                        item.name,
                                                                )
                                                                .join(" + ")}
                                                        </span>
                                                    ) : (
                                                        <span className="muted">
                                                            On its own
                                                        </span>
                                                    )}
                                                </td>
                                                <td>
                                                    <div className="row-actions">
                                                        <button
                                                            type="button"
                                                            className="icon-btn"
                                                            disabled={!i}
                                                            aria-label={`Move ${exercise.name} up`}
                                                            onClick={() =>
                                                                move(i, -1)
                                                            }
                                                        >
                                                            <ArrowUp
                                                                size={15}
                                                            />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="icon-btn"
                                                            disabled={
                                                                i ===
                                                                exercises.length -
                                                                    1
                                                            }
                                                            aria-label={`Move ${exercise.name} down`}
                                                            onClick={() =>
                                                                move(i, 1)
                                                            }
                                                        >
                                                            <ArrowDown
                                                                size={15}
                                                            />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="icon-btn"
                                                            aria-label={`Edit ${exercise.name}`}
                                                            onClick={() =>
                                                                setEditor({
                                                                    index: i,
                                                                    exercise,
                                                                })
                                                            }
                                                        >
                                                            <Pencil size={15} />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="icon-btn danger-text"
                                                            aria-label={`Remove ${exercise.name}`}
                                                            onClick={() =>
                                                                setExercises(
                                                                    (items) =>
                                                                        clearSingleExerciseGroups(
                                                                            items.filter(
                                                                                (
                                                                                    _,
                                                                                    j,
                                                                                ) =>
                                                                                    i !==
                                                                                    j,
                                                                            ),
                                                                        ),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 size={15} />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <EmptyState
                                compact
                                title="Start with your first movement"
                                description="Choose exercises from the library or create your own, then add sets, reps, and optional supersets."
                            >
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setEditor({})}
                                >
                                    <Plus size={16} />
                                    Add exercise
                                </Button>
                            </EmptyState>
                        )}
                        <div className="builder-note">
                            To create a superset, choose a partner from this
                            workout or directly from the library. Changes are
                            saved with your training day.
                        </div>
                    </section>
                ) : (
                    <section className="rest-callout">
                        <Coffee size={30} />
                        <div>
                            <h2>Rest is part of the work.</h2>
                            <p>
                                No exercises needed. Save this day and schedule
                                some recovery.
                                {day?.exercises?.length > 0 &&
                                    " Saving as a rest day will remove its existing exercises and thumbnails."}
                            </p>
                        </div>
                    </section>
                )}
                <div className="form-actions page-actions">
                    <ButtonLink
                        variant="secondary"
                        href={
                            day
                                ? url(`/training-days/${day.id}`)
                                : url(`/training-plans/${plan.id}`)
                        }
                    >
                        Cancel
                    </ButtonLink>
                    <Button disabled={form.processing}>
                        <Save size={16} />
                        {form.processing
                            ? `Saving${form.progress ? ` ${form.progress.percentage}%` : "…"}`
                            : "Save training day"}
                    </Button>
                </div>
            </form>
            {editor && (
                <ExerciseEditor
                    customExercises={customExercises}
                    exercise={editor.exercise}
                    exercises={exercises}
                    onSave={updateExercise}
                    onClose={() => setEditor(null)}
                />
            )}
        </>
    );
}

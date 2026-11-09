import TrainingThumbnail from "../Components/TrainingThumbnail";
import { useTraining } from "./CoachingUI";
import { useState } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { Button, Field } from "../Components/UI";
export default function ScheduleEntryForm({
    plans,
    entry,
    selectedDay,
    selectedDate,
    onDateChange,
    onCancel,
    onSaved,
}) {
    const { url, coaching } = useTraining();
    const { today } = usePage().props;
    const singlePlan = !entry && plans.length === 1 ? plans[0] : null;
    const initialDay =
        plans
            .flatMap((p) => p.training_days)
            .find(
                (d) => d.id === Number(entry?.training_day_id || selectedDay),
            ) ||
        (singlePlan?.training_days.length === 1
            ? singlePlan.training_days[0]
            : null);
    const [planId, setPlanId] = useState(
        initialDay?.training_plan_id || singlePlan?.id || "",
    );
    const [blockId, setBlockId] = useState(
        initialDay?.training_block_id || singlePlan?.default_block_id || "",
    );
    const [directRest, setDirectRest] = useState(
        entry?.entry_type === "rest" && !entry?.training_day_id,
    );
    const form = useForm({
        training_day_id: initialDay?.id || "",
        title: entry?.title || initialDay?.title || "",
        entry_type: entry?.entry_type || initialDay?.type || "training",
        scheduled_date: entry?.scheduled_date || selectedDate || today,
        scheduled_time: entry?.scheduled_time?.slice(0, 5) || "",
        notes: entry?.notes || "",
    });
    const selectedPlan = plans.find((p) => p.id === Number(planId));
    const blocks = coaching ? selectedPlan?.workout_blocks || [] : [];
    const days = (selectedPlan?.training_days || []).filter(
        (day) => !blocks.length || day.training_block_id === Number(blockId),
    );
    const historical =
        entry && !entry.training_day_id && entry.entry_type === "training";
    const recorded = Boolean(entry?.workout_snapshot);
    function selectDay(id) {
        const day = days.find((d) => d.id === Number(id));
        form.setData({
            ...form.data,
            training_day_id: id,
            title: day?.title || "",
            entry_type: day?.type || "training",
        });
    }
    function changeMode(rest) {
        setDirectRest(rest);
        setPlanId("");
        form.setData({
            ...form.data,
            training_day_id: "",
            entry_type: rest ? "rest" : "training",
            title: rest ? "Rest Day" : "",
        });
    }
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                entry
                    ? form.put(url(`/schedule-entries/${entry.id}`), {
                          onSuccess: onSaved,
                          onSaved,
                      })
                    : form.post(url("/schedule-entries"), {
                          onSuccess: onSaved,
                          onSaved,
                      });
            }}
        >
            {form.data.training_day_id && !directRest && (
                <div className="thumbnail-identity schedule-selected-image">
                    <TrainingThumbnail
                        item={
                            recorded
                                ? entry
                                : days.find(
                                      (day) =>
                                          day.id ===
                                          Number(form.data.training_day_id),
                                  )
                        }
                    />
                    <strong>{form.data.title}</strong>
                </div>
            )}
            {!coaching && (
                <div className="segmented schedule-mode">
                    <button
                        type="button"
                        className={!directRest ? "active" : ""}
                        disabled={
                            recorded || (coaching && !!entry?.program_run_id)
                        }
                        onClick={() => changeMode(false)}
                    >
                        From a training plan
                    </button>
                    <button
                        type="button"
                        className={directRest ? "active" : ""}
                        disabled={
                            recorded || (coaching && !!entry?.program_run_id)
                        }
                        onClick={() => changeMode(true)}
                    >
                        Rest day
                    </button>
                </div>
            )}
            {!directRest && (
                <>
                    <div className="form-grid">
                        <Field label={coaching ? "Training" : "Training plan"}>
                            <select
                                aria-label={
                                    coaching ? "Training" : "Training plan"
                                }
                                value={planId}
                                disabled={
                                    recorded ||
                                    (coaching && !!entry?.program_run_id)
                                }
                                onChange={(e) => {
                                    setPlanId(e.target.value);
                                    const nextPlan = plans.find(
                                        (p) => p.id === Number(e.target.value),
                                    );
                                    setBlockId(
                                        nextPlan?.default_block_id || "",
                                    );
                                    const nextDays =
                                        plans.find(
                                            (p) =>
                                                p.id === Number(e.target.value),
                                        )?.training_days || [];
                                    const eligible =
                                        coaching && nextPlan?.default_block_id
                                            ? nextDays.filter(
                                                  (d) =>
                                                      d.training_block_id ===
                                                      nextPlan.default_block_id,
                                              )
                                            : nextDays;
                                    const single =
                                        eligible.length === 1
                                            ? eligible[0]
                                            : null;
                                    form.setData({
                                        ...form.data,
                                        training_day_id: single?.id || "",
                                        title: single?.title || "",
                                        entry_type: single?.type || "training",
                                    });
                                }}
                                required={!historical && !recorded}
                            >
                                <option value="">Select a plan</option>
                                {plans.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                        {!coaching &&
                                            (p.workspace_type ===
                                            "coach_assigned"
                                                ? ` · Coach assigned${p.coach_name ? ` — ${p.coach_name}` : ""}`
                                                : " · Personal")}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        {blocks.length > 1 && (
                            <Field label="Program block">
                                <select
                                    aria-label="Program block"
                                    value={blockId}
                                    disabled={
                                        recorded || !!entry?.program_run_id
                                    }
                                    onChange={(e) => {
                                        setBlockId(e.target.value);
                                        form.setData({
                                            ...form.data,
                                            training_day_id: "",
                                            title: "",
                                        });
                                    }}
                                >
                                    {blocks.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.name} · Weeks {b.start_week}–
                                            {b.end_week}
                                        </option>
                                    ))}
                                </select>
                                <small className="field-hint">
                                    Each block has its own workout
                                    prescriptions.
                                </small>
                            </Field>
                        )}
                        <Field
                            label={coaching ? "Workout" : "Training day"}
                            error={form.errors.training_day_id}
                        >
                            <select
                                aria-label={
                                    coaching ? "Workout" : "Training day"
                                }
                                value={form.data.training_day_id}
                                required={!historical && !recorded}
                                onChange={(e) => selectDay(e.target.value)}
                                disabled={
                                    !planId ||
                                    recorded ||
                                    (coaching && !!entry?.program_run_id)
                                }
                            >
                                <option value="">Select a day</option>
                                {days.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.title}{" "}
                                        {d.type === "rest" ? "(Rest)" : ""}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>
                    {!plans.length && (
                        <p className="info-note">
                            Create a{" "}
                            <Link href={url("/training-plans/create")}>
                                training plan
                            </Link>{" "}
                            and add a day first, or schedule a rest day.
                        </p>
                    )}
                    {planId && !days.length && (
                        <p className="info-note">
                            This plan has no days yet.{" "}
                            <Link
                                href={url(
                                    `/training-plans/${planId}/days/create`,
                                )}
                            >
                                Add a training day
                            </Link>
                            .
                        </p>
                    )}
                    {historical && (
                        <p className="info-note">
                            The original template was deleted. You can still
                            edit this session’s date, title, and notes.
                        </p>
                    )}
                </>
            )}
            {recorded && (
                <p className="info-note">
                    This session has a saved workout. You can still edit its
                    date, time, title, and notes.
                </p>
            )}
            <div className="form-grid">
                <Field label="Date" required error={form.errors.scheduled_date}>
                    <input
                        type="date"
                        required
                        value={form.data.scheduled_date}
                        onInput={(e) => {
                            form.setData("scheduled_date", e.target.value);
                            onDateChange?.(e.target.value);
                        }}
                    />
                </Field>
                <Field
                    label="Time (Optional)"
                    error={form.errors.scheduled_time}
                >
                    <input
                        type="time"
                        aria-label="Time (Optional)"
                        value={form.data.scheduled_time}
                        onInput={(event) =>
                            form.setData("scheduled_time", event.target.value)
                        }
                    />
                    <small className="field-hint">
                        Leave blank for Anytime on the selected day.
                    </small>
                    {form.data.scheduled_time && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => form.setData("scheduled_time", "")}
                        >
                            Anytime
                        </Button>
                    )}
                </Field>
            </div>
            <Field label="Session title" error={form.errors.title}>
                <input
                    maxLength={150}
                    value={form.data.title}
                    onChange={(e) => form.setData("title", e.target.value)}
                    placeholder="e.g. Morning Leg Day"
                />
            </Field>
            <Field label="Notes" error={form.errors.notes}>
                <textarea
                    rows={3}
                    maxLength={5000}
                    value={form.data.notes}
                    onChange={(e) => form.setData("notes", e.target.value)}
                    placeholder="Anything to keep in mind for this session"
                />
            </Field>
            {form.errors.entry_type && (
                <p className="field-error">{form.errors.entry_type}</p>
            )}
            <p className="field-hint">
                You can schedule more than one session on the same date.
            </p>
            <div className="form-actions">
                <Button type="button" variant="secondary" onClick={onCancel}>
                    Cancel
                </Button>
                <Button disabled={form.processing}>
                    {form.processing
                        ? "Saving…"
                        : entry
                          ? "Save changes"
                          : "Save to calendar"}
                </Button>
            </div>
        </form>
    );
}

import { useTraining } from "./CoachingUI";
import { useState } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { Button, Field } from "../Components/UI";
export default function ScheduleEntryForm({
    plans,
    entry,
    selectedDay,
    selectedDate,
    onCancel,
    onSaved,
}) {
    const { url } = useTraining();
    const { today } = usePage().props;
    const initialDay = plans
        .flatMap((p) => p.training_days)
        .find((d) => d.id === Number(entry?.training_day_id || selectedDay));
    const [planId, setPlanId] = useState(initialDay?.training_plan_id || "");
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
    const days =
        plans.find((p) => p.id === Number(planId))?.training_days || [];
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
            <div className="segmented schedule-mode">
                <button
                    type="button"
                    className={!directRest ? "active" : ""}
                    disabled={recorded}
                    onClick={() => changeMode(false)}
                >
                    From a training plan
                </button>
                <button
                    type="button"
                    className={directRest ? "active" : ""}
                    disabled={recorded}
                    onClick={() => changeMode(true)}
                >
                    Rest day
                </button>
            </div>
            {!directRest && (
                <>
                    <div className="form-grid">
                        <Field label="Training plan">
                            <select
                                value={planId}
                                disabled={recorded}
                                onChange={(e) => {
                                    setPlanId(e.target.value);
                                    form.setData({
                                        ...form.data,
                                        training_day_id: "",
                                        title: "",
                                        entry_type: "training",
                                    });
                                }}
                                required={!historical && !recorded}
                            >
                                <option value="">Select a plan</option>
                                {plans.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field
                            label="Training day"
                            error={form.errors.training_day_id}
                        >
                            <select
                                value={form.data.training_day_id}
                                required={!historical && !recorded}
                                onChange={(e) => selectDay(e.target.value)}
                                disabled={!planId || recorded}
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
                        onInput={(e) =>
                            form.setData("scheduled_date", e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Time (Optional)"
                    error={form.errors.scheduled_time}
                >
                    <input
                        type="time"
                        value={form.data.scheduled_time}
                        onInput={(event) =>
                            form.setData("scheduled_time", event.target.value)
                        }
                    />
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

import { useState } from "react";
import { router } from "@inertiajs/react";
import { Button, Field, Modal, TimeLabel } from "../Components/UI";
import TrainingThumbnail from "../Components/TrainingThumbnail";
import WeekdayPicker, { weekdaySummary } from "../Components/WeekdayPicker";
import { coachingJson, useTraining } from "./CoachingUI";

export default function WeeklyScheduleEditor({
    days,
    editable = true,
    onSaved,
}) {
    const { url, canManage } = useTraining();
    const [editing, setEditing] = useState(null),
        [weekdays, setWeekdays] = useState([]),
        [time, setTime] = useState(""),
        [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    async function save(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        try {
            await coachingJson(url(`/training-days/${editing.id}`), {
                _method: "put",
                schedule_only: true,
                weekdays,
                default_time: time || null,
                expected_weekdays: editing.weekdays || [],
                expected_default_time:
                    editing.default_time?.slice(0, 5) || null,
            });
            await new Promise((resolve) =>
                router.reload({ onSuccess: onSaved, onFinish: resolve }),
            );
            setEditing(null);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <div className="weekly-schedule-editor">
                {days.map((day) => (
                    <div className="weekly-schedule-row" key={day.id}>
                        <TrainingThumbnail item={day} />
                        <div>
                            <strong>{day.title}</strong>
                            <small>
                                {weekdaySummary(day.weekdays || [])} ·{" "}
                                {day.default_time ? (
                                    <TimeLabel time={day.default_time} />
                                ) : (
                                    "Anytime"
                                )}
                            </small>
                        </div>
                        {editable && canManage ? (
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    setEditing(day);
                                    setWeekdays(day.weekdays || []);
                                    setTime(
                                        day.default_time?.slice(0, 5) || "",
                                    );
                                    setError("");
                                }}
                            >
                                Change
                            </Button>
                        ) : (
                            <span className="badge rest">Protected</span>
                        )}
                    </div>
                ))}
            </div>
            {editing && (
                <Modal
                    title={`Weekly schedule · ${editing.title}`}
                    onClose={() => !busy && setEditing(null)}
                >
                    <form onSubmit={save}>
                        <WeekdayPicker
                            value={weekdays}
                            onChange={setWeekdays}
                        />
                        <Field label="Workout time (optional)">
                            <input
                                type="time"
                                value={time}
                                onChange={(e) => setTime(e.target.value)}
                            />
                            <small className="field-hint">
                                Blank means Anytime.
                            </small>
                        </Field>
                        {time && (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setTime("")}
                            >
                                Anytime
                            </Button>
                        )}
                        <p>
                            Save the weekly pattern, then review the affected
                            Calendar dates before confirming changes.
                        </p>
                        {error && (
                            <p className="field-error" role="alert">
                                {error}
                            </p>
                        )}
                        <div className="form-actions">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setEditing(null)}
                                disabled={busy}
                            >
                                Cancel
                            </Button>
                            <Button disabled={busy}>
                                {busy ? "Saving…" : "Save weekly schedule"}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}

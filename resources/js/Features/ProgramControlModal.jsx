import { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Modal, Field, Button, DateLabel } from "../Components/UI";
import { coachingJson, useTraining } from "./CoachingUI";

export const programStatus = (status) =>
    ({
        active: "Active",
        paused: "Paused",
        completed: "Completed",
        ended: "Ended early",
    })[status] || status;
const titles = {
    pause: "Pause program",
    resume: "Resume program",
    shift: "Adjust remaining schedule",
    end: "End program early",
};

export default function ProgramControlModal({
    run,
    position,
    action,
    onClose,
}) {
    const { today } = usePage().props;
    const { url } = useTraining();
    const [values, setValues] = useState(
        action === "pause"
            ? { action, pause_date: today, resume_date: "" }
            : ["resume", "shift"].includes(action)
              ? {
                    action,
                    start_date:
                        run.resume_on && run.resume_on >= today
                            ? run.resume_on
                            : today,
                }
              : { action },
    );
    const [known, setKnown] = useState(false);
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    function change(key, value) {
        setValues((v) => ({ ...v, [key]: value }));
        setPreview(null);
        setError("");
    }
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError("");
        try {
            const result = await coachingJson(
                url(
                    `/program-runs/${run.id}/${preview ? "control" : "control-preview"}`,
                ),
                {
                    ...values,
                    ...(preview
                        ? { signature: preview.signature, confirmed: true }
                        : {}),
                },
            );
            if (result.saved) router.reload({ onFinish: onClose });
            else setPreview(result.preview);
        } catch (e) {
            setError(e.message);
            setPreview(null);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal title={titles[action]} onClose={() => !busy && onClose()}>
            <form onSubmit={submit}>
                <p>
                    {run.definition_snapshot.name} · Week{" "}
                    {position.current_week} of {position.duration_weeks}
                </p>
                <p>
                    {position.completed_workouts} completed ·{" "}
                    {position.skipped_workouts} skipped. Saved results and
                    individually moved workouts stay protected.
                </p>
                {action === "pause" && (
                    <>
                        <Field label="Pause starting" required>
                            <input
                                type="date"
                                min={today}
                                required
                                value={values.pause_date}
                                disabled={busy}
                                onChange={(e) =>
                                    change("pause_date", e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Return date">
                            <select
                                value={known ? "known" : "later"}
                                disabled={busy}
                                onChange={(e) => {
                                    setKnown(e.target.value === "known");
                                    change("resume_date", "");
                                }}
                            >
                                <option value="later">I'll resume later</option>
                                <option value="known">I know the date</option>
                            </select>
                        </Field>
                        {known && (
                            <Field label="Expected resume date" required>
                                <input
                                    type="date"
                                    min={values.pause_date}
                                    required
                                    value={values.resume_date}
                                    disabled={busy}
                                    onChange={(e) =>
                                        change("resume_date", e.target.value)
                                    }
                                />
                            </Field>
                        )}
                        <p className="field-hint">
                            Workouts from the pause date stop counting as
                            missed. A known return date shifts eligible dates by
                            the pause length. You still choose Resume program
                            when ready.
                        </p>
                    </>
                )}
                {["resume", "shift"].includes(action) && (
                    <>
                        <Field
                            label={
                                action === "resume"
                                    ? "Resume on"
                                    : "Start remaining workouts on"
                            }
                            required
                        >
                            <input
                                type="date"
                                min={today}
                                required
                                value={values.start_date}
                                disabled={busy}
                                onChange={(e) =>
                                    change("start_date", e.target.value)
                                }
                            />
                        </Field>
                        <p className="field-hint">
                            Keep the spacing, logical weeks and saved exercises.{" "}
                            {action === "resume" && run.resume_on
                                ? "The known return date was already applied; only its date adjustment is added."
                                : "The first eligible remaining workout starts on this date."}{" "}
                            A date with an earlier workout time requires a later
                            day.
                        </p>
                    </>
                )}
                {action === "end" && (
                    <p className="coach-callout">
                        This ends the run permanently. Remove only eligible
                        future workouts; keep past sessions, manual exceptions,
                        history and progress. You can repeat the definition as a
                        new run later.
                    </p>
                )}
                {preview && (
                    <section
                        aria-label="Program change preview"
                        aria-live="polite"
                        className="program-control-preview"
                    >
                        <h3>Review changes</h3>
                        <p>
                            {preview.move.length} moved ·{" "}
                            {preview.remove.length} removed ·{" "}
                            {preview.keep.length} kept ·{" "}
                            {preview.protected.length} protected
                        </p>
                        {action !== "end" && (
                            <p>
                                Schedule adjustment:{" "}
                                {preview.days > 0 ? "+" : ""}
                                {preview.days} days. Logical week stays{" "}
                                {position.current_week}.
                            </p>
                        )}
                        {["move", "remove", "keep", "protected"].map(
                            (group) => (
                                <details
                                    key={group}
                                    open={
                                        group === "move" || group === "remove"
                                    }
                                >
                                    <summary>
                                        {group.toUpperCase()} ·{" "}
                                        {preview[group].length}
                                    </summary>
                                    <ul className="program-control-rows">
                                        {preview[group].map((row) => (
                                            <li key={row.id}>
                                                <strong>
                                                    {row.title} · Week{" "}
                                                    {row.program_week}
                                                </strong>
                                                <span>
                                                    <DateLabel
                                                        date={
                                                            row.scheduled_date
                                                        }
                                                    />
                                                    {row.to && (
                                                        <>
                                                            {" "}
                                                            →{" "}
                                                            <DateLabel
                                                                date={row.to}
                                                            />
                                                        </>
                                                    )}
                                                    {row.scheduled_time &&
                                                        ` · ${row.scheduled_time.slice(0, 5)}`}
                                                </span>
                                                {row.reason && (
                                                    <small>{row.reason}</small>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            ),
                        )}
                    </section>
                )}
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
                <div className="form-actions">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={busy}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={busy}
                        variant={
                            action === "end" && preview ? "danger" : "primary"
                        }
                    >
                        {busy
                            ? "Please wait…"
                            : preview
                              ? titles[action]
                              : "Preview changes"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

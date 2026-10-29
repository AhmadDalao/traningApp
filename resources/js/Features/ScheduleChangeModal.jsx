import { useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { Modal, Button, Field, DateLabel, TimeLabel } from "../Components/UI";
import { useTraining } from "./CoachingUI";

export default function ScheduleChangeModal({ entry, plan, date, onClose }) {
    const { url } = useTraining();
    const { today } = usePage().props;
    const stopping = Boolean(plan);
    const [values, setValues] = useState(
        stopping
            ? {
                  end_date: plan.end_date || today,
              }
            : {
                  scheduled_date: date || entry.scheduled_date,
                  scheduled_time: entry.scheduled_time?.slice(0, 5) || "",
                  scope: "one",
              },
    );
    const [preview, setPreview] = useState(null);
    const [error, setError] = useState("");
    const [busy, setBusy] = useState(false);
    const base = stopping
        ? `/training-plans/${plan.id}/stop-schedule`
        : `/schedule-entries/${entry.id}/reschedule`;
    function change(key, value) {
        setValues({ ...values, [key]: value });
        setPreview(null);
        setError("");
    }
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError("");
        try {
            const token = document.cookie
                .split("; ")
                .find((cookie) => cookie.startsWith("XSRF-TOKEN="))
                ?.slice(11);
            if (!navigator.onLine)
                throw new Error(
                    "You are offline. Reconnect before saving changes.",
                );
            const response = await fetch(
                url(base + (preview ? "" : "-preview")),
                {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        "X-XSRF-TOKEN": decodeURIComponent(token || ""),
                    },
                    body: JSON.stringify({
                        ...values,
                        ...(preview
                            ? { signature: preview.signature, confirmed: true }
                            : {}),
                    }),
                },
            );
            const result = await response.json().catch(() => ({}));
            if (!response.ok)
                throw new Error(
                    Object.values(result.errors || {})
                        .flat()
                        .join(" ") ||
                        result.message ||
                        "Could not save. Refresh the page and try again.",
                );
            if (result.saved) {
                router.reload({ onFinish: onClose });
            } else {
                setPreview(result.preview);
            }
        } catch (err) {
            setError(
                err.message ||
                    "Connection interrupted. Review a fresh preview before trying again.",
            );
            setPreview(null);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal
            title={stopping ? "Stop recurring schedule" : "Change schedule"}
            onClose={() => !busy && onClose()}
            wide
        >
            <form onSubmit={submit}>
                <p className="copy-description">{plan?.name || entry.title}</p>
                {stopping ? (
                    <>
                        <Field label="Stop after" required>
                            <input
                                type="date"
                                required
                                min={today}
                                value={values.end_date}
                                disabled={busy}
                                onInput={(e) =>
                                    change("end_date", e.target.value)
                                }
                            />
                        </Field>
                        <p className="field-hint">
                            Keep sessions on this date. Remove only eligible
                            generated sessions after it. Your plan, history,
                            active workouts and individual exceptions stay.
                        </p>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={busy}
                            onClick={() => change("end_date", today)}
                        >
                            Stop today
                        </Button>
                    </>
                ) : (
                    <>
                        <p>
                            <DateLabel date={entry.scheduled_date} long /> →{" "}
                            <DateLabel date={values.scheduled_date} long />
                        </p>
                        <div className="form-grid">
                            <Field label="New date" required>
                                <input
                                    type="date"
                                    required
                                    min={today}
                                    value={values.scheduled_date}
                                    disabled={busy}
                                    onInput={(e) =>
                                        change("scheduled_date", e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Time (optional)">
                                <input
                                    type="time"
                                    value={values.scheduled_time}
                                    disabled={busy || values.scope === "future"}
                                    onInput={(e) =>
                                        change("scheduled_time", e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        {entry.can_move_series && (
                            <Field label="Apply to">
                                <select
                                    disabled={busy}
                                    value={values.scope}
                                    onChange={(e) => {
                                        setValues({
                                            ...values,
                                            scope: e.target.value,
                                            scheduled_time:
                                                entry.scheduled_time?.slice(
                                                    0,
                                                    5,
                                                ) || "",
                                        });
                                        setPreview(null);
                                        setError("");
                                    }}
                                >
                                    <option value="one">
                                        This session only
                                    </option>
                                    <option value="future">
                                        This and future sessions
                                    </option>
                                </select>
                            </Field>
                        )}
                        <p className="field-hint">
                            {values.scope === "future"
                                ? "Move this session and change its weekday from this occurrence onward. Other weekdays stay. Review the future changes below before applying."
                                : "Only this session moves. The weekly schedule stays unchanged."}
                        </p>
                    </>
                )}
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
                {preview && (
                    <>
                        {preview.destination_count > 0 && (
                            <p className="field-hint">
                                This date already has{" "}
                                {preview.destination_count} session(s). Moving
                                here keeps them; multiple sessions on a date are
                                allowed.
                            </p>
                        )}
                        {preview.move && (
                            <p className="coach-callout">
                                Move this session to{" "}
                                <DateLabel date={preview.move.to} />
                                {preview.move.time && (
                                    <>
                                        {" "}
                                        · <TimeLabel time={preview.move.time} />
                                    </>
                                )}
                                . Its usual recurrence changes only when “This
                                and future” is selected.
                            </p>
                        )}
                        {(stopping || values.scope === "future") && (
                            <div className="schedule-change-diff">
                                {["add", "keep", "remove", "protected"].map(
                                    (group) => (
                                        <details
                                            key={group}
                                            open={
                                                group === "remove" ||
                                                group === "add"
                                            }
                                        >
                                            <summary>
                                                {group.charAt(0).toUpperCase() +
                                                    group.slice(1)}{" "}
                                                · {preview[group].length}
                                            </summary>
                                            <div className="schedule-change-rows">
                                                {preview[group].length ? (
                                                    preview[group].map(
                                                        (row) => (
                                                            <p
                                                                key={
                                                                    row.id ||
                                                                    row.key
                                                                }
                                                            >
                                                                <strong>
                                                                    {row.title}
                                                                </strong>
                                                                <span>
                                                                    <DateLabel
                                                                        date={
                                                                            row.scheduled_date
                                                                        }
                                                                    />
                                                                    {row.scheduled_time && (
                                                                        <>
                                                                            {" "}
                                                                            ·{" "}
                                                                            <TimeLabel
                                                                                time={
                                                                                    row.scheduled_time
                                                                                }
                                                                            />
                                                                        </>
                                                                    )}
                                                                </span>
                                                                {row.reason && (
                                                                    <small>
                                                                        {
                                                                            row.reason
                                                                        }
                                                                    </small>
                                                                )}
                                                            </p>
                                                        ),
                                                    )
                                                ) : (
                                                    <p>No sessions</p>
                                                )}
                                            </div>
                                        </details>
                                    ),
                                )}
                            </div>
                        )}
                    </>
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
                        disabled={busy}
                        variant={stopping && preview ? "danger" : "primary"}
                    >
                        {busy
                            ? "Please wait…"
                            : preview
                              ? stopping
                                  ? "Stop schedule"
                                  : "Apply changes"
                              : "Preview changes"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

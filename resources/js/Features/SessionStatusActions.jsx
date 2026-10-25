import { useState } from "react";
import { router } from "@inertiajs/react";
import { Check, SkipForward } from "lucide-react";
import { Button } from "../Components/UI";
export default function SessionStatusActions({ entry, compact = false }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    function update(status) {
        if (!status || busy) return;
        setBusy(true);
        setError("");
        router.patch(
            `/schedule-entries/${entry.id}/status`,
            { status },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(errors.status || "Could not update status."),
                onFinish: () => setBusy(false),
            },
        );
    }
    if (compact && entry.entry_type === "rest") return null;
    if (compact && entry.status === "completed")
        return (
            <span className="completed-label">
                <Check size={16} />
                Completed
            </span>
        );
    return (
        <div className="status-actions">
            {entry.status === "planned" && entry.entry_type === "training" ? (
                <>
                    <Button
                        disabled={busy}
                        onClick={() => update("completed")}
                        className={compact ? "small" : ""}
                    >
                        <Check size={16} />
                        {busy ? "Saving…" : "Mark completed"}
                    </Button>
                    {!compact && (
                        <Button
                            variant="secondary"
                            disabled={busy}
                            onClick={() => update("skipped")}
                        >
                            <SkipForward size={16} />
                            Mark skipped
                        </Button>
                    )}
                </>
            ) : (
                <select
                    aria-label="Change session status"
                    value=""
                    disabled={busy}
                    onChange={(e) => update(e.target.value)}
                >
                    <option value="">
                        {busy ? "Saving…" : "Change status"}
                    </option>
                    {["planned", "completed", "skipped"]
                        .filter((s) => s !== entry.status)
                        .map((s) => (
                            <option key={s} value={s}>
                                {s[0].toUpperCase() + s.slice(1)}
                            </option>
                        ))}
                </select>
            )}
            {error && (
                <span role="alert" className="field-error">
                    {error}
                </span>
            )}
        </div>
    );
}

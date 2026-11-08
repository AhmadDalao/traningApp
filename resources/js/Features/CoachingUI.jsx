import PersonAvatar from "../Components/PersonAvatar";
import {
    CheckCircle2,
    PauseCircle,
    AlertTriangle,
    ClipboardList,
    Clock,
    LayoutDashboard,
    Layers,
    CalendarDays,
    History,
    TrendingUp,
} from "lucide-react";
import { usePage, Link, router } from "@inertiajs/react";
import { useState } from "react";
import { Button, ConfirmDialog, ButtonLink, DateLabel } from "../Components/UI";

export function useTraining() {
    const { trainingContext } = usePage().props;
    const context = trainingContext || {
        prefix: "",
        canManage: true,
        coaching: false,
        templates: false,
    };
    return { ...context, url: (path) => `${context.prefix || ""}${path}` };
}
export function Errors({ errors }) {
    return Object.entries(errors || {}).map(([key, value]) => (
        <p key={key} className="field-error" role="alert">
            {value}
        </p>
    ));
}
export function CoachStatus({ status }) {
    const Icon = ["active", "completed"].includes(status)
        ? CheckCircle2
        : status === "paused"
          ? PauseCircle
          : Clock;
    return (
        <span className={`coach-status ${status || ""}`}>
            <Icon size={13} aria-hidden="true" />
            {(status || "No subscription").replaceAll("_", " ")}
        </span>
    );
}
export function EndRelationship({ relationship, label = "Disconnect coach" }) {
    const [open, setOpen] = useState(false);
    return (
        <>
            <Button variant="secondary" onClick={() => setOpen(true)}>
                {label}
            </Button>
            {open && (
                <ConfirmDialog
                    title={`${label}?`}
                    description="Access ends immediately. Plans, scheduled sessions, workouts and progress stay with the person training."
                    url={`/my-coaching/relationships/${relationship.id}`}
                    method="post"
                    data={{ action: "end", confirmed: true }}
                    confirmLabel={label}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}
export const traineeStates = {
    all: "All",
    active: "Active",
    setup: "Needs setup",
    attention: "Needs attention",
    paused: "Paused",
    pending: "Pending",
    ended: "Ended",
};
export function TraineeState({ state }) {
    const Icon =
        state === "attention"
            ? AlertTriangle
            : state === "setup"
              ? ClipboardList
              : state === "paused"
                ? PauseCircle
                : state === "active"
                  ? CheckCircle2
                  : Clock;
    return (
        <span className={`coach-status ${state}`}>
            <Icon size={13} aria-hidden="true" />
            {traineeStates[state] || state}
        </span>
    );
}
export function TraineeNav() {
    const ctx = useTraining(),
        { url: current } = usePage();
    if (!ctx.coaching) return null;
    const state = ctx.summary;
    const tabs = [
        ["", "Overview", LayoutDashboard],
        ["/training-plans", "Training", Layers],
        ["/calendar", "Calendar", CalendarDays],
        ["/training-history", "History", History],
        ["/progress", "Progress", TrendingUp],
    ];
    const active =
        current.includes("/program-runs") || current.includes("/training-days")
            ? "/training-plans"
            : current.includes("/schedule-entries")
              ? "/calendar"
              : [...tabs]
                    .reverse()
                    .find(
                        ([path]) => path && current.startsWith(ctx.url(path)),
                    )?.[0] || "";
    return (
        <section className="trainee-context" aria-label="Current trainee">
            <Link className="trainee-back" href="/coach/trainees">
                ← Trainees
            </Link>
            <div className="trainee-identity">
                <div className="trainee-person">
                    <PersonAvatar name={ctx.trainee.name} large />
                    <div>
                        <Link href={ctx.url("")}>
                            <strong>{ctx.trainee.name}</strong>
                        </Link>
                        <small>
                            {ctx.trainee.training_code} · Coaching view
                        </small>
                    </div>
                </div>
                <div>
                    <TraineeState state={state?.display_state} />
                    <small>
                        {state?.subscription?.package_name ||
                            "Coaching not configured"}
                        {state?.subscription?.ends_on
                            ? ` · Ends ${state.subscription.ends_on}`
                            : state?.subscription
                              ? " · Ongoing"
                              : ""}
                    </small>
                </div>
            </div>
            <nav className="trainee-desktop-nav" aria-label="Trainee sections">
                {tabs.map(([path, name, Icon]) => (
                    <Link
                        key={path}
                        className={active === path ? "active" : ""}
                        href={ctx.url(path)}
                    >
                        <Icon size={15} aria-hidden="true" /> {name}
                    </Link>
                ))}
            </nav>
            <label className="trainee-mobile-nav">
                Trainee section
                <select
                    value={active}
                    onChange={(e) => router.visit(ctx.url(e.target.value))}
                >
                    {tabs.map(([path, name]) => (
                        <option key={path} value={path}>
                            {name}
                        </option>
                    ))}
                </select>
            </label>
            {!ctx.canManage && (
                <p className="field-hint">
                    Read-only access. Management needs active coaching and an
                    active assignment.
                </p>
            )}
            {current.includes("/training-plans") &&
                state?.display_state === "setup" && (
                    <Link className="trainee-back" href={ctx.url("/setup")}>
                        Continue Assignment →
                    </Link>
                )}
        </section>
    );
}
export async function coachingJson(path, data = {}, method = "POST") {
    if (!navigator.onLine)
        throw new Error("You are offline. Reconnect before saving changes.");
    const cookie = document.cookie
        .split("; ")
        .find((value) => value.startsWith("XSRF-TOKEN="));
    const response = await fetch(path, {
        method,
        credentials: "same-origin",
        headers: {
            ...(data instanceof FormData
                ? {}
                : { "Content-Type": "application/json" }),
            Accept: "application/json",
            "X-XSRF-TOKEN": cookie ? decodeURIComponent(cookie.slice(11)) : "",
        },
        body: data instanceof FormData ? data : JSON.stringify(data),
    });
    const result = await response.json();
    if (!response.ok) {
        const error = new Error(
            Object.values(result.errors || {}).flat()[0] ||
                result.message ||
                "Could not complete the request.",
        );
        error.fields = Object.fromEntries(
            Object.entries(result.errors || {}).map(([key, values]) => [
                key,
                Array.isArray(values) ? values[0] : values,
            ]),
        );
        throw error;
    }
    return result;
}
export const consentText =
    "Your coach can view your training goals, body-weight progress, Calendar, workout history, exercise progress and personal records, for training assigned by this coach. Your personal training and other coaches’ assignments remain private. With an active coaching subscription, they can create and manage their own assigned plans and future sessions. They cannot record results or complete workouts for you. You can disconnect at any time.";

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
    return (
        <span className={`coach-status ${status || ""}`}>
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
    return (
        <span className={`coach-status ${state}`}>
            {traineeStates[state] || state}
        </span>
    );
}
export function TraineeNav() {
    const ctx = useTraining(),
        { url: current } = usePage();
    if (!ctx.coaching) return null;
    const state = ctx.summary,
        plan = state?.plan;
    const tabs = [
        ["", "Overview"],
        ["/training-plans", "Program"],
        ["/calendar", "Calendar"],
        ["/training-history", "History"],
        ["/progress", "Progress"],
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
                <div>
                    <Link href={ctx.url("")}>
                        <strong>{ctx.trainee.name}</strong>
                    </Link>
                    <small>{ctx.trainee.training_code} · Coaching view</small>
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
                {plan && (
                    <div>
                        <strong>{plan.name}</strong>
                        <small>
                            {plan.current_week
                                ? `Week ${plan.current_week} of ${plan.duration_weeks}`
                                : plan.run_status || "Training assigned"}
                        </small>
                    </div>
                )}
            </div>
            <nav className="trainee-desktop-nav" aria-label="Trainee sections">
                {tabs.map(([path, name]) => (
                    <Link
                        key={path}
                        className={active === path ? "active" : ""}
                        href={ctx.url(path)}
                    >
                        {name}
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
                        Continue coaching setup →
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
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-XSRF-TOKEN": cookie ? decodeURIComponent(cookie.slice(11)) : "",
        },
        body: JSON.stringify(data),
    });
    const result = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(result.errors || {}).flat()[0] ||
                result.message ||
                "Could not complete the request.",
        );
    return result;
}
export const consentText =
    "Your coach can view your training goals, body-weight progress, Calendar, workout history, exercise progress and personal records, including personal workouts and sessions from other coaches. With an active coaching subscription, they can create and manage their own assigned plans and future sessions. They cannot record results or complete workouts for you. You can disconnect at any time.";

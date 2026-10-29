import { usePage, Link } from "@inertiajs/react";
import { useState } from "react";
import { Button, ConfirmDialog } from "../Components/UI";

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
export function TraineeNav() {
    const context = useTraining();
    const { url: current } = usePage();
    if (!context.coaching) return null;
    const tabs = [
        ["", "Overview"],
        ["/training-plans", "Plans"],
        ["/calendar", "Calendar"],
        ["/training-history", "Training History"],
        ["/progress", "Progress"],
    ];
    return (
        <div className="trainee-context">
            <div>
                <strong>{context.trainee.name}</strong>
                <small>{context.trainee.training_code} · Coaching view</small>
            </div>
            <nav aria-label="Trainee sections">
                {tabs.map(([path, name]) => (
                    <Link
                        key={path}
                        className={
                            path
                                ? current.startsWith(context.url(path))
                                    ? "active"
                                    : ""
                                : current.split("?")[0] === context.prefix
                                  ? "active"
                                  : ""
                        }
                        href={context.url(path)}
                    >
                        {name}
                    </Link>
                ))}
            </nav>
            {!context.canManage && (
                <p className="field-hint">
                    Read-only access. Management needs an active subscription
                    and assignment.
                </p>
            )}
        </div>
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

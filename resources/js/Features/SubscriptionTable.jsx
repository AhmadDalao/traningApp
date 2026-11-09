import { useState } from "react";
import { Link, useForm, usePage } from "@inertiajs/react";
import { Pause, Play, RotateCcw, Square, ArrowUpRight } from "lucide-react";
import { Button, ButtonLink, ConfirmDialog } from "../Components/UI";
import TrainingThumbnail from "../Components/TrainingThumbnail";
import PersonAvatar from "../Components/PersonAvatar";
import { CoachStatus, Errors } from "./CoachingUI";

function SubscriptionDate({ date }) {
    return date ? (
        <time className="subscription-date" dateTime={date}>
            {new Date(`${date}T12:00:00`).toLocaleDateString("en-GB", {
                day: "numeric",
                month: "short",
                year: "numeric",
            })}
        </time>
    ) : (
        <span className="subscription-date">Ongoing</span>
    );
}

function CoverageRemaining({ item }) {
    const days = item.days_until_end;
    if (item.effective_status === "ended") return <span>Ended</span>;
    if (days !== null && days < 0)
        return (
            <span>
                Ended {Math.abs(days)} {days === -1 ? "day" : "days"} ago
            </span>
        );
    if (item.effective_status === "upcoming")
        return (
            <span>
                Starts in {item.days_until_start}{" "}
                {item.days_until_start === 1 ? "day" : "days"}
            </span>
        );
    if (days === null) return <span>No end date</span>;
    return (
        <span
            className={
                item.effective_status === "active" && days <= 7
                    ? "subscription-ending-soon"
                    : ""
            }
        >
            {days === 0
                ? "Ends today"
                : days === 1
                  ? "Ends tomorrow"
                  : `${days} days until end`}
            {item.effective_status === "paused" && (
                <small>Coverage is paused</small>
            )}
        </span>
    );
}

function Actions({ item }) {
    const { today } = usePage().props;
    const form = useForm({ action: "pause" });
    const [ending, setEnding] = useState(false);
    if (item.relationship.status !== "active")
        return <span>Connection ended</span>;
    const renewable =
        ["ended", "expired"].includes(item.effective_status) ||
        (item.ends_on && item.ends_on < today);
    const setup = `/coach/trainees/${item.relationship.trainee.id}/setup${item.assignment_id && item.assignment_status === "active" ? `?assignment=${item.assignment_id}` : "?assign=1"}`;
    return (
        <>
            <div className="subscription-actions">
                {renewable ? (
                    <ButtonLink variant="secondary" href={setup}>
                        <RotateCcw size={15} />
                        {item.assignment_id &&
                        item.assignment_status !== "active"
                            ? "Assign again"
                            : "Renew"}
                    </ButtonLink>
                ) : (
                    <Button
                        variant="secondary"
                        disabled={form.processing}
                        onClick={() => {
                            form.transform(() => ({
                                action:
                                    item.status === "paused"
                                        ? "resume"
                                        : "pause",
                            }));
                            form.patch(`/coach/subscriptions/${item.id}`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        {item.status === "paused" ? (
                            <Play size={15} />
                        ) : (
                            <Pause size={15} />
                        )}
                        {item.status === "paused" ? "Resume" : "Pause"}
                    </Button>
                )}
                {item.status !== "ended" && (
                    <Button variant="secondary" onClick={() => setEnding(true)}>
                        <Square size={14} />
                        End
                    </Button>
                )}
            </div>
            <Errors errors={form.errors} />
            {ending && (
                <ConfirmDialog
                    title={`End ${item.plan?.name || item.package_name} subscription?`}
                    description="This program’s coaching coverage will end. Other subscriptions keep running. Saved plans and workout history stay available."
                    url={`/coach/subscriptions/${item.id}`}
                    method="patch"
                    data={{ action: "end", confirmed: true }}
                    confirmLabel="End subscription"
                    onClose={() => setEnding(false)}
                />
            )}
        </>
    );
}
export default function SubscriptionTable({ items }) {
    if (!items.length)
        return (
            <div className="panel coach-card">
                <h2>No subscriptions match</h2>
                <p>
                    Assign a training program to activate its own subscription,
                    or change your filters.
                </p>
            </div>
        );
    return (
        <div className="panel subscription-table-wrap">
            <table className="subscription-table">
                <caption className="sr-only">
                    Program subscriptions, coverage and actions
                </caption>
                <thead>
                    <tr>
                        {[
                            "Trainee",
                            "Training program",
                            "Package",
                            "Start date",
                            "End date",
                            "Status",
                            "Time remaining",
                            "Actions",
                        ].map((label) => (
                            <th key={label} scope="col">
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {items.map((item) => (
                        <tr key={item.id}>
                            <td data-label="Trainee">
                                <div className="person-identity">
                                    <PersonAvatar
                                        name={item.relationship.trainee.name}
                                    />
                                    <div>
                                        <Link
                                            href={`/coach/trainees/${item.relationship.trainee.id}`}
                                        >
                                            <strong>
                                                {item.relationship.trainee.name}
                                            </strong>
                                        </Link>
                                        <small>
                                            {
                                                item.relationship.trainee
                                                    .training_code
                                            }
                                        </small>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Training program">
                                {item.plan ? (
                                    <div className="thumbnail-identity">
                                        <TrainingThumbnail item={item.plan} />
                                        <Link
                                            href={`/coach/trainees/${item.relationship.trainee.id}/training-plans/${item.plan.id}`}
                                        >
                                            {item.plan.name}
                                            <ArrowUpRight size={13} />
                                        </Link>
                                    </div>
                                ) : (
                                    <>
                                        <strong>Training not assigned</strong>
                                        <small>
                                            Available for the next assignment
                                        </small>
                                    </>
                                )}
                            </td>
                            <td data-label="Package">
                                {item.package_name}
                                {item.notes && (
                                    <details>
                                        <summary>Private notes</summary>
                                        <p>{item.notes}</p>
                                    </details>
                                )}
                            </td>
                            <td data-label="Start date">
                                <SubscriptionDate date={item.starts_on} />
                            </td>
                            <td data-label="End date">
                                <SubscriptionDate date={item.ends_on} />
                            </td>
                            <td data-label="Status">
                                <CoachStatus status={item.effective_status} />
                            </td>
                            <td data-label="Time remaining">
                                <CoverageRemaining item={item} />
                            </td>
                            <td data-label="Actions">
                                <Actions item={item} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head } from "@inertiajs/react";
import { Plus, Pencil, Trash2 } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    EmptyState,
    DateLabel,
    Pagination,
    ConfirmDialog,
    formatWeight,
} from "../../Components/UI";
import BodyWeightForm from "../../Features/BodyWeightForm";
import BodyWeightChart from "../../Features/BodyWeightChart";

export default function Index({
    summary,
    trend,
    entries,
    training,
    initialTab = "body",
}) {
    const { url, canManage, coaching, templates } = useTraining();
    const [tab, setTab] = useState(initialTab),
        [editing, setEditing] = useState(null),
        [deleting, setDeleting] = useState(null);
    const cards =
        tab === "body"
            ? [
                  ["Current Body Weight", formatWeight(summary.current)],
                  ["Target Body Weight", formatWeight(summary.target)],
                  [
                      "Change from first entry",
                      summary.change === null
                          ? "—"
                          : `${summary.change > 0 ? "+" : ""}${summary.change} kg`,
                  ],
                  ...(summary.target !== null
                      ? [
                            [
                                "Progress toward target",
                                summary.progress === null
                                    ? "—"
                                    : `${summary.progress}%`,
                            ],
                        ]
                      : []),
              ]
            : [
                  ["Completed this month", training.completed],
                  ["Skipped this month", training.skipped],
                  [
                      "Completion rate",
                      training.completion_rate === null
                          ? "—"
                          : `${training.completion_rate}%`,
                  ],
              ];
    return (
        <>
            <Head title="Progress" />
            <PageHeader
                title="Progress"
                description="See how your body weight and training consistency change over time."
            >
                {!coaching && (
                    <Button onClick={() => setEditing({})}>
                        <Plus size={16} />
                        Record weight
                    </Button>
                )}
            </PageHeader>
            <div
                className="progress-tabs"
                role="group"
                aria-label="Progress view"
            >
                <Button
                    variant={tab === "body" ? "primary" : "secondary"}
                    aria-pressed={tab === "body"}
                    onClick={() => setTab("body")}
                >
                    Body weight
                </Button>
                <ButtonLink
                    href={url("/progress/exercises")}
                    variant="secondary"
                >
                    Exercise Progress
                </ButtonLink>
                <Button
                    variant={tab === "training" ? "primary" : "secondary"}
                    aria-pressed={tab === "training"}
                    onClick={() => setTab("training")}
                >
                    Training stats
                </Button>
            </div>
            <div className="stats-grid progress-stats">
                {cards.map(([label, value]) => (
                    <div className="stat-card" key={label}>
                        <div className="stat-top">
                            <span>{label}</span>
                        </div>
                        <strong>{value}</strong>
                    </div>
                ))}
            </div>
            {tab === "body" ? (
                <>
                    {summary.target !== null && (
                        <p className="progress-explanation">
                            {summary.progress !== null
                                ? `${summary.remaining} kg from your target. Progress uses your first recorded weight and is capped between 0% and 100%.`
                                : "Progress appears when your first entry and target match your lose or gain goal."}{" "}
                            <ButtonLink
                                className="small"
                                href="/profile/edit#preferences"
                                variant="secondary"
                            >
                                Edit goal
                            </ButtonLink>
                        </p>
                    )}
                    {trend.length ? (
                        <div className="progress-columns">
                            <section className="panel profile-panel">
                                <h2>Weight trend</h2>
                                <BodyWeightChart entries={trend} />
                            </section>
                            <section className="panel profile-panel">
                                <h2>Recent entries</h2>
                                <ul className="weight-entry-list">
                                    {entries.data.map((entry) => (
                                        <li key={entry.id}>
                                            <div>
                                                <DateLabel
                                                    date={entry.recorded_date}
                                                    long
                                                />
                                                <strong>
                                                    {formatWeight(
                                                        entry.weight_kg,
                                                    )}
                                                </strong>
                                                {entry.note && (
                                                    <p>{entry.note}</p>
                                                )}
                                            </div>
                                            <div className="weight-entry-actions">
                                                {!coaching && (
                                                    <button
                                                        className="icon-btn"
                                                        aria-label={`Edit weight on ${entry.recorded_date}`}
                                                        onClick={() =>
                                                            setEditing(entry)
                                                        }
                                                    >
                                                        <Pencil size={15} />
                                                    </button>
                                                )}
                                                {!coaching && (
                                                    <button
                                                        className="icon-btn danger-text"
                                                        aria-label={`Delete weight on ${entry.recorded_date}`}
                                                        onClick={() =>
                                                            setDeleting(entry)
                                                        }
                                                    >
                                                        <Trash2 size={15} />
                                                    </button>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                <Pagination links={entries.links} />
                            </section>
                        </div>
                    ) : (
                        <section className="panel">
                            <EmptyState
                                title="Start tracking your progress"
                                description="Record your first body weight to see changes over time."
                            >
                                {!coaching && (
                                    <Button onClick={() => setEditing({})}>
                                        Record weight
                                    </Button>
                                )}
                            </EmptyState>
                        </section>
                    )}
                </>
            ) : (
                <section className="panel profile-panel">
                    <h2>Your training this month</h2>
                    <p className="progress-explanation">
                        Completion rate is completed training sessions divided
                        by all training sessions due this month, including
                        skipped and uncompleted planned sessions. Future
                        sessions and rest days are excluded.
                    </p>
                    {training.due === 0 && (
                        <p>No training sessions are due this month yet.</p>
                    )}
                    <ButtonLink
                        href={url(
                            "/training-history?period=this_month&type=training",
                        )}
                        variant="secondary"
                    >
                        View Training History
                    </ButtonLink>
                </section>
            )}
            {editing && (
                <BodyWeightForm
                    entry={editing.id ? editing : null}
                    onClose={() => setEditing(null)}
                />
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete body weight entry?"
                    description="This removes this measurement and recalculates your progress from the remaining entries."
                    url={`/body-weight/${deleting.id}`}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}

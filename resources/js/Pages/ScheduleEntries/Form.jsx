import { useState } from "react";
import { Link } from "@inertiajs/react";
import TrainingThumbnail from "../../Components/TrainingThumbnail";
import {
    StatusBadge,
    DateLabel,
    TimeLabel,
    ButtonLink,
} from "../../Components/UI";
import { useTraining } from "../../Features/CoachingUI";
import { Head, router } from "@inertiajs/react";
import { Breadcrumbs, PageHeader } from "../../Components/UI";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
export default function Form({
    plans,
    entry,
    selectedDay,
    selectedDate,
    sameDayEntries = [],
}) {
    const { url, coaching, trainee } = useTraining();
    const [date, setDate] = useState(selectedDate);
    return (
        <>
            <Head title={entry ? "Edit session" : "Schedule session"} />
            <Breadcrumbs
                items={[
                    { label: "Calendar", href: url("/calendar") },
                    { label: entry ? "Edit session" : "Schedule session" },
                ]}
            />
            <PageHeader
                title={
                    coaching
                        ? entry
                            ? "Edit workout"
                            : "Add Workout"
                        : entry
                          ? "Edit your session"
                          : "Schedule a session"
                }
                description={
                    coaching
                        ? "Add a one-off workout within the active coaching period."
                        : "A small commitment to yourself."
                }
            >
                <ButtonLink variant="secondary" href={url("/calendar")}>
                    ← Back to calendar
                </ButtonLink>
            </PageHeader>
            <div className={coaching ? "schedule-page-grid" : ""}>
                <div className="panel form-panel schedule-workout-panel">
                    <ScheduleEntryForm
                        plans={plans}
                        entry={entry}
                        selectedDay={selectedDay}
                        selectedDate={selectedDate}
                        onDateChange={
                            coaching && !entry
                                ? (value) => {
                                      setDate(value);
                                      if (/^\d{4}-\d{2}-\d{2}$/.test(value))
                                          router.get(
                                              url("/schedule-entries/create"),
                                              { date: value },
                                              {
                                                  only: ["sameDayEntries"],
                                                  preserveState: true,
                                                  preserveScroll: true,
                                                  replace: true,
                                              },
                                          );
                                  }
                                : undefined
                        }
                        onCancel={() =>
                            router.visit(
                                entry
                                    ? url(`/schedule-entries/${entry.id}`)
                                    : url("/calendar"),
                            )
                        }
                    />
                </div>
                {coaching && !entry && (
                    <aside className="panel coach-card schedule-day-agenda">
                        <h2>
                            On this day{" "}
                            <span className="count-bubble">
                                {sameDayEntries.length}
                            </span>
                        </h2>
                        <p>{date && <DateLabel date={date} long />}</p>
                        <div className="schedule-agenda-scroll">
                            {sameDayEntries.map((item) => (
                                <article
                                    key={item.id}
                                    className="coach-session"
                                >
                                    <TrainingThumbnail item={item} />
                                    <div className="coach-session-copy">
                                        <Link
                                            href={url(
                                                `/schedule-entries/${item.id}`,
                                            )}
                                        >
                                            <strong>{item.title}</strong>
                                        </Link>
                                        <small>
                                            {item.scheduled_time ? (
                                                <TimeLabel
                                                    time={item.scheduled_time}
                                                />
                                            ) : (
                                                "Anytime"
                                            )}
                                        </small>
                                    </div>
                                    <StatusBadge status={item.status} />
                                </article>
                            ))}
                            {!sameDayEntries.length && (
                                <p>
                                    No workouts scheduled. You can add the first
                                    one.
                                </p>
                            )}
                        </div>
                        <p className="field-hint">
                            Multiple workouts can share a date. Adding one here
                            leaves weekly schedules unchanged.
                        </p>
                    </aside>
                )}
            </div>
        </>
    );
}

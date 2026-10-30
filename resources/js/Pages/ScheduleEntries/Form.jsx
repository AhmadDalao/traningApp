import { useTraining } from "../../Features/CoachingUI";
import { Head, router } from "@inertiajs/react";
import { Breadcrumbs, PageHeader } from "../../Components/UI";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
export default function Form({ plans, entry, selectedDay, selectedDate }) {
    const { url, coaching, trainee } = useTraining();
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
                        ? `${entry ? "Edit workout for" : "Schedule"} ${trainee.name}`
                        : entry
                          ? "Edit your session"
                          : "Schedule a session"
                }
                description={
                    coaching
                        ? "Add a one-off workout within the active coaching period."
                        : "A small commitment to yourself."
                }
            />
            <div className="panel form-panel">
                <ScheduleEntryForm
                    plans={plans}
                    entry={entry}
                    selectedDay={selectedDay}
                    selectedDate={selectedDate}
                    onCancel={() =>
                        router.visit(
                            entry
                                ? url(`/schedule-entries/${entry.id}`)
                                : url("/calendar"),
                        )
                    }
                />
            </div>
        </>
    );
}

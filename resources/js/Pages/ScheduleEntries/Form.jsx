import { Head, router } from "@inertiajs/react";
import { Breadcrumbs, PageHeader } from "../../Components/UI";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
export default function Form({ plans, entry, selectedDay, selectedDate }) {
    return (
        <>
            <Head title={entry ? "Edit session" : "Schedule session"} />
            <Breadcrumbs
                items={[
                    { label: "Calendar", href: "/calendar" },
                    { label: entry ? "Edit session" : "Schedule session" },
                ]}
            />
            <PageHeader
                title={entry ? "Edit your session" : "Schedule a session"}
                description="A small commitment to yourself."
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
                                ? `/schedule-entries/${entry.id}`
                                : "/calendar",
                        )
                    }
                />
            </div>
        </>
    );
}

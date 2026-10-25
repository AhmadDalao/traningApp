import { useRef, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import listPlugin from "@fullcalendar/list";
import interactionPlugin from "@fullcalendar/interaction";
import {
    Plus,
    ChevronLeft,
    ChevronRight,
    Check,
    Minus,
    Coffee,
} from "lucide-react";
import { PageHeader, Button, Modal } from "../../Components/UI";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
export default function Index({ entries, plans, calendarDate, calendarView }) {
    const { today } = usePage().props;
    const ref = useRef(null);
    const [title, setTitle] = useState("");
    const [view, setView] = useState(calendarView);
    const [filter, setFilter] = useState("all");
    const [schedule, setSchedule] = useState(null);
    const [loading, setLoading] = useState(false);
    function navigate(action, value) {
        const api = ref.current.getApi();
        if (action === "view") api.changeView(value);
        else if (action === "today") api.gotoDate(today);
        else api[action]();
        const date = api.getDate();
        const dateString = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
        setView(api.view.type);
        setLoading(true);
        router.get(
            "/calendar",
            { date: dateString, view: api.view.type },
            {
                only: ["entries", "calendarDate", "calendarView"],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setLoading(false),
            },
        );
    }
    const visible = entries.filter(
        (e) =>
            filter === "all" || e.entry_type === filter || e.status === filter,
    );
    const events = visible.map((e) => ({
        id: String(e.id),
        title: e.title,
        url: `/schedule-entries/${e.id}`,
        start: e.scheduled_date,
        allDay: true,
        classNames: [`event-${e.entry_type}`, `event-${e.status}`],
        extendedProps: { status: e.status, type: e.entry_type },
    }));
    return (
        <>
            <Head title="Calendar" />
            <PageHeader
                eyebrow="MAKE TIME FOR YOURSELF"
                title="Your training calendar"
                description="A little structure for your strongest weeks."
            >
                <Button onClick={() => setSchedule({ date: today })}>
                    <Plus size={17} />
                    Schedule session
                </Button>
            </PageHeader>
            <section
                className={`panel calendar-panel ${loading ? "calendar-loading" : ""}`}
                aria-busy={loading}
            >
                <div className="calendar-toolbar">
                    <div className="calendar-date-controls">
                        <div className="calendar-arrows">
                            <button
                                className="icon-btn"
                                aria-label="Previous period"
                                disabled={loading}
                                onClick={() => navigate("prev")}
                            >
                                <ChevronLeft size={19} />
                            </button>
                            <button
                                className="icon-btn"
                                aria-label="Next period"
                                disabled={loading}
                                onClick={() => navigate("next")}
                            >
                                <ChevronRight size={19} />
                            </button>
                        </div>
                        <h2>{title}</h2>
                        <Button
                            variant="secondary"
                            className="small"
                            disabled={loading}
                            onClick={() => navigate("today")}
                        >
                            Today
                        </Button>
                    </div>
                    <div className="segmented">
                        {[
                            ["dayGridMonth", "Month"],
                            ["dayGridWeek", "Week"],
                            ["listMonth", "List"],
                        ].map(([id, label]) => (
                            <button
                                key={id}
                                className={view === id ? "active" : ""}
                                disabled={loading}
                                onClick={() => navigate("view", id)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="calendar-filters" aria-label="Calendar filters">
                    {[
                        "all",
                        "training",
                        "rest",
                        "planned",
                        "completed",
                        "skipped",
                    ].map((f) => (
                        <button
                            key={f}
                            aria-pressed={filter === f}
                            onClick={() => setFilter(f)}
                            className={`filter-chip ${filter === f ? "active" : ""}`}
                        >
                            {f !== "all" && (
                                <span className={`filter-dot dot-${f}`} />
                            )}
                            {
                                {
                                    all: "All sessions",
                                    training: "Training",
                                    rest: "Rest days",
                                    planned: "Planned",
                                    completed: "Completed",
                                    skipped: "Skipped",
                                }[f]
                            }
                        </button>
                    ))}
                </div>
                <div className="calendar-container">
                    <FullCalendar
                        ref={ref}
                        plugins={[dayGridPlugin, listPlugin, interactionPlugin]}
                        initialView={calendarView}
                        initialDate={calendarDate}
                        headerToolbar={false}
                        now={today}
                        height="auto"
                        firstDay={0}
                        fixedWeekCount={false}
                        dayMaxEvents={3}
                        events={events}
                        editable={false}
                        selectable={false}
                        datesSet={(info) => setTitle(info.view.title)}
                        dateClick={(info) =>
                            setSchedule({ date: info.dateStr })
                        }
                        eventClick={(info) => {
                            info.jsEvent.preventDefault();
                            router.visit(`/schedule-entries/${info.event.id}`);
                        }}
                        eventContent={({ event }) => (
                            <div
                                className="calendar-event-content"
                                title={`${event.title} · ${event.extendedProps.status}`}
                            >
                                {event.extendedProps.status === "completed" ? (
                                    <Check size={12} />
                                ) : event.extendedProps.status === "skipped" ? (
                                    <Minus size={12} />
                                ) : event.extendedProps.type === "rest" ? (
                                    <Coffee size={12} />
                                ) : (
                                    <span className="event-dot" />
                                )}
                                <span>{event.title}</span>
                            </div>
                        )}
                        noEventsContent="No sessions for this period. Click a date to make a plan."
                    />
                </div>
            </section>
            <p className="below-note">
                Click a date to schedule. Open a session to view exercises or
                change its status.
            </p>
            {schedule && (
                <Modal
                    title="Schedule a session"
                    onClose={() => setSchedule(null)}
                >
                    <ScheduleEntryForm
                        plans={plans}
                        selectedDate={schedule.date}
                        onSaved={() => setSchedule(null)}
                        onCancel={() => setSchedule(null)}
                    />
                </Modal>
            )}
        </>
    );
}

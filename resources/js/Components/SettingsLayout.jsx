import { Link, usePage } from "@inertiajs/react";
import {
    ArrowLeft,
    ChevronRight,
    UserRound,
    Dumbbell,
    Bell,
    Smartphone,
    Users,
    CalendarDays,
    Layers,
} from "lucide-react";
import SettingsNav from "./SettingsNav";

const sections = [
    [
        "profile",
        "Coach profile",
        "Your coaching identity and bio",
        "/coach/settings?section=profile",
        UserRound,
    ],
    [
        "account",
        "Training preferences",
        "Account details, goals and equipment",
        "/coach/settings/account",
        Dumbbell,
    ],
    [
        "notifications",
        "Notifications",
        "Workout reminders and coach updates",
        "/coach/settings/notifications",
        Bell,
    ],
    [
        "devices",
        "App & devices",
        "Install the app and manage this device",
        "/coach/settings/notifications?section=devices",
        Smartphone,
    ],
];
export default function SettingsLayout({ section = "profile", children }) {
    const { props, url } = usePage();
    const coach = props.trainingContext?.workspace === "coach";
    if (!coach)
        return (
            <>
                <SettingsNav section={section} />
                {children}
            </>
        );
    const summary = props.settingsSummary;
    const landing = section === "profile" && !url.includes("section=profile");
    return (
        <div
            className={`coach-settings ${landing ? "settings-landing" : "settings-detail"}`}
        >
            <header className="settings-page-title">
                <span className="eyebrow">SETTINGS</span>
                <h1>Profile & Settings</h1>
                <p>
                    Manage your coaching profile, training preferences,
                    notifications and app access.
                </p>
            </header>
            {summary && (
                <section className="panel settings-hero">
                    <div className="settings-hero-identity">
                        <span className="settings-avatar">
                            {(
                                summary.profile?.display_name ||
                                props.auth.user.name
                            )
                                .slice(0, 1)
                                .toUpperCase()}
                        </span>
                        <div>
                            <h2>
                                {summary.profile?.display_name ||
                                    props.auth.user.name}
                            </h2>
                            <p>
                                Coach
                                {summary.profile?.specialization
                                    ? ` · ${summary.profile.specialization}`
                                    : ""}
                            </p>
                            <span className="badge completed">
                                Coach workspace
                            </span>
                        </div>
                    </div>
                    <div className="settings-hero-stats">
                        {[
                            [summary.trainees, "Connected trainees", Users],
                            [summary.workouts, "Workouts today", CalendarDays],
                            [summary.programs, "Programs", Layers],
                        ].map(([count, label, Icon]) => (
                            <div key={label}>
                                <Icon size={21} />
                                <strong>{count}</strong>
                                <span>{label}</span>
                            </div>
                        ))}
                    </div>
                </section>
            )}
            <div className="settings-layout">
                <nav
                    className="settings-section-nav panel"
                    aria-label="Settings sections"
                >
                    <h2>Settings</h2>
                    <p>Manage your account and preferences.</p>
                    {sections.map(([key, label, hint, href, Icon]) => (
                        <Link
                            href={href}
                            key={key}
                            className={section === key ? "active" : ""}
                            aria-current={section === key ? "page" : undefined}
                        >
                            <Icon size={23} />
                            <span>
                                <strong>{label}</strong>
                                <small>{hint}</small>
                            </span>
                            <ChevronRight size={17} />
                        </Link>
                    ))}
                </nav>
                <div className="settings-content">
                    <Link className="settings-back" href="/coach/settings">
                        <ArrowLeft size={18} />
                        Back to settings
                    </Link>
                    {children}
                </div>
            </div>
        </div>
    );
}

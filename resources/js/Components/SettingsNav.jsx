import { Link, usePage } from "@inertiajs/react";
import { ArrowLeft, Bell, UserRound, Settings } from "lucide-react";
import { Breadcrumbs } from "./UI";

export default function SettingsNav({ section = "profile" }) {
    const { trainingContext } = usePage().props;
    const coach = trainingContext?.workspace === "coach";
    const base = coach ? "/coach/settings" : "/profile";
    const tabs = [
        ["profile", coach ? "Coach profile" : "Profile", base, Settings],
        [
            "account",
            "Account & training preferences",
            coach ? `${base}/account` : "/profile/edit",
            UserRound,
        ],
        ["notifications", "Notifications & app", `${base}/notifications`, Bell],
    ];
    return (
        <div className="settings-navigation">
            <Breadcrumbs
                items={[
                    {
                        label: "Overview",
                        href: coach ? "/coach" : "/dashboard",
                    },
                    { label: "Settings", href: base },
                    { label: tabs.find((t) => t[0] === section)?.[1] },
                ]}
            />
            <Link
                className="settings-back"
                href={
                    section === "profile"
                        ? coach
                            ? "/coach"
                            : "/dashboard"
                        : base
                }
            >
                <ArrowLeft size={16} />
                {section === "profile"
                    ? "Back to overview"
                    : "Back to settings"}
            </Link>
            <nav className="settings-tabs" aria-label="Settings sections">
                {tabs.map(([key, label, href, Icon]) => (
                    <Link
                        key={key}
                        href={href}
                        className={key === section ? "active" : ""}
                        aria-current={key === section ? "page" : undefined}
                    >
                        <Icon size={18} />
                        {label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}

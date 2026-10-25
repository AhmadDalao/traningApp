import { useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
    Dumbbell,
    LayoutDashboard,
    CalendarDays,
    Layers,
    LogOut,
    Menu,
    X,
    CheckCircle2,
} from "lucide-react";
export default function AppLayout({ children }) {
    const { auth, flash } = usePage().props;
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const nav = [
        {
            href: "/dashboard",
            label: "Overview",
            icon: LayoutDashboard,
            active: url.startsWith("/dashboard"),
        },
        {
            href: "/training-plans",
            label: "Training plans",
            icon: Layers,
            active: url.startsWith("/training-"),
        },
        {
            href: "/calendar",
            label: "Calendar",
            icon: CalendarDays,
            active: url.startsWith("/calendar") || url.startsWith("/schedule-"),
        },
    ];
    return (
        <div className="app">
            <a className="skip-link" href="#main">
                Skip to content
            </a>
            <div className="mobile-top">
                <Link href="/dashboard" className="brand">
                    <Dumbbell />
                    Training Planner
                </Link>
                <button
                    className="icon-btn"
                    aria-label="Toggle menu"
                    aria-expanded={open}
                    onClick={() => setOpen(!open)}
                >
                    {open ? <X /> : <Menu />}
                </button>
            </div>
            {open && (
                <div className="nav-backdrop" onClick={() => setOpen(false)} />
            )}
            <aside className={`sidebar ${open ? "open" : ""}`}>
                <Link href="/dashboard" className="brand">
                    <span className="brand-mark">
                        <Dumbbell size={23} />
                    </span>
                    <span>
                        Training<span className="brand-second">Planner</span>
                    </span>
                </Link>
                <div className="nav-label">YOUR WORKSPACE</div>
                <nav>
                    {nav.map(({ href, label, icon: Icon, active }) => (
                        <Link
                            key={href}
                            href={href}
                            className={`nav-item ${active ? "active" : ""}`}
                            onClick={() => setOpen(false)}
                        >
                            <Icon size={19} />
                            {label}
                            {active && <span className="nav-dot" />}
                        </Link>
                    ))}
                </nav>
                <div className="sidebar-bottom">
                    <div className="sidebar-note">
                        <span className="small-line" />
                        <p>
                            A little progress.
                            <br />
                            Every single day.
                        </p>
                    </div>
                    <div className="user-block">
                        <div className="avatar">
                            {auth.user.name.slice(0, 1).toUpperCase()}
                        </div>
                        <div>
                            <strong>{auth.user.name}</strong>
                            <span>Personal workspace</span>
                        </div>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="nav-item logout"
                    >
                        <LogOut size={18} />
                        Log out
                    </Link>
                </div>
            </aside>
            <div className="workspace">
                <div className="topbar">
                    <span>YOUR PERSONAL TRAINING SPACE</span>
                    <div className="topbar-right">
                        <span className="live-dot" />
                        Make time for yourself
                        <div className="avatar small">
                            {auth.user.name.slice(0, 1).toUpperCase()}
                        </div>
                    </div>
                </div>
                <main id="main">
                    {flash.success && (
                        <div
                            key={`${url}-${flash.success}`}
                            className="flash"
                            role="status"
                        >
                            <CheckCircle2 size={18} />
                            {flash.success}
                        </div>
                    )}
                    {children}
                </main>
                <footer className="app-footer">
                    Training Planner <span>Plan. Train. Track.</span>
                </footer>
            </div>
        </div>
    );
}

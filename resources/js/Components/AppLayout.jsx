import PwaStatus from "../Features/Notifications/PwaStatus";
import { useEffect, useState } from "react";
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
    History,
    TrendingUp,
    UserRound,
    ChevronDown,
    Users,
    BriefcaseBusiness,
} from "lucide-react";
import ScheduleReviewNotice from "../Features/ScheduleReviewNotice";
import { TraineeNav } from "../Features/CoachingUI";
export default function AppLayout({ children }) {
    const { auth, flash, trainingContext } = usePage().props;
    const { url } = usePage();
    const [open, setOpen] = useState(false);
    const [userOpen, setUserOpen] = useState(false);
    const closeNavigation = () => {
        setUserOpen(false);
        setOpen(false);
    };
    useEffect(() => {
        setUserOpen(false);
        setOpen(false);
    }, [url]);
    const coach = trainingContext?.workspace === "coach";
    const home = coach ? "/coach" : "/dashboard";
    const nav = coach
        ? [
              {
                  href: "/coach",
                  label: "Coach Overview",
                  icon: LayoutDashboard,
                  active: url === "/coach",
              },
              {
                  href: "/coach/trainees",
                  label: "Trainees",
                  icon: Users,
                  active: url.startsWith("/coach/trainees"),
              },
              {
                  href: "/coach/training-plans",
                  label: "Coach Plans",
                  icon: Layers,
                  active:
                      url.startsWith("/coach/training-plans") ||
                      url.startsWith("/coach/training-days"),
              },
              {
                  href: "/coach/subscriptions",
                  label: "Subscriptions",
                  icon: BriefcaseBusiness,
                  active: url.startsWith("/coach/subscriptions"),
              },
          ]
        : [
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
                  active:
                      url.startsWith("/training-plans") ||
                      url.startsWith("/training-days"),
              },
              {
                  href: "/calendar",
                  label: "Calendar",
                  icon: CalendarDays,
                  active:
                      url.startsWith("/calendar") ||
                      url.startsWith("/schedule-"),
              },
              {
                  href: "/training-history",
                  label: "Training History",
                  icon: History,
                  active: url.startsWith("/training-history"),
              },
              {
                  href: "/progress",
                  label: "Progress",
                  icon: TrendingUp,
                  active: url.startsWith("/progress"),
              },
              {
                  href: "/my-coaching",
                  label: "My Coaching",
                  icon: Users,
                  active: url.startsWith("/my-coaching"),
              },
          ];
    return (
        <div className="app">
            <a className="skip-link" href="#main">
                Skip to content
            </a>
            <div className="mobile-top">
                <Link href={home} className="brand">
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
                <Link href={home} className="brand">
                    <span className="brand-mark">
                        <Dumbbell size={23} />
                    </span>
                    <span>
                        Training<span className="brand-second">Planner</span>
                    </span>
                </Link>
                <div className="nav-label">
                    {coach ? "COACH WORKSPACE" : "PERSONAL WORKSPACE"}
                </div>
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
                    <button
                        type="button"
                        className="user-block user-menu-toggle"
                        aria-expanded={userOpen}
                        aria-controls="user-menu"
                        onClick={() => setUserOpen(!userOpen)}
                    >
                        <div className="avatar">
                            {auth.user.name.slice(0, 1).toUpperCase()}
                        </div>
                        <div>
                            <strong>{auth.user.name}</strong>
                            <span>
                                {coach
                                    ? "Coach workspace"
                                    : "Personal workspace"}
                            </span>
                        </div>
                        <ChevronDown size={15} />
                    </button>
                    {userOpen && (
                        <div
                            id="user-menu"
                            className="user-menu"
                            onClick={(event) => {
                                if (event.target.closest("a, button"))
                                    closeNavigation();
                            }}
                        >
                            {auth.user.is_coach && (
                                <>
                                    <Link
                                        href="/workspace"
                                        method="post"
                                        data={{ workspace: "personal" }}
                                        as="button"
                                        className="nav-item"
                                    >
                                        <UserRound size={17} />
                                        Personal Workspace
                                    </Link>
                                    <Link
                                        href="/workspace"
                                        method="post"
                                        data={{ workspace: "coach" }}
                                        as="button"
                                        className="nav-item"
                                    >
                                        <Users size={17} />
                                        Coach Workspace
                                    </Link>
                                    <Link
                                        href="/profile/coach"
                                        className="nav-item"
                                    >
                                        Coach profile
                                    </Link>
                                </>
                            )}
                            <Link href="/notifications" className="nav-item">
                                Notifications & install
                            </Link>
                            <Link href="/profile" className="nav-item">
                                <UserRound size={17} />
                                Profile
                            </Link>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="nav-item"
                            >
                                <LogOut size={17} />
                                Log out
                            </Link>
                        </div>
                    )}
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
                    <span>
                        {coach
                            ? "YOUR COACHING WORKSPACE"
                            : "YOUR PERSONAL TRAINING SPACE"}
                    </span>
                    <div className="topbar-right">
                        <span className="live-dot" />
                        Make time for yourself
                        <Link
                            href="/profile"
                            className="avatar small"
                            aria-label="Open profile"
                        >
                            {auth.user.name.slice(0, 1).toUpperCase()}
                        </Link>
                    </div>
                </div>
                <main id="main">
                    <PwaStatus />
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
                    <ScheduleReviewNotice plan={flash.schedule_review} />
                    <TraineeNav />
                    {children}
                </main>
                <footer className="app-footer">
                    Training Planner <span>Plan. Train. Track.</span>
                </footer>
            </div>
        </div>
    );
}

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
    Bell,
    Settings,
    CreditCard,
    ChevronRight,
    Smartphone,
    Check,
    Search,
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
    useEffect(() => {
        if (!userOpen && !open) return;
        const escape = (event) => {
            if (event.key === "Escape") closeNavigation();
        };
        const outside = (event) => {
            if (!event.target.closest(".sidebar-bottom")) setUserOpen(false);
        };
        document.addEventListener("keydown", escape);
        document.addEventListener("pointerdown", outside);
        return () => {
            document.removeEventListener("keydown", escape);
            document.removeEventListener("pointerdown", outside);
        };
    }, [userOpen, open]);
    const coach = trainingContext?.workspace === "coach";
    const personalDashboard = !coach && url.split("?")[0] === "/dashboard";
    const referenceStyle = coach || personalDashboard;
    const home = coach ? "/coach" : "/dashboard";
    const settings = coach ? "/coach/settings" : "/profile";
    const notifications = coach
        ? "/coach/settings?section=notifications"
        : "/profile/notifications";
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
                  label: "Programs",
                  icon: Layers,
                  active:
                      url.startsWith("/coach/training-plans") ||
                      url.startsWith("/coach/training-days"),
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
    const currentLabel =
        url.startsWith("/coach/settings") ||
        url.startsWith("/coach/notifications")
            ? "Profile & Settings"
            : url.startsWith("/coach/subscriptions")
              ? "Subscriptions"
              : nav.find((item) => item.active)?.label || "Coaching";
    const mobileNav = coach
        ? [
              ...nav,
              {
                  href: "/coach/subscriptions",
                  label: "Subs",
                  icon: CreditCard,
                  active: url.startsWith("/coach/subscriptions"),
              },
              {
                  href: settings,
                  label: "Settings",
                  icon: Settings,
                  active:
                      url.startsWith(settings) ||
                      url.startsWith("/coach/notifications"),
              },
          ]
        : [
              {
                  href: "/dashboard",
                  label: "Overview",
                  icon: LayoutDashboard,
                  active: true,
              },
              { href: "/training-plans", label: "Plans", icon: Layers },
              { href: "/calendar", label: "Calendar", icon: CalendarDays },
              { href: "/progress", label: "Progress", icon: TrendingUp },
              { href: "/profile", label: "Settings", icon: Settings },
          ];
    return (
        <div
            className={`app journey-app ${coach ? "coach-workspace" : "personal-workspace"} ${referenceStyle ? "reference-workspace" : ""} ${personalDashboard ? "personal-dashboard-workspace" : ""}`}
        >
            <a className="skip-link" href="#main">
                Skip to content
            </a>
            <div className="mobile-top">
                <button
                    className="icon-btn"
                    aria-label="Toggle menu"
                    aria-expanded={open}
                    onClick={() => setOpen(!open)}
                >
                    {open ? <X /> : <Menu />}
                </button>
                <Link href={home} className="brand">
                    <span className="brand-mark">
                        <Dumbbell size={24} />
                    </span>
                    <span>
                        Training<span className="brand-second">Planner</span>
                    </span>
                </Link>
                {referenceStyle && (
                    <Link
                        className="icon-btn"
                        href={notifications}
                        aria-label="Notification settings"
                    >
                        <Bell size={23} />
                    </Link>
                )}
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
                <nav aria-label="Main navigation">
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
                    {coach && (
                        <Link
                            href="/coach/subscriptions"
                            className={`nav-item ${url.startsWith("/coach/subscriptions") ? "active" : ""}`}
                            onClick={closeNavigation}
                        >
                            <CreditCard size={19} />
                            Subscriptions
                        </Link>
                    )}
                    <Link
                        href={settings}
                        className={`nav-item ${url.startsWith(settings) || url === "/coach/notifications" || url === "/notifications" ? "active" : ""}`}
                        onClick={closeNavigation}
                    >
                        <Settings size={19} aria-hidden="true" />{" "}
                        {coach ? "Profile & Settings" : "Profile & settings"}
                    </Link>
                </nav>
                <div className="sidebar-bottom">
                    <div className="sidebar-note">
                        <strong>
                            {coach
                                ? "Make every session count."
                                : "A little progress."}
                        </strong>
                        <p>
                            {coach
                                ? "Plan training, monitor progress and keep your trainees moving forward."
                                : "Every single day."}
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
                            {referenceStyle && (
                                <div className="mobile-switch-heading">
                                    <strong>
                                        {auth.user.is_coach
                                            ? "Switch workspace"
                                            : "Your account"}
                                    </strong>
                                    <button
                                        className="icon-btn"
                                        aria-label="Close workspace switcher"
                                        onClick={() => setUserOpen(false)}
                                    >
                                        <X size={22} />
                                    </button>
                                </div>
                            )}
                            {auth.user.is_coach && (
                                <>
                                    <span className="menu-heading">
                                        Switch workspace
                                    </span>
                                    <Link
                                        href="/workspace"
                                        method="post"
                                        data={{ workspace: "personal" }}
                                        as="button"
                                        className={`nav-item ${!coach ? "selected" : ""}`}
                                    >
                                        <UserRound size={17} />
                                        Personal Workspace{" "}
                                        {!coach && <Check size={16} />}
                                    </Link>
                                    <Link
                                        href="/workspace"
                                        method="post"
                                        data={{ workspace: "coach" }}
                                        as="button"
                                        className={`nav-item ${coach ? "selected" : ""}`}
                                    >
                                        <Users size={17} />
                                        Coach Workspace{" "}
                                        {coach && <Check size={16} />}
                                    </Link>
                                    <span className="menu-heading">
                                        Coaching
                                    </span>
                                    <Link
                                        href="/coach/settings?section=profile"
                                        className="nav-item"
                                    >
                                        <UserRound size={17} />
                                        Coach profile
                                    </Link>
                                </>
                            )}
                            {coach && (
                                <Link
                                    href="/coach/subscriptions"
                                    onClick={closeNavigation}
                                    className="nav-item"
                                >
                                    <Layers size={17} />
                                    Coaching administration
                                </Link>
                            )}
                            <Link href={notifications} className="nav-item">
                                <Bell size={17} />
                                Notifications & install
                            </Link>
                            <span className="menu-heading">Account</span>
                            <Link
                                href={
                                    coach
                                        ? "/coach/settings/account"
                                        : "/profile/edit"
                                }
                                className="nav-item"
                            >
                                <UserRound size={17} />
                                Account profile
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
                    {referenceStyle ? (
                        <nav
                            className="topbar-crumb"
                            aria-label="Workspace location"
                        >
                            <Link href={home}>
                                {coach ? "Coaching" : "Personal"}
                            </Link>
                            <ChevronRight size={15} />
                            <strong>{coach ? currentLabel : "Overview"}</strong>
                        </nav>
                    ) : (
                        <span>YOUR PERSONAL TRAINING SPACE</span>
                    )}
                    <div className="topbar-right">
                        {coach && (
                            <>
                                <Link
                                    href="/coach/trainees"
                                    className="topbar-team"
                                >
                                    Your trainees <ChevronDown size={16} />
                                </Link>
                                <Link
                                    href="/coach/trainees?focus=search"
                                    className="icon-btn"
                                    aria-label="Search trainees"
                                >
                                    <Search size={20} />
                                </Link>
                            </>
                        )}
                        {referenceStyle ? (
                            <Link
                                href={notifications}
                                className="icon-btn"
                                aria-label="Notification settings"
                            >
                                <Bell size={20} />
                            </Link>
                        ) : (
                            <>
                                <span className="live-dot" />
                                Make time for yourself
                            </>
                        )}
                        <Link
                            href={settings}
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
                {referenceStyle && (
                    <nav
                        className={
                            coach ? "coach-bottom-nav" : "personal-bottom-nav"
                        }
                        aria-label="Mobile navigation"
                    >
                        {mobileNav.map(
                            ({ href, label, icon: Icon, active }) => (
                                <Link
                                    key={href}
                                    href={href}
                                    className={active ? "active" : ""}
                                    aria-current={active ? "page" : undefined}
                                >
                                    <Icon size={21} />
                                    <span>
                                        {label === "Coach Overview"
                                            ? "Overview"
                                            : label}
                                    </span>
                                </Link>
                            ),
                        )}
                    </nav>
                )}
                <footer className="app-footer">
                    Training Planner <span>Plan. Train. Track.</span>
                </footer>
            </div>
        </div>
    );
}

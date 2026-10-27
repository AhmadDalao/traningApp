import { useEffect, useRef, useState } from "react";
import { Link, router } from "@inertiajs/react";
import {
    X,
    CalendarDays,
    Check,
    Circle,
    Minus,
    Trash2,
    ArrowRight,
} from "lucide-react";
import TrainingIcon from "./TrainingIcon";

export function Button({
    children,
    className = "",
    variant = "primary",
    ...props
}) {
    return (
        <button className={`btn btn-${variant} ${className}`} {...props}>
            {children}
        </button>
    );
}
export function ButtonLink({
    children,
    href,
    variant = "primary",
    className = "",
    ...props
}) {
    return (
        <Link
            href={href}
            className={`btn btn-${variant} ${className}`}
            {...props}
        >
            {children}
        </Link>
    );
}
export function Field({
    label,
    error,
    children,
    hint,
    required,
    className = "",
}) {
    return (
        <label className={`field ${className}`}>
            <span className="field-label">
                {label}
                {required && <span className="required"> *</span>}
            </span>
            {children}
            {hint && <span className="field-hint">{hint}</span>}
            {error && (
                <span className="field-error" role="alert">
                    {error}
                </span>
            )}
        </label>
    );
}
export function StatusBadge({ status, type, active = false }) {
    if (active)
        return <span className="badge workout-active">In progress</span>;
    const Icon =
        status === "completed" ? Check : status === "skipped" ? Minus : Circle;
    return (
        <span className={`badge ${status}`}>
            <Icon size={11} />
            {status === "completed"
                ? "Completed"
                : status === "skipped"
                  ? "Skipped"
                  : "Planned"}
        </span>
    );
}
export function TypeBadge({ type }) {
    return (
        <span className={`badge ${type}`}>
            {type === "rest" ? "Rest day" : "Training"}
        </span>
    );
}
export function DayIcon({ icon, type, focus, large = false }) {
    return (
        <div
            className={`day-icon ${type === "rest" ? "rest-icon" : ""} ${large ? "large" : ""}`}
        >
            <TrainingIcon
                icon={icon}
                type={type}
                focus={focus}
                size={large ? 28 : 21}
            />
        </div>
    );
}
export function PageHeader({ eyebrow, title, description, children }) {
    return (
        <header className="page-header">
            <div>
                {eyebrow && <div className="eyebrow">{eyebrow}</div>}
                <h1>{title}</h1>
                {description && <p>{description}</p>}
            </div>
            {children && <div className="header-actions">{children}</div>}
        </header>
    );
}
export function Breadcrumbs({ items }) {
    return (
        <nav className="breadcrumbs" aria-label="Breadcrumb">
            {items.map((item, i) => (
                <span key={i}>
                    {i > 0 && <span className="crumb-separator">/</span>}
                    {item.href ? (
                        <Link href={item.href}>{item.label}</Link>
                    ) : (
                        item.label
                    )}
                </span>
            ))}
        </nav>
    );
}
export function EmptyState({ title, description, children, compact = false }) {
    return (
        <div className={`empty-state ${compact ? "compact" : ""}`}>
            <div className="empty-icon">
                <CalendarDays size={28} />
            </div>
            <h3>{title}</h3>
            <p>{description}</p>
            {children}
        </div>
    );
}
export function Modal({ title, description, children, onClose, wide = false }) {
    const ref = useRef(null);
    useEffect(() => {
        const dialog = ref.current;
        dialog.showModal();
        return () => dialog.close();
    }, []);
    return (
        <dialog
            ref={ref}
            className={`modal ${wide ? "wide" : ""}`}
            onCancel={(e) => {
                e.preventDefault();
                onClose();
            }}
            onClick={(e) => {
                if (e.target === ref.current) onClose();
            }}
            aria-label={title}
        >
            <div className="modal-inner">
                <div className="modal-header">
                    <div>
                        <h2>{title}</h2>
                        {description && <p>{description}</p>}
                    </div>
                    <button
                        type="button"
                        className="icon-btn"
                        onClick={onClose}
                        aria-label="Close dialog"
                    >
                        <X size={20} />
                    </button>
                </div>
                {children}
            </div>
        </dialog>
    );
}
export function ConfirmDialog({
    title,
    description,
    url,
    onClose,
    method = "delete",
    data = {},
    confirmLabel = "Delete",
    cancelLabel = "Cancel",
    variant = "danger",
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    return (
        <Modal title={title} onClose={() => !busy && onClose()}>
            <div
                className={`delete-icon ${variant === "primary" ? "confirm-primary" : ""}`}
            >
                {variant === "danger" ? (
                    <Trash2 size={25} />
                ) : (
                    <Check size={25} />
                )}
            </div>
            <p className="confirm-copy">{description}</p>
            {error && <p className="field-error">{error}</p>}
            <div className="form-actions confirm-actions">
                <Button variant="secondary" onClick={onClose} disabled={busy}>
                    {cancelLabel}
                </Button>
                <Button
                    variant={variant}
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        router.visit(url, {
                            method,
                            data,
                            preserveState: true,
                            preserveScroll: true,
                            onSuccess: onClose,
                            onError: (errors) =>
                                setError(
                                    Object.values(errors)[0] ||
                                        "Could not save this change. Please try again.",
                                ),
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    {busy ? "Saving…" : confirmLabel}
                </Button>
            </div>
        </Modal>
    );
}
export function DateLabel({ date, long = false }) {
    return formatDate(date, long);
}
export function formatWeight(value) {
    return value === null || value === undefined || value === ""
        ? "—"
        : `${Number(value).toLocaleString("en-GB", { maximumFractionDigits: 2 })} kg`;
}
export function TimeLabel({ time }) {
    return time ? time.slice(0, 5) : null;
}
export function formatDate(date, long = false) {
    return new Date(`${date.slice(0, 10)}T12:00:00`).toLocaleDateString(
        "en-GB",
        long
            ? {
                  weekday: "long",
                  day: "numeric",
                  month: "long",
                  year: "numeric",
              }
            : { weekday: "short", day: "numeric", month: "short" },
    );
}
export function Pagination({ links }) {
    return (
        links?.length > 3 && (
            <nav className="pagination" aria-label="Pagination">
                {links.map((link, i) =>
                    link.url ? (
                        <Link
                            key={i}
                            className={link.active ? "active" : ""}
                            href={link.url}
                        >
                            {link.label
                                .replace(/&laquo;/g, "←")
                                .replace(/&raquo;/g, "→")}
                        </Link>
                    ) : (
                        <span key={i}>
                            {link.label
                                .replace(/&laquo;/g, "←")
                                .replace(/&raquo;/g, "→")}
                        </span>
                    ),
                )}
            </nav>
        )
    );
}
export function TextLink({ href, children }) {
    return (
        <Link href={href} className="text-link">
            {children}
            <ArrowRight size={14} />
        </Link>
    );
}

import { useEffect, useState } from "react";
import { Head, usePage, useForm, Link } from "@inertiajs/react";
import { QRCodeSVG } from "qrcode.react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Modal,
    DateLabel,
} from "../../Components/UI";
import {
    CoachStatus,
    EndRelationship,
    Errors,
    coachingJson,
    consentText,
} from "../../Features/CoachingUI";
function Connection({ relationship: row }) {
    const form = useForm({ action: "accept" });
    return (
        <section className="panel coach-card">
            <div className="coach-card-title">
                <h2>{row.coach?.display_name || "Coach"}</h2>
                <CoachStatus status={row.status} />
            </div>
            {row.coach?.specialization && <p>{row.coach.specialization}</p>}
            {row.status === "pending" ? (
                <>
                    <p>{consentText}</p>
                    <Errors errors={form.errors} />
                    <div className="coach-actions">
                        <Button
                            disabled={form.processing}
                            onClick={() => {
                                form.transform(() => ({ action: "accept" }));
                                form.post(
                                    `/my-coaching/relationships/${row.id}`,
                                );
                            }}
                        >
                            Accept coach
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={form.processing}
                            onClick={() => {
                                form.transform(() => ({ action: "decline" }));
                                form.post(
                                    `/my-coaching/relationships/${row.id}`,
                                );
                            }}
                        >
                            Decline
                        </Button>
                    </div>
                </>
            ) : (
                <>
                    {row.subscriptions.map((sub) => (
                        <div className="coach-subscription" key={sub.id}>
                            <strong>{sub.package_name}</strong>
                            <CoachStatus status={sub.effective_status} />
                            <p>
                                <DateLabel date={sub.starts_on} /> —{" "}
                                {sub.ends_on ? (
                                    <DateLabel date={sub.ends_on} />
                                ) : (
                                    "Open-ended"
                                )}
                            </p>
                        </div>
                    ))}
                    {row.plans.map((plan) => (
                        <Link
                            className="coach-plan-link"
                            key={plan.id}
                            href={`/training-plans/${plan.id}`}
                        >
                            {plan.name} →
                        </Link>
                    ))}
                    {row.status === "active" && (
                        <EndRelationship relationship={row} />
                    )}
                </>
            )}
        </section>
    );
}
export default function Coaching({ relationships }) {
    const { auth } = usePage().props;
    const [qr, setQr] = useState(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [copied, setCopied] = useState(false),
        [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!qr) return;
        const id = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(id);
    }, [qr]);
    async function showQr() {
        setBusy(true);
        setError("");
        try {
            setQr(await coachingJson("/my-coaching/qr"));
            setNow(Date.now());
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    async function copy(value) {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            setError("Copy the displayed ID or link manually.");
        }
    }
    const valid = qr && new Date(qr.expires_at).getTime() > now;
    return (
        <>
            <Head title="My Coaching" />
            <PageHeader
                title="My Coaching"
                description="Choose who can support your training. You keep control of your data."
            >
                <ButtonLink variant="secondary" href="/profile/coach">
                    {auth.user.is_coach
                        ? "Coach profile"
                        : "Enable Coach Workspace"}
                </ButtonLink>
            </PageHeader>
            <section className="panel coach-card">
                <h2>Your Training Planner ID</h2>
                <div className="coach-actions">
                    <code className="training-code">
                        {auth.user.training_code}
                    </code>
                    <Button
                        variant="secondary"
                        onClick={() => copy(auth.user.training_code)}
                    >
                        {copied ? "Copied" : "Copy ID"}
                    </Button>
                    <Button disabled={busy} onClick={showQr}>
                        {busy ? "Creating…" : "Show Coach QR"}
                    </Button>
                </div>
                <p>
                    Share your ID or temporary QR with a coach. They must send a
                    request, and you decide whether to accept.
                </p>
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
            </section>
            <div className="coach-grid">
                {relationships.map((row) => (
                    <Connection relationship={row} key={row.id} />
                ))}
            </div>
            {!relationships.length && (
                <section className="panel coach-card">
                    <h2>No coaching connections yet</h2>
                    <p>
                        Share your ID when you are ready. Your personal training
                        works without a coach.
                    </p>
                </section>
            )}
            {qr && (
                <Modal title="Connect with a coach" onClose={() => setQr(null)}>
                    <div className="coach-qr">
                        {valid ? (
                            <QRCodeSVG
                                value={qr.url}
                                size={240}
                                marginSize={4}
                                title="Temporary coaching request link"
                            />
                        ) : (
                            <p>This QR has expired. Generate a new one.</p>
                        )}
                        <p>
                            Single use ·{" "}
                            {valid
                                ? `${Math.ceil((new Date(qr.expires_at).getTime() - now) / 60000)} minutes remaining`
                                : "Expired"}
                        </p>
                        <p>
                            Scanning only opens a request. It never signs anyone
                            into your account.
                        </p>
                        {valid && (
                            <>
                                <input
                                    readOnly
                                    aria-label="Temporary coaching link"
                                    value={qr.url}
                                />
                                <Button
                                    variant="secondary"
                                    onClick={() => copy(qr.url)}
                                >
                                    Copy temporary link
                                </Button>
                            </>
                        )}
                        <Button onClick={showQr} disabled={busy}>
                            Generate new QR
                        </Button>
                    </div>
                </Modal>
            )}
        </>
    );
}

import { useEffect, useState, useSyncExternalStore } from "react";
import { Head } from "@inertiajs/react";
import { Bell, Smartphone } from "lucide-react";
import { Button, Field, PageHeader } from "../../Components/UI";
import InstallCard from "../../Features/Notifications/InstallCard";
import {
    pwaSnapshot,
    pushSupport,
    subscribePwa,
    vapidBytes,
} from "../../Features/Notifications/pwa";
import { coachingJson as jsonRequest } from "../../Features/CoachingUI";

export default function Index({
    preferences,
    deviceEnabled,
    deviceCount,
    pushConfigured,
    publicKey,
}) {
    const [settings, setSettings] = useState(preferences);
    const [enabled, setEnabled] = useState(deviceEnabled);
    const [count, setCount] = useState(deviceCount);
    const [permission, setPermission] = useState(
        () => window.Notification?.permission || "unsupported",
    );
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    const { online, registration, registrationError } = useSyncExternalStore(
        subscribePwa,
        pwaSnapshot,
    );
    const supported = pushSupport();
    useEffect(() => {
        const update = () =>
            setPermission(window.Notification?.permission || "unsupported");
        window.addEventListener("focus", update);
        return () => window.removeEventListener("focus", update);
    }, []);
    async function action(callback) {
        if (!online) {
            setError("Reconnect before changing notifications.");
            return;
        }
        setBusy(true);
        setError("");
        setMessage("");
        try {
            await callback();
        } catch (failure) {
            setError(failure.message || "Could not save. Please try again.");
        } finally {
            setBusy(false);
        }
    }
    async function enable() {
        // Request permission directly inside this user gesture, before any network await.
        const permissionRequest =
            Notification.permission === "default"
                ? Notification.requestPermission()
                : Promise.resolve(Notification.permission);
        await action(async () => {
            const result = await permissionRequest;
            setPermission(result);
            if (result !== "granted") {
                setMessage(
                    "Notifications remain off. You can change this in your browser settings.",
                );
                return;
            }
            const current = await registration.pushManager.getSubscription();
            // Explicit re-enable creates a clean association after logout/account switch or rotation.
            if (current) await current.unsubscribe();
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: vapidBytes(publicKey),
            });
            try {
                await jsonRequest("/notifications/subscription", {
                    endpoint: subscription.endpoint,
                    keys: subscription.toJSON().keys,
                });
            } catch (failure) {
                await subscription.unsubscribe().catch(() => {});
                throw failure;
            }
            if (!enabled) setCount((value) => value + 1);
            setEnabled(true);
            setMessage(
                "This device is enabled. Choose which notifications you want below.",
            );
        });
    }
    const status = !supported
        ? "Not supported in this browser"
        : permission === "denied"
          ? "Blocked by your browser"
          : enabled && permission === "granted"
            ? "Enabled on this device"
            : "Off on this device";
    return (
        <>
            <Head title="Notifications" />
            <PageHeader
                title="Notifications"
                description="Choose what reaches you. These settings apply to your account in both workspaces."
            />
            {error && (
                <p className="field-error notification-feedback" role="alert">
                    {error}
                </p>
            )}
            {message && (
                <p className="notification-feedback" role="status">
                    {message}
                </p>
            )}
            <div className="notification-grid">
                <div>
                    <section className="panel notification-panel">
                        <div className="notification-title">
                            <Smartphone size={22} />
                            <h2>This device</h2>
                        </div>
                        <strong className="notification-state">{status}</strong>
                        <p>
                            {count} {count === 1 ? "device is" : "devices are"}{" "}
                            enabled for this account. Each device must be
                            enabled separately.
                        </p>
                        <p>
                            Notifications can appear on your lock screen. We
                            keep them brief and leave personal training metrics
                            inside the app.
                        </p>
                        {!pushConfigured && (
                            <p className="notification-help">
                                Push delivery is not configured on this server
                                yet. You can save your preferences now.
                            </p>
                        )}
                        {!supported && (
                            <p className="notification-help">
                                You can keep using all training features. On
                                iPhone or iPad, install from Safari’s Share
                                menu, then open the app from your home screen.
                            </p>
                        )}
                        {permission === "denied" && (
                            <p className="notification-help">
                                Allow notifications in your browser or device
                                settings, then return here. We will not keep
                                asking.
                            </p>
                        )}
                        {registrationError && (
                            <p className="notification-help">
                                App setup could not finish. Reconnect and reload
                                to try again. Your training still works.
                            </p>
                        )}
                        <div className="notification-actions">
                            {(!enabled || permission !== "granted") && (
                                <Button
                                    disabled={
                                        busy ||
                                        !online ||
                                        !supported ||
                                        !pushConfigured ||
                                        !registration ||
                                        permission === "denied"
                                    }
                                    onClick={enable}
                                >
                                    <Bell size={17} />
                                    Enable on this device
                                </Button>
                            )}
                            {enabled && (
                                <Button
                                    variant="secondary"
                                    disabled={busy || !online}
                                    onClick={() =>
                                        action(async () => {
                                            await jsonRequest(
                                                "/notifications/subscription",
                                                {},
                                                "DELETE",
                                            );
                                            setEnabled(false);
                                            setCount((value) =>
                                                Math.max(0, value - 1),
                                            );
                                            const current =
                                                await registration?.pushManager.getSubscription();
                                            await current
                                                ?.unsubscribe()
                                                .catch(() => {});
                                            setMessage(
                                                "This device is disabled. Your account preferences are unchanged.",
                                            );
                                        })
                                    }
                                >
                                    Disable this device
                                </Button>
                            )}
                            <Button
                                variant="secondary"
                                disabled={
                                    busy ||
                                    !online ||
                                    !enabled ||
                                    permission !== "granted" ||
                                    !pushConfigured
                                }
                                onClick={() =>
                                    action(async () => {
                                        const result = await jsonRequest(
                                            "/notifications/test",
                                            {},
                                        );
                                        setMessage(result.message);
                                    })
                                }
                            >
                                Send test notification
                            </Button>
                        </div>
                        <p className="field-hint">
                            Logging out disconnects this device. Browser
                            permission may remain granted, but another account
                            must explicitly enable its own notifications.
                        </p>
                    </section>
                    <section className="panel notification-panel">
                        <div className="notification-title">
                            <Bell size={22} />
                            <h2>What you receive</h2>
                        </div>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                action(async () => {
                                    await jsonRequest(
                                        "/notifications/preferences",
                                        settings,
                                        "PUT",
                                    );
                                    setMessage(
                                        "Notification preferences saved.",
                                    );
                                });
                            }}
                        >
                            <label className="notification-toggle">
                                <input
                                    type="checkbox"
                                    checked={settings.workout_reminders}
                                    onChange={(event) =>
                                        setSettings({
                                            ...settings,
                                            workout_reminders:
                                                event.target.checked,
                                        })
                                    }
                                />
                                <span>
                                    <strong>Workout reminders</strong>
                                    <small>
                                        One reminder before a planned workout
                                        with a start time. No rest-day or
                                        missed-workout messages.
                                    </small>
                                </span>
                            </label>
                            <Field label="Remind me before my workout">
                                <select
                                    value={settings.reminder_minutes}
                                    onChange={(event) =>
                                        setSettings({
                                            ...settings,
                                            reminder_minutes: Number(
                                                event.target.value,
                                            ),
                                        })
                                    }
                                >
                                    <option value={15}>15 minutes</option>
                                    <option value={30}>30 minutes</option>
                                    <option value={60}>1 hour</option>
                                    <option value={120}>2 hours</option>
                                </select>
                            </Field>
                            <label className="notification-toggle">
                                <input
                                    type="checkbox"
                                    checked={settings.coach_updates}
                                    onChange={(event) =>
                                        setSettings({
                                            ...settings,
                                            coach_updates: event.target.checked,
                                        })
                                    }
                                />
                                <span>
                                    <strong>Coach updates</strong>
                                    <small>
                                        Connection requests, accepted requests,
                                        assigned plans, and important schedule
                                        or program changes.
                                    </small>
                                </span>
                            </label>
                            {!enabled &&
                                (settings.workout_reminders ||
                                    settings.coach_updates) && (
                                    <p className="notification-help">
                                        Preferences can be on while this device
                                        is off. Enable a device above to receive
                                        messages here.
                                    </p>
                                )}
                            <Button type="submit" disabled={busy || !online}>
                                {busy ? "Saving…" : "Save preferences"}
                            </Button>
                        </form>
                    </section>
                </div>
                <InstallCard />
            </div>
        </>
    );
}

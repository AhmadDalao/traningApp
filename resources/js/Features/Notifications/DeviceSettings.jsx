import { useEffect, useState, useSyncExternalStore } from "react";
import { Bell, Laptop, Settings } from "lucide-react";
import { Button } from "../../Components/UI";
import InstallCard from "../../Features/Notifications/InstallCard";
import {
    pwaSnapshot,
    pushSupport,
    subscribePwa,
    vapidBytes,
} from "../../Features/Notifications/pwa";
import { coachingJson as jsonRequest } from "../../Features/CoachingUI";

export default function Index({
    deviceEnabled,
    deviceCount,
    pushConfigured,
    publicKey,
}) {
    const [enabled, setEnabled] = useState(deviceEnabled);
    const [count, setCount] = useState(deviceCount);
    const [permission, setPermission] = useState(
        () => window.Notification?.permission || "unsupported",
    );
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState("");
    const [error, setError] = useState("");
    const [deviceDetails, setDeviceDetails] = useState(false);
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
            <div className="notification-grid settings-devices">
                <div>
                    <section
                        className={`panel notification-panel device-panel compact-device ${deviceDetails ? "expanded" : ""}`}
                    >
                        <div className="notification-title">
                            <Laptop size={22} />
                            <h2>Connected devices</h2>
                        </div>
                        {true && (
                            <>
                                <div className="device-summary">
                                    <Laptop size={24} />
                                    <div>
                                        <strong>This device</strong>
                                        <small>{status}</small>
                                    </div>
                                    <span className="badge completed">
                                        Current
                                    </span>
                                </div>
                                <Button
                                    variant="secondary"
                                    aria-expanded={deviceDetails}
                                    onClick={() =>
                                        setDeviceDetails(!deviceDetails)
                                    }
                                >
                                    <Settings size={17} />
                                    {deviceDetails
                                        ? "Hide device options"
                                        : "Manage devices"}
                                </Button>
                            </>
                        )}
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
                </div>
                <InstallCard compact />
            </div>
        </>
    );
}

import { router } from "@inertiajs/react";

let state = {
    online: navigator.onLine,
    waiting: null,
    installPrompt: null,
    registration: null,
    registrationError: false,
};
const listeners = new Set();
const change = (patch) => {
    state = { ...state, ...patch };
    listeners.forEach((listener) => listener());
};
export const subscribePwa = (listener) => {
    listeners.add(listener);
    return () => listeners.delete(listener);
};
export const pwaSnapshot = () => state;
let reloadRequested = false;
let started = false;
export function startPwa() {
    if (started) return;
    started = true;
    window.addEventListener("online", () => change({ online: true }));
    window.addEventListener("offline", () => change({ online: false }));
    window.addEventListener("beforeinstallprompt", (event) => {
        event.preventDefault();
        change({ installPrompt: event });
    });
    window.addEventListener("appinstalled", () =>
        change({ installPrompt: null }),
    );
    router.on("before", (event) => {
        if (!navigator.onLine) {
            change({ online: false });
            event.preventDefault();
        }
        if (event.detail.visit.url.pathname === "/logout") {
            navigator.serviceWorker?.controller?.postMessage({
                type: "CLEAR_NOTIFICATIONS",
            });
        }
    });
    if (
        !import.meta.env.PROD ||
        !window.isSecureContext ||
        !("serviceWorker" in navigator)
    )
        return;
    navigator.serviceWorker.addEventListener("controllerchange", () => {
        if (reloadRequested) window.location.reload();
    });
    navigator.serviceWorker
        .register("/sw.js", { scope: "/", updateViaCache: "none" })
        .then((registration) => {
            change({ registration, waiting: registration.waiting });
            registration.addEventListener("updatefound", () => {
                const worker = registration.installing;
                worker?.addEventListener("statechange", () => {
                    if (
                        worker.state === "installed" &&
                        navigator.serviceWorker.controller
                    )
                        change({ waiting: registration.waiting });
                });
            });
            // Browser revisits check naturally; visibility checks do not interrupt or reload.
            document.addEventListener("visibilitychange", () => {
                if (document.visibilityState === "visible" && navigator.onLine)
                    registration.update().catch(() => {});
            });
        })
        .catch(() => change({ registrationError: true }));
}
export function activateUpdate() {
    if (!state.waiting || !navigator.onLine) return;
    reloadRequested = true;
    // Another tab may already have activated this worker. This tab still reloads
    // only when its own user chooses the update action.
    if (state.waiting.state === "activated") window.location.reload();
    else state.waiting.postMessage({ type: "ACTIVATE_UPDATE" });
}
export function standalone() {
    return (
        window.matchMedia("(display-mode: standalone)").matches ||
        navigator.standalone === true
    );
}
export function pushSupport() {
    return (
        window.isSecureContext &&
        "serviceWorker" in navigator &&
        "PushManager" in window &&
        "Notification" in window
    );
}
export function vapidBytes(value) {
    return Uint8Array.from(
        atob(value.replace(/-/g, "+").replace(/_/g, "/")),
        (char) => char.charCodeAt(0),
    );
}

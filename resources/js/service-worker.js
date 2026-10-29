/* VERSION and STATIC_ASSETS are generated from the deployed Vite manifest.
   Only public, explicitly listed assets ever enter CacheStorage. */
const CACHE = `training-planner-static-${VERSION}`;
const allowed = new Set(STATIC_ASSETS);

self.addEventListener("install", (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHE);
            for (const path of STATIC_ASSETS) {
                const response = await fetch(path, {
                    credentials: "omit",
                    cache: "reload",
                });
                if (
                    !response.ok ||
                    (path !== "/offline.html" &&
                        (response.headers.get("content-type") || "").includes(
                            "text/html",
                        ))
                ) {
                    throw new Error("Static asset unavailable");
                }
                await cache.put(path, response);
            }
        })(),
    );
    // The app, never install(), decides when a waiting worker may activate.
});
self.addEventListener("activate", (event) => {
    event.waitUntil(
        (async () => {
            const previous = (await caches.keys()).filter(
                (key) =>
                    key.startsWith("training-planner-static-") && key !== CACHE,
            );
            // Keep one previous public asset generation for already-open tabs.
            for (const key of previous.slice(0, -1)) await caches.delete(key);
            await self.clients.claim();
        })(),
    );
});
self.addEventListener("message", (event) => {
    if (event.data?.type === "ACTIVATE_UPDATE")
        event.waitUntil(self.skipWaiting());
    if (event.data?.type === "CLEAR_NOTIFICATIONS") {
        event.waitUntil(
            self.registration
                .getNotifications()
                .then((rows) => rows.forEach((row) => row.close())),
        );
    }
});
self.addEventListener("fetch", (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== "GET" || url.origin !== self.location.origin) return;
    if (request.mode === "navigate") {
        event.respondWith(
            fetch(request).catch(async () =>
                (await caches.open(CACHE)).match("/offline.html"),
            ),
        );
        return;
    }
    // Inertia/XHR/API and all other paths are network-only, including storage uploads.
    if (
        !url.search &&
        (allowed.has(url.pathname) ||
            /^\/build\/assets\/[A-Za-z0-9_.-]+\.(js|css|woff2?)$/.test(
                url.pathname,
            )) &&
        !request.headers.has("X-Inertia")
    ) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(CACHE);
                const current = await cache.match(url.pathname);
                if (current) return current;
                if (url.pathname.startsWith("/build/assets/")) {
                    for (const key of await caches.keys()) {
                        if (
                            key.startsWith("training-planner-static-") &&
                            key !== CACHE
                        ) {
                            const previous = await (
                                await caches.open(key)
                            ).match(url.pathname);
                            if (previous) return previous;
                        }
                    }
                }
                return fetch(request);
            })(),
        );
    }
});
function safeDestination(value) {
    return typeof value === "string" &&
        /^\/(?:notifications(?:\/open\/(?:session|plan)\/\d+)?|my-coaching|coach\/trainees\/\d+)$/.test(
            value,
        )
        ? value
        : "/notifications";
}
self.addEventListener("push", (event) => {
    event.waitUntil(
        (async () => {
            let data = {};
            try {
                data = event.data?.json() || {};
            } catch {
                /* Show a generic fallback. */
            }
            const text = (value, fallback) =>
                typeof value === "string" ? value.slice(0, 180) : fallback;
            await self.registration.showNotification(
                text(data.title, "Training Planner"),
                {
                    body: text(
                        data.body,
                        "Open Training Planner to review your training.",
                    ),
                    icon: "/pwa/icon-192.png",
                    badge: "/pwa/icon-192.png",
                    tag:
                        typeof data.tag === "string" &&
                        /^tp-[a-f0-9]{64}$/.test(data.tag)
                            ? data.tag
                            : "training-planner",
                    renotify: false,
                    data: { url: safeDestination(data.url) },
                },
            );
        })(),
    );
});
self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const url = new URL(
        safeDestination(event.notification.data?.url),
        self.location.origin,
    ).href;
    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: "window",
                includeUncontrolled: true,
            });
            const existing = windows.find((client) => client.url === url);
            if (existing) return existing.focus();
            // Do not navigate away from a workout or an unsaved form in another tab.
            return self.clients.openWindow(url);
        })(),
    );
});

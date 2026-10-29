import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const source = fs.readFileSync(
    new URL("../../resources/js/service-worker.js", import.meta.url),
    "utf8",
);
function worker() {
    const events = {},
        stores = new Map(),
        shown = [],
        opened = [],
        calls = [];
    let skipped = 0,
        claimed = 0;
    const cache = (key) => {
        if (!stores.has(key)) stores.set(key, new Map());
        const rows = stores.get(key);
        return {
            put: async (path, response) => rows.set(path, response),
            match: async (path) => rows.get(path),
        };
    };
    const self = {
        location: { origin: "https://training.example" },
        addEventListener: (event, callback) => {
            events[event] = callback;
        },
        skipWaiting: async () => {
            skipped++;
        },
        clients: {
            claim: async () => {
                claimed++;
            },
            matchAll: async () => [],
            openWindow: async (url) => {
                opened.push(url);
            },
        },
        registration: {
            showNotification: async (title, options) =>
                shown.push({ title, ...options }),
            getNotifications: async () => [],
        },
    };
    let network = async (request) =>
        new Response(typeof request === "string" ? request : request.url, {
            headers: { "content-type": "text/javascript" },
        });
    vm.runInNewContext(
        `const VERSION="test-v1"; const STATIC_ASSETS=["/offline.html","/build/assets/app-hash.js"];\n${source}`,
        {
            self,
            URL,
            Set,
            Error,
            fetch: (...args) => {
                calls.push(args);
                return network(...args);
            },
            caches: {
                open: async (key) => cache(key),
                keys: async () => [...stores.keys()],
                delete: async (key) => stores.delete(key),
            },
        },
    );
    async function fire(name, fields = {}) {
        let promise;
        const event = {
            ...fields,
            waitUntil: (value) => {
                promise = value;
            },
            respondWith: (value) => {
                promise = value;
            },
        };
        events[name](event);
        return promise === undefined ? undefined : await promise;
    }
    return {
        fire,
        stores,
        shown,
        opened,
        calls,
        self,
        network: (value) => {
            network = value;
        },
        skipped: () => skipped,
        claimed: () => claimed,
    };
}
const request = (path, extra = {}) => ({
    url: `https://training.example${path}`,
    method: "GET",
    mode: "cors",
    headers: new Headers(),
    ...extra,
});

test("install caches public allowlist with omitted credentials and does not activate automatically", async () => {
    const sw = worker();
    await sw.fire("install");
    assert.equal(sw.skipped(), 0);
    assert.equal(sw.calls.length, 2);
    assert.ok(sw.calls.every(([, options]) => options.credentials === "omit"));
    assert.deepEqual([...sw.stores.values()][0].size, 2);
    await sw.fire("message", { data: { type: "ACTIVATE_UPDATE" } });
    assert.equal(sw.skipped(), 1);
});
test("private HTML, Inertia, APIs, media, mutations and third party requests never enter cache", async () => {
    const sw = worker();
    await sw.fire("install");
    const size = [...sw.stores.values()][0].size;
    for (const path of [
        "/dashboard",
        "/training-history",
        "/notifications",
        "/storage/private.jpg",
        "/api/user",
        "/build/assets/app-hash.js?user=1",
    ]) {
        assert.equal(
            await sw.fire("fetch", { request: request(path) }),
            undefined,
        );
    }
    assert.equal(
        await sw.fire("fetch", {
            request: request("/build/assets/app-hash.js", { method: "POST" }),
        }),
        undefined,
    );
    assert.equal(
        await sw.fire("fetch", {
            request: request("/build/assets/app-hash.js", {
                headers: new Headers({ "X-Inertia": "true" }),
            }),
        }),
        undefined,
    );
    assert.equal(
        await sw.fire("fetch", {
            request: request("/", {
                url: "https://other.example/build/assets/app-hash.js",
            }),
        }),
        undefined,
    );
    const page = await sw.fire("fetch", {
        request: request("/dashboard", { mode: "navigate" }),
    });
    assert.equal(await page.text(), "https://training.example/dashboard");
    assert.equal([...sw.stores.values()][0].size, size);
});
test("navigation goes network first and offline uses only the generic fallback", async () => {
    const sw = worker();
    await sw.fire("install");
    sw.network(async () => {
        throw new Error("offline");
    });
    const response = await sw.fire("fetch", {
        request: request("/training-history/9", { mode: "navigate" }),
    });
    assert.equal(await response.text(), "/offline.html");
    const asset = await sw.fire("fetch", {
        request: request("/build/assets/app-hash.js"),
    });
    assert.equal(await asset.text(), "/build/assets/app-hash.js");
});
test("install refuses private HTML returned in place of a static script", async () => {
    const sw = worker();
    sw.network(
        async () =>
            new Response("private", {
                headers: { "content-type": "text/html" },
            }),
    );
    await assert.rejects(() => sw.fire("install"), /Static asset unavailable/);
    assert.equal(sw.skipped(), 0);
});
test("activation removes only old app caches", async () => {
    const sw = worker();
    sw.stores.set("training-planner-static-old", new Map());
    sw.stores.set("training-planner-static-previous", new Map());
    sw.stores.set("unrelated-cache", new Map());
    await sw.fire("activate");
    assert.equal(sw.stores.has("training-planner-static-old"), false);
    assert.equal(sw.stores.has("unrelated-cache"), true);
    assert.equal(sw.claimed(), 1);
});
test("malformed push is generic and normal push has stable non-renotifying tag", async () => {
    const sw = worker();
    await sw.fire("push", {
        data: {
            json: () => {
                throw new Error("bad JSON");
            },
        },
    });
    assert.equal(sw.shown[0].title, "Training Planner");
    const tag = "tp-" + "a".repeat(64);
    await sw.fire("push", {
        data: {
            json: () => ({
                title: "Workout reminder",
                body: "Open your Calendar",
                tag,
                url: "/notifications/open/session/12",
            }),
        },
    });
    assert.equal(sw.shown[1].tag, tag);
    assert.equal(sw.shown[1].renotify, false);
    assert.equal(sw.shown[1].data.url, "/notifications/open/session/12");
});
test("notification clicks reject external, protocol-relative and mutation paths", async () => {
    for (const url of [
        "https://evil.test",
        "//evil.test",
        "/logout",
        "/notifications/open/session/1?redirect=https://evil.test",
        "javascript:alert(1)",
    ]) {
        const sw = worker();
        let closed = false;
        await sw.fire("notificationclick", {
            notification: {
                data: { url },
                close: () => {
                    closed = true;
                },
            },
        });
        assert.equal(closed, true);
        assert.equal(sw.opened[0], "https://training.example/notifications");
    }
});
test("click focuses exact destination and leaves unrelated workout tabs untouched", async () => {
    const sw = worker();
    let focused = 0;
    sw.self.clients.matchAll = async () => [
        {
            url: "https://training.example/schedule-entries/9/workout",
            navigate: () => {
                throw new Error("must not navigate workout");
            },
        },
    ];
    await sw.fire("notificationclick", {
        notification: { data: { url: "/my-coaching" }, close() {} },
    });
    assert.equal(sw.opened[0], "https://training.example/my-coaching");
    sw.self.clients.matchAll = async () => [
        { url: "https://training.example/my-coaching", focus: () => focused++ },
    ];
    await sw.fire("notificationclick", {
        notification: { data: { url: "/my-coaching" }, close() {} },
    });
    assert.equal(focused, 1);
});
test("logout message closes displayed notifications", async () => {
    const sw = worker();
    let closed = 0;
    sw.self.registration.getNotifications = async () => [
        { close: () => closed++ },
    ];
    await sw.fire("message", { data: { type: "CLEAR_NOTIFICATIONS" } });
    assert.equal(closed, 1);
});

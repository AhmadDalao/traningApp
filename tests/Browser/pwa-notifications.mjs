/** Local QA only. Uses disposable accounts, real SW/cache/offline, mocked browser push permission/provider. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import http from "node:http";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const engine = process.env.QA_BROWSER || "chromium";
const output = process.env.QA_OUTPUT_DIR;
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
assert.ok(
    output && accounts[2].email.startsWith("qa-v200-"),
    "Disposable QA account required",
);
const fixture = JSON.parse(
    fs.readFileSync(path.join(output, "pwa-fixtures.json")),
)[engine];
// WebKit setOffline(true) blocks SW responses upstream (Playwright #42775).
// A loopback proxy lets us verify actual origin failure without stopping the app.
const proxy =
    engine === "webkit"
        ? http.createServer((request, response) => {
              const upstream = http.request(
                  {
                      hostname: "127.0.0.1",
                      port: 8000,
                      path: request.url,
                      method: request.method,
                      headers: request.headers,
                  },
                  (result) => {
                      response.writeHead(result.statusCode, result.headers);
                      result.pipe(response);
                  },
              );
              upstream.on("error", () => response.destroy());
              request.pipe(upstream);
          })
        : null;
if (proxy)
    await new Promise((resolve) => proxy.listen(0, "127.0.0.1", resolve));
const proxyPort = proxy?.address().port;
const base = proxy ? `http://127.0.0.1:${proxyPort}` : "http://localhost:8000";
const sourcePath = "resources/js/service-worker.js";
const originalSource = fs.readFileSync(sourcePath, "utf8");
const browser = await pw[engine].launch();
const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    serviceWorkers: "allow",
});
context.setDefaultTimeout(12000);
context.setDefaultNavigationTimeout(15000);
const checks = [],
    issues = [];
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
context.on("page", (page) =>
    page.on("pageerror", (error) => issues.push(error.message)),
);
async function go(page, route) {
    await page.goto(base + route);
    await page.locator("#app > *").first().waitFor();
}
async function login(page, account = accounts[2]) {
    await go(page, "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL((url) => !url.pathname.startsWith("/login"));
    await page.locator("#app > *").first().waitFor();
}
async function geometry(page, label) {
    for (const [width, height] of [
        [320, 480],
        [360, 800],
        [375, 812],
        [390, 844],
        [430, 900],
        [768, 1024],
        [1280, 900],
    ]) {
        await page.setViewportSize({ width, height });
        const dims = await page.evaluate(() => ({
            client: document.documentElement.clientWidth,
            scroll: document.documentElement.scrollWidth,
        }));
        assert.ok(
            dims.scroll <= dims.client + 1,
            `${label} overflows ${width}: ${JSON.stringify(dims)}`,
        );
    }
    pass(label + " fits all seven viewports including 320×480");
}
try {
    const page = await context.newPage();
    await page.addInitScript(() => {
        window.__permissionRequests = 0;
        if (window.Notification)
            Notification.requestPermission = async () => {
                window.__permissionRequests++;
                return "denied";
            };
    });
    await login(page);
    await go(page, "/notifications");
    await page
        .getByRole("heading", { name: "Notifications", exact: true })
        .waitFor();
    assert.equal(await page.evaluate(() => window.__permissionRequests), 0);
    pass("No permission prompt on login or settings load");
    const csrf = await context.request.put(
        base + "/notifications/preferences",
        {
            data: {
                workout_reminders: true,
                coach_updates: true,
                reminder_minutes: 60,
            },
            headers: { Accept: "application/json" },
        },
    );
    assert.equal(csrf.status(), 419);
    pass("Live HTTP preferences reject mutations without a CSRF token");
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.waitForFunction(() => !!navigator.serviceWorker.controller);
    pass("Real service worker installs and controls the application");
    await geometry(page, "Notification settings and install card");
    await page.setViewportSize({ width: 320, height: 480 });
    await page
        .getByRole("button", { name: "Save preferences" })
        .scrollIntoViewIfNeeded();
    assert.ok(
        await page
            .getByRole("button", { name: "Save preferences" })
            .isVisible(),
    );
    pass("Short-height settings actions remain reachable");
    await page.getByRole("checkbox", { name: /Workout reminders/ }).check();
    await page.getByLabel("Remind me before my workout").selectOption("30");
    await page.getByRole("checkbox", { name: /Coach updates/ }).check();
    await page.getByRole("button", { name: "Save preferences" }).click();
    await page.getByText("Notification preferences saved.").waitFor();
    await page.reload();
    await page
        .getByRole("heading", { name: "Notifications", exact: true })
        .waitFor();
    assert.equal(
        await page.getByLabel("Remind me before my workout").inputValue(),
        "30",
    );
    assert.ok(
        await page
            .getByRole("checkbox", { name: /Workout reminders/ })
            .isChecked(),
    );
    pass("Account preferences persist without enabling a device");
    await page.evaluate(() => {
        const event = new Event("beforeinstallprompt", { cancelable: true });
        event.prompt = async () => {};
        event.userChoice = Promise.resolve({ outcome: "dismissed" });
        window.dispatchEvent(event);
    });
    await page
        .getByRole("button", { name: "Install app", exact: true })
        .click();
    await page
        .getByText("You can install later from your browser’s menu.")
        .waitFor();
    pass("Dismissed installation leaves normal browser use available");
    await context.setOffline(true);
    await page.getByText("You're offline", { exact: true }).waitFor();
    assert.ok(
        await page
            .getByRole("button", { name: "Save preferences" })
            .isDisabled(),
    );
    pass("Offline settings cannot submit mutations or queue changes");
    if (proxy) {
        await context.setOffline(false);
        proxy.closeAllConnections();
        await new Promise((resolve) => proxy.close(resolve));
    }
    await page.goto(base + "/training-history");
    await page.getByRole("heading", { name: "You're offline" }).waitFor();
    assert.equal(await page.locator("#app").count(), 0);
    pass(
        "Offline navigation shows generic fallback without cached private history",
    );
    await context.setOffline(false);
    if (proxy)
        await new Promise((resolve) =>
            proxy.listen(proxyPort, "127.0.0.1", resolve),
        );
    await page.getByRole("link", { name: "Try again" }).click();
    await page.locator("#app > *").first().waitFor();
    pass("Reconnect and retry restores authenticated navigation");
    for (const route of [
        "/dashboard",
        "/calendar",
        "/training-plans",
        "/training-history",
        "/progress",
        "/my-coaching",
        "/notifications",
    ]) {
        await go(page, route);
        assert.equal(await page.locator("#app > *").count(), 1);
    }
    pass("Core authenticated pages work with the worker active");
    const cached = await page.evaluate(async () => {
        const keys = await caches.keys();
        const urls = [];
        for (const key of keys)
            for (const req of await (await caches.open(key)).keys())
                urls.push(new URL(req.url).pathname);
        return urls;
    });
    assert.ok(cached.length > 20);
    assert.ok(
        cached.every(
            (url) =>
                url.startsWith("/build/assets/") ||
                url.startsWith("/pwa/") ||
                ["/offline.html", "/favicon.svg"].includes(url),
        ),
    );
    pass(
        "CacheStorage inspection contains public assets only; no auth, Inertia, API or media URLs",
    );
    await go(page, "/schedule-entries/" + fixture.entry);
    const startWorkout = page.getByRole("button", {
        name: "Start workout",
        exact: true,
    });
    if (await startWorkout.count()) await startWorkout.click();
    else
        await page
            .getByRole("link", { name: "Resume workout", exact: true })
            .click();
    await page.waitForURL(/\/workout$/);
    await page.locator(".workout-set").first().waitFor();
    const actual = page
        .locator(".workout-set")
        .first()
        .locator("input[type=number]")
        .first();
    await actual.fill("17");
    await page.evaluate(() => (window.__pageIdentity = "workout-kept"));
    fs.writeFileSync(
        sourcePath,
        originalSource + "\n// Disposable local update QA " + engine + "\n",
    );
    await page.evaluate(async () =>
        (await navigator.serviceWorker.getRegistration()).update(),
    );
    await page
        .getByText("Update available", { exact: true })
        .waitFor({ timeout: 20000 });
    assert.ok(
        await page
            .getByRole("button", { name: "Update & reload" })
            .isDisabled(),
    );
    assert.equal(await actual.inputValue(), "17");
    assert.equal(
        await page.evaluate(() => window.__pageIdentity),
        "workout-kept",
    );
    pass(
        "New worker waits; active workout stays open with entered data intact",
    );
    const form = await context.newPage();
    await go(form, "/training-plans/create");
    const name = form.getByLabel("Plan name", { exact: false });
    await name.fill("Unsaved PWA QA plan");
    await form.evaluate(() => (window.__pageIdentity = "form-kept"));
    const settings = await context.newPage();
    await go(settings, "/notifications");
    await settings.getByText("Update available", { exact: true }).waitFor();
    settings.once("dialog", (dialog) => dialog.accept());
    await settings.getByRole("button", { name: "Update & reload" }).click();
    await settings.waitForEvent("load");
    await settings
        .getByRole("heading", { name: "Notifications", exact: true })
        .waitFor();
    assert.equal(
        await page.evaluate(() => window.__pageIdentity),
        "workout-kept",
    );
    assert.equal(await actual.inputValue(), "17");
    assert.equal(await name.inputValue(), "Unsaved PWA QA plan");
    assert.equal(await form.evaluate(() => window.__pageIdentity), "form-kept");
    pass(
        "Explicit update reloads only requesting tab; other workout/form tabs retain unsaved input",
    );
    form.once("dialog", (dialog) => dialog.accept());
    await form.getByRole("button", { name: "Update & reload" }).click();
    await form.waitForEvent("load");
    await form.getByRole("heading", { name: "Create training plan" }).waitFor();
    assert.equal(await form.evaluate(() => window.__pageIdentity), undefined);
    pass(
        "A preserved tab can explicitly reload after another tab has activated the update",
    );
    await settings.screenshot({
        path: path.join(output, `pwa-settings-${engine}.png`),
        fullPage: true,
    });
    await form.close();
    await page.close();
    // Simulate supported API permission states without contacting any real push provider.
    await settings.addInitScript(() => {
        const parse = JSON.parse;
        JSON.parse = function (...args) {
            const value = parse.apply(this, args);
            if (value?.component === "Notifications/Index") {
                value.props.pushConfigured = true;
                value.props.publicKey = "B" + "A".repeat(86);
            }
            return value;
        };
        window.__permissionRequests = 0;
        window.__permission = "default";
        window.PushManager ||= class {};
        window.Notification ||= class {};
        Object.defineProperty(Notification, "permission", {
            configurable: true,
            get: () => window.__permission,
        });
        Notification.requestPermission = async () => {
            window.__permissionRequests++;
            window.__permission = "denied";
            return "denied";
        };
    });
    await settings.reload();
    await settings
        .getByRole("heading", { name: "Notifications", exact: true })
        .waitFor();
    await settings
        .getByRole("button", { name: "Enable on this device" })
        .click();
    await settings
        .getByText("Blocked by your browser", { exact: true })
        .waitFor();
    assert.equal(await settings.evaluate(() => window.__permissionRequests), 1);
    assert.ok(
        await settings
            .getByRole("button", { name: "Enable on this device" })
            .isDisabled(),
    );
    pass(
        "Explicit enable asks once; denied permission shows recovery guidance without repeated prompts",
    );
    await settings.evaluate(() => {
        window.__permission = "granted";
        window.dispatchEvent(new Event("focus"));
        const subscription = {
            endpoint: "https://fcm.googleapis.com/browser-qa",
            toJSON: () => ({ keys: { p256dh: "test", auth: "test" } }),
            unsubscribe: async () => true,
        };
        Object.defineProperty(
            ServiceWorkerRegistration.prototype,
            "pushManager",
            {
                configurable: true,
                get: () => ({
                    getSubscription: async () => null,
                    subscribe: async () => subscription,
                }),
            },
        );
        const fetch = window.fetch;
        window.__pushRequests = [];
        window.fetch = (input, options) => {
            if (
                ["/notifications/subscription", "/notifications/test"].includes(
                    String(input),
                )
            ) {
                window.__pushRequests.push({
                    path: String(input),
                    method: options.method,
                });
                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            enabled: true,
                            message: "Test queued by QA transport.",
                        }),
                        {
                            status: 200,
                            headers: { "Content-Type": "application/json" },
                        },
                    ),
                );
            }
            return fetch(input, options);
        };
    });
    await settings
        .getByRole("button", { name: "Enable on this device" })
        .click();
    await settings
        .getByText("Enabled on this device", { exact: true })
        .waitFor();
    await settings
        .getByRole("button", { name: "Send test notification" })
        .click();
    await settings.getByText("Test queued by QA transport.").waitFor();
    await settings.getByRole("button", { name: "Disable this device" }).click();
    await settings.getByText("Off on this device", { exact: true }).waitFor();
    assert.deepEqual(
        await settings.evaluate(() =>
            window.__pushRequests.map((row) => row.method),
        ),
        ["POST", "POST", "DELETE"],
    );
    pass(
        "Mocked granted permission enables this device, queues a test, and disables only this device",
    );
    await settings.addInitScript(() => {
        Object.defineProperty(window, "PushManager", {
            value: undefined,
            configurable: true,
        });
        delete window.PushManager;
    });
    await settings.reload();
    await settings
        .getByText("Not supported in this browser", { exact: true })
        .waitFor();
    assert.ok(
        await settings
            .getByRole("button", { name: "Save preferences" })
            .isEnabled(),
    );
    pass("Unsupported push leaves preferences and core app usable");
    await settings
        .getByRole("checkbox", { name: /Workout reminders/ })
        .uncheck();
    await settings.getByRole("checkbox", { name: /Coach updates/ }).uncheck();
    await settings.getByRole("button", { name: "Save preferences" }).click();
    await settings.getByText("Notification preferences saved.").waitFor();
    await settings.addInitScript(() =>
        Object.defineProperty(navigator, "standalone", {
            value: true,
            configurable: true,
        }),
    );
    await settings.reload();
    await settings
        .getByRole("heading", { name: "Training Planner is installed" })
        .waitFor();
    pass(
        "Standalone-like detection replaces installation instructions with installed state",
    );
    await settings.setViewportSize({ width: 1280, height: 900 });
    await settings
        .getByRole("button", { name: "Log out", exact: true })
        .last()
        .click();
    await settings.waitForURL("**/login");
    await login(settings, accounts[1]);
    await go(settings, "/notifications");
    await settings
        .getByRole("link", { name: "Coach Overview", exact: true })
        .waitFor();
    await geometry(settings, "Coach notification settings");
    pass(
        "Coach account settings retain Coach navigation and use the same notification engine",
    );
    assert.deepEqual(issues, []);
    pass("No browser JavaScript errors");
    fs.writeFileSync(
        path.join(output, `pwa-${engine}.json`),
        JSON.stringify(
            {
                checks,
                count: checks.length,
                issues,
                cache_paths: cached,
                physical_device_tested: false,
                offline_navigation_mode: proxy
                    ? "origin-outage; WebKit offline flag limitation #42775"
                    : "setOffline",
                provider_delivery_tested: false,
            },
            null,
            2,
        ),
    );
} catch (error) {
    for (const [index, page] of context.pages().entries()) {
        console.error(
            "FAILED PAGE",
            index,
            page.url(),
            (await page.locator("body").innerText()).slice(0, 2200),
        );
        await page
            .screenshot({
                path: path.join(output, `pwa-failure-${engine}-${index}.png`),
                fullPage: true,
            })
            .catch(() => {});
    }
    throw error;
} finally {
    fs.writeFileSync(sourcePath, originalSource);
    await browser.close();
    if (proxy?.listening) {
        proxy.closeAllConnections();
        await new Promise((resolve) => proxy.close(resolve));
    }
}

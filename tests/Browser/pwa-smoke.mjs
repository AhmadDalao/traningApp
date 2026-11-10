import fs from "node:fs";
import path from "node:path";
import assert from "node:assert/strict";
const pw = await import(process.env.PLAYWRIGHT_MODULE),
    engine = process.env.QA_BROWSER || "chromium",
    output = process.env.QA_OUTPUT_DIR,
    accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE)),
    base = process.env.QA_BASE_URL || "http://localhost:8000";
assert(accounts[2].email.startsWith("qa-v200-"));
const b = await pw[engine].launch(),
    context = await b.newContext({
        viewport: { width: 390, height: 844 },
        serviceWorkers: "allow",
    }),
    p = await context.newPage(),
    checks = [],
    issues = [];
p.setDefaultTimeout(20000);
p.on("pageerror", (e) => issues.push(e.message));
const pass = (x) => {
    checks.push(x);
    console.log("PASS", x);
};
await p.addInitScript(() => {
    window.__permissions = 0;
    if (window.Notification)
        Notification.requestPermission = async () => {
            window.__permissions++;
            return "denied";
        };
});
try {
    await p.goto(base + "/login");
    await p.getByLabel("Email address").fill(accounts[2].email);
    await p.getByLabel("Password", { exact: true }).fill(accounts[2].password);
    await p.getByRole("button", { name: "Log in", exact: true }).click();
    await p.waitForURL((u) => u.pathname != "/login");
    await p.goto(base + "/notifications");
    await p
        .getByRole("heading", { name: "Notifications", exact: true })
        .waitFor();
    assert.equal(await p.evaluate(() => window.__permissions), 0);
    pass("No permission prompt without user action");
    await p
        .getByText(
            "Push delivery is not configured on this server yet. You can save your preferences now.",
        )
        .waitFor();
    assert(
        await p
            .getByRole("button", { name: "Enable on this device", exact: true })
            .isDisabled(),
    );
    pass("Push remains safely disabled and clearly explained");
    await p.evaluate(() =>
        Promise.race([
            navigator.serviceWorker.ready,
            new Promise((_, reject) =>
                setTimeout(
                    () =>
                        reject(
                            new Error("Service worker activation timed out"),
                        ),
                    45000,
                ),
            ),
        ]),
    );
    await p.waitForFunction(() => !!navigator.serviceWorker.controller);
    pass("Real service worker installs and controls the app");
    for (const [width, height] of [
        [320, 480],
        [360, 800],
        [375, 812],
        [390, 844],
        [430, 900],
        [768, 1024],
        [1280, 900],
    ]) {
        await p.setViewportSize({ width, height });
        assert(
            await p.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        pass(`Notification settings fit ${width}x${height}`);
    }
    await p.getByRole("switch", { name: /Workout reminders/ }).check();
    await p.getByLabel("Remind me before my workout").selectOption("30");
    await p.getByRole("switch", { name: /Coach updates/ }).check();
    await p
        .getByRole("button", { name: "Save preferences", exact: true })
        .click();
    await p.getByText("Notification preferences saved.").waitFor();
    await p.reload();
    assert.equal(
        await p.getByLabel("Remind me before my workout").inputValue(),
        "30",
    );
    pass("Account preferences persist without enabling delivery");
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
    pass("Preference writes reject missing CSRF tokens");
    const paths = await p.evaluate(async () => {
        const paths = [];
        for (const key of await caches.keys()) {
            const c = await caches.open(key);
            for (const req of await c.keys())
                paths.push(new URL(req.url).pathname);
        }
        return paths;
    });
    assert(paths.length > 10);
    assert(
        paths.every(
            (x) =>
                x.startsWith("/build/") ||
                x.startsWith("/pwa/") ||
                ["/offline.html", "/favicon.svg"].includes(x),
        ),
    );
    pass(
        "Cache stores only public assets, never authenticated pages or uploads",
    );
    const manifest = await context.request.get(base + "/manifest.webmanifest");
    assert.equal(manifest.status(), 200);
    assert.equal((await manifest.json()).display, "standalone");
    pass("Install manifest is available");
    const worker = await context.request.get(base + "/sw.js");
    assert.equal(worker.status(), 200);
    assert.equal(worker.headers()["service-worker-allowed"], "/");
    assert(worker.headers()["cache-control"].includes("no-store"));
    pass("Service worker scope and no-store headers are correct");
    await p.setViewportSize({ width: 390, height: 844 });
    await p.screenshot({
        path: path.join(output, `pwa-smoke-${engine}.png`),
        fullPage: true,
    });
    await context.setOffline(true);
    await p.getByText("You're offline", { exact: true }).waitFor();
    assert(
        await p
            .getByRole("button", { name: "Save preferences", exact: true })
            .isDisabled(),
    );
    pass("Offline notification mutations are disabled");
    if (engine === "chromium") {
        await p.goto(base + "/dashboard");
        await p.getByRole("heading", { name: "You're offline" }).waitFor();
        pass(
            "Offline navigation shows the generic fallback without private data",
        );
    }
    await context.setOffline(false);
    await p.goto(base + "/notifications");
    await p
        .getByRole("heading", { name: "Notifications", exact: true })
        .waitFor();
    pass("Reconnection restores normal authenticated navigation");
    const noStore = await context.request.get(base + "/notifications");
    assert(noStore.headers()["cache-control"].includes("no-store"));
    pass("Authenticated settings retain private no-store responses");
    assert.deepEqual(issues, []);
    pass("No browser runtime errors");
} catch (e) {
    await p.screenshot({
        path: path.join(output, `pwa-smoke-failure-${engine}.png`),
        fullPage: true,
    });
    throw e;
} finally {
    fs.writeFileSync(
        path.join(output, `pwa-smoke-${engine}.json`),
        JSON.stringify(
            {
                checks,
                count: checks.length,
                issues,
                real_push_delivery: false,
                physical_device_tested: false,
            },
            null,
            2,
        ),
    );
    await b.close();
}

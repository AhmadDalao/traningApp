/** v2.5: program subscriptions, Settings, fullpage editors and bounded dashboards. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const out = process.env.QA_OUTPUT_DIR;
const engine = process.env.QA_BROWSER || "chromium";
const fixture = JSON.parse(fs.readFileSync(path.join(out, "fixtures.json")))[
    engine
];
const desktop = JSON.parse(
    fs.readFileSync(path.join(out, "desktop-fixtures.json")),
)[engine];
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const account = accounts[fixture.coach];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch();
const context = await browser.newContext({ serviceWorkers: "block" });
const page = await context.newPage();
page.setDefaultTimeout(20000);
const checks = [],
    errors = [];
page.on("pageerror", (e) => errors.push(e.message));
function pass(name) {
    checks.push(name);
    console.log("PASS", name);
}
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
async function go(url) {
    await page.goto(base + url);
    await page.locator("h1").waitFor();
    await page.waitForLoadState("networkidle");
}
async function matrix(name) {
    for (const [width, height] of [
        [320, 480],
        [360, 800],
        [375, 812],
        [390, 844],
        [430, 900],
        [768, 1024],
        [1280, 800],
        [1440, 900],
        [1920, 1080],
    ]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
            `${name}: overflow at ${width}`,
        );
        assert.equal(
            await page.locator("a button, button button, label button").count(),
            0,
            `${name}: nested controls`,
        );
        if (width === 1440 || width === 390) {
            await page
                .locator("img")
                .evaluateAll(
                    async (imgs) =>
                        await Promise.all(
                            imgs.map((img) => img.decode().catch(() => {})),
                        ),
                );
            await page.screenshot({
                path: path.join(out, `v250-${engine}-${name}-${width}.png`),
                fullPage: true,
            });
        }
        pass(`${name}: ${width}×${height}`);
    }
    await page.setViewportSize({ width: 1440, height: 900 });
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await button("Log in").click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    await go("/coach");
    await matrix("overview");
    assert.ok(
        (
            await page
                .locator(".coach-dashboard .training-thumbnail")
                .first()
                .boundingBox()
        ).width >= 80,
        "Overview thumbnails are large enough to recognize",
    );
    const sizes = await page
        .locator(".coach-dashboard .coach-overview-columns > .coach-card")
        .evaluateAll((rows) =>
            rows.map((r) => Math.round(r.getBoundingClientRect().height)),
        );
    assert.equal(new Set(sizes).size, 1);
    assert.equal(sizes[0], 440);
    const scroll = page.locator(".coach-panel-scroll").nth(1);
    await scroll.evaluate((el) => {
        const row = el.querySelector(".coach-session");
        for (let i = 0; i < 100; i++) el.append(row.cloneNode(true));
    });
    assert.ok(await scroll.evaluate((el) => el.scrollHeight > el.clientHeight));
    assert.equal(
        Math.round((await scroll.locator("..").boundingBox()).height),
        440,
    );
    pass("100 workout rows stay inside a fixed 440px panel");
    await go("/coach/settings");
    await matrix("settings");
    await page
        .getByRole("navigation", { name: "Settings sections" })
        .getByRole("link", { name: "Notifications & app" })
        .click();
    await page.waitForURL("**/coach/settings/notifications");
    assert.equal(await page.locator(".coach-workspace").count(), 1);
    assert.equal(
        await page
            .getByRole("navigation", { name: "Main navigation" })
            .getByRole("link", { name: "Notifications", exact: true })
            .count(),
        0,
    );
    await matrix("notifications");
    await page
        .getByRole("link", { name: "Back to settings", exact: true })
        .click();
    await page.waitForURL("**/coach/settings");
    pass("Notifications live inside Settings and Back keeps Coach workspace");
    await go("/coach/subscriptions");
    await matrix("subscriptions");
    assert.ok(
        (await page.locator(".subscription-table tbody tr").count()) >= 3,
    );
    await page.getByLabel("Search subscriptions").fill("Powerbuilding");
    await page.waitForResponse(
        (r) => r.url().includes("/coach/subscriptions?") && r.status() === 200,
    );
    assert.equal(await page.locator(".subscription-table tbody tr").count(), 2);
    await page.getByLabel("Items per page").selectOption("25");
    await page.waitForURL(/per_page=25/);
    const first = page.locator(".subscription-table tbody tr").first(),
        second = page.locator(".subscription-table tbody tr").nth(1);
    await first.getByRole("button", { name: "Pause", exact: true }).click();
    await first.getByRole("button", { name: "Resume", exact: true }).waitFor();
    assert.equal(
        await second
            .getByRole("button", { name: "Pause", exact: true })
            .count(),
        1,
    );
    await first.getByRole("button", { name: "Resume", exact: true }).click();
    await first.getByRole("button", { name: "Pause", exact: true }).waitFor();
    pass("Pause and Resume affect one program row; the other remains active");
    await go("/coach/training-plans");
    await matrix("programs");
    await page.getByLabel("Items per page").selectOption("50");
    await page.waitForURL(/per_page=50/);
    pass("Program list has persistent page-size control");
    await go(`/coach/training-plans/${desktop.template_id}/edit`);
    await button("Add Training Day").click();
    await page.waitForURL(/\/days\/create\?builder=1/);
    assert.equal(await page.getByRole("dialog").count(), 0);
    await matrix("training-day");
    await button("Add exercise").first().click();
    const dialog = page.getByRole("dialog").last();
    await dialog.waitFor();
    assert.ok(
        (await dialog.boundingBox()).width >= 950,
        "Exercise editor wider on desktop",
    );
    await page.keyboard.press("Escape");
    await page
        .getByRole("link", { name: "Back to program", exact: false })
        .click();
    await page.waitForURL(/\/edit$/);
    pass(
        "Training day uses full page; exercise editor has room; Back returns to builder",
    );
    await go(`/coach/trainees/${fixture.trainee_id}/calendar`);
    await matrix("calendar");
    const colors = await page
        .locator(".fc-daygrid-event")
        .evaluateAll((rows) => [
            ...new Set(rows.map((e) => getComputedStyle(e).backgroundColor)),
        ]);
    assert.ok(colors.length >= 2, "Distinct program backgrounds");
    await page.locator(".program-legend button").nth(1).click();
    assert.ok((await page.locator(".fc-daygrid-event").count()) > 0);
    assert.equal(
        await page
            .locator(".fc-daygrid-event")
            .evaluateAll(
                (rows) =>
                    new Set(
                        rows.map((e) => getComputedStyle(e).backgroundColor),
                    ).size,
            ),
        1,
    );
    pass("Program legend filters calendar and uses stable colours");
    await button("Add Workout").click();
    await page.waitForURL(/\/schedule-entries\/create/);
    assert.equal(await page.getByRole("dialog").count(), 0);
    await matrix("add-workout");
    assert.ok(await page.locator(".schedule-day-agenda").count());
    await page
        .getByRole("link", { name: "Back to calendar", exact: false })
        .click();
    await page.waitForURL(/\/calendar$/);
    pass("Add Workout is fullpage, includes the day agenda and a clear return");
    await go(`/coach/trainees/${fixture.trainee_id}`);
    await matrix("trainee-multiple-programs");
    assert.ok(
        (await page
            .locator(".assigned-program-list .coach-training-row")
            .count()) >= 2,
    );
    pass("Trainee overview exposes all program subscriptions");
    assert.deepEqual(errors, []);
    fs.writeFileSync(
        path.join(out, `independent-subscriptions-${engine}.json`),
        JSON.stringify(
            { base, engine, checks, count: checks.length, errors },
            null,
            2,
        ),
    );
} catch (e) {
    await page.screenshot({
        path: path.join(out, `v250-${engine}-failure.png`),
        fullPage: true,
    });
    throw e;
} finally {
    await browser.close();
}

/** Renew one ended program period through the real UI; retain every existing workout. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const fixture = JSON.parse(fs.readFileSync(path.join(out, "fixtures.json")))[
    engine
];
const account = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE))[
    fixture.coach
];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch(),
    context = await browser.newContext({ serviceWorkers: "block" }),
    page = await context.newPage();
page.setDefaultTimeout(20000);
const checks = [],
    errors = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (x) => {
    checks.push(x);
    console.log("PASS", x);
};
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
const props = async () =>
    JSON.parse(await page.locator("#app").getAttribute("data-page")).props;
async function go(url) {
    await page.goto(url, { waitUntil: "networkidle" });
}
try {
    await go(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await button("Log in").click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    await go(base + fixture.coach_urls[1]);
    const before = (await props()).position.sessions.map((s) => [
        s.id,
        s.scheduled_date,
        s.scheduled_time,
        s.status,
    ]);
    await go(base + "/coach/subscriptions?search=Powerbuilding");
    const rows = page.locator(".subscription-table tbody tr");
    await rows.first().waitFor();
    const initial = await rows.count();
    const endedBefore = await rows
        .getByRole("link", { name: "Renew", exact: true })
        .count();
    const activeBefore = await rows
        .getByRole("button", { name: "Pause", exact: true })
        .count();
    assert.ok(activeBefore === 1 || activeBefore === 2);
    const endThisRun = activeBefore === 2;
    if (endThisRun) {
        await rows
            .filter({
                has: page.getByRole("button", { name: "Pause", exact: true }),
            })
            .first()
            .getByRole("button", { name: "End", exact: true })
            .click();
        await page
            .getByRole("dialog")
            .getByRole("button", { name: "End subscription", exact: true })
            .click();
        await page.getByRole("dialog").waitFor({ state: "detached" });
    }
    await rows
        .getByRole("link", { name: "Renew", exact: true })
        .first()
        .waitFor();
    assert.equal(
        await rows.getByRole("button", { name: "Pause", exact: true }).count(),
        1,
    );
    pass("Ended program leaves the other subscription active");
    await rows
        .getByRole("link", { name: "Renew", exact: true })
        .first()
        .click();
    await page.waitForURL(/setup\?assignment=/);
    assert.equal(
        await page.locator('input[name="training"]:checked').count(),
        1,
    );
    assert.ok(await button("Continue").isEnabled());
    pass("Ended subscription retains its assigned training choice");
    await button("Continue").click();
    await page
        .getByRole("heading", { name: "Schedule Training", exact: true })
        .waitFor();
    await button("Continue to Review").click();
    await page
        .getByRole("heading", { name: "Review Assignment", exact: true })
        .waitFor();
    for (const [width, height] of [
        [320, 480],
        [390, 844],
        [1280, 900],
    ]) {
        await page.setViewportSize({ width, height });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        pass(`Renewal review fits ${width}×${height}`);
    }
    await page.getByRole("checkbox", { name: /Renew coaching today/ }).check();
    await button("Confirm Training").click();
    await page.waitForURL(new RegExp(`/coach/trainees/${fixture.trainee_id}$`));
    await go(base + fixture.coach_urls[1]);
    assert.deepEqual(
        (await props()).position.sessions.map((s) => [
            s.id,
            s.scheduled_date,
            s.scheduled_time,
            s.status,
        ]),
        before,
    );
    pass("Renewal keeps the same run, session IDs, dates and status");
    await go(base + "/coach/subscriptions?search=Powerbuilding");
    await rows.first().waitFor();
    assert.equal(await rows.count(), initial + 1);
    assert.equal(
        await rows.getByRole("button", { name: "Pause", exact: true }).count(),
        2,
    );
    assert.equal(
        await rows.getByRole("link", { name: "Renew", exact: true }).count(),
        endedBefore + (endThisRun ? 1 : 0),
    );
    pass(
        "New period created; previous ended period and other active program retained",
    );
    assert.deepEqual(errors, []);
    pass("No browser errors");
} catch (e) {
    await page.screenshot({
        path: path.join(out, `renewal-failure-${engine}.png`),
        fullPage: true,
    });
    throw e;
} finally {
    fs.writeFileSync(
        path.join(out, `program-subscription-renewal-${engine}.json`),
        JSON.stringify({ checks, count: checks.length, errors }, null, 2),
    );
    await browser.close();
}

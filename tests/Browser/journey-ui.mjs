/** Mockup implementation checks against local disposable QA accounts. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const f = JSON.parse(fs.readFileSync(path.join(out, "polish-fixtures.json")))[
    engine
];
const layout = JSON.parse(fs.readFileSync(path.join(out, "fixtures.json")))[
    engine
];
const browser = await pw[engine].launch();
const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
});
const page = await context.newPage();
page.setDefaultTimeout(20000);
const checks = [],
    errors = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (name) => {
    checks.push(name);
    console.log("PASS", name);
};
const base = "http://localhost:8000";
async function visit(route) {
    await page.goto(base + route);
    await page.locator(".journey-app").waitFor();
}
async function login(index) {
    assert.match(accounts[index].email, /^qa-v200-/);
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(accounts[index].email);
    await page
        .getByLabel("Password", { exact: true })
        .fill(accounts[index].password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/(coach|dashboard)$/);
}
async function capture(name) {
    for (const [width, height] of [
        [320, 480],
        [390, 844],
        [768, 1024],
        [1280, 900],
    ]) {
        await page.setViewportSize({ width, height });
        await page.evaluate(() => {
            document.activeElement?.blur();
            window.scrollTo(0, 0);
        });
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
            name + " overflow",
        );
        pass(`${name} fits ${width}x${height}`);
        if (width === 390 || width === 1280)
            await page.screenshot({
                path: path.join(out, `${engine}-journey-${name}-${width}.png`),
                fullPage: true,
            });
    }
}
try {
    await login(f.coach);
    await visit("/coach");
    assert.equal(await page.locator(".coach-pending").count(), 0);
    assert.equal(await page.locator(".coach-summary .stat-icon").count(), 4);
    pass("Coach overview uses four icon metrics and no empty pending panel");
    await capture("coach-overview");
    await visit("/coach/trainees");
    assert.ok((await page.locator(".person-avatar").count()) > 0);
    await capture("trainees");
    await visit(`/coach/trainees/${f.trainee}`);
    assert.equal(
        await page
            .locator(".trainee-context")
            .getByText("QA Own training " + engine, { exact: true })
            .count(),
        0,
    );
    assert.equal(await page.locator(".current-training").count(), 1);
    assert.equal(
        await page.locator(".trainee-quick-grid > section").count(),
        3,
    );
    pass(
        "Trainee context stays compact; training is shown once with three quick cards",
    );
    await capture("trainee");
    await visit(`/coach/trainees/${f.trainee}/training-plans/${f.own.plan}`);
    await capture("program");
    await visit(
        `/coach/trainees/${f.trainee}/training-plans/${f.own.plan}/schedule-preview`,
    );
    await page.locator(".schedule-diff-tabs").waitFor();
    assert.equal(
        await page
            .locator(".plan-schedule-preview .plan-schedule-row:visible")
            .count(),
        8,
    );
    const rowsBefore = await page
        .locator(".plan-schedule-preview .plan-schedule-row")
        .count();
    await page
        .getByRole("button", {
            name: `View all ${rowsBefore} sessions`,
            exact: true,
        })
        .click();
    assert.equal(
        await page
            .locator(".plan-schedule-preview .plan-schedule-row:visible")
            .count(),
        rowsBefore,
    );
    await page
        .getByRole("button", { name: "Show fewer sessions", exact: true })
        .click();
    pass(
        "Manage schedule exposes the full reviewed group without an inner scrollbar",
    );
    await capture("manage-schedule");
    await visit(`/coach/trainees/${f.trainee}/calendar`);
    await page.locator(".fc").waitFor();
    await capture("calendar");
    await visit(`/coach/trainees/${f.trainee}/schedule-entries/create`);
    await capture("schedule");
    await visit(`/coach/trainees/${f.trainee}/training-history`);
    await capture("history");
    await visit(`/coach/trainees/${f.trainee}/progress`);
    await capture("coach-progress");
    await page
        .getByRole("button", { name: "Log out", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL(base + "/login");
    await login(f.index);
    await visit("/dashboard");
    assert.equal(await page.locator(".dashboard-feature").count(), 2);
    assert.equal(await page.locator(".dashboard-scroll").count(), 0);
    assert.ok((await page.locator(".today-list .session-card").count()) <= 3);
    pass(
        "Personal overview shows next/recent workouts and bounds lists without inner scrolling",
    );
    await capture("personal-overview");
    const workoutCard = page.locator(".dashboard-feature").first();
    await workoutCard
        .getByRole("button", { name: "Start workout", exact: true })
        .or(
            workoutCard.getByRole("link", {
                name: "Resume workout",
                exact: true,
            }),
        )
        .click();
    await page.waitForURL(/\/schedule-entries\/\d+\/workout$/);
    pass(
        "Primary next-session action starts the existing workout flow directly",
    );
    await visit("/progress");
    await capture("personal-progress");
    await visit("/recommendations");
    assert.equal(await page.locator(".recommended-card").count(), 3);
    assert.ok(
        (await page.locator(".discovery-catalog .catalog-row").count()) > 3,
    );
    await capture("discovery");
    await page
        .getByRole("button", { name: "Log out", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL(base + "/login");
    await login(layout.coach);
    await visit(
        `/coach/trainees/${layout.trainee}/assign?template=${layout.template}&another=1`,
    );
    await capture("assignment");
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `journey-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

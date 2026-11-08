/** v2.3.9 actual builder -> assignment -> schedule journey; local disposable accounts. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.equal(base, "http://localhost:8000");
const out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const fixture = JSON.parse(fs.readFileSync(path.join(out, "fixtures.json")))[
    engine
];
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const account = accounts[fixture.coach];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch();
const context = await browser.newContext({ serviceWorkers: "block" }),
    page = await context.newPage();
page.setDefaultTimeout(20000);
const errors = [],
    checks = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (name) => {
    checks.push(name);
    console.log("PASS", name);
};
const button = (root, name) =>
    root.getByRole("button", { name, exact: true }).locator("visible=true");
async function matrix(stage) {
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
            stage + ": page overflow " + width,
        );
        assert.equal(
            await page.locator("a button,button button,label button").count(),
            0,
        );
        const active = page.locator("dialog[open]").last();
        if (await active.count())
            assert.ok(
                await active.evaluate(
                    (el) => el.scrollWidth <= el.clientWidth + 1,
                ),
                stage + ": dialog overflow",
            );
        await page.evaluate(() => {
            window.scrollTo(0, 0);
            document.activeElement?.blur();
        });
        if ([320, 1280].includes(width))
            await page.screenshot({
                path: path.join(out, `${engine}-journey-${stage}-${width}.png`),
                fullPage: true,
            });
        pass(`${stage}: ${width}x${height}, no overflow or nested controls`);
    }
}
async function addDay() {
    await button(page, "Add Training Day").click();
    const day = page.getByRole("dialog", {
        name: "Add Training Day",
        exact: true,
    });
    await day.waitFor();
    await day.getByLabel("Title", { exact: false }).fill("Full Body Strength");
    await day.getByRole("checkbox", { name: "Monday", exact: true }).check();
    await day.getByRole("checkbox", { name: "Wednesday", exact: true }).check();
    await button(day, "Add exercise").first().click();
    const exercise = page.getByRole("dialog", {
        name: "Add exercise",
        exact: true,
    });
    await exercise.getByLabel("Find an exercise").fill("Barbell Bench Press");
    await exercise.locator(".library-image").first().click();
    await page
        .getByRole("dialog")
        .last()
        .locator(".plan-image-preview img")
        .evaluate((img) => img.decode());
    await page.keyboard.press("Escape");
    assert.equal(await page.getByRole("dialog").count(), 2);
    await exercise.locator(".library-card").first().click();
    await exercise.getByLabel("Reps / duration").fill("10");
    await button(exercise, "Add exercise").click();
    await exercise.waitFor({ state: "detached" });
    await matrix("embedded-day");
    await button(day, "Save training day").click();
    await day.waitFor({ state: "detached" });
    await page.locator(".builder-day-list li").waitFor();
    assert.match(page.url(), /\/edit(?:\?|$)/);
    await page.reload();
    await page.locator(".builder-day-list li").waitFor();
    assert.equal(await page.locator(".builder-day-list li").count(), 1);
    pass(
        "Existing exercise library, image preview and reload preserve the embedded day builder",
    );
}
async function reviewAndAssign() {
    await page
        .getByRole("heading", { name: "Schedule Training", exact: true })
        .waitFor();
    await page.locator(".assignment-day").first().waitFor();
    const props = JSON.parse(
        await page.locator("#app").getAttribute("data-page"),
    ).props;
    const start = new Date(props.today + "T12:00:00Z");
    start.setUTCDate(start.getUTCDate() + 7);
    await page
        .getByLabel("Start training", { exact: false })
        .fill(start.toISOString().slice(0, 10));
    assert.ok(
        (await page
            .locator(".assignment-week li")
            .filter({ hasText: "Full Body Strength" })
            .count()) >= 2,
    );
    await matrix("schedule");
    await button(page, "Continue to Review").click();
    await page.locator(".assignment-review-rows li").first().waitFor();
    await matrix("review");
    const confirmation = page.getByRole("checkbox", {
        name: /^(Activate|Resume|Renew) coaching today/,
    });
    if (await confirmation.count()) {
        assert.ok(await button(page, "Assign & Schedule").isDisabled());
        await confirmation.check();
    }
    assert.ok(await button(page, "Assign & Schedule").isEnabled());
    await button(page, "Assign & Schedule").click();
    await page.waitForURL(new RegExp(`/coach/trainees/${fixture.trainee_id}$`));
    assert.equal(
        await page
            .getByRole("heading", { name: "Current Training", exact: true })
            .count(),
        1,
    );
    pass(
        "Final confirmation assigns and schedules, returning to one Current Training card",
    );
}
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await button(page, "Log in").click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    await page.goto(base + "/coach");
    await page.getByRole("link", { name: "Settings", exact: true }).click();
    await page.waitForURL("**/coach/settings");
    assert.equal(await page.locator(".coach-workspace").count(), 1);
    pass("Settings stays in Coach Workspace");
    await page
        .getByRole("link", { name: "Account & personal details", exact: true })
        .click();
    await page.waitForURL("**/coach/settings/account");
    await button(page, "Save profile").click();
    await page.waitForURL("**/coach/settings");
    assert.equal(await page.locator(".coach-workspace").count(), 1);
    pass("Account edit/save stays in Coach Workspace");
    await page
        .getByRole("link", { name: "Notifications & install", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL("**/coach/notifications");
    assert.equal(await page.locator(".coach-workspace").count(), 1);
    pass("Notifications stays in Coach Workspace");
    await page.goto(base + "/coach/training-plans");
    await button(page, "Create Program").click();
    await page
        .getByLabel("Program name")
        .fill(`QA Journey ${engine} ${Date.now()}`);
    await page.getByLabel("Duration (weeks)").fill("2");
    await addDay();
    await matrix("builder");
    await button(page, "Save & Assign").click();
    await page.waitForURL(/\/coach\/training-plans\/\d+\/assign$/);
    const assignUrl = page.url();
    await page.getByRole("heading", { name: /^Assign QA Journey/ }).waitFor();
    assert.ok((await page.locator(".trainee-table tbody tr").count()) > 0);
    pass("Save & Assign opens eligible trainees immediately");
    await page
        .getByLabel("Search trainees")
        .fill(accounts[fixture.trainee].name);
    await page.waitForResponse(
        (r) => r.url().includes("/assign?") && r.status() === 200,
    );
    await matrix("choose-trainee");
    await page
        .locator(".trainee-table tbody tr")
        .filter({ hasText: accounts[fixture.trainee].name })
        .getByRole("link", { name: "Assign", exact: true })
        .click();
    await reviewAndAssign();
    await matrix("trainee-overview");
    await page.goto(assignUrl);
    await page
        .getByText("Already assigned", { exact: true })
        .locator("visible=true")
        .waitFor();
    assert.equal(
        await page
            .locator(".trainee-table tbody tr")
            .filter({ hasText: accounts[fixture.trainee].name })
            .getByRole("link", { name: "Assign", exact: true })
            .count(),
        0,
    );
    pass("Already assigned training offers View rather than another copy");
    // A second, intentional trainee-first program must rotate the completed receipt.
    await page.goto(
        base + `/coach/trainees/${fixture.trainee_id}/setup?assign=1`,
    );
    await matrix("training-choice");
    await button(page, "Create New Program").click();
    await page.waitForURL(/\/coach\/training-plans\/create\?assign_to=/);
    assert.ok(await page.locator(".builder-trainee").textContent());
    await page
        .getByLabel("Program name")
        .fill(`QA Journey Trainee First ${engine} ${Date.now()}`);
    await page.getByLabel("Duration (weeks)").fill("1");
    await addDay();
    await button(page, "Save & Assign").click();
    await page.waitForURL(/\/setup\?template=/);
    await reviewAndAssign();
    pass(
        "Trainee-first creation returns directly to Schedule and supports a new assignment after completed setup",
    );
    for (const segment of ["calendar", "training-history", "progress"]) {
        await page.goto(
            base + `/coach/trainees/${fixture.trainee_id}/${segment}`,
        );
        await page.locator(".trainee-context").waitFor();
        pass(`Assigned trainee context is preserved on ${segment}`);
    }
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `coach-journey-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

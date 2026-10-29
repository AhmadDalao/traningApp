import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const output = process.env.QA_OUTPUT_DIR || "storage/app/private/v200-qa";
const fixture = JSON.parse(
    fs.readFileSync(path.join(output, "program-fixtures.json")),
);
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.ok(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const browser = await pw[process.env.QA_BROWSER || "chromium"].launch();
const issues = [],
    checks = [];
const pass = (label) => {
    checks.push(label);
    console.log("PASS", label);
};
async function login(index) {
    const account = accounts[index];
    assert.match(account.email, /^qa-v200-/);
    const ctx = await browser.newContext({
        viewport: { width: 320, height: 850 },
    });
    const page = await ctx.newPage();
    page.setDefaultTimeout(7000);
    page.on("pageerror", (e) => issues.push(e.message));
    page.on("console", (m) => {
        if (["error", "warning"].includes(m.type())) issues.push(m.text());
    });
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/(dashboard|coach)$/);
    return page;
}
async function matrix(page, label) {
    for (const width of [320, 360, 375, 390, 430, 768, 1280]) {
        await page.setViewportSize({ width, height: 850 });
        await page.evaluate(
            () =>
                new Promise((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(r)),
                ),
        );
        const d = await page.evaluate(() => ({
            width: innerWidth,
            scroll: document.documentElement.scrollWidth,
        }));
        assert.ok(
            d.scroll <= d.width + 1,
            `${label} ${width}: ${JSON.stringify(d)}`,
        );
        pass(`${label}: ${width}px`);
    }
    await page.setViewportSize({ width: 320, height: 850 });
    await page.screenshot({
        path: path.join(
            output,
            `${process.env.QA_BROWSER || "chromium"}-${label}.png`,
        ),
        fullPage: true,
    });
}
let coach;
try {
    coach = await login(fixture.coach_index);
    const prefix = `/coach/trainees/${fixture.trainee}`;
    await coach.goto(base + prefix + "/assign");
    await coach
        .getByLabel("Coach template")
        .selectOption(String(fixture.template));
    assert.equal(await coach.getByLabel("Start date").count(), 0);
    await matrix(coach, "coach-program-assignment");
    await coach
        .getByRole("button", { name: "Confirm assignment", exact: true })
        .click();
    await coach.waitForURL(/\/training-plans\/\d+$/);
    const definition = new URL(coach.url()).pathname;
    await matrix(coach, "coach-program-definition");
    await coach
        .getByRole("link", { name: "Start program", exact: true })
        .click();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    await coach
        .getByLabel("Start date")
        .fill(
            tomorrow.toLocaleDateString("en-CA", { timeZone: "Asia/Riyadh" }),
        );
    await coach
        .getByRole("button", { name: "Preview program schedule", exact: true })
        .click();
    await coach
        .getByRole("button", { name: "Confirm & start program", exact: true })
        .waitFor();
    await matrix(coach, "coach-program-preview");
    await coach
        .getByRole("button", { name: "Confirm & start program", exact: true })
        .click();
    await coach.waitForURL(/\/program-runs\/\d+$/);
    const runPath = new URL(coach.url()).pathname;
    const runId = runPath.split("/").at(-1);
    assert.equal(
        await coach
            .getByRole("button", { name: "Start workout", exact: true })
            .count(),
        0,
    );
    pass(
        "Coach starts the program schedule without gaining workout logging controls",
    );
    await matrix(coach, "coach-program-run");
    await coach.goto(base + definition);
    await coach
        .getByRole("button", { name: "Edit blocks", exact: true })
        .click();
    const dialog = coach.getByRole("dialog");
    assert.equal(await dialog.locator("fieldset[disabled]").count(), 1);
    await dialog
        .locator("fieldset")
        .nth(1)
        .getByLabel("Block name")
        .fill("Build reviewed on mobile");
    await dialog
        .getByRole("button", { name: "Save blocks", exact: true })
        .click();
    await dialog.waitFor({ state: "hidden" });
    await coach.goto(base + runPath);
    await coach
        .getByRole("button", { name: "Review program changes", exact: true })
        .click();
    await dialog.waitFor();
    await matrix(coach, "coach-future-block-review");
    await dialog
        .getByRole("button", { name: "Confirm changes", exact: true })
        .click();
    await dialog.waitFor({ state: "hidden" });
    pass(
        "Coach edits only a future block and explicitly applies the reviewed diff",
    );
    const trainee = await login(Number(process.env.QA_TRAINEE_INDEX || 2));
    await trainee.goto(base + `/program-runs/${runId}`);
    await trainee
        .getByRole("button", { name: "Start workout", exact: true })
        .click();
    await trainee.waitForURL(/\/workout$/);
    const row = trainee.locator(".workout-set").first();
    await row.getByLabel("Reps", { exact: true }).fill("10");
    await row.getByLabel("Weight (kg)").fill("0");
    await row
        .getByRole("button", { name: "Complete set 1", exact: true })
        .click();
    await row
        .getByRole("button", { name: "Save set 1", exact: true })
        .waitFor();
    await trainee
        .getByRole("button", { name: "Finish workout", exact: true })
        .first()
        .click();
    await trainee
        .getByRole("dialog")
        .getByRole("button", { name: "Finish anyway", exact: true })
        .click();
    await trainee.waitForURL(/\/schedule-entries\/\d+$/);
    await coach.goto(base + runPath);
    await coach.getByText("WEEK 2 OF 2", { exact: true }).waitFor();
    pass(
        "Trainee logging advances the program and coach sees the new block read-only",
    );
    await coach.goto(base + prefix);
    await matrix(coach, "coach-program-monitoring");
    await trainee.goto(base + `/training-plans/${fixture.long_program}`);
    await matrix(trainee, "long-program-and-block-names");
    await trainee.goto(base + "/calendar");
    await matrix(trainee, "program-calendar");
    assert.deepEqual(issues, []);
    pass("Coach program flow ends without console errors or React warnings");
    fs.writeFileSync(
        path.join(
            output,
            `coach-program-browser-${process.env.QA_BROWSER || "chromium"}.json`,
        ),
        JSON.stringify(
            { base, checks, count: checks.length, issues, definition, runPath },
            null,
            2,
        ),
    );
    console.log("COMPLETE", checks.length);
} catch (e) {
    if (coach)
        await coach.screenshot({
            path: path.join(output, "coach-program-failure.png"),
            fullPage: true,
        });
    throw e;
} finally {
    await browser.close();
}

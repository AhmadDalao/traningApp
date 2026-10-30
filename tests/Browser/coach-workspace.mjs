import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR;
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const fixtures = JSON.parse(
    fs.readFileSync(path.join(output, "workspace-fixtures.json")),
);
const engine = process.env.QA_BROWSER || "chromium",
    f = fixtures[engine];
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.equal(base, "http://localhost:8000", "Source-release QA is local only");
const browser = await pw[engine].launch();
const issues = [],
    checks = [];
const pass = (name) => {
    checks.push(name);
    console.log("PASS", name);
};
const context = await browser.newContext({
    viewport: { width: 320, height: 850 },
});
const page = await context.newPage();
page.setDefaultTimeout(10000);
page.on("pageerror", (e) => issues.push(e.message));
page.on("console", (m) => {
    if (["error", "warning"].includes(m.type())) issues.push(m.text());
});
async function visit(url) {
    await page.goto(base + url);
    await page.locator("#app > *").first().waitFor();
}
async function matrix(label) {
    for (const [width, height] of [
        [320, 850],
        [360, 850],
        [375, 850],
        [390, 850],
        [430, 850],
        [768, 850],
        [1280, 850],
        [320, 480],
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
            `${label}: ${width}x${height} overflow`,
        );
        pass(`${label} ${width}x${height}`);
    }
    await page.setViewportSize({ width: 390, height: 850 });
}
async function screenshot(name, width = 390) {
    await page.setViewportSize({ width, height: 850 });
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
    await page.screenshot({
        path: path.join(output, `${engine}-workspace-${name}.png`),
        fullPage: true,
    });
}
try {
    const account = accounts[fixtures.coach];
    assert.match(account.email, /^qa-v200-/);
    await visit("/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    await visit("/coach");
    await matrix("Operational overview");
    await screenshot("overview");
    await visit("/coach/trainees");
    await matrix("Operational roster");
    await page.setViewportSize({ width: 1280, height: 850 });
    assert.ok(await page.locator(".trainee-table table").isVisible());
    assert.ok(!(await page.locator(".trainee-mobile-cards").isVisible()));
    pass("Desktop renders a real table");
    await screenshot("table", 1280);
    await page.setViewportSize({ width: 320, height: 850 });
    assert.ok(await page.locator(".trainee-mobile-cards").isVisible());
    assert.ok(!(await page.locator(".trainee-table").isVisible()));
    pass("Mobile uses cards without hidden table overflow");
    await screenshot("cards");
    for (const [name, key] of [
        ["Needs setup", "setup"],
        ["Needs attention", "attention"],
        ["Paused", "paused"],
    ]) {
        await page
            .getByRole("navigation", { name: "Trainee filters" })
            .getByRole("button", { name: new RegExp("^" + name) })
            .click();
        await page.waitForURL(new RegExp("status=" + key));
        assert.ok(
            (await page.locator(".trainee-mobile-cards article").count()) > 0,
        );
        pass(`${name} filter has real fixtures`);
    }
    await page
        .getByRole("button", { name: "Add trainee", exact: true })
        .first()
        .click();
    await page
        .getByLabel("Training Planner ID", { exact: true })
        .fill(f.pending_code);
    await page
        .getByRole("button", { name: "Find member", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Send request", exact: true })
        .waitFor();
    await matrix("Secure add trainee");
    await page
        .getByRole("button", { name: "Send request", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "Request sent", exact: true })
        .waitFor();
    await page.getByRole("button", { name: "Done", exact: true }).click();
    pass("Request sent remains in context");
    const prefix = `/coach/trainees/${f.trainee}`;
    await visit(prefix);
    await page
        .getByRole("link", { name: "Continue setup", exact: true })
        .click();
    await page.waitForURL(new RegExp(prefix + "/setup"));
    await page
        .getByRole("button", { name: "Create coaching plan", exact: true })
        .click();
    await page
        .getByRole("dialog")
        .getByLabel("Package name")
        .fill("QA Wizard package " + engine);
    await page
        .getByLabel("Duration in days (optional)", { exact: true })
        .fill("90");
    await page
        .getByRole("button", { name: "Save package", exact: true })
        .click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    pass("Create coaching plan returns to setup");
    await page
        .getByLabel(/^Coaching plan/)
        .selectOption({ label: "QA Wizard package " + engine + " · 90 days" });
    await matrix("Setup coaching");
    await screenshot("step1");
    await page
        .getByRole("button", { name: "Save coaching & continue", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "2. Choose training", exact: true })
        .waitFor();
    await page
        .getByRole("button", { name: "Save & finish later", exact: true })
        .first()
        .click();
    await page.waitForURL(base + prefix);
    pass("Coaching-only setup is saved");
    await visit(prefix + "/setup");
    await page
        .getByRole("heading", { name: "2. Choose training", exact: true })
        .waitFor();
    pass("Continue setup resumes at missing training");
    await page.getByLabel("Coach template").selectOption(String(f.template));
    await matrix("Setup training");
    await screenshot("step2");
    await page
        .getByRole("button", { name: "Save training & continue", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "3. Preview the schedule", exact: true })
        .waitFor();
    await page.getByLabel("Start program on").fill(fixtures.tomorrow);
    await page
        .getByRole("button", { name: "Preview schedule", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Activate coaching", exact: true })
        .waitFor();
    await matrix("Human schedule preview");
    await screenshot("step3");
    assert.equal(
        await page
            .getByRole("heading", { name: /^(Add|Keep|Remove|Protected)$/ })
            .count(),
        0,
    );
    pass("New setup shows workouts instead of reconciliation jargon");
    await page
        .getByRole("button", { name: "Activate coaching", exact: true })
        .click();
    await page.waitForURL(base + prefix);
    pass("Activation returns to trainee console");
    const contextHeader = page.getByRole("region", { name: "Current trainee" });
    await contextHeader.getByText("Active", { exact: true }).waitFor();
    await screenshot("console");
    await page
        .getByRole("link", { name: "Schedule workout", exact: true })
        .first()
        .click();
    await page.getByLabel("Date", { exact: false }).fill(fixtures.tomorrow);
    await page.getByLabel("Time (Optional)", { exact: true }).fill("20:00");
    const planSelect = page.getByLabel(/^Training plan/);
    await planSelect.selectOption({ index: 1 });
    await page.getByLabel("Training day").selectOption({ index: 1 });
    await page
        .getByLabel("Session title", { exact: true })
        .fill("QA quick workout " + engine);
    await matrix("Context quick schedule");
    await page
        .getByRole("button", {
            name: /^(Schedule session|Save to calendar|Schedule training|Save session)$/i,
        })
        .click();
    await page.waitForURL(/\/schedule-entries\/\d+$/);
    pass("Quick schedule creates a trainee session");
    for (const [url, label] of [
        ["", "Overview"],
        ["/training-plans", "Program"],
        ["/calendar", "Calendar"],
        ["/training-history", "History"],
        ["/progress", "Progress"],
    ]) {
        await visit(prefix + url);
        assert.ok(
            await page
                .getByRole("region", { name: "Current trainee" })
                .getByRole("link", { name: f.name, exact: true })
                .isVisible(),
        );
        await matrix("Trainee " + label);
        pass(`${label} preserves trainee identity`);
    }
    await visit(prefix + "/coaching");
    await matrix("In-context coaching");
    await page.getByRole("button", { name: "Pause", exact: true }).click();
    await page.getByRole("button", { name: "Resume", exact: true }).waitFor();
    pass("Pause coaching in trainee context");
    await page.getByRole("button", { name: "Resume", exact: true }).click();
    await page.getByRole("button", { name: "Pause", exact: true }).waitFor();
    pass("Resume coaching in trainee context");
    await page
        .getByRole("button", { name: "End subscription", exact: true })
        .click();
    const dialog = page.getByRole("dialog");
    await dialog
        .getByRole("button", { name: "End subscription", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Start coaching", exact: true })
        .waitFor();
    pass("End coaching preserves trainee data");
    await visit(prefix + "/training-history");
    await page
        .getByRole("region", { name: "Current trainee" })
        .getByText(/Read-only access/)
        .waitFor();
    pass("Ended coaching keeps read-only history");
    await visit("/dashboard");
    assert.equal(
        await page.getByRole("region", { name: "Current trainee" }).count(),
        0,
    );
    pass("Personal Workspace has no trainee context");
    await visit("/coach/training-plans");
    await page
        .getByLabel("Search templates", { exact: true })
        .fill("QA Guided strength " + engine);
    await page.getByRole("button", { name: "Filter", exact: true }).click();
    await page.waitForURL(/search=QA/);
    await page
        .getByRole("link", { name: "Assign to trainee →", exact: true })
        .waitFor();
    await matrix("Coach template library");
    await page
        .getByRole("link", { name: "Assign to trainee →", exact: true })
        .click();
    await page
        .getByRole("heading", { name: "Assign to trainee", exact: true })
        .waitFor();
    pass("Program assignment uses shared setup entry");
    assert.deepEqual(issues, []);
    pass("No browser runtime or console errors");
} catch (error) {
    await page.screenshot({
        path: path.join(output, `${engine}-workspace-failure.png`),
        fullPage: true,
    });
    fs.writeFileSync(
        path.join(output, `${engine}-workspace-failure.html`),
        await page.content(),
    );
    throw error;
} finally {
    fs.writeFileSync(
        path.join(output, `workspace-${engine}-results.json`),
        JSON.stringify({ checks, issues }, null, 2),
    );
    await browser.close();
}
console.log("COMPLETE", checks.length);

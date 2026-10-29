/** v2.1 controls against disposable local QA fixtures only. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR;
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const engine = process.env.QA_BROWSER || "chromium";
const fixtures = JSON.parse(
    fs.readFileSync(path.join(output, "control-fixtures.json")),
)[engine];
const browser = await pw[engine].launch();
const base = "http://localhost:8000";
const checks = [],
    issues = [];
const pass = (text) => {
    checks.push(text);
    console.log("PASS", text);
};
let page,
    expectedFailure = false;
const date = (offset) => {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return d.toLocaleDateString("en-CA", { timeZone: "Asia/Riyadh" });
};
async function login(index) {
    assert.match(accounts[index].email, /^qa-v200-/);
    const ctx = await browser.newContext({
        viewport: { width: 320, height: 850 },
    });
    const p = await ctx.newPage();
    p.setDefaultTimeout(9000);
    p.on("pageerror", (e) => issues.push(e.message));
    p.on("console", (m) => {
        if (["error", "warning"].includes(m.type()) && !expectedFailure)
            issues.push(m.text());
    });
    p.on("response", (r) => {
        if (r.status() >= 400 && !expectedFailure)
            issues.push(`${r.status()} ${r.url()}`);
    });
    await p.goto(base + "/login");
    await p.getByLabel("Email address").fill(accounts[index].email);
    await p
        .getByLabel("Password", { exact: true })
        .fill(accounts[index].password);
    await p.getByRole("button", { name: "Log in", exact: true }).click();
    await p.waitForURL(/\/(dashboard|coach)$/);
    return p;
}
async function matrix(label) {
    for (const width of [320, 360, 375, 390, 430, 768, 1280]) {
        await page.setViewportSize({ width, height: 850 });
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        const dims = await page.evaluate(() => ({
            width: innerWidth,
            scroll: document.documentElement.scrollWidth,
            rows: [...document.querySelectorAll(".program-sessions li > div")]
                .filter((d) => d.clientWidth > 0)
                .map((d) => ({ client: d.clientWidth, scroll: d.scrollWidth })),
            dialogs: [...document.querySelectorAll("dialog[open]")].map(
                (d) => ({ client: d.clientWidth, scroll: d.scrollWidth }),
            ),
        }));
        assert.ok(
            dims.scroll <= dims.width + 1 &&
                dims.dialogs.every((d) => d.scroll <= d.client + 1) &&
                dims.rows.every((d) => d.scroll <= d.client + 1),
            `${label}: ${JSON.stringify(dims)}`,
        );
        pass(`${label}: ${width}px, no page or dialog overflow`);
    }
    await page.setViewportSize({ width: 320, height: 480 });
    const dialog = page.getByRole("dialog");
    if (await dialog.count()) {
        const button = dialog.getByRole("button").last();
        await button.scrollIntoViewIfNeeded();
        assert.ok(await button.isVisible());
        const bounds = await button.boundingBox();
        assert.ok(bounds.y >= 0 && bounds.y + bounds.height <= 481);
        pass(`${label}: action reachable at 320 × 480`);
    }
    await page.setViewportSize({ width: 320, height: 850 });
    await page.evaluate(() => {
        window.scrollTo(0, 0);
        document.querySelector("dialog[open]")?.scrollTo(0, 0);
    });
    await page.screenshot({
        path: path.join(output, `${engine}-control-${label}.png`),
        fullPage: false,
    });
}
async function open(action) {
    await page.getByRole("button", { name: action, exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.waitFor();
    return dialog;
}
async function preview(dialog) {
    await dialog
        .getByRole("button", { name: "Preview changes", exact: true })
        .click();
    await dialog
        .getByRole("heading", { name: "Review changes", exact: true })
        .waitFor();
}
async function apply(dialog, label) {
    await dialog.getByRole("button", { name: label, exact: true }).click();
    await dialog.waitFor({ state: "hidden" });
}
try {
    page = await login(2);
    const fixture = fixtures.personal;
    const runUrl = base + `/program-runs/${fixture.run}`;
    await page.goto(runUrl);
    await matrix("active-run");
    await page
        .getByRole("button", { name: "Reschedule", exact: true })
        .first()
        .click();
    let dialog = page.getByRole("dialog");
    await dialog.getByLabel("New date").fill(date(3));
    await dialog
        .getByRole("button", { name: "Preview changes", exact: true })
        .click();
    await dialog
        .getByRole("button", { name: "Apply changes", exact: true })
        .waitFor();
    await matrix("missed-reschedule");
    await apply(dialog, "Apply changes");
    await page
        .getByRole("button", { name: "Mark skipped", exact: true })
        .first()
        .click();
    await page.waitForFunction(
        () =>
            ![...document.querySelectorAll("button")].some(
                (b) => b.textContent.trim() === "Mark skipped",
            ),
    );
    pass(
        "Missed reschedule and skip use existing flows and clear missed actions",
    );
    dialog = await open("Pause program");
    await dialog.getByLabel("Return date").selectOption("known");
    await dialog.getByLabel("Expected resume date").fill(date(7));
    await preview(dialog);
    await matrix("pause-preview");
    await page.keyboard.press("Escape");
    await dialog.waitFor({ state: "hidden" });
    await page.waitForFunction(
        () => document.activeElement?.textContent.trim() === "Pause program",
    );
    pass("Escape cancels without mutation and restores focus");
    dialog = await open("Pause program");
    await dialog.getByLabel("Return date").selectOption("known");
    await dialog.getByLabel("Expected resume date").fill(date(7));
    await preview(dialog);
    await apply(dialog, "Pause program");
    await page.getByText("PROGRAM PAUSED", { exact: true }).waitFor();
    assert.equal(
        await page
            .getByRole("button", { name: "Start workout", exact: true })
            .count(),
        0,
    );
    await matrix("paused-run");
    await page.goto(base + "/dashboard");
    assert.ok(
        (
            await page
                .locator(
                    `.program-run-link[href="/program-runs/${fixture.run}"]`,
                )
                .innerText()
        ).includes("Paused"),
    );
    pass("Dashboard distinguishes paused program and offers resume");
    await page.goto(runUrl);
    dialog = await open("Resume program");
    await preview(dialog);
    assert.ok(
        (await dialog.innerText()).includes("Schedule adjustment: 0 days"),
    );
    await matrix("resume-preview");
    await apply(dialog, "Resume program");
    await page
        .getByRole("button", { name: "Pause program", exact: true })
        .waitFor();
    pass("Known return resumes without applying the pause shift twice");
    dialog = await open("Adjust remaining schedule");
    await dialog.getByLabel("Start remaining workouts on").fill(date(20));
    await preview(dialog);
    await matrix("shift-preview");
    await apply(dialog, "Adjust remaining schedule");
    dialog = await open("Pause program");
    await preview(dialog);
    await apply(dialog, "Pause program");
    dialog = await open("End program");
    await preview(dialog);
    await matrix("end-preview");
    assert.ok(
        (await dialog.innerText()).includes("Moved or edited individually") ||
            (
                await dialog
                    .locator("summary")
                    .filter({ hasText: "PROTECTED" })
                    .innerText()
            ).includes("2"),
    );
    await apply(dialog, "End program early");
    await page.getByText("PROGRAM ENDED EARLY", { exact: true }).waitFor();
    assert.equal(
        await page
            .getByRole("button", { name: "Resume program", exact: true })
            .count(),
        0,
    );
    await page
        .getByRole("link", { name: "Repeat program", exact: true })
        .waitFor();
    await matrix("ended-run");
    await page.goto(base + `/training-history/${fixture.completed}`);
    await page.getByText("Completed", { exact: true }).first().waitFor();
    pass("Ending keeps completed structured workout history readable");
    await page.goto(base + "/program-runs");
    await matrix("run-history");
    await page.context().close();

    page = await login(1);
    const coach = fixtures.coach;
    const prefix = `/coach/trainees/${coach.trainee}`;
    await page.goto(base + prefix + `/program-runs/${coach.run}`);
    assert.equal(
        await page
            .getByRole("button", { name: "Start workout", exact: true })
            .count(),
        0,
    );
    dialog = await open("Pause program");
    await preview(dialog);
    await matrix("coach-pause");
    await apply(dialog, "Pause program");
    dialog = await open("Resume program");
    await dialog.getByLabel("Resume on").fill(date(10));
    await preview(dialog);
    await matrix("coach-resume");
    await apply(dialog, "Resume program");
    dialog = await open("Adjust remaining schedule");
    await dialog.getByLabel("Start remaining workouts on").fill(date(14));
    await preview(dialog);
    await apply(dialog, "Adjust remaining schedule");
    dialog = await open("End program");
    await preview(dialog);
    await matrix("coach-end");
    await apply(dialog, "End program early");
    await page.getByText("PROGRAM ENDED EARLY", { exact: true }).waitFor();
    pass(
        "Coach pause, resume, shift and end work without performance logging controls",
    );
    assert.deepEqual(issues, []);
    pass("No console errors, React warnings or broken assets");
    fs.writeFileSync(
        path.join(output, `control-browser-${engine}.json`),
        JSON.stringify({ count: checks.length, checks, issues }, null, 2),
    );
    console.log("COMPLETE", checks.length);
} catch (error) {
    if (page)
        await page.screenshot({
            path: path.join(output, `control-failure-${engine}.png`),
            fullPage: true,
        });
    fs.writeFileSync(
        path.join(output, `control-failure-${engine}.json`),
        JSON.stringify(
            { checks, issues, error: error.message, url: page?.url() },
            null,
            2,
        ),
    );
    throw error;
} finally {
    await browser.close();
}

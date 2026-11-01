/** Complete program-first activation at every release viewport; disposable fixtures only. */
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE || "playwright");
const output = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
const f = JSON.parse(
    fs.readFileSync(path.join(output, "atomic-fixtures.json")),
);
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const base = process.env.QA_BASE_URL || "http://localhost:8000";
assert.equal(base, "http://localhost:8000");
assert.match(accounts[f.coach].email, /^qa-v200-/);
const browser = await pw[engine].launch();
const context = await browser.newContext();
const page = await context.newPage();
page.setDefaultTimeout(12000);
const checks = [],
    issues = [];
page.on("pageerror", (e) => issues.push(e.message));
page.on("console", (m) => {
    if (["error", "warning"].includes(m.type())) issues.push(m.text());
});
const pass = (s) => {
    checks.push(s);
    console.log("PASS", s);
};
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
async function visit(url) {
    await page.goto(base + url);
    await page.locator("#app > *").first().waitFor();
}
async function fit(stage) {
    assert.ok(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        stage + " overflow",
    );
    pass(stage + " fits");
}
async function waitReview() {
    await page.locator(".setup-review-rows li").first().waitFor();
}
try {
    await visit("/login");
    await page.getByLabel("Email address").fill(accounts[f.coach].email);
    await page
        .getByLabel("Password", { exact: true })
        .fill(accounts[f.coach].password);
    await button("Log in").click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    for (const [i, [width, height]] of [
        [320, 850],
        [360, 850],
        [375, 850],
        [390, 850],
        [430, 850],
        [768, 850],
        [1280, 850],
        [320, 480],
    ].entries()) {
        const trainee = f[engine][i],
            prefix = `/coach/trainees/${trainee.trainee}`;
        await page.setViewportSize({ width, height });
        await visit(prefix);
        await page
            .getByRole("link", { name: "Activate Coaching", exact: true })
            .click();
        await page
            .getByRole("heading", { name: "1. Choose Training Program" })
            .waitFor();
        assert.ok(
            await page
                .getByRole("region", { name: "Current trainee" })
                .getByText(trainee.name, { exact: true })
                .isVisible(),
        );
        await page
            .getByRole("radio", {
                name: f.template_name || "QA Twelve-week Strength",
                exact: true,
            })
            .check();
        await fit(`Training ${width}x${height}`);
        await button("Next: Dates & Schedule").click();
        await page
            .getByLabel("Training starts")
            .fill(i === 0 ? f.today : f.start);
        await fit(`Dates ${width}x${height}`);
        await button("Review subscription & calendar").click();
        await waitReview();
        await page
            .getByLabel(/^Coaching Package/)
            .selectOption(String(f.package));
        await button("Refresh review").click();
        await waitReview();
        assert.ok(!(await button("Activate Coaching").isEnabled()));
        assert.ok(
            (await page.getByText(/Extension:.*days/).count()) ||
                (await page
                    .getByText("Include this subscription extension")
                    .isVisible()),
        );
        await page.getByLabel("Include this subscription extension").check();
        await button("Activate Coaching").waitFor();
        await waitReview();
        if (i === 0) {
            const invalid = page
                .locator(".setup-review-rows li")
                .filter({ has: page.locator(".field-error") });
            assert.ok((await invalid.count()) > 0);
            const oldCount = await page
                .locator(".setup-review-rows li")
                .count();
            await invalid
                .first()
                .getByRole("button", {
                    name: /^Anytime( Today)?$/,
                    exact: true,
                })
                .click();
            await waitReview();
            await button("Activate Coaching").waitFor({ state: "visible" });
            assert.equal(
                await page.locator(".setup-review-rows li").count(),
                oldCount,
            );
            pass(
                "Elapsed workout corrected inline without moving or omitting a row",
            );
        }
        await fit(`Review ${width}x${height}`);
        assert.equal(await page.locator(".setup-review-rows li").count(), 48);
        await page
            .locator(".setup-review-rows")
            .evaluate((e) => (e.scrollTop = e.scrollHeight));
        await page
            .locator(".setup-review-rows li")
            .last()
            .scrollIntoViewIfNeeded();
        assert.ok(
            await page.locator(".setup-review-rows li").last().isVisible(),
        );
        await button("Activate Coaching").scrollIntoViewIfNeeded();
        assert.ok(await button("Activate Coaching").isEnabled());
        if (i === 7 || i === 6) {
            await page.evaluate(() => {
                document.activeElement?.blur();
                window.scrollTo(0, 0);
            });
            await page.screenshot({
                path: path.join(
                    output,
                    `${engine}-atomic-review-${width}x${height}.png`,
                ),
                fullPage: true,
            });
        }
        await button("Activate Coaching").click();
        await page.waitForURL(base + prefix);
        assert.equal(
            await page
                .getByRole("link", { name: "Activate Coaching", exact: true })
                .count(),
            0,
        );
        pass(`Complete 48-workout activation ${width}x${height}`);
    }
    const prefix = `/coach/trainees/${f[engine][7].trainee}`;
    await visit(prefix + "/coaching");
    await button("Pause").click();
    await button("Resume").waitFor();
    await visit(prefix);
    await page
        .getByRole("link", { name: "Resume Coaching", exact: true })
        .click();
    await button("Next: Dates & Schedule").click();
    await page.getByText(/Schedule already active/).waitFor();
    await button("Review subscription & calendar").click();
    await waitReview();
    await page
        .getByText("Coaching Subscription will be resumed today.")
        .waitFor();
    await button("Resume Coaching").click();
    await page.waitForURL(base + prefix);
    pass("Prominent Resume Coaching explicitly preserves schedule");
    await visit(prefix + "/coaching");
    await button("End subscription").click();
    await page
        .getByRole("dialog")
        .getByRole("button", { name: "End subscription", exact: true })
        .click();
    await button("Start coaching").waitFor();
    await visit(prefix);
    await page
        .getByRole("link", { name: "Renew Coaching", exact: true })
        .click();
    await button("Next: Dates & Schedule").click();
    await button("Review subscription & calendar").click();
    await waitReview();
    await page
        .getByText(
            "A new Coaching Subscription starts today. The previous period is preserved.",
        )
        .waitFor();
    await button("Renew Coaching").click();
    await page.waitForURL(base + prefix);
    pass("Prominent Renew Coaching starts a new period");
    assert.deepEqual(issues, []);
    pass("No console or runtime errors");
} catch (error) {
    await page.screenshot({
        path: path.join(output, `${engine}-atomic-failure.png`),
        fullPage: true,
    });
    fs.writeFileSync(
        path.join(output, `${engine}-atomic-failure.html`),
        await page.content(),
    );
    throw error;
} finally {
    fs.writeFileSync(
        path.join(output, `atomic-${engine}-results.json`),
        JSON.stringify({ checks, issues }, null, 2),
    );
    await browser.close();
}
console.log("COMPLETE", checks.length);

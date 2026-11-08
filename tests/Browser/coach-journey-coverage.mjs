/** Disposable local fixtures exercise inline coaching changes through the real assignment UI. */
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
const cases = JSON.parse(
    fs.readFileSync(path.join(out, "coaching-cases.json")),
)[engine];
const account = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE))[
    fixture.coach
];
assert.match(account.email, /^qa-v200-/);
const browser = await pw[engine].launch(),
    context = await browser.newContext({
        viewport: { width: 390, height: 844 },
    }),
    page = await context.newPage();
page.setDefaultTimeout(15000);
const checks = [],
    errors = [];
page.on("pageerror", (e) => errors.push(e.message));
const pass = (x) => {
    checks.push(x);
    console.log("PASS", x);
};
const button = (name) =>
    page.getByRole("button", { name, exact: true }).locator("visible=true");
try {
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await button("Log in").click();
    await page.waitForURL(/\/(coach|dashboard)$/);
    for (const [mode, record] of Object.entries(cases)) {
        const url = `/coach/trainees/${record.trainee_id}/setup?template=${record.template_id}`;
        await page.goto(base + url);
        await page.locator(".assignment-day").first().waitFor();
        const today = JSON.parse(
            await page.locator("#app").getAttribute("data-page"),
        ).props.today;
        const start = new Date(today + "T12:00:00Z");
        start.setUTCDate(start.getUTCDate() + 7);
        const date = start.toISOString().slice(0, 10);
        await page.getByLabel("Start training", { exact: false }).fill(date);
        if (mode === "new") {
            await button("Save & finish later").click();
            await page.waitForURL(
                base + `/coach/trainees/${record.trainee_id}`,
            );
            await page.goto(base + url);
            await page.locator(".assignment-day").first().waitFor();
            assert.equal(
                await page
                    .getByLabel("Start training", { exact: false })
                    .inputValue(),
                date,
            );
            pass(
                "Finish later and template reload preserve dates without activating",
            );
            await button("Create Coaching Package").click();
            const modal = page.getByRole("dialog", {
                name: "Create coaching package",
            });
            await modal
                .getByLabel("Package name")
                .fill("QA 7 Day Package " + engine);
            await modal.getByLabel("Duration in days (optional)").fill("7");
            await modal
                .getByRole("button", { name: "Save package", exact: true })
                .click();
            await modal.waitFor({ state: "detached" });
            assert.equal(
                await page
                    .getByLabel("Start training", { exact: false })
                    .inputValue(),
                date,
            );
            await page
                .getByLabel("Coaching Package", { exact: false })
                .selectOption({
                    label: "QA 7 Day Package " + engine + " · 7 days",
                });
            pass(
                "Inline package creation preserves trainee, program and schedule",
            );
        }
        await button("Continue to Review").click();
        await page.locator(".assignment-review-rows li").first().waitFor();
        const extend = page.getByRole("checkbox", {
            name: /Extend coaching through/,
        });
        if (mode === "new" || mode === "short") {
            assert.ok(await button("Assign & Schedule").isDisabled());
            await extend.check();
            await page.waitForResponse(
                (r) => r.url().endsWith("/setup/preview") && r.ok(),
            );
            await page.locator('.setup-fields[aria-busy="false"]').waitFor();
            pass(mode + ": coverage extension is explicit and re-reviewed");
        }
        if (mode !== "short") {
            const verb = { new: "Activate", paused: "Resume", ended: "Renew" }[
                mode
            ];
            const confirm = page.getByRole("checkbox", {
                name: new RegExp("^" + verb + " coaching today"),
            });
            assert.ok(await button("Assign & Schedule").isDisabled());
            await confirm.check();
            pass(
                mode +
                    ": " +
                    verb +
                    " is explicit and stays in assignment flow",
            );
        }
        assert.ok(await button("Assign & Schedule").isEnabled());
        await button("Assign & Schedule").click();
        await page.waitForURL(base + `/coach/trainees/${record.trainee_id}`);
        await page
            .getByRole("heading", { name: "Current Training", exact: true })
            .waitFor();
        pass(mode + ": one confirmation returns to active trainee training");
    }
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} catch (error) {
    await page.screenshot({
        path: path.join(out, `coverage-failure-${engine}.png`),
        fullPage: true,
    });
    console.log("Failure URL:", page.url());
    throw error;
} finally {
    fs.writeFileSync(
        path.join(out, `coach-journey-coverage-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

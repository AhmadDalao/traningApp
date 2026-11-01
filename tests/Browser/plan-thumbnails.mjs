/** Exercise-backed plan covers, responsive layouts and failed-image fallback. */
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
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE));
const fixture = JSON.parse(
    fs.readFileSync(path.join(out, "thumbnail-fixtures.json")),
)[engine];
const browser = await pw[engine].launch();
const context = await browser.newContext({ serviceWorkers: "block" });
const page = await context.newPage();
page.setDefaultTimeout(20000);
const checks = [],
    errors = [];
page.on("pageerror", (error) => errors.push(error.message));
const pass = (name) => {
    checks.push(name);
    console.log("PASS", name);
};
async function login(index) {
    const account = accounts[index];
    assert.match(account.email, /^qa-v200-/);
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/(coach|dashboard)$/);
}
async function loaded() {
    await page.locator(".plan-thumbnail").first().waitFor();
    await page.locator(".plan-thumbnail").evaluateAll((images) =>
        images.forEach((img) => {
            img.loading = "eager";
        }),
    );
    await page.waitForFunction(() =>
        [...document.querySelectorAll(".plan-thumbnail")].every(
            (img) => img.complete && img.naturalWidth > 0,
        ),
    );
}
async function matrix(name) {
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
        await page.evaluate(() => window.scrollTo(0, 0));
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        assert.ok(
            await page
                .locator(".plan-thumbnail")
                .evaluateAll((images) =>
                    images.every(
                        (img) => img.getBoundingClientRect().height >= 70,
                    ),
                ),
        );
        assert.ok(
            await page.locator(".plan-actions > .btn").evaluateAll((buttons) =>
                buttons.every((button) => {
                    const box = button.getBoundingClientRect();
                    return box.width >= 90 && box.height <= 52;
                }),
            ),
            "Plan actions remain readable without letter-by-letter wrapping",
        );
        pass(`${name}: thumbnails fit ${width}x${height}`);
        if (width === 390 || width === 1280)
            await page.screenshot({
                path: path.join(out, `${engine}-${name}-${width}.png`),
                fullPage: true,
            });
    }
}
try {
    await login(fixture.trainee);
    await page.goto(base + "/recommendations");
    await loaded();
    assert.equal(
        await page.locator(".recommended-card .plan-thumbnail").count(),
        3,
    );
    assert.equal(
        await page.locator(".catalog-row .plan-thumbnail").count(),
        23,
    );
    assert.ok(
        await page
            .locator(".plan-thumbnail")
            .evaluateAll((images) =>
                images.every((img) =>
                    img.getAttribute("src").startsWith("/exercise-library/"),
                ),
            ),
    );
    pass("Recommended and all-catalog cards load real exercise photos");
    await matrix("discovery");
    await context.route("**/exercise-library/**", (route) => route.abort());
    await page.reload();
    await loaded();
    assert.ok(
        await page
            .locator(".plan-thumbnail")
            .evaluateAll((images) =>
                images.every(
                    (img) => img.getAttribute("src") === "/images/gym.jpg",
                ),
            ),
    );
    pass("Broken image requests fall back to a loaded gym photo");
    await context.unroute("**/exercise-library/**");
    await page.goto(base + "/training-plans");
    await loaded();
    assert.equal(await page.locator(".plan-thumbnail").count(), 3);
    assert.equal(
        await page.locator('.plan-thumbnail[src="/images/gym.jpg"]').count(),
        1,
    );
    pass(
        "Personal and assigned plans use exercise covers; empty plan uses fallback",
    );
    await matrix("personal-plans");
    await page
        .getByRole("button", { name: "Log out", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL(base + "/login");
    await login(fixture.coach);
    await page.goto(base + "/coach/training-plans");
    await loaded();
    assert.equal(await page.locator(".plan-thumbnail").count(), 2);
    await matrix("coach-plans");
    await page.goto(
        base + `/coach/trainees/${fixture.trainee_id}/training-plans`,
    );
    await loaded();
    assert.equal(await page.locator(".plan-thumbnail").count(), 1);
    pass("Coach trainee list shows only its authorized assignment cover");
    await matrix("assigned-plans");
    await page.goto(base + "/coach/recommendations");
    await loaded();
    assert.equal(await page.locator(".plan-thumbnail").count(), 26);
    pass("Coach discovery shares the same thumbnail presentation");
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `thumbnails-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

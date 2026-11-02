/** Shared Coach/Personal program cards: real images, layout, links and fallback. */
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
const fixture = JSON.parse(fs.readFileSync(path.join(out, "fixtures.json")))[
    engine
];
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
const cards = () => page.locator(".program-run-summary");
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
    await cards().first().waitFor();
    await cards()
        .locator("img")
        .evaluateAll((images) =>
            images.forEach((img) => {
                img.loading = "eager";
            }),
        );
    await page.waitForFunction(() =>
        [...document.querySelectorAll(".program-run-summary img")].every(
            (img) => img.complete && img.naturalWidth > 0,
        ),
    );
}
async function matrix(name, count) {
    await loaded();
    assert.equal(await cards().count(), count);
    assert.equal(
        new Set(
            await cards().evaluateAll((rows) =>
                rows.map((row) => row.getAttribute("href")),
            ),
        ).size,
        count,
    );
    pass(`${name}: each saved run retains a distinct link`);
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
        const boxes = await cards().evaluateAll((rows) =>
            rows.map((row) => {
                const rect = (el) => {
                    const b = el.getBoundingClientRect();
                    return {
                        x: b.x,
                        y: b.y,
                        width: b.width,
                        height: b.height,
                        right: b.right,
                        bottom: b.bottom,
                    };
                };
                return {
                    row: rect(row),
                    image: rect(row.querySelector("img")),
                    copy: rect(row.querySelector(".program-run-copy")),
                    action: rect(row.querySelector(".program-view")),
                    direction: getComputedStyle(row).display,
                };
            }),
        );
        for (const b of boxes) {
            assert.equal(b.direction, "grid");
            assert.ok(b.image.width >= 60 && b.image.height >= 60);
            assert.ok(b.image.right <= b.copy.x && b.copy.right <= b.row.right);
            assert.ok(
                Math.abs(b.image.y - b.copy.y) < (b.row.width <= 620 ? 2 : 40),
                "Photo and copy stay beside each other",
            );
            assert.ok(
                b.action.right <= b.row.right &&
                    b.action.bottom <= b.row.bottom,
            );
            if (b.row.width <= 620)
                assert.ok(
                    b.action.height >= 44 && b.action.y >= b.copy.bottom,
                    JSON.stringify(b),
                );
            else
                assert.ok(
                    b.action.x >= b.copy.right && b.row.height < 200,
                    "Desktop row stays compact with a trailing action",
                );
        }
        pass(
            `${name}: aligned photo, details and action at ${width}x${height}`,
        );
        if (width === 390 || width === 1280)
            await page.screenshot({
                path: path.join(out, `${engine}-${name}-${width}.png`),
                fullPage: true,
            });
    }
    const first = cards().first();
    await first.focus();
    assert.ok(await first.evaluate((el) => el === document.activeElement));
    const href = await first.getAttribute("href");
    await page.keyboard.press("Enter");
    await page.waitForURL(base + href);
    await page.getByRole("heading", { level: 1 }).waitFor();
    pass(`${name}: keyboard opens the correct program run`);
}
try {
    await login(fixture.trainee);
    await page.goto(base + "/dashboard");
    await loaded();
    assert.equal(
        await cards().locator('img[src="/images/gym.jpg"]').count(),
        1,
    );
    pass(
        "Personal Overview loads exercise covers and a fallback for an imageless program",
    );
    await matrix("personal-overview", 3);
    await page.goto(base + "/progress");
    await matrix("personal-progress", 3);
    await page.goto(base + "/progress");
    await loaded();
    await context.route("**/storage/**", (route) => route.abort());
    await page.reload();
    await loaded();
    assert.equal(
        await cards().locator('img[src="/images/gym.jpg"]').count(),
        3,
    );
    pass("Missing run images fall back without breaking the cards");
    await context.unroute("**/storage/**");
    await page
        .getByRole("button", { name: "Log out", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL(base + "/login");
    await login(fixture.coach);
    await page.goto(base + `/coach/trainees/${fixture.trainee_id}/progress`);
    await loaded();
    assert.equal(
        await cards().locator('img[src="/images/gym.jpg"]').count(),
        0,
    );
    pass(
        "Coach Progress uses assigned covers without the trainee's private program",
    );
    await matrix("coach-progress", 2);
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `program-cards-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

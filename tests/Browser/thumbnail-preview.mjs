/** Full-image previews across Personal and Coach plan/program surfaces. */
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
const dialog = () => page.getByRole("dialog");
async function login(index) {
    const account = accounts[index];
    assert.match(account.email, /^qa-v200-/);
    await page.goto(base + "/login");
    await page.getByLabel("Email address").fill(account.email);
    await page.getByLabel("Password", { exact: true }).fill(account.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await page.waitForURL(/\/(coach|dashboard)$/);
}
async function open(trigger) {
    const url = page.url();
    await trigger.click();
    await dialog().waitFor();
    const full = dialog().locator(".plan-image-preview > img");
    await full.evaluate((img) => img.decode());
    assert.equal(
        await full.getAttribute("src"),
        await trigger.locator("img").getAttribute("src"),
    );
    assert.equal(
        await full.evaluate((img) => getComputedStyle(img).objectFit),
        "contain",
    );
    assert.equal(
        page.url(),
        url,
        "Image click must not navigate to the program",
    );
    const sizes = await dialog().evaluate((el) => {
        const b = el.getBoundingClientRect();
        return {
            width: el.clientWidth,
            scroll: el.scrollWidth,
            x: b.x,
            y: b.y,
            right: b.right,
            bottom: b.bottom,
            w: innerWidth,
            h: innerHeight,
        };
    });
    assert.ok(
        sizes.scroll <= sizes.width + 1 &&
            sizes.x >= 0 &&
            sizes.y >= 0 &&
            sizes.right <= sizes.w + 1 &&
            sizes.bottom <= sizes.h + 1,
    );
    assert.ok(
        await dialog()
            .getByRole("button", { name: "Close dialog" })
            .isVisible(),
    );
}
async function surface(url, name, selector = ".plan-thumbnail-trigger") {
    await page.goto(base + url);
    const trigger = page.locator(selector).first();
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
        await open(trigger);
        if (
            (name === "personal-progress" || name === "recommendations") &&
            (width === 390 || width === 1280)
        )
            await page.screenshot({
                path: path.join(out, `${engine}-preview-${name}-${width}.png`),
            });
        await dialog().getByRole("button", { name: "Close dialog" }).click();
        await dialog().waitFor({ state: "detached" });
        await page.waitForFunction(
            (el) => el === document.activeElement,
            await trigger.elementHandle(),
        );
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        pass(
            `${name}: uncropped image, close and focus return at ${width}x${height}`,
        );
    }
    await trigger.focus();
    await page.keyboard.press("Enter");
    await dialog().waitFor();
    for (let i = 0; i < 5; i++) {
        await page.keyboard.press("Tab");
        assert.ok(
            await dialog().evaluate(
                (el) =>
                    el.contains(document.activeElement) ||
                    document.activeElement === document.body,
            ),
            "Background interactive controls stay inert while the native dialog is open",
        );
    }
    await page.keyboard.press("Escape");
    await dialog().waitFor({ state: "detached" });
    await page.waitForFunction(
        (el) => el === document.activeElement,
        await trigger.elementHandle(),
    );
    await open(trigger);
    await page.mouse.click(4, 4);
    await dialog().waitFor({ state: "detached" });
    pass(`${name}: keyboard, inert background, Escape and backdrop close work`);
}
try {
    await login(fixture.trainee);
    await surface("/dashboard", "personal-overview");
    await surface("/progress", "personal-progress");
    await surface("/training-plans", "personal-plans");
    await surface(
        "/recommendations",
        "recommendations",
        ".recommended-card .plan-thumbnail-trigger",
    );
    await surface(
        "/recommendations",
        "catalog",
        ".catalog-row .plan-thumbnail-trigger",
    );
    const trigger = page.locator(".plan-thumbnail-trigger").first();
    await open(trigger);
    const original = dialog().getByRole("link", {
        name: "Open original image",
    });
    const originalURL = await original.getAttribute("href");
    const [tab] = await Promise.all([
        page.waitForEvent("popup"),
        original.click(),
    ]);
    await tab.waitForLoadState();
    assert.equal(tab.url(), new URL(originalURL, base).href);
    await tab.close();
    await page.keyboard.press("Escape");
    pass("Open original image opens the image itself in a separate tab");
    await context.route("**/storage/**", (route) => route.abort());
    await page.goto(base + "/progress");
    const image = page
        .locator(".program-run-summary .plan-thumbnail-trigger")
        .last();
    await page.waitForFunction(() =>
        [...document.querySelectorAll(".program-run-summary img")].every(
            (img) =>
                img.complete &&
                img.naturalWidth > 0 &&
                img.getAttribute("src") === "/images/gym.jpg",
        ),
    );
    await open(image);
    assert.equal(
        await dialog().locator(".plan-image-preview > img").getAttribute("src"),
        "/images/gym.jpg",
    );
    await page.keyboard.press("Escape");
    await context.unroute("**/storage/**");
    pass("Unavailable thumbnails open a working fallback preview");
    await page
        .getByRole("button", { name: "Log out", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL(base + "/login");
    await login(fixture.coach);
    const prefix = `/coach/trainees/${fixture.trainee_id}`;
    await surface(prefix + "/progress", "coach-progress");
    await surface("/coach/training-plans", "coach-plans");
    await surface(prefix + "/training-plans", "assigned-plans");
    await surface("/coach/recommendations", "coach-recommendations");
    assert.equal(
        await page.locator("a button").count(),
        0,
        "No thumbnail button is nested in a navigation link",
    );
    assert.deepEqual(errors, []);
    pass("No invalid nested thumbnail buttons or browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `preview-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

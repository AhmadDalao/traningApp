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
async function surface(
    url,
    name,
    selector = ".plan-thumbnail-trigger",
    widths = [
        [320, 480],
        [390, 844],
        [768, 1024],
        [1280, 900],
    ],
) {
    await page.goto(base + url);
    await page.locator(selector).first().waitFor();
    for (const [width, height] of widths) {
        await page.setViewportSize({ width, height });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
            `${name}: page overflow at ${width}`,
        );
        assert.equal(
            await page.locator("a button,button button,label button").count(),
            0,
            `${name}: no nested interactive image controls`,
        );
        const trigger = page.locator(selector).first();
        await open(trigger);
        await dialog().getByRole("button", { name: "Close dialog" }).click();
        await dialog().waitFor({ state: "detached" });
        if ([320, 1280].includes(width))
            await page.screenshot({
                path: path.join(out, `${engine}-coverage-${name}-${width}.png`),
                fullPage: true,
            });
        pass(`${name}: ${width}×${height}, photo preview and layout`);
    }
}
async function props(url) {
    await page.goto(base + url);
    return page.evaluate(
        () => JSON.parse(document.querySelector("#app").dataset.page).props,
    );
}
try {
    await login(fixture.trainee);
    const run = await props(fixture.personal_url);
    const assigned = await props(
        fixture.coach_urls[0].replace(
            `/coach/trainees/${fixture.trainee_id}`,
            "",
        ),
    );
    const planid = assigned.run.training_plan_id;
    const program = await props(`/training-plans/${planid}`);
    const day = program.blocks[0].training_days[0];
    const entries = assigned.position.sessions;
    const completed = entries.find((e) => e.status === "completed");
    const planned = entries.find((e) => e.status === "planned");
    await surface(
        "/dashboard",
        "dashboard",
        ".session-main .training-thumbnail",
    );
    await surface(
        "/training-history",
        "history",
        ".history-title-cell .training-thumbnail",
    );
    await surface(
        `/schedule-entries/${planned.id}`,
        "session",
        ".exercise-image",
    );
    await surface(
        `/training-history/${completed.id}`,
        "saved-workout",
        ".exercise-image",
    );
    await surface(
        `/training-days/${day.id}`,
        "training-day",
        ".exercise-image",
    );
    await surface(
        `/training-plans/${planid}`,
        "program-detail",
        ".page-title-media .training-thumbnail",
    );
    await surface(
        fixture.personal_url,
        "run",
        ".program-sessions .training-thumbnail",
    );
    await surface("/program-runs", "runs", ".training-thumbnail");
    const discovery = await props("/recommendations");
    const recommendation = await page
        .locator(".recommended-card a")
        .first()
        .getAttribute("href");
    await surface(recommendation, "recommendation-detail", ".exercise-image");
    await page
        .getByRole("button", { name: "Log out", exact: true })
        .locator("visible=true")
        .click();
    await page.waitForURL(base + "/login");
    await login(fixture.coach);
    const prefix = `/coach/trainees/${fixture.trainee_id}`;
    await surface(
        prefix,
        "coach-overview",
        ".coach-current-training .training-thumbnail",
    );
    await surface(
        prefix + "/setup?assign=1",
        "setup",
        ".assignment-program-option .training-thumbnail",
        [
            [320, 480],
            [360, 800],
            [375, 812],
            [390, 844],
            [430, 900],
            [768, 1024],
            [1280, 900],
        ],
    );
    await page.locator('input[name="training"]:enabled').first().check();
    const choice = await page
        .locator('input[name="training"]:checked')
        .getAttribute("aria-label");
    await open(
        page.locator(".assignment-program-option .training-thumbnail").last(),
    );
    await page.keyboard.press("Escape");
    assert.equal(
        await page
            .locator('input[name="training"]:checked')
            .getAttribute("aria-label"),
        choice,
    );
    pass("Photo preview does not change setup selection");
    await surface(
        prefix + "/training-history",
        "coach-history",
        ".history-title-cell .training-thumbnail",
    );
    await surface(
        prefix + `/schedule-entries/${planned.id}`,
        "coach-session",
        ".exercise-image",
    );
    await surface(
        "/coach",
        "coach-sessions",
        ".coach-session .training-thumbnail",
        [
            [320, 480],
            [1280, 900],
        ],
    );
    await surface(
        prefix + `/schedule-entries/create?day=${day.id}`,
        "schedule-selection",
        ".schedule-selected-image .training-thumbnail",
        [
            [320, 480],
            [1280, 900],
        ],
    );
    await page.goto(base + prefix + "/calendar");
    const calendarProps = JSON.parse(
        await page.locator("#app").getAttribute("data-page"),
    ).props;
    await page
        .locator(`[data-date="${calendarProps.today}"] .fc-daygrid-day-number`)
        .click();
    await page
        .locator(".schedule-day-agenda .training-thumbnail")
        .first()
        .click();
    assert.equal(await page.getByRole("dialog").count(), 1);
    await page.keyboard.press("Escape");
    assert.equal(await page.getByRole("dialog").count(), 0);
    pass("Calendar date photos open and return to the session list");
    // Library previews are separate from the exercise selection control.
    await page.goto(base + "/coach/training-plans");
    const template = await page
        .getByRole("link", { name: "View", exact: true })
        .first()
        .getAttribute("href");
    const templateProps = await props(template);
    await page.goto(
        base +
            `/coach/training-days/${templateProps.blocks[0].training_days[0].id}/edit`,
    );
    await page
        .getByRole("button", { name: "Add exercise", exact: true })
        .click();
    await page.locator(".library-image").first().click();
    assert.equal(await page.getByRole("dialog").count(), 2);
    await page.keyboard.press("Escape");
    assert.equal(await page.getByRole("dialog").count(), 1);
    await page.locator(".library-card").first().click();
    await page.getByLabel("Exercise name", { exact: false }).waitFor();
    pass(
        "Library full-image preview closes back to the editor and selection still works",
    );
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} finally {
    fs.writeFileSync(
        path.join(out, `coverage-${engine}.json`),
        JSON.stringify({ checks, errors }, null, 2),
    );
    await browser.close();
}

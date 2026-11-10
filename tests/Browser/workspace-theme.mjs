import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
const pw = await import(process.env.PLAYWRIGHT_MODULE),
    base = process.env.QA_BASE_URL || "http://localhost:8000",
    out = process.env.QA_OUTPUT_DIR,
    engine = process.env.QA_BROWSER || "chromium";
assert(
    ["http://localhost:8000", "https://training.ahmaddalao.com"].includes(base),
);
const accounts = JSON.parse(fs.readFileSync(process.env.QA_ACCOUNTS_FILE)),
    f = JSON.parse(fs.readFileSync(path.join(out, "dashboard-fixtures.json"))),
    browser = await pw[engine].launch(),
    checks = [],
    errors = [];
const pass = (x) => {
    checks.push(x);
    console.log("PASS", x);
};
let page;
try {
    const guest = await browser.newContext();
    page = await guest.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    for (const route of ["/login", "/register"]) {
        await page.goto(base + route);
        await page.locator(".reference-workspace").waitFor();
        for (const [width, height] of [
            [320, 480],
            [390, 844],
            [768, 1024],
            [1280, 900],
        ]) {
            await page.setViewportSize({ width, height });
            assert(await page.locator(".reference-workspace").count());
            assert(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <= innerWidth + 1,
                ),
            );
            if ([390, 1280].includes(width))
                await page.screenshot({
                    path: path.join(
                        out,
                        `${engine}-auth-${route.slice(1)}-${width}.png`,
                    ),
                    fullPage: true,
                });
        }
        pass(`${route}: shared theme and four responsive widths`);
    }
    await guest.close();
    for (const role of ["personal", "coach"]) {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 1000 },
            serviceWorkers: "block",
        });
        page = await context.newPage();
        page.setDefaultTimeout(15000);
        page.on("pageerror", (e) => errors.push(e.message));
        const account = accounts[role === "coach" ? 0 : 2];
        assert(account.email.startsWith("qa-v200-"));
        await page.goto(base + "/login");
        await page.getByLabel("Email address").fill(account.email);
        await page
            .getByLabel("Password", { exact: true })
            .fill(account.password);
        await page.getByRole("button", { name: "Log in", exact: true }).click();
        await page.waitForURL((u) => u.pathname != "/login");
        const prefix = `/coach/trainees/${f.trainee}`;
        const routes =
            role === "personal"
                ? [
                      ["/dashboard", "dashboard"],
                      ["/training-plans", "plans"],
                      ["/training-plans/create", "plan-create"],
                      [`/training-plans/${f.plan}`, "plan"],
                      [`/training-plans/${f.plan}/edit`, "plan-edit"],
                      [`/training-plans/${f.plan}/days/create`, "day-create"],
                      [`/training-days/${f.day}`, "day"],
                      [`/training-days/${f.day}/edit`, "day-edit"],
                      [
                          `/training-plans/${f.plan}/schedule-preview`,
                          "schedule-review",
                      ],
                      ["/recommendations", "discovery"],
                      ["/recommendations/full-body", "recommendation"],
                      ["/calendar", "calendar"],
                      ["/schedule-entries/create", "workout-create"],
                      [`/schedule-entries/${f.first}`, "session"],
                      [`/schedule-entries/${f.first}/edit`, "session-edit"],
                      [`/schedule-entries/${f.first}/workout`, "workout"],
                      ["/training-history", "history"],
                      [`/training-history/${f.recorded}`, "history-details"],
                      ["/progress", "progress"],
                      ["/progress/exercises", "exercise-progress"],
                      ["/program-runs", "programs"],
                      [`/program-runs/${f.run}`, "program-run"],
                      ["/my-coaching", "my-coaching"],
                      ["/profile", "settings"],
                      ["/profile/edit", "settings-edit"],
                      ["/training-preferences", "settings-preferences"],
                      ["/notifications", "settings-notifications"],
                      ["/profile?section=devices", "settings-devices"],
                  ]
                : [
                      ["/coach", "overview"],
                      ["/coach/trainees", "trainees"],
                      ["/coach/training-plans", "programs"],
                      ["/coach/training-plans/create", "program-create"],
                      [`/coach/training-plans/${f.coachPlan}`, "program"],
                      [
                          `/coach/training-plans/${f.coachPlan}/edit`,
                          "program-edit",
                      ],
                      [`/coach/training-plans/${f.coachPlan}/assign`, "assign"],
                      ["/coach/subscriptions", "subscriptions"],
                      [prefix, "trainee"],
                      [prefix + "/setup", "setup"],
                      [prefix + "/coaching", "coaching"],
                      [prefix + "/calendar", "calendar"],
                      [prefix + "/schedule-entries/create", "workout-create"],
                      [
                          prefix + `/schedule-entries/${f.coachSession}`,
                          "session",
                      ],
                      [
                          prefix + `/schedule-entries/${f.coachSession}/edit`,
                          "session-edit",
                      ],
                      [prefix + "/training-history", "history"],
                      [prefix + "/progress", "progress"],
                      [prefix + "/progress/exercises", "exercise-progress"],
                      [prefix + `/training-plans/${f.assigned}`, "assigned"],
                      ["/coach/settings", "settings"],
                      ["/coach/settings/account", "settings-account"],
                      [
                          "/coach/settings/notifications",
                          "settings-notifications",
                      ],
                      ["/coach/settings?section=devices", "settings-devices"],
                  ];
        for (const [route, name] of routes) {
            const response = await page.goto(base + route);
            assert(response.status() < 400, `${route}: ${response.status()}`);
            await page.locator("main").waitFor();
            await page.waitForLoadState("networkidle");
            assert(
                await page.locator(".reference-workspace").count(),
                `${route} missing shared theme`,
            );
            for (const [width, height] of [
                [320, 480],
                [390, 844],
                [768, 1024],
                [1280, 900],
                [1920, 1080],
            ]) {
                await page.setViewportSize({ width, height });
                await page.evaluate(
                    () =>
                        new Promise((r) =>
                            requestAnimationFrame(() =>
                                requestAnimationFrame(r),
                            ),
                        ),
                );
                const overflowing = await page.evaluate(() => ({
                    width: document.documentElement.scrollWidth,
                    viewport: innerWidth,
                    items: [...document.querySelectorAll("main *")]
                        .filter(
                            (e) =>
                                e.getBoundingClientRect().right >
                                    innerWidth + 2 &&
                                getComputedStyle(e).position !== "fixed",
                        )
                        .slice(0, 6)
                        .map((e) => e.className),
                }));
                assert(
                    overflowing.width <= width + 1,
                    `${role}/${name} ${width} overflow: ${JSON.stringify(overflowing)}`,
                );
                assert.equal(
                    await page.locator("a button,button button").count(),
                    0,
                    `${route} nested interactive controls`,
                );
                if (width < 761)
                    assert(
                        await page
                            .getByRole("navigation", {
                                name: "Mobile navigation",
                                exact: true,
                            })
                            .isVisible(),
                    );
                if ([390, 1280].includes(width))
                    await page.screenshot({
                        path: path.join(
                            out,
                            `${engine}-${role}-${name}-${width}.png`,
                        ),
                        fullPage: true,
                    });
            }
            pass(
                `${role}/${name}: five responsive widths, shared theme, usable navigation`,
            );
        }
        await page.goto(
            base +
                (role === "coach"
                    ? "/coach/training-plans"
                    : "/training-plans"),
        );
        await page.setViewportSize({ width: 320, height: 480 });
        const thumbnail = page
            .getByRole("button", { name: /View full image for/ })
            .first();
        await thumbnail.click();
        const dialog = page.getByRole("dialog");
        await dialog.waitFor();
        assert(
            await dialog
                .getByRole("link", { name: "Open original image" })
                .isVisible(),
        );
        assert(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        await page.keyboard.press("Escape");
        await page.waitForFunction(
            (el) => document.activeElement === el,
            await thumbnail.elementHandle(),
        );
        pass(`${role}: full image dialog, Escape and focus return at 320px`);
        // Use the same settings flow and persist the same preferences in either workspace.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(
            base + (role === "coach" ? "/coach/settings" : "/profile"),
        );
        await page
            .getByRole("navigation", { name: "Settings sections" })
            .getByRole("button", { name: /Notifications/ })
            .click();
        await page.getByRole("switch", { name: /Workout reminders/ }).check();
        await page.getByLabel("Remind me before my workout").selectOption("30");
        await page
            .getByRole("button", { name: "Save changes", exact: true })
            .filter({ visible: true })
            .click();
        await page.waitForLoadState("networkidle");
        await page.reload();
        await page
            .getByRole("button", { name: "Back to settings", exact: true })
            .click();
        await page
            .getByRole("navigation", { name: "Settings sections" })
            .getByRole("button", { name: /Notifications/ })
            .click();
        assert.equal(
            await page.getByLabel("Remind me before my workout").inputValue(),
            "30",
        );
        await page
            .getByRole("button", { name: "Back to settings", exact: true })
            .click();
        assert(
            await page
                .getByRole("navigation", { name: "Settings sections" })
                .isVisible(),
        );
        await page
            .getByRole("navigation", { name: "Settings sections" })
            .getByRole("button", { name: /App & devices/ })
            .click();
        await page
            .getByRole("button", { name: "Manage devices", exact: true })
            .click();
        assert(
            await page
                .getByRole("button", {
                    name: "Enable on this device",
                    exact: true,
                })
                .isDisabled(),
        );
        pass(
            `${role}: shared settings save, reload, back navigation and device controls`,
        );
        await context.close();
    }
    assert.deepEqual(errors, []);
    pass("No browser runtime errors");
} catch (e) {
    if (page && !page.isClosed())
        await page.screenshot({
            path: path.join(out, `${engine}-theme-failure.png`),
            fullPage: true,
        });
    throw e;
} finally {
    fs.writeFileSync(
        path.join(out, `workspace-theme-${engine}.json`),
        JSON.stringify({ checks, count: checks.length, errors }, null, 2),
    );
    await browser.close();
}

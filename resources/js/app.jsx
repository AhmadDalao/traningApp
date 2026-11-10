import "../css/app.css";
import "../css/schedule.css";
import "../css/coaching.css";
import "../css/profile.css";
import "../css/workout.css";
import "../css/exercise-progress.css";
import "../css/discovery.css";
import "../css/programs.css";
import "../css/mobile.css";
import "../css/notifications.css";
import "../css/polish.css";
import "../css/journey.css";
import "../css/coach-workspace.css";
import "../css/coach-refresh.css";
import "../css/personal-dashboard.css";
import "../css/workspace-theme.css";
import { startPwa } from "./Features/Notifications/pwa";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import AppLayout from "./Components/AppLayout";
startPwa();
createInertiaApp({
    title: (title) => `${title} · Training Planner`,
    resolve: async (name) => {
        const pages = import.meta.glob("./Pages/**/*.jsx");
        const page = await pages[`./Pages/${name}.jsx`]();
        if (!name.startsWith("Auth/"))
            page.default.layout =
                page.default.layout ||
                ((page) => <AppLayout>{page}</AppLayout>);
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: "#6541df" },
});

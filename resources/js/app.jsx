import "../css/app.css";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import AppLayout from "./Components/AppLayout";
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
    progress: { color: "#1763ef" },
});

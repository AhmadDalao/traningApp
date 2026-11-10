import { usePage } from "@inertiajs/react";
import SettingsPage from "../../Features/SettingsPage";
export default function Edit(props) {
    const coach = usePage().props.trainingContext?.workspace === "coach";
    return (
        <SettingsPage
            {...props}
            initialSection={coach ? "account" : "profile"}
        />
    );
}

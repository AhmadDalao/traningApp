import { useSyncExternalStore } from "react";
import { usePage } from "@inertiajs/react";
import { Button } from "../../Components/UI";
import { activateUpdate, pwaSnapshot, subscribePwa } from "./pwa";

export default function PwaStatus() {
    const { online, waiting } = useSyncExternalStore(subscribePwa, pwaSnapshot);
    const { url } = usePage();
    const workout = /\/workout(?:\?|$)/.test(url);
    return (
        <>
            {!online && (
                <div className="pwa-notice" role="status">
                    <strong>You're offline</strong>
                    <p>
                        Reconnect before saving changes. Nothing is queued to
                        sync. Keep this page open to retain anything you have
                        typed.
                    </p>
                </div>
            )}
            {waiting && (
                <div className="pwa-notice" role="status">
                    <div>
                        <strong>Update available</strong>
                        <p>
                            {workout
                                ? "Finish or leave your workout before updating. This page will stay open."
                                : "Save your work before reloading to use the latest version."}
                        </p>
                    </div>
                    <Button
                        variant="secondary"
                        disabled={workout || !online}
                        onClick={() => {
                            if (
                                window.confirm(
                                    "Reload Training Planner? Save any unsaved changes first.",
                                )
                            )
                                activateUpdate();
                        }}
                    >
                        Update & reload
                    </Button>
                </div>
            )}
        </>
    );
}

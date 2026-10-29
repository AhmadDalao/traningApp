import { useEffect, useState, useSyncExternalStore } from "react";
import { Download, CheckCircle2 } from "lucide-react";
import { Button } from "../../Components/UI";
import { pwaSnapshot, standalone, subscribePwa } from "./pwa";

export default function InstallCard() {
    const { installPrompt } = useSyncExternalStore(subscribePwa, pwaSnapshot);
    const [installed, setInstalled] = useState(standalone);
    const [message, setMessage] = useState("");
    const [busy, setBusy] = useState(false);
    const ios =
        /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
    useEffect(() => {
        const update = () => setInstalled(true);
        window.addEventListener("appinstalled", update);
        return () => window.removeEventListener("appinstalled", update);
    }, []);
    return (
        <section className="panel notification-panel">
            <div className="notification-title">
                {installed ? (
                    <CheckCircle2 size={22} />
                ) : (
                    <Download size={22} />
                )}
                <h2>
                    {installed
                        ? "Training Planner is installed"
                        : "Install Training Planner"}
                </h2>
            </div>
            <p>
                Keep your training a tap away on your home screen. An internet
                connection is required to view and save your training.
            </p>
            {!installed &&
                (installPrompt ? (
                    <Button
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            try {
                                await installPrompt.prompt();
                                const result = await installPrompt.userChoice;
                                setMessage(
                                    result.outcome === "accepted"
                                        ? "Follow your browser’s installation instructions."
                                        : "You can install later from your browser’s menu.",
                                );
                            } catch {
                                setMessage(
                                    "Use your browser’s menu to install Training Planner.",
                                );
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        <Download size={17} />
                        Install app
                    </Button>
                ) : (
                    <p className="notification-help">
                        {ios
                            ? "On iPhone or iPad, open this page in Safari, tap Share, then Add to Home Screen. Open the installed app to enable notifications."
                            : "Open your browser’s menu and choose Install app or Add to Home Screen, if available. You can always keep using Training Planner in your browser."}
                    </p>
                ))}
            {message && <p role="status">{message}</p>}
        </section>
    );
}

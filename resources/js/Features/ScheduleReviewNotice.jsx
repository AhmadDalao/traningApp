import { useTraining } from "./CoachingUI";
import { useEffect, useState } from "react";
import { Button, ButtonLink } from "../Components/UI";
export default function ScheduleReviewNotice({ plan }) {
    const { url, templates } = useTraining();
    const [dismissed, setDismissed] = useState(false);
    useEffect(() => setDismissed(false), [plan]);
    if (templates || !plan || dismissed) return null;
    return (
        <section
            className="schedule-review-notice"
            aria-label="Plan schedule changed"
        >
            <div>
                <strong>Your plan schedule changed.</strong>
                <p>
                    Would you like to review future Calendar sessions for{" "}
                    {plan.name}?
                </p>
            </div>
            <div className="schedule-review-actions">
                <ButtonLink
                    href={url(`/training-plans/${plan.id}/schedule-preview`)}
                    className="small"
                >
                    Review schedule
                </ButtonLink>
                <Button
                    variant="secondary"
                    className="small"
                    onClick={() => setDismissed(true)}
                >
                    Not now
                </Button>
            </div>
        </section>
    );
}

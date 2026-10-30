import { useState } from "react";
import { useForm } from "@inertiajs/react";
import { Button, Field, Modal } from "../Components/UI";
import { Errors, coachingJson } from "./CoachingUI";
export default function AddTrainee({ close }) {
    const request = useForm({ training_code: "" });
    const [sent, setSent] = useState("");
    const [member, setMember] = useState(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    async function lookup(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        setMember(null);
        try {
            const result = await coachingJson("/coach/lookup", request.data);
            setMember(result.member);
            if (!result.member) setError("No other member found with that ID.");
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <Modal title="Add trainee" onClose={close}>
            {sent ? (
                <>
                    <h3>Request sent</h3>
                    <p>Waiting for {sent} to accept your coaching request.</p>
                    <Button onClick={close}>Done</Button>
                </>
            ) : (
                <>
                    {" "}
                    <div>
                        <h2>Add trainee</h2>
                        <form onSubmit={lookup} className="coach-actions">
                            <Field label="Training Planner ID">
                                <input
                                    required
                                    maxLength={11}
                                    placeholder="TP-7F4K9M2Q"
                                    value={request.data.training_code}
                                    onChange={(e) => {
                                        request.setData(
                                            "training_code",
                                            e.target.value.toUpperCase(),
                                        );
                                        setMember(null);
                                    }}
                                />
                            </Field>
                            <Button disabled={busy}>Find member</Button>
                        </form>
                        <p className="field-hint">
                            Or scan their temporary QR with your phone camera.
                            Search uses an exact ID, never an email address.
                        </p>
                        {error && (
                            <p className="field-error" role="alert">
                                {error}
                            </p>
                        )}
                        <Errors errors={request.errors} />
                        {member && (
                            <div className="coach-member">
                                <strong>{member.name}</strong>
                                <span>Training Planner member</span>
                                <Button
                                    disabled={request.processing}
                                    onClick={() =>
                                        request.post("/coach/requests", {
                                            onSuccess: () => {
                                                setSent(member.name);
                                                setMember(null);
                                                request.reset();
                                            },
                                        })
                                    }
                                >
                                    Send request
                                </Button>
                            </div>
                        )}
                    </div>
                </>
            )}
        </Modal>
    );
}

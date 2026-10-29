import { Head, usePage } from "@inertiajs/react";
import { Pencil, ArrowRight } from "lucide-react";
import {
    PageHeader,
    ButtonLink,
    DateLabel,
    formatWeight,
} from "../../Components/UI";
import { BmiSummary } from "../../Features/ProfileFields";

export default function Show({ profile, options }) {
    const { auth } = usePage().props;
    profile ||= {};
    return (
        <>
            <Head title="Profile" />
            <PageHeader
                title="Profile"
                description="Your personal information and training preferences."
            >
                <ButtonLink href="/profile/edit">
                    <Pencil size={16} />
                    Edit profile
                </ButtonLink>
            </PageHeader>
            <section className="panel coach-card">
                <h2>Training Planner ID</h2>
                <p>
                    <code className="training-code">
                        {auth.user.training_code}
                    </code>
                </p>
                <div className="coach-actions">
                    <ButtonLink href="/my-coaching" variant="secondary">
                        Copy ID, show QR & My Coaching
                    </ButtonLink>
                    {auth.user.is_coach && (
                        <ButtonLink href="/profile/coach" variant="secondary">
                            Coach profile
                        </ButtonLink>
                    )}
                </div>
            </section>
            {!profile.onboarding_completed_at && (
                <div className="profile-reminder">
                    <span>
                        Finish your optional setup to get plan suggestions.
                    </span>
                    <ButtonLink href="/onboarding" variant="secondary">
                        Continue setup
                    </ButtonLink>
                </div>
            )}
            <div className="profile-columns">
                <section className="panel profile-panel">
                    <h2>Personal information</h2>
                    <div className="profile-identity">
                        <div className="avatar">
                            {auth.user.name.slice(0, 1).toUpperCase()}
                        </div>
                        <div>
                            <h3>{auth.user.name}</h3>
                            <p>{auth.user.email}</p>
                        </div>
                    </div>
                    <dl className="profile-details">
                        <div>
                            <dt>Date of birth</dt>
                            <dd>
                                {profile.date_of_birth ? (
                                    <DateLabel
                                        date={profile.date_of_birth}
                                        long
                                    />
                                ) : (
                                    "Not set"
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt>Age (calculated)</dt>
                            <dd>
                                {profile.age !== null &&
                                profile.age !== undefined
                                    ? `${profile.age} years`
                                    : "—"}
                            </dd>
                        </div>
                        <div>
                            <dt>Height</dt>
                            <dd>
                                {profile.height_cm
                                    ? `${Number(profile.height_cm)} cm`
                                    : "Not set"}
                            </dd>
                        </div>
                        <div>
                            <dt>Current Body Weight</dt>
                            <dd>{formatWeight(profile.current_weight_kg)}</dd>
                        </div>
                    </dl>
                    <BmiSummary
                        height={profile.height_cm}
                        weight={profile.current_weight_kg}
                    />
                    <ButtonLink href="/progress" variant="secondary">
                        View progress
                        <ArrowRight size={16} />
                    </ButtonLink>
                </section>
                <div>
                    <section className="panel profile-panel">
                        <div className="panel-heading">
                            <h2>Training preferences</h2>
                            <ButtonLink
                                href="/profile/edit#preferences"
                                variant="secondary"
                                className="small"
                            >
                                Edit training preferences
                            </ButtonLink>
                        </div>
                        <dl className="profile-details">
                            <div className="wide-detail">
                                <dt>Training Goals</dt>
                                <dd>
                                    {profile.training_goals?.length
                                        ? profile.training_goals.map(
                                              (goal, index) => (
                                                  <div key={goal}>
                                                      {index === 0
                                                          ? "Primary"
                                                          : "Secondary"}
                                                      : {options.goals[goal]}
                                                  </div>
                                              ),
                                          )
                                        : profile.training_goal
                                          ? `Primary: ${options.goals[profile.training_goal]}`
                                          : "Not set"}
                                </dd>
                            </div>
                            <div>
                                <dt>Experience level</dt>
                                <dd>
                                    {options.levels[profile.experience_level] ||
                                        "Not set"}
                                </dd>
                            </div>
                            <div>
                                <dt>Training days per week</dt>
                                <dd>
                                    {profile.training_days_per_week
                                        ? `${profile.training_days_per_week} days`
                                        : "Not set"}
                                </dd>
                            </div>
                            <div>
                                <dt>Typical session duration</dt>
                                <dd>
                                    {profile.session_duration_minutes
                                        ? `${profile.session_duration_minutes} minutes`
                                        : "Not set"}
                                </dd>
                            </div>
                            <div className="wide-detail">
                                <dt>Equipment access</dt>
                                <dd>
                                    {profile.equipment_access
                                        ?.map((key) => options.equipment[key])
                                        .join(", ") || "Not set"}
                                </dd>
                            </div>
                        </dl>
                        <ButtonLink href="/recommendations" variant="secondary">
                            Recommended plans
                            <ArrowRight size={16} />
                        </ButtonLink>
                    </section>
                    <section className="panel profile-panel">
                        <h2>Body weight goal</h2>
                        <dl className="profile-details">
                            <div>
                                <dt>Goal</dt>
                                <dd>
                                    {options.bodyGoals[
                                        profile.body_weight_goal
                                    ] || "Not tracking"}
                                </dd>
                            </div>
                            <div>
                                <dt>Target Body Weight</dt>
                                <dd>
                                    {formatWeight(
                                        profile.target_body_weight_kg,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </>
    );
}

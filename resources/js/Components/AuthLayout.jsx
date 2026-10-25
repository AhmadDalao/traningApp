import { Dumbbell, ArrowUpRight } from "lucide-react";
export default function AuthLayout({ children }) {
    return (
        <div className="auth-page">
            <aside className="auth-visual">
                <div className="auth-shade" />
                <a className="brand" href="/login">
                    <span className="brand-mark">
                        <Dumbbell />
                    </span>
                    Training Planner
                </a>
                <div className="auth-message">
                    <div className="eyebrow">MAKE YOUR NEXT SESSION COUNT</div>
                    <h1>
                        Plan with purpose.
                        <br />
                        Train with focus.
                    </h1>
                    <p>
                        Your training, all in one place.
                        <br />
                        Build a routine. Show up. See your progress.
                    </p>
                    <div className="auth-bottom">
                        A stronger you starts with a plan.
                        <ArrowUpRight size={20} />
                    </div>
                </div>
            </aside>
            <main className="auth-main">
                <div className="auth-form">{children}</div>
                <p className="auth-footnote">
                    Your plan. Your pace. Your progress.
                </p>
            </main>
        </div>
    );
}

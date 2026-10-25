import { Head, Link, useForm } from "@inertiajs/react";
import { ArrowRight } from "lucide-react";
import AuthLayout from "../../Components/AuthLayout";
import { Button, Field } from "../../Components/UI";
export default function Login() {
    const form = useForm({ email: "", password: "", remember: false });
    return (
        <AuthLayout>
            <Head title="Log in" />
            <span className="auth-kicker">LET’S GET BACK TO IT</span>
            <h1>Welcome back.</h1>
            <p className="auth-intro">
                A stronger routine starts here. Log in to your planner.
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/login", {
                        onFinish: () => form.reset("password"),
                    });
                }}
            >
                <Field label="Email address" error={form.errors.email}>
                    <input
                        type="email"
                        name="email"
                        required
                        autoFocus
                        autoComplete="username"
                        placeholder="you@example.com"
                        value={form.data.email}
                        onChange={(e) => form.setData("email", e.target.value)}
                    />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <input
                        type="password"
                        name="password"
                        required
                        autoComplete="current-password"
                        placeholder="Your password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData("password", e.target.value)
                        }
                    />
                </Field>
                <label className="checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.remember}
                        onChange={(e) =>
                            form.setData("remember", e.target.checked)
                        }
                    />
                    Remember me
                </label>
                <Button className="full" disabled={form.processing}>
                    {form.processing ? "Logging in…" : "Log in"}
                    <ArrowRight size={17} />
                </Button>
            </form>
            <p className="auth-switch">
                New here? <Link href="/register">Create an account</Link>
            </p>
        </AuthLayout>
    );
}

import { Head, Link, useForm } from "@inertiajs/react";
import { ArrowRight } from "lucide-react";
import AuthLayout from "../../Components/AuthLayout";
import { Button, Field } from "../../Components/UI";
export default function Register() {
    const form = useForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
    });
    return (
        <AuthLayout>
            <Head title="Create account" />
            <span className="auth-kicker">YOUR NEXT CHAPTER</span>
            <h1>Make room for progress.</h1>
            <p className="auth-intro">
                Create your account and start planning your training.
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/register", {
                        onFinish: () =>
                            form.reset("password", "password_confirmation"),
                    });
                }}
            >
                <Field label="Your name" error={form.errors.name}>
                    <input
                        autoFocus
                        required
                        maxLength={100}
                        autoComplete="name"
                        value={form.data.name}
                        onChange={(e) => form.setData("name", e.target.value)}
                        placeholder="Your name"
                    />
                </Field>
                <Field label="Email address" error={form.errors.email}>
                    <input
                        type="email"
                        required
                        autoComplete="username"
                        value={form.data.email}
                        onChange={(e) => form.setData("email", e.target.value)}
                        placeholder="you@example.com"
                    />
                </Field>
                <Field label="Password" error={form.errors.password}>
                    <input
                        type="password"
                        required
                        minLength={8}
                        maxLength={72}
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData("password", e.target.value)
                        }
                        placeholder="At least 8 characters"
                    />
                </Field>
                <Field label="Confirm password">
                    <input
                        type="password"
                        required
                        minLength={8}
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                "password_confirmation",
                                e.target.value,
                            )
                        }
                        placeholder="Re-enter your password"
                    />
                </Field>
                <Button className="full" disabled={form.processing}>
                    {form.processing ? "Creating account…" : "Create account"}
                    <ArrowRight size={17} />
                </Button>
            </form>
            <p className="auth-note">
                No email confirmation. You’ll be ready to train right away.
            </p>
            <p className="auth-switch">
                Already have an account? <Link href="/login">Log in</Link>
            </p>
        </AuthLayout>
    );
}

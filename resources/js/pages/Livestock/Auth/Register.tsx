"use client"

import { Head, useForm, Link } from "@inertiajs/react"
import { LoaderCircle } from "lucide-react"
import { FormEventHandler, useEffect } from "react"
import LivestockLogo from "@/components/livestock/LivestockLogo"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import LivestockLayout from "@/layouts/livestock/LivestockLayout"

type RegisterForm = {
    name: string
    email: string
    password: string
    password_confirmation: string
    ref?: string
    cf_turnstile_response: string
}

export default function LivestockRegister() {
    const { data, setData, post, processing, errors, reset } = useForm<Required<RegisterForm>>({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        ref: "",
        cf_turnstile_response: "",
    })

    const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

    useEffect(() => {
        const params = new URLSearchParams(window.location.search)
        const ref = params.get("ref")
        if (ref) {
            setData("ref", ref)
        }
    }, [])

    const submit: FormEventHandler = (e) => {
        e.preventDefault()
        post("/register", {
            onFinish: () => reset("password", "password_confirmation"),
        })
    }

    return (
        <LivestockLayout>
            <Head title="Register - Bida Livestock" />
            
            <div className="min-h-[calc(100vh-200px)] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
                <div className="max-w-md w-full space-y-8">
                    {/* Logo and Header */}
                    <div className="text-center">
                        <div className="flex justify-center mb-6">
                            <LivestockLogo size="lg" />
                        </div>
                        <h2 className="text-3xl font-bold text-gray-900 dark:text-white">
                            Create Your Account
                        </h2>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Join Bida Livestock and start trading today
                        </p>
                    </div>

                    {/* Registration Form */}
                    <div className="bg-white dark:bg-gray-800 shadow-xl rounded-lg p-8 border border-amber-200 dark:border-amber-800/50">
                        <form className="space-y-6" onSubmit={submit}>
                            <div className="space-y-4">
                                <div>
                                    <Label htmlFor="name">Full Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        required
                                        autoFocus
                                        value={data.name}
                                        onChange={(e) => setData("name", e.target.value)}
                                        className="mt-1 bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                        placeholder="John Doe"
                                    />
                                    <InputError message={errors.name} className="mt-2" />
                                </div>

                                <div>
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        value={data.email}
                                        onChange={(e) => setData("email", e.target.value)}
                                        className="mt-1 bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                        placeholder="you@example.com"
                                    />
                                    <InputError message={errors.email} className="mt-2" />
                                </div>

                                <div>
                                    <Label htmlFor="password">Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        required
                                        value={data.password}
                                        onChange={(e) => setData("password", e.target.value)}
                                        className="mt-1 bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                        placeholder="••••••••"
                                    />
                                    <InputError message={errors.password} className="mt-2" />
                                </div>

                                <div>
                                    <Label htmlFor="password_confirmation">Confirm Password</Label>
                                    <Input
                                        id="password_confirmation"
                                        type="password"
                                        required
                                        value={data.password_confirmation}
                                        onChange={(e) => setData("password_confirmation", e.target.value)}
                                        className="mt-1 bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                        placeholder="••••••••"
                                    />
                                    <InputError message={errors.password_confirmation} className="mt-2" />
                                </div>

                                {data.ref && (
                                    <div>
                                        <Label htmlFor="ref">Referral Code</Label>
                                        <Input
                                            id="ref"
                                            type="text"
                                            value={data.ref}
                                            readOnly
                                            className="mt-1 bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600 cursor-not-allowed"
                                        />
                                    </div>
                                )}
                            </div>

                            <TurnstileField
                                onToken={(token) => setData("cf_turnstile_response", token)}
                                error={errors.cf_turnstile_response}
                            />

                            <Button
                                type="submit"
                                className="w-full bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-700 hover:to-orange-700 text-white"
                                disabled={processing || turnstileBlocksSubmit}
                            >
                                {processing ? (
                                    <>
                                        <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />
                                        Creating account...
                                    </>
                                ) : (
                                    "Create Account"
                                )}
                            </Button>
                        </form>

                        <div className="mt-6">
                            <p className="text-xs text-gray-500 dark:text-gray-400 text-center">
                                By creating an account, you agree to our Terms of Service and Privacy Policy
                            </p>
                        </div>

                        <div className="mt-6 text-center">
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                Already have an account?{" "}
                                <Link
                                    href="/login"
                                    className="font-medium text-amber-600 hover:text-amber-500 dark:text-amber-400 dark:hover:text-amber-300"
                                >
                                    Sign in
                                </Link>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </LivestockLayout>
    )
}

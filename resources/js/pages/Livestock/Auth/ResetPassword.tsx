"use client"

import { Head, useForm, Link } from "@inertiajs/react"
import { LoaderCircle } from "lucide-react"
import { FormEventHandler } from "react"
import LivestockLogo from "@/components/livestock/LivestockLogo"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import LivestockLayout from "@/layouts/livestock/LivestockLayout"

type ResetPasswordForm = {
    token: string
    email: string
    password: string
    password_confirmation: string
    cf_turnstile_response: string
}

export default function LivestockResetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors } = useForm<ResetPasswordForm>({
        token,
        email,
        password: "",
        password_confirmation: "",
        cf_turnstile_response: "",
    })

    const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

    const submit: FormEventHandler = (e) => {
        e.preventDefault()
        post("/reset-password")
    }

    return (
        <LivestockLayout>
            <Head title="Reset Password - Bida Livestock" />
            
            <div className="min-h-[calc(100vh-200px)] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
                <div className="max-w-md w-full space-y-8">
                    {/* Logo and Header */}
                    <div className="text-center">
                        <div className="flex justify-center mb-6">
                            <LivestockLogo size="lg" />
                        </div>
                        <h2 className="text-3xl font-bold text-gray-900 dark:text-white">
                            Reset Password
                        </h2>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Enter your new password below
                        </p>
                    </div>

                    {/* Form */}
                    <div className="bg-white dark:bg-gray-800 shadow-xl rounded-lg p-8 border border-amber-200 dark:border-amber-800/50">
                        <form className="space-y-6" onSubmit={submit}>
                            <div className="space-y-4">
                                <div>
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        disabled
                                        className="mt-1 bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="password">New Password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        required
                                        autoFocus
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
                                        Resetting...
                                    </>
                                ) : (
                                    "Reset Password"
                                )}
                            </Button>
                        </form>

                        <div className="mt-6 text-center">
                            <Link
                                href="/login"
                                className="text-sm font-medium text-amber-600 hover:text-amber-500 dark:text-amber-400 dark:hover:text-amber-300"
                            >
                                ← Back to login
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </LivestockLayout>
    )
}

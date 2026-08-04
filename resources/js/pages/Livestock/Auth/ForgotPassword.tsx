"use client"

import { Head, useForm, Link } from "@inertiajs/react"
import { LoaderCircle, Mail } from "lucide-react"
import { FormEventHandler } from "react"
import LivestockLogo from "@/components/livestock/LivestockLogo"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import LivestockLayout from "@/layouts/livestock/LivestockLayout"

type ForgotPasswordForm = {
    email: string
    cf_turnstile_response: string
}

export default function LivestockForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm<ForgotPasswordForm>({
        email: "",
        cf_turnstile_response: "",
    })

    const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

    const submit: FormEventHandler = (e) => {
        e.preventDefault()
        post("/forgot-password")
    }

    return (
        <LivestockLayout>
            <Head title="Forgot Password - Bida Livestock" />
            
            <div className="min-h-[calc(100vh-200px)] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
                <div className="max-w-md w-full space-y-8">
                    {/* Logo and Header */}
                    <div className="text-center">
                        <div className="flex justify-center mb-6">
                            <LivestockLogo size="lg" />
                        </div>
                        <h2 className="text-3xl font-bold text-gray-900 dark:text-white">
                            Forgot Password?
                        </h2>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            No worries! Enter your email and we'll send you reset instructions.
                        </p>
                    </div>

                    {/* Status Message */}
                    {status && (
                        <div className="rounded-md bg-amber-50 dark:bg-amber-900/20 p-4">
                            <p className="text-sm text-amber-800 dark:text-amber-200">{status}</p>
                        </div>
                    )}

                    {/* Form */}
                    <div className="bg-white dark:bg-gray-800 shadow-xl rounded-lg p-8 border border-amber-200 dark:border-amber-800/50">
                        <form className="space-y-6" onSubmit={submit}>
                            <div>
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    autoFocus
                                    value={data.email}
                                    onChange={(e) => setData("email", e.target.value)}
                                    className="mt-1 bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                    placeholder="you@example.com"
                                />
                                <InputError message={errors.email} className="mt-2" />
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
                                        Sending...
                                    </>
                                ) : (
                                    <>
                                        <Mail className="mr-2 h-4 w-4" />
                                        Send Reset Link
                                    </>
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

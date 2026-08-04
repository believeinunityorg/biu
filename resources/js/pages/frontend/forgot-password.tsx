"use client"

import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { FormEventHandler, useEffect } from "react"
import { motion } from "framer-motion"
import { ArrowLeft, LoaderCircle, Mail } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Link, useForm } from "@inertiajs/react"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import { PageHead } from "@/components/frontend/PageHead"
import { usePasswordResetCooldown } from "@/hooks/use-password-reset-cooldown"

interface ForgotPasswordProps {
  seo?: { title: string; description?: string }
  status?: string
  passwordResetCooldownUntil?: number | null
  passwordResetThrottleSeconds?: number
}

export default function ForgotPasswordPage({
  seo,
  status,
  passwordResetCooldownUntil,
  passwordResetThrottleSeconds = 60,
}: ForgotPasswordProps) {
  const { data, setData, post, processing, errors } = useForm<Required<{ email: string; cf_turnstile_response: string }>>({
    email: "",
    cf_turnstile_response: "",
  })

  const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

  const { isCoolingDown, countdownLabel, cooldownEmail } = usePasswordResetCooldown(
    data.email,
    passwordResetCooldownUntil,
    passwordResetThrottleSeconds,
  )

  useEffect(() => {
    if (! data.email && cooldownEmail) {
      setData("email", cooldownEmail)
    }
  }, [cooldownEmail, data.email, setData])

  const submit: FormEventHandler = (e) => {
    e.preventDefault()
    if (isCoolingDown) {
      return
    }

    post(route("password.email"))
  }

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Forgot Password"} description={seo?.description} />
      <div className="min-h-screen relative overflow-hidden bg-gradient-to-br from-purple-600 via-blue-600 to-purple-700 dark:from-gray-900 dark:via-gray-800 dark:to-purple-900">
        <div
          className="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-20"
          style={{ backgroundImage: "url(/images/believe-hero.png)" }}
        >
          <div className="absolute inset-0 bg-purple-900/70 dark:bg-purple-900/80" />
        </div>
        <div className="absolute inset-0 bg-grid-pattern opacity-5" />

        <div className="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10 py-12 sm:py-16 md:py-20">
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8 }}
            className="max-w-md mx-auto"
          >
            <Card className="border-0 shadow-2xl bg-white/95 dark:bg-gray-800/95 backdrop-blur-md overflow-hidden">
              <div className="bg-gradient-to-br from-blue-500 to-blue-600 dark:from-blue-600 dark:to-blue-700 p-4 sm:p-5">
                <div className="flex flex-col items-center text-center">
                  <div className="mx-auto bg-white rounded-xl w-16 h-16 sm:w-20 sm:h-20 flex items-center justify-center mb-2 shadow-lg p-2.5">
                    <img
                      src="/favicon-96x96.png"
                      alt="Believe In Unity Logo"
                      className="h-full w-full object-contain"
                    />
                  </div>
                  <CardTitle className="text-xl sm:text-2xl font-bold text-white mb-1">Forgot password?</CardTitle>
                  <CardDescription className="text-sm text-white/90 max-w-sm">
                    Enter your email and we&apos;ll send you a link to reset your password
                  </CardDescription>
                </div>
              </div>

              <CardContent className="p-6 sm:p-8 space-y-6">
                {status && (
                  <div className="p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                    <p className="text-sm text-green-800 dark:text-green-300 text-center">{status}</p>
                  </div>
                )}

                <form className="space-y-5" onSubmit={submit}>
                  <div>
                    <Label htmlFor="email" className="text-gray-900 dark:text-white font-medium">
                      Email Address
                    </Label>
                    <div className="mt-2 space-y-2">
                      <div className="relative">
                        <Mail className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <Input
                          id="email"
                          type="email"
                          name="email"
                          autoComplete="email"
                          autoFocus
                          placeholder="Enter your email"
                          value={data.email}
                          onChange={(e) => setData("email", e.target.value)}
                          className="h-12 bg-white pl-10 dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                          required
                          disabled={isCoolingDown || processing}
                        />
                      </div>
                      {! isCoolingDown && <InputError message={errors.email} />}
                    </div>
                  </div>

                  <TurnstileField
                    onToken={(token) => setData("cf_turnstile_response", token)}
                    error={errors.cf_turnstile_response}
                  />

                  <Button
                    type="submit"
                    className="w-full h-12 sm:h-14 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold text-base sm:text-lg rounded-lg shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-300 disabled:opacity-60 disabled:hover:scale-100"
                    disabled={processing || isCoolingDown || turnstileBlocksSubmit}
                  >
                    {processing ? (
                      <>
                        <LoaderCircle className="h-5 w-5 animate-spin mr-2" />
                        Sending...
                      </>
                    ) : isCoolingDown ? (
                      `Wait ${countdownLabel}`
                    ) : (
                      "Email password reset link"
                    )}
                  </Button>
                </form>

                <div className="text-center pt-4 border-t border-gray-200 dark:border-gray-700">
                  <Link
                    href={route("login")}
                    className="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 hover:underline font-medium transition-colors"
                  >
                    <ArrowLeft className="h-4 w-4" />
                    Back to login
                  </Link>
                </div>
              </CardContent>
            </Card>
          </motion.div>
        </div>
      </div>
    </FrontendLayout>
  )
}

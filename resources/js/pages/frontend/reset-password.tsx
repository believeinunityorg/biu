"use client"

import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { FormEventHandler, useState } from "react"
import { motion } from "framer-motion"
import { Eye, EyeOff, LoaderCircle, Lock } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Link, useForm } from "@inertiajs/react"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import { PageHead } from "@/components/frontend/PageHead"

interface ResetPasswordProps {
  seo?: { title: string; description?: string }
  token: string
  email: string
}

type ResetPasswordForm = {
  token: string
  email: string
  password: string
  password_confirmation: string
  cf_turnstile_response: string
}

export default function ResetPasswordPage({ seo, token, email }: ResetPasswordProps) {
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirm, setShowConfirm] = useState(false)

  const { data, setData, post, processing, errors, reset } = useForm<Required<ResetPasswordForm>>({
    token,
    email,
    password: "",
    password_confirmation: "",
    cf_turnstile_response: "",
  })

  const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

  const submit: FormEventHandler = (e) => {
    e.preventDefault()
    post(route("password.store"), {
      onFinish: () => reset("password", "password_confirmation"),
    })
  }

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Reset Password"} description={seo?.description} />
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
                  <CardTitle className="text-xl sm:text-2xl font-bold text-white mb-1">Reset password</CardTitle>
                  <CardDescription className="text-sm text-white/90 max-w-sm">
                    Choose a new password for your account
                  </CardDescription>
                </div>
              </div>

              <CardContent className="p-6 sm:p-8 space-y-6">
                <form className="space-y-5" onSubmit={submit}>
                  <div>
                    <Label htmlFor="email" className="text-gray-900 dark:text-white font-medium">
                      Email Address
                    </Label>
                    <Input
                      id="email"
                      type="email"
                      name="email"
                      autoComplete="email"
                      value={data.email}
                      readOnly
                      onChange={(e) => setData("email", e.target.value)}
                      className="mt-2 h-12 bg-gray-50 dark:bg-gray-700/80 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white"
                    />
                    <InputError message={errors.email} className="mt-2" />
                  </div>

                  <div>
                    <Label htmlFor="password" className="text-gray-900 dark:text-white font-medium">
                      New password
                    </Label>
                    <div className="relative mt-2">
                      <Lock className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-5 w-5" />
                      <Input
                        id="password"
                        type={showPassword ? "text" : "password"}
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        placeholder="Enter new password"
                        value={data.password}
                        onChange={(e) => setData("password", e.target.value)}
                        className="pl-10 pr-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                        required
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword(!showPassword)}
                        className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                      >
                        {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                      </button>
                      <InputError message={errors.password} className="mt-2" />
                    </div>
                  </div>

                  <div>
                    <Label htmlFor="password_confirmation" className="text-gray-900 dark:text-white font-medium">
                      Confirm password
                    </Label>
                    <div className="relative mt-2">
                      <Lock className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-5 w-5" />
                      <Input
                        id="password_confirmation"
                        type={showConfirm ? "text" : "password"}
                        name="password_confirmation"
                        autoComplete="new-password"
                        placeholder="Confirm new password"
                        value={data.password_confirmation}
                        onChange={(e) => setData("password_confirmation", e.target.value)}
                        className="pl-10 pr-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                        required
                      />
                      <button
                        type="button"
                        onClick={() => setShowConfirm(!showConfirm)}
                        className="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                      >
                        {showConfirm ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                      </button>
                      <InputError message={errors.password_confirmation} className="mt-2" />
                    </div>
                  </div>

                  <TurnstileField
                    onToken={(token) => setData("cf_turnstile_response", token)}
                    error={errors.cf_turnstile_response}
                  />

                  <Button
                    type="submit"
                    className="w-full h-12 sm:h-14 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold text-base sm:text-lg rounded-lg shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-300"
                    disabled={processing || turnstileBlocksSubmit}
                  >
                    {processing && <LoaderCircle className="h-5 w-5 animate-spin mr-2" />}
                    Reset password
                  </Button>
                </form>

                <div className="text-center pt-4 border-t border-gray-200 dark:border-gray-700">
                  <Link
                    href={route("login")}
                    className="text-sm text-blue-600 hover:text-blue-700 hover:underline font-medium transition-colors"
                  >
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

"use client"

import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { FormEventHandler, useState } from "react"
import { motion } from "framer-motion"
import { Eye, EyeOff, Mail, Lock, Heart, LoaderCircle } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Separator } from "@/components/frontend/ui/separator"
import { Switch } from "@/components/frontend/ui/switch"
import { Link, useForm, usePage } from "@inertiajs/react"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import { PageHead } from "@/components/frontend/PageHead"

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
    cf_turnstile_response: string;
};

interface LoginProps {
    seo?: { title: string; description?: string }
    status?: string
    canResetPassword?: boolean
}

export default function LoginPage({ seo, status, canResetPassword }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false)

    const { data, setData, post, processing, errors, reset } = useForm<Required<LoginForm>>({
            email: '',
            password: '',
            remember: true,
            cf_turnstile_response: '',
        });

    const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password', 'cf_turnstile_response'),
        });
    };

    return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Log In"} description={seo?.description} />
    <div className="min-h-screen relative overflow-hidden bg-gradient-to-br from-purple-600 via-blue-600 to-purple-700 dark:from-gray-900 dark:via-gray-800 dark:to-purple-900">
      {/* Background Image Overlay */}
      <div
        className="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-20"
        style={{
          backgroundImage: 'url(/images/believe-hero.png)'
        }}
      >
        {/* Dark overlay for better content readability */}
        <div className="absolute inset-0 bg-purple-900/70 dark:bg-purple-900/80"></div>
      </div>

      {/* Subtle Pattern Overlay */}
      <div className="absolute inset-0 bg-grid-pattern opacity-5"></div>

      <div className="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10 py-12 sm:py-16 md:py-20">
        <motion.div
          initial={{ opacity: 0, y: 30 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.8 }}
          className="max-w-md mx-auto"
        >
          <Card className="border-0 shadow-2xl bg-white/95 dark:bg-gray-800/95 backdrop-blur-md overflow-hidden">
            {/* Gradient Header */}
            <div className="bg-gradient-to-br from-blue-500 to-blue-600 dark:from-blue-600 dark:to-blue-700 p-4 sm:p-5">
              <div className="flex flex-col items-center text-center">
                <div className="mx-auto bg-white rounded-xl w-16 h-16 sm:w-20 sm:h-20 flex items-center justify-center mb-2 shadow-lg p-2.5">
                  <img
                    src="/favicon-96x96.png"
                    alt="Believe In Unity Logo"
                    className="h-full w-full object-contain"
                  />
                </div>
                <CardTitle className="text-xl sm:text-2xl font-bold text-white mb-1">Welcome Back</CardTitle>
                <CardDescription className="text-sm text-white/90 max-w-sm">
                  Sign in to your account to continue making a difference
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
                  <div className="mt-2">
                    <div className="relative">
                      <Mail className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                      <Input
                        id="email"
                        type="email"
                        placeholder="Enter your email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="h-12 bg-white pl-10 dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                        required
                      />
                    </div>
                    <InputError message={errors.email} className="mt-2" />
                  </div>
                </div>

                <div>
                  <Label htmlFor="password" className="text-gray-900 dark:text-white font-medium">
                    Password
                  </Label>
                  <div className="mt-2">
                    <div className="relative">
                      <Lock className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                      <Input
                        id="password"
                        type={showPassword ? "text" : "password"}
                        placeholder="Enter your password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="h-12 bg-white pl-10 pr-10 dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                        required
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword(!showPassword)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                      >
                        {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                      </button>
                    </div>
                    <InputError message={errors.password} className="mt-2" />
                  </div>
                </div>

                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-3">
                    <Switch
                      id="remember"
                      checked={data.remember}
                      onCheckedChange={(checked) => setData('remember', checked)}
                      className="data-[state=checked]:bg-blue-600"
                    />
                    <Label htmlFor="remember" className="text-sm text-gray-900 dark:text-white cursor-pointer">
                      Remember me
                    </Label>
                  </div>
                  {(canResetPassword ?? true) && (
                    <Link href={route("password.request")} className="text-sm text-blue-600 hover:text-blue-700 hover:underline font-medium transition-colors">
                      Forgot password?
                    </Link>
                  )}
                </div>

                <TurnstileField
                  onToken={(token) => setData('cf_turnstile_response', token)}
                  error={errors.cf_turnstile_response}
                />

                <Button
                  type="submit"
                  className="w-full h-12 sm:h-14 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold text-base sm:text-lg rounded-lg shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-300"
                  disabled={processing || turnstileBlocksSubmit}
                >
                  {processing ? (
                    <>
                      <LoaderCircle className="h-5 w-5 animate-spin mr-2" />
                      Signing In...
                    </>
                  ) : (
                    "Sign In"
                  )}
                </Button>
              </form>

              <div className="text-center pt-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Don't have an account?{" "}
                  <Link href="/register" className="text-blue-600 hover:text-blue-700 hover:underline font-medium transition-colors">
                    Sign up
                  </Link>
                </p>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  By signing in, you agree to our{" "}
                  <Link href="/terms-of-service" className="text-blue-600 dark:text-blue-400 hover:underline">Terms of Service</Link>
                  {" "}and{" "}
                  <Link href="/privacy-policy" className="text-blue-600 dark:text-blue-400 hover:underline font-medium">Privacy Policy</Link>.
                </p>
              </div>
            </CardContent>
          </Card>
        </motion.div>
      </div>
    </div>
    </FrontendLayout>
  )
}

import type React from "react"

import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { FormEventHandler } from 'react';
import { useState } from "react"
import { motion } from "framer-motion"
import { Eye, EyeOff, Mail, Lock, User, Heart, ArrowLeft, LoaderCircle, ShieldCheck } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Checkbox } from "@/components/frontend/ui/checkbox"
import { Link, useForm, usePage } from "@inertiajs/react"
import InputError from "@/components/input-error";
import { MultiSelect } from "@/components/ui/multi-select"
import { PageHead } from "@/components/frontend/PageHead"

type RegisterForm = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    agreeToTerms: boolean;
    referralCode: string;
    positions: number[];
};

interface UserRegisterPageProps {
  seo?: { title: string; description?: string }
  referralCode?: string
  positions: { id: number; name: string }[]
  lockOrganization?: { id: number; name: string } | null
  giftInviteEmail?: string | null
  giftInviteToken?: string | null
}

export default function UserRegisterPage({
  seo,
  referralCode = '',
  positions,
  lockOrganization = null,
  giftInviteEmail = null,
}: UserRegisterPageProps) {
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  //   const [isSubmitting, setIsSubmitting] = useState(false)
  //   const [isSuccess, setIsSuccess] = useState(false)

  const { data, setData, post, processing, errors, reset } = useForm<Required<RegisterForm>>({
    name: '',
    email: giftInviteEmail || '',
    password: '',
    password_confirmation: '',
    agreeToTerms: false,
      referralCode: referralCode,
    positions: [] as number[],
  });

  const submit: FormEventHandler = (e) => {
    e.preventDefault();
    post('/register', {
      onFinish: () => reset('password', 'password_confirmation'),
    });
  };


  //   const handleInputChange = (field: string, value: string | boolean) => {
  //     setFormData((prev) => ({ ...prev, [field]: value }))
  //   }

  //   const handleSubmit = async (e: React.FormEvent) => {
  //     e.preventDefault()
  //     setIsSubmitting(true)

  //     // Simulate API call
  //     await new Promise((resolve) => setTimeout(resolve, 2000))

  //     setIsSubmitting(false)
  //     setIsSuccess(true)
  //   }

  //   if (isSuccess) {
  //     return (
  //       <div className="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 flex items-center justify-center py-12">
  //         <div className="container mx-auto px-4">
  //           <motion.div
  //             initial={{ opacity: 0, scale: 0.9 }}
  //             animate={{ opacity: 1, scale: 1 }}
  //             transition={{ duration: 0.6 }}
  //             className="max-w-md mx-auto"
  //           >
  //             <Card className="border-0 shadow-xl bg-white dark:bg-gray-800 text-center">
  //               <CardContent className="pt-8 pb-8">
  //                 <div className="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-6">
  //                   <CheckCircle className="h-8 w-8 text-green-600" />
  //                 </div>
  //                 <h2 className="text-2xl font-bold text-gray-900 dark:text-white mb-4">Account Created Successfully!</h2>
  //                 <p className="text-gray-600 dark:text-gray-300 mb-6">
  //                   Welcome to {import.meta.env.VITE_APP_NAME}! We've sent a verification email to{" "}
  //                   <span className="font-medium text-blue-600">{formData.email}</span>
  //                 </p>
  //                 <Alert className="mb-6 border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
  //                   <Send className="h-4 w-4 text-blue-600" />
  //                   <AlertDescription className="text-blue-700 dark:text-blue-400">
  //                     Please check your email and click the verification link to activate your account.
  //                   </AlertDescription>
  //                 </Alert>
  //                 <div className="space-y-3">
  //                   <Link href={route('login')}>
  //                     <Button className="w-full bg-blue-600 hover:bg-blue-700">Go to Sign In</Button>
  //                   </Link>
  //                   <Link href={route('home')}>
  //                     <Button variant="outline" className="w-full bg-transparent">
  //                       Continue Browsing
  //                     </Button>
  //                   </Link>
  //                 </div>
  //                 <p className="text-sm text-gray-500 dark:text-gray-400 mt-4">
  //                   Didn't receive the email?{" "}
  //                   <button className="text-blue-600 hover:underline">Resend verification</button>
  //                 </p>
  //               </CardContent>
  //             </Card>
  //           </motion.div>
  //         </div>
  //       </div>
  //     )
  //   }

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Register as Supporter"} description={seo?.description} />
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
          className="max-w-4xl mx-auto"
        >
          {/* Back Button */}
          <Link href="/register" className="inline-flex items-center text-white/90 hover:text-white mb-6 transition-colors">
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to registration options
          </Link>

          <Card className="border-0 shadow-2xl bg-white/95 dark:bg-gray-800/95 backdrop-blur-md overflow-hidden">
            {/* Gradient Header */}
            <div className="bg-gradient-to-br from-blue-500 to-blue-600 dark:from-blue-600 dark:to-blue-700 p-6 sm:p-8">
              <div className="flex flex-col items-center text-center">
                <div className="mx-auto bg-white/20 backdrop-blur-sm w-16 h-16 sm:w-20 sm:h-20 rounded-2xl flex items-center justify-center mb-4 shadow-lg">
                  <User className="h-8 w-8 sm:h-10 sm:w-10 text-white" />
                </div>
                <CardTitle className="text-2xl sm:text-3xl font-bold text-white mb-2">Create Your Account</CardTitle>
                <CardDescription className="text-base text-white/90 max-w-sm">
                  Join thousands of supporters making a difference worldwide
                </CardDescription>
              </div>
            </div>
              <CardContent className="p-6 sm:p-8">
                <form onSubmit={submit} className="space-y-6">
                  {lockOrganization && (
                    <div className="rounded-lg border border-purple-200 bg-purple-50 p-4 dark:border-purple-800 dark:bg-purple-900/20">
                      <p className="text-sm font-semibold text-purple-800 dark:text-purple-200">
                        Primary organization: {lockOrganization.name}
                      </p>
                      <p className="mt-1 text-sm text-purple-700 dark:text-purple-300">
                        After registration, this organization will be set as your locked primary organization.
                      </p>
                    </div>
                  )}

                  {referralCode && !lockOrganization && (
                    <div className="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                      <div className="flex items-center justify-center gap-2 mb-1">
                        <ShieldCheck className="h-5 w-5 text-green-600" />
                        <h3 className="text-sm font-semibold text-green-800 dark:text-green-300">
                          Referral Code Applied
                        </h3>
                      </div>
                      <p className="text-sm font-bold text-green-600 dark:text-green-400">{referralCode}</p>
                    </div>
                  )}

                  {giftInviteEmail && (
                    <div className="rounded-lg border border-violet-200 bg-violet-50 p-4 dark:border-violet-800 dark:bg-violet-900/20">
                      <p className="text-sm font-semibold text-violet-800 dark:text-violet-200">
                        You have a Believe Points gift waiting
                      </p>
                      <p className="mt-1 text-sm text-violet-700 dark:text-violet-300">
                        Register with <strong>{giftInviteEmail}</strong> to claim Holding BP into your Gifted balance.
                      </p>
                    </div>
                  )}

                  {/* Grid Layout for Form Fields */}
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {/* Full Name */}
                    <div className="md:col-span-1">
                      <Label htmlFor="firstName" className="text-gray-900 dark:text-white font-medium">
                        Full Name
                      </Label>
                      <div className="relative mt-2">
                        <User className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <Input
                          id="firstName"
                          type="text"
                          placeholder="Enter your full name"
                          value={data.name}
                          onChange={(e) => setData('name', e.target.value)}
                          disabled={processing}
                          className="pl-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                          required
                        />
                      </div>
                      <InputError message={errors.name} className="mt-2" />
                    </div>

                    {/* Email Address */}
                    <div className="md:col-span-1">
                      <Label htmlFor="email" className="text-gray-900 dark:text-white font-medium">
                        Email Address
                      </Label>
                      <div className="relative mt-2">
                        <Mail className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <Input
                          id="email"
                          type="email"
                          placeholder="john@example.com"
                          value={data.email}
                          onChange={(e) => setData('email', e.target.value)}
                          disabled={processing || !!giftInviteEmail}
                          readOnly={!!giftInviteEmail}
                          className="pl-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                          required
                        />
                      </div>
                      <InputError message={errors.email} className="mt-2" />
                    </div>

                    {/* Password */}
                    <div className="md:col-span-1">
                      <Label htmlFor="password" className="text-gray-900 dark:text-white font-medium">
                        Password
                      </Label>
                      <div className="relative mt-2">
                        <Lock className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <Input
                          id="password"
                          type={showPassword ? "text" : "password"}
                          placeholder="Create a strong password"
                          value={data.password}
                          onChange={(e) => setData('password', e.target.value)}
                          disabled={processing}
                          className="pl-10 pr-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                          required
                        />
                        <button
                          type="button"
                          onClick={() => setShowPassword(!showPassword)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 transition-colors hover:text-gray-600"
                        >
                          {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                        </button>
                      </div>
                      <InputError message={errors.password} className="mt-2" />
                    </div>

                    {/* Confirm Password */}
                    <div className="md:col-span-1">
                      <Label htmlFor="confirmPassword" className="text-gray-900 dark:text-white font-medium">
                        Confirm Password
                      </Label>
                      <div className="relative mt-2">
                        <Lock className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <Input
                          id="confirmPassword"
                          type={showConfirmPassword ? "text" : "password"}
                          placeholder="Confirm your password"
                          value={data.password_confirmation}
                          onChange={(e) => setData('password_confirmation', e.target.value)}
                          disabled={processing}
                          className="pl-10 pr-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200"
                          required
                        />
                        <button
                          type="button"
                          onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 transition-colors hover:text-gray-600"
                        >
                          {showConfirmPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                        </button>
                      </div>
                      <InputError message={errors.password_confirmation} className="mt-2" />
                    </div>

                    {/* Position Selection - Full Width */}
                    <div className="md:col-span-2">
                      <Label className="text-gray-900 dark:text-white font-medium">
                        I am a <span className="text-red-500">*</span>
                      </Label>
                      <div className="mt-2">
                        <MultiSelect
                          options={positions.map(p => ({ label: p.name, value: p.id.toString() }))}
                          selected={data.positions.map(String)}
                          onChange={(selected) => setData('positions', selected.map(Number))}
                          placeholder="Select your position(s)"
                        />
                      </div>
                      <InputError message={errors.positions} className="mt-2" />
                    </div>
                  </div>

                  <div className="space-y-3">
                    <div className="flex items-center space-x-2">
                      <Checkbox
                        id="terms"
                        required
                        checked={data.agreeToTerms}
                        onCheckedChange={(checked) => setData("agreeToTerms", checked as boolean)}
                      />
                      <Label htmlFor="terms" className="text-sm text-gray-900 dark:text-white">
                        I agree to the{" "}
                        <Link href="/terms-of-service" className="text-blue-600 hover:underline">
                          Terms of Service
                        </Link>{" "}
                        and{" "}
                        <Link href="/privacy-policy" className="text-blue-600 hover:underline">
                          Privacy Policy
                        </Link>
                      </Label>
                    </div>
                    {/* <div className="flex items-center space-x-2">
                    <Checkbox
                      id="newsletter"
                      checked={formData.subscribeNewsletter}
                      onCheckedChange={(checked) => handleInputChange("subscribeNewsletter", checked as boolean)}
                    />
                    <Label htmlFor="newsletter" className="text-sm text-gray-900 dark:text-white">
                      Subscribe to our newsletter for updates and impact stories
                    </Label>
                  </div> */}
                  </div>

                  <Button
                    type="submit"
                    className="w-full h-12 sm:h-14 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold text-base sm:text-lg rounded-lg shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-300"
                    disabled={processing}
                  >
                    {processing ? (
                      <>
                        <LoaderCircle className="h-5 w-5 animate-spin mr-2" />
                        Creating Account...
                      </>
                    ) : (
                      <>
                        Create Account
                        <Heart className="ml-2 h-5 w-5" />
                      </>
                    )}
                  </Button>
                </form>

                {/* <div className="relative">
                <Separator />
                <div className="absolute inset-0 flex items-center justify-center">
                  <span className="bg-white px-2 text-sm text-gray-500">Or sign up with</span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <Button
                  variant="outline"
                  className="h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600"
                >
                  <svg className="mr-2 h-5 w-5" viewBox="0 0 24 24">
                    <path
                      fill="currentColor"
                      d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                    />
                    <path
                      fill="currentColor"
                      d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                    />
                    <path
                      fill="currentColor"
                      d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                    />
                    <path
                      fill="currentColor"
                      d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                    />
                  </svg>
                  Google
                </Button>
                <Button
                  variant="outline"
                  className="h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600"
                >
                  <svg className="mr-2 h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                  </svg>
                  Facebook
                </Button>
              </div> */}

                <div className="text-center pt-4 border-t border-gray-200 dark:border-gray-700">
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Already have an account?{" "}
                    <Link href="/login" className="text-blue-600 hover:text-blue-700 hover:underline font-medium transition-colors">
                      Sign in
                    </Link>
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

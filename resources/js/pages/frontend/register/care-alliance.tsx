import { useEffect, useMemo, useState } from "react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Checkbox } from "@/components/frontend/ui/checkbox"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import { Link, useForm, usePage } from "@inertiajs/react"
import { PageHead } from "@/components/frontend/PageHead"
import { AnimatePresence, motion } from "framer-motion"
import {
  AlertCircle,
  ArrowLeft,
  Building2,
  CheckCircle,
  Eye,
  EyeOff,
  Globe,
  Heart,
  LoaderCircle,
  Lock,
  Mail,
  MapPin,
  Network,
  Percent,
  User,
} from "lucide-react"
import InputError from "@/components/input-error"
import { Alert, AlertDescription } from "@/components/frontend/ui/alert"
import toast from "react-hot-toast"

const MEMBERSHIP_JOIN_METHODS = [
  { value: "open_enrollment", label: "Open Enrollment" },
  { value: "request_to_join", label: "Request to Join" },
  { value: "invitation_only", label: "Invitation Only" },
] as const

const STEPS = 3

/** Map Laravel/Inertia validation keys to the first step that contains those fields. */
function getFirstStepWithErrors(errors: Record<string, string | string[] | undefined>): number {
  const keys = Object.keys(errors).filter((k) => {
    const v = errors[k]
    if (v == null) return false
    if (Array.isArray(v)) return v.length > 0
    return String(v).length > 0
  })
  if (keys.length === 0) return 1

  const step1 = new Set(["contact_name", "email", "password", "password_confirmation", "general"])
  const step2 = new Set([
    "name",
    "description",
    "city",
    "state",
    "website",
    "ein",
    "management_fee_percent",
    "fund_model",
    "memberships_enabled",
    "membership_type",
    "membership_name",
    "join_method",
  ])

  if (keys.some((k) => step1.has(k))) return 1
  if (keys.some((k) => step2.has(k))) return 2
  if (
    keys.some(
      (k) => k === "primary_action_category_ids" || k.startsWith("primary_action_category_ids."),
    )
  )
    return 3

  return 1
}

interface RegisterCareAllianceProps {
  seo?: { title: string; description?: string }
  primaryActionCategories?: { id: number; name: string }[]
}

type CareAllianceForm = {
  contact_name: string
  email: string
  password: string
  password_confirmation: string
  name: string
  description: string
  city: string
  state: string
  website: string
  ein: string
  management_fee_percent: string
  fund_model: "direct" | "campaign_split"
  primary_action_category_ids: number[]
  memberships_enabled: boolean
  membership_type: "free" | "paid"
  membership_name: string
  join_method: (typeof MEMBERSHIP_JOIN_METHODS)[number]["value"]
}

export default function RegisterCareAlliancePage() {
  const { seo, primaryActionCategories = [] } = usePage<RegisterCareAllianceProps>().props

  const [step, setStep] = useState(1)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [showPasswordsMatchHint, setShowPasswordsMatchHint] = useState(false)

  const form = useForm<CareAllianceForm>({
    contact_name: "",
    email: "",
    password: "",
    password_confirmation: "",
    name: "",
    description: "",
    city: "",
    state: "",
    website: "",
    ein: "",
    management_fee_percent: "",
    fund_model: "campaign_split",
    primary_action_category_ids: [],
    memberships_enabled: false,
    membership_type: "free",
    membership_name: "",
    join_method: "request_to_join",
  })

  const inputClass =
    "pl-10 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 transition-all duration-200"
  const labelClass = "text-gray-900 dark:text-white font-medium"

  const passwordRequirements = useMemo(() => {
    const password = form.data.password || ""
    return [
      {
        id: "length",
        label: "At least 8 characters",
        met: password.length >= 8,
      },
      {
        id: "lowercase",
        label: "At least one lowercase letter",
        met: /[a-z]/.test(password),
      },
      {
        id: "uppercase",
        label: "At least one uppercase letter",
        met: /[A-Z]/.test(password),
      },
      {
        id: "number",
        label: "At least one number",
        met: /[0-9]/.test(password),
      },
      {
        id: "symbol",
        label: "At least one symbol",
        met: /[^A-Za-z0-9]/.test(password),
      },
    ]
  }, [form.data.password])

  const allPasswordRequirementsMet = useMemo(
    () => passwordRequirements.every((requirement) => requirement.met),
    [passwordRequirements]
  )

  const passwordsMatch = useMemo(() => {
    if (!form.data.password || !form.data.password_confirmation) {
      return false
    }
    return form.data.password === form.data.password_confirmation
  }, [form.data.password, form.data.password_confirmation])

  useEffect(() => {
    if (!form.data.password_confirmation) {
      setShowPasswordsMatchHint(false)
      return
    }

    if (!passwordsMatch) {
      setShowPasswordsMatchHint(false)
      return
    }

    setShowPasswordsMatchHint(true)
    const t = window.setTimeout(() => setShowPasswordsMatchHint(false), 2500)
    return () => window.clearTimeout(t)
  }, [form.data.password_confirmation, passwordsMatch])

  const validateStep = (stepNumber: number): boolean => {
    switch (stepNumber) {
      case 1:
        return !!(
          form.data.contact_name.trim() &&
          form.data.email.trim() &&
          allPasswordRequirementsMet &&
          passwordsMatch
        )
      case 2:
        if (!form.data.name.trim()) return false
        if (form.data.memberships_enabled) {
          return (
            form.data.membership_name.trim().length > 0 &&
            (form.data.membership_type === "free" || form.data.membership_type === "paid") &&
            MEMBERSHIP_JOIN_METHODS.some((method) => method.value === form.data.join_method)
          )
        }
        return true
      case 3:
        return form.data.primary_action_category_ids.length >= 1 && form.data.primary_action_category_ids.length <= 8
      default:
        return false
    }
  }

  const stepValidationHint = (stepNumber: number): string => {
    switch (stepNumber) {
      case 1:
        return "Please enter your name, email, and a password of at least 8 characters that matches the confirmation."
      case 2:
        return form.data.memberships_enabled
          ? "Please enter your alliance name, membership name, type, and join method."
          : "Please enter your alliance name."
      case 3:
        return "Please select at least one category (up to 8)."
      default:
        return "Please complete this step before continuing."
    }
  }

  const goToStep = (targetStep: number) => {
    if (targetStep < 1 || targetStep > STEPS) return
    if (targetStep < step) {
      setStep(targetStep)
      return
    }
    if (targetStep > step) {
      for (let s = step; s < targetStep; s++) {
        if (!validateStep(s)) {
          toast.error(stepValidationHint(s))
          return
        }
      }
      setStep(targetStep)
    }
  }

  const toggleCategory = (id: number) => {
    const prev = form.data.primary_action_category_ids
    if (prev.includes(id)) {
      form.setData(
        "primary_action_category_ids",
        prev.filter((x) => x !== id)
      )
      return
    }
    if (prev.length >= 8) {
      toast.error("Choose at most 8 categories.")
      return
    }
    form.setData("primary_action_category_ids", [...prev, id])
  }

  const errorsSerialized = useMemo(() => JSON.stringify(form.errors), [form.errors])

  useEffect(() => {
    const parsed = JSON.parse(errorsSerialized) as Record<string, string | string[] | undefined>
    if (!parsed || Object.keys(parsed).length === 0) return
    setStep(getFirstStepWithErrors(parsed))
  }, [errorsSerialized])

  const submit = () => {
    if (!validateStep(3)) {
      toast.error(stepValidationHint(3))
      return
    }
    form.post("/register/care-alliance", {
      preserveScroll: true,
      onError: (errors) => {
        setStep(getFirstStepWithErrors(errors as Record<string, string | string[] | undefined>))
      },
    })
  }

  const stepProgressLabel =
    step === 1 ? "Account & password" : step === 2 ? "Alliance setup" : "Categories & focus areas"

  const headerDescription =
    step === 1
      ? "Set up your email and password to continue"
      : step === 2
        ? "Name your alliance, add location and optional fee, and choose how funds are distributed"
        : "Select up to 8 primary action categories that describe your alliance"

  const errors = form.errors

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Register Unity Impact Alliance"} description={seo?.description} />
      <div className="min-h-screen relative overflow-hidden bg-gradient-to-br from-purple-600 via-blue-600 to-purple-700 dark:from-gray-900 dark:via-gray-800 dark:to-purple-900">
        <div
          className="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-20"
          style={{
            backgroundImage: "url(/images/believe-hero.png)",
          }}
        >
          <div className="absolute inset-0 bg-purple-900/70 dark:bg-purple-900/80" />
        </div>
        <div className="absolute inset-0 bg-grid-pattern opacity-5" />

        <div className="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10 py-12 sm:py-16 md:py-20">
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8 }}
            className="max-w-4xl mx-auto"
          >
            <Link
              href="/register"
              className="inline-flex items-center text-white/90 hover:text-white mb-6 transition-colors"
            >
              <ArrowLeft className="mr-2 h-4 w-4" />
              Back to registration options
            </Link>

            <div className="mb-8">
              <div className="flex items-center justify-between px-2 sm:justify-center sm:space-x-4">
                {[1, 2, 3].map((stepNumber) => (
                  <div key={stepNumber} className="flex items-center">
                    <button
                      type="button"
                      onClick={() => goToStep(stepNumber)}
                      disabled={stepNumber > step && !validateStep(step)}
                      className={`w-10 h-10 sm:w-12 sm:h-12 rounded-full flex items-center justify-center font-semibold text-sm transition-all duration-200 border-2 ${
                        step >= stepNumber
                          ? "bg-gradient-to-r from-violet-500 to-purple-600 text-white shadow-lg border-violet-300"
                          : "bg-white/10 dark:bg-gray-700/50 text-white/70 dark:text-gray-400 border-white/20 dark:border-gray-600 hover:bg-white/20 dark:hover:bg-gray-600"
                      } ${stepNumber <= step ? "cursor-pointer" : "cursor-not-allowed opacity-80"}`}
                    >
                      {step > stepNumber ? <CheckCircle className="h-5 w-5 sm:h-6 sm:w-6" /> : stepNumber}
                    </button>
                    {stepNumber < STEPS && (
                      <div
                        className={`w-8 sm:w-16 h-1 mx-1 sm:mx-2 transition-all duration-200 rounded-full ${
                          step > stepNumber
                            ? "bg-gradient-to-r from-violet-500 to-purple-600"
                            : "bg-white/20 dark:bg-gray-700/50"
                        }`}
                      />
                    )}
                  </div>
                ))}
              </div>
              <div className="flex justify-center mt-3 sm:mt-4">
                <span className="text-xs sm:text-sm text-white/90 dark:text-white/80 text-center px-4">
                  Step {step} of {STEPS}: {stepProgressLabel}
                </span>
              </div>
            </div>

            <Card className="border-0 shadow-2xl bg-white/95 dark:bg-gray-800/95 backdrop-blur-md overflow-hidden">
              <div className="bg-gradient-to-br from-violet-500 to-purple-700 dark:from-violet-600 dark:to-purple-800 p-4 sm:p-5">
                <div className="flex flex-col items-center text-center">
                  <div className="mx-auto bg-white rounded-xl w-16 h-16 sm:w-20 sm:h-20 flex items-center justify-center mb-2 shadow-lg p-2.5">
                    <img src="/favicon-96x96.png" alt="" className="h-full w-full object-contain" />
                  </div>
                  <CardTitle className="text-xl sm:text-2xl font-bold text-white mb-1">Register Your Unity Impact Alliance</CardTitle>
                  <CardDescription className="text-sm text-white/90 max-w-md min-h-[2.5rem] sm:min-h-[3rem] flex items-center justify-center">
                    {headerDescription}
                  </CardDescription>
                </div>
              </div>

              <CardContent className="p-6 sm:p-8 space-y-6">
                {errors.general && (
                  <Alert className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
                    <AlertCircle className="h-4 w-4 text-red-600" />
                    <AlertDescription className="text-red-700 dark:text-red-400">{errors.general}</AlertDescription>
                  </Alert>
                )}

                {step === 1 && (
                  <motion.div
                    initial={{ opacity: 0, x: 16 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.45 }}
                    className="space-y-6"
                  >
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                      <div className="md:col-span-1">
                        <Label htmlFor="contactName" className={labelClass}>
                          Your name
                        </Label>
                        <div className="relative mt-2">
                          <User className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="contactName"
                            value={form.data.contact_name}
                            onChange={(e) => form.setData("contact_name", e.target.value)}
                            placeholder="Full name"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.contact_name} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="email" className={labelClass}>
                          Email address
                        </Label>
                        <div className="relative mt-2">
                          <Mail className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData("email", e.target.value)}
                            placeholder="you@example.com"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.email} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="password" className={labelClass}>
                          Password
                        </Label>
                        <div className="relative mt-2">
                          <Lock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="password"
                            type={showPassword ? "text" : "password"}
                            value={form.data.password}
                            onChange={(e) => form.setData("password", e.target.value)}
                            placeholder="At least 8 characters"
                            className={`${inputClass} pr-10`}
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
                        {form.data.password && !allPasswordRequirementsMet && (
                          <div className="rounded-md border border-dashed border-primary/30 bg-primary/5 p-3 text-xs">
                            <p className="mb-2 font-medium text-primary">Your password must include:</p>
                            <div className="space-y-1">
                              {passwordRequirements.map((requirement, idx) => (
                                <motion.div
                                  key={requirement.id}
                                  className={`flex items-center gap-2 ${
                                    requirement.met ? "text-emerald-600" : "text-muted-foreground"
                                  }`}
                                  initial={{ opacity: 0.85, scale: 1 }}
                                  animate={
                                    allPasswordRequirementsMet && requirement.met
                                      ? { opacity: 1, scale: [1, 1.03, 1] }
                                      : { opacity: 1, scale: 1 }
                                  }
                                  transition={{ duration: 0.55, delay: allPasswordRequirementsMet ? idx * 0.06 : 0 }}
                                >
                                  {requirement.met ? (
                                    <CheckCircle className="h-4 w-4" />
                                  ) : (
                                    <AlertCircle className="h-4 w-4" />
                                  )}
                                  <span>{requirement.label}</span>
                                </motion.div>
                              ))}
                            </div>
                          </div>
                        )}
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="passwordConfirmation" className={labelClass}>
                          Confirm password
                        </Label>
                        <div className="relative mt-2">
                          <Lock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="passwordConfirmation"
                            type={showConfirmPassword ? "text" : "password"}
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData("password_confirmation", e.target.value)}
                            placeholder="Confirm your password"
                            className={`${inputClass} pr-10`}
                          />
                          <button
                            type="button"
                            onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                          >
                            {showConfirmPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
                          </button>
                        </div>
                        <InputError message={errors.password_confirmation} className="mt-2" />

                        {form.data.password_confirmation && !passwordsMatch && (
                          <div className="flex items-center gap-2 text-xs text-red-500">
                            <AlertCircle className="h-4 w-4" />
                            <span>Passwords do not match</span>
                          </div>
                        )}

                        <AnimatePresence>
                          {form.data.password_confirmation && passwordsMatch && showPasswordsMatchHint && (
                            <motion.div
                              className="flex items-center gap-2 text-xs text-emerald-600"
                              initial={{ opacity: 0, y: 4 }}
                              animate={{ opacity: 1, y: 0 }}
                              exit={{ opacity: 0, y: 4 }}
                              transition={{ duration: 0.25 }}
                            >
                              <CheckCircle className="h-4 w-4" />
                              <span>Passwords match</span>
                            </motion.div>
                          )}
                        </AnimatePresence>
                      </div>
                    </div>
                    <Button
                      type="button"
                      className="w-full h-12 sm:h-14 bg-gradient-to-r from-violet-600 to-purple-700 hover:from-violet-700 hover:to-purple-800 text-white font-bold text-base sm:text-lg rounded-lg shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                      onClick={() => goToStep(2)}
                      disabled={!validateStep(1)}
                    >
                      Continue
                    </Button>
                  </motion.div>
                )}

                {step === 2 && (
                  <motion.div
                    initial={{ opacity: 0, x: 16 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.45 }}
                    className="space-y-6"
                  >
                    <div className="text-center">
                      <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-violet-100 dark:bg-violet-900/40 mb-3">
                        <Network className="h-6 w-6 text-violet-600 dark:text-violet-300" />
                      </div>
                      <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-1">Alliance details</h3>
                      <p className="text-sm text-gray-600 dark:text-gray-400">
                        This information appears on your public profile and campaign pages.
                      </p>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                      <div className="md:col-span-2">
                        <Label htmlFor="allianceName" className={labelClass}>
                          Alliance name
                        </Label>
                        <div className="relative mt-2">
                          <Building2 className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="allianceName"
                            value={form.data.name}
                            onChange={(e) => form.setData("name", e.target.value)}
                            placeholder="e.g. Southeast Unity Impact Alliance"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.name} className="mt-2" />
                      </div>
                      <div className="md:col-span-2">
                        <Label htmlFor="description" className={labelClass}>
                          Description
                        </Label>
                        <Textarea
                          id="description"
                          rows={4}
                          value={form.data.description}
                          onChange={(e) => form.setData("description", e.target.value)}
                          placeholder="Tell supporters about your alliance."
                          className="mt-2 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20"
                        />
                        <InputError message={errors.description} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="city" className={labelClass}>
                          City
                        </Label>
                        <div className="relative mt-2">
                          <MapPin className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="city"
                            value={form.data.city}
                            onChange={(e) => form.setData("city", e.target.value)}
                            placeholder="City"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.city} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="state" className={labelClass}>
                          State / region
                        </Label>
                        <div className="relative mt-2">
                          <MapPin className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="state"
                            value={form.data.state}
                            onChange={(e) => form.setData("state", e.target.value)}
                            placeholder="State"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.state} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="website" className={labelClass}>
                          Website (optional)
                        </Label>
                        <div className="relative mt-2">
                          <Globe className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="website"
                            value={form.data.website}
                            onChange={(e) => form.setData("website", e.target.value)}
                            placeholder="https://"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.website} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="ein" className={labelClass}>
                          EIN (optional)
                        </Label>
                        <div className="relative mt-2">
                          <Building2 className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="ein"
                            value={form.data.ein}
                            onChange={(e) => form.setData("ein", e.target.value)}
                            placeholder="e.g. 12-3456789 (leave blank to assign automatically)"
                            className={inputClass}
                          />
                        </div>
                        <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                          If you do not have an EIN yet, leave this empty — we will create a unique identifier for your
                          records.
                        </p>
                        <InputError message={errors.ein} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label htmlFor="fee" className={labelClass}>
                          Default alliance fee % (optional)
                        </Label>
                        <div className="relative mt-2">
                          <Percent className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 h-5 w-5" />
                          <Input
                            id="fee"
                            inputMode="decimal"
                            value={form.data.management_fee_percent}
                            onChange={(e) => form.setData("management_fee_percent", e.target.value)}
                            placeholder="e.g. 5"
                            className={inputClass}
                          />
                        </div>
                        <InputError message={errors.management_fee_percent} className="mt-2" />
                      </div>
                      <div className="md:col-span-1">
                        <Label className={labelClass}>Fund distribution model</Label>
                        <Select
                          value={form.data.fund_model}
                          onValueChange={(v) => form.setData("fund_model", v as "direct" | "campaign_split")}
                        >
                          <SelectTrigger className="mt-2 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="campaign_split">Campaign-based split (recommended)</SelectItem>
                            <SelectItem value="direct">Direct donations (no split)</SelectItem>
                          </SelectContent>
                        </Select>
                        <InputError message={errors.fund_model} className="mt-2" />
                      </div>
                    </div>

                    <div className="rounded-xl border border-violet-200 bg-violet-50/60 p-4 dark:border-violet-800 dark:bg-violet-950/30">
                      <div className="flex items-center justify-between gap-4">
                        <div>
                          <p className="text-sm font-semibold text-gray-900 dark:text-white">Enable Memberships</p>
                          <p className="text-xs text-gray-600 dark:text-gray-400">
                            Turn on supporter membership for your Unity Impact Alliance
                          </p>
                        </div>
                        <button
                          type="button"
                          role="switch"
                          aria-checked={form.data.memberships_enabled}
                          onClick={() => {
                            const next = !form.data.memberships_enabled
                            form.setData("memberships_enabled", next)
                            if (next && !form.data.membership_name.trim() && form.data.name.trim()) {
                              form.setData("membership_name", form.data.name.trim())
                            }
                          }}
                          className={`relative h-7 w-12 shrink-0 cursor-pointer rounded-full transition ${
                            form.data.memberships_enabled ? "bg-violet-600" : "bg-gray-300 dark:bg-gray-600"
                          }`}
                        >
                          <span
                            className={`absolute top-0.5 h-6 w-6 rounded-full bg-white shadow transition ${
                              form.data.memberships_enabled ? "left-5" : "left-0.5"
                            }`}
                          />
                        </button>
                      </div>

                      {form.data.memberships_enabled && (
                        <div className="mt-4 space-y-4 border-t border-violet-200 pt-4 dark:border-violet-800">
                          <div>
                            <Label htmlFor="membershipName" className={labelClass}>
                              Membership Name *
                            </Label>
                            <Input
                              id="membershipName"
                              value={form.data.membership_name}
                              onChange={(e) => form.setData("membership_name", e.target.value)}
                              placeholder="e.g. Alliance Supporters"
                              className={`${inputClass} mt-2 pl-3`}
                            />
                            <InputError message={errors.membership_name} className="mt-2" />
                          </div>

                          <div>
                            <Label className={labelClass}>Membership Type *</Label>
                            <Select
                              value={form.data.membership_type}
                              onValueChange={(value) =>
                                form.setData("membership_type", value as "free" | "paid")
                              }
                            >
                              <SelectTrigger className="mt-2 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600">
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="free">Free</SelectItem>
                                <SelectItem value="paid">Paid</SelectItem>
                              </SelectContent>
                            </Select>
                            <InputError message={errors.membership_type} className="mt-2" />
                          </div>

                          <div>
                            <Label className={labelClass}>Join Method *</Label>
                            <Select
                              value={form.data.join_method}
                              onValueChange={(value) =>
                                form.setData(
                                  "join_method",
                                  value as (typeof MEMBERSHIP_JOIN_METHODS)[number]["value"],
                                )
                              }
                            >
                              <SelectTrigger className="mt-2 h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600">
                                <SelectValue placeholder="Select join method" />
                              </SelectTrigger>
                              <SelectContent>
                                {MEMBERSHIP_JOIN_METHODS.map((method) => (
                                  <SelectItem key={method.value} value={method.value}>
                                    {method.label}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                            <InputError message={errors.join_method} className="mt-2" />
                          </div>
                        </div>
                      )}
                    </div>

                    <div className="flex flex-col sm:flex-row gap-3">
                      <Button
                        type="button"
                        variant="outline"
                        className="flex-1 h-12 border-gray-300 dark:border-gray-600"
                        onClick={() => goToStep(1)}
                      >
                        Back
                      </Button>
                      <Button
                        type="button"
                        className="flex-1 h-12 sm:h-14 bg-gradient-to-r from-violet-600 to-purple-700 hover:from-violet-700 hover:to-purple-800 text-white font-bold rounded-lg shadow-lg disabled:opacity-50"
                        onClick={() => goToStep(3)}
                        disabled={!validateStep(2)}
                      >
                        Continue
                      </Button>
                    </div>
                  </motion.div>
                )}

                {step === 3 && (
                  <motion.div
                    initial={{ opacity: 0, x: 16 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.45 }}
                    className="space-y-6"
                  >
                    <div className="text-center">
                      <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-violet-100 dark:bg-violet-900/40 mb-3">
                        <Heart className="h-6 w-6 text-violet-600 dark:text-violet-300" />
                      </div>
                      <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-1">Primary action categories</h3>
                      <p className="text-sm text-gray-600 dark:text-gray-400">
                        Choose up to 8 categories that best describe your alliance (same taxonomy as nonprofit profiles).
                      </p>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-80 overflow-y-auto pr-1 rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-gray-50/80 dark:bg-gray-900/40">
                      {primaryActionCategories.map((c) => (
                        <label key={c.id} className="flex items-start gap-2 text-sm cursor-pointer text-gray-900 dark:text-white">
                          <Checkbox
                            checked={form.data.primary_action_category_ids.includes(c.id)}
                            onCheckedChange={() => toggleCategory(c.id)}
                            className="mt-0.5"
                          />
                          <span>{c.name}</span>
                        </label>
                      ))}
                    </div>
                    <InputError message={errors.primary_action_category_ids} className="mt-1" />
                    <p className="text-xs text-center text-muted-foreground">
                      {form.data.primary_action_category_ids.length} of 8 categories selected
                    </p>
                    <div className="flex flex-col sm:flex-row gap-3">
                      <Button
                        type="button"
                        variant="outline"
                        className="flex-1 h-12 border-gray-300 dark:border-gray-600"
                        onClick={() => goToStep(2)}
                      >
                        Back
                      </Button>
                      <Button
                        type="button"
                        className="flex-1 h-12 sm:h-14 bg-gradient-to-r from-violet-600 to-purple-700 hover:from-violet-700 hover:to-purple-800 text-white font-bold rounded-lg shadow-lg inline-flex items-center justify-center gap-2 disabled:opacity-50"
                        onClick={() => void submit()}
                        disabled={form.processing || !validateStep(3)}
                      >
                        {form.processing ? (
                          <>
                            <LoaderCircle className="h-5 w-5 animate-spin" />
                            Creating alliance…
                          </>
                        ) : (
                          <>
                            Create alliance
                            <Heart className="h-5 w-5" />
                          </>
                        )}
                      </Button>
                    </div>
                  </motion.div>
                )}

                <div className="text-center pt-2 border-t border-gray-200 dark:border-gray-700">
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Already have an account?{" "}
                    <Link
                      href="/login"
                      className="text-violet-600 dark:text-violet-400 hover:underline font-medium transition-colors"
                    >
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

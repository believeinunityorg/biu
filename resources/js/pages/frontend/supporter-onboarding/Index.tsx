"use client"

import type React from "react"
import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Head, Link, router, usePage } from "@inertiajs/react"
import { motion, AnimatePresence } from "framer-motion"
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  Calendar,
  Check,
  ChevronRight,
  Gift,
  Heart,
  Loader2,
  MapPin,
  Monitor,
  Moon,
  PartyPopper,
  Shield,
  Sparkles,
  Sun,
  Upload,
  Users,
  Bell,
  Palette,
  Camera,
  AlertCircle,
} from "lucide-react"
import toast from "react-hot-toast"

import { PageHead } from "@/components/frontend/PageHead"
import { Alert, AlertDescription } from "@/components/frontend/ui/alert"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Progress } from "@/components/frontend/ui/progress"
import { Checkbox } from "@/components/frontend/ui/checkbox"
import { RadioGroup, RadioGroupItem } from "@/components/frontend/ui/radio-group"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import {
  ProfileOrganizationPicker,
  type ProfileOrgOption,
} from "@/components/frontend/profile-organization-picker"
import { useAppearance, type Appearance } from "@/hooks/use-appearance"
import { cn, isValidMmDd } from "@/lib/utils"

type PurposeOption = { value: string; label: string }
type CauseOption = { id: number; name: string }
type RoleOption = { id: number; name: string }

type OnboardingUser = {
  name: string
  image?: string | null
  onboarding_purpose?: string | null
  dob?: string | null
  city?: string
  state?: string
  zipcode?: string
  supporter_interests?: number[]
  positions?: number[]
  primary_organization_id?: number | null
  primary_organization?: ProfileOrgOption | null
  primary_organization_locked?: boolean
  secondary_organization_ids?: number[]
  account_visibility?: "public" | "private"
  messaging_policy?: "everyone" | "followers_only" | "organizations_i_follow" | "no_one"
  preferred_theme?: Appearance
  proximity_notifications_enabled?: boolean
  notification_preferences?: Record<string, boolean>
}

type PageProps = {
  step: number
  totalSteps: number
  canFinish: boolean
  user: OnboardingUser
  purposes: PurposeOption[]
  onboardingRoles: RoleOption[]
  causes: CauseOption[]
  organizations: ProfileOrgOption[]
}

const MONTHS = [
  { value: "01", label: "January" },
  { value: "02", label: "February" },
  { value: "03", label: "March" },
  { value: "04", label: "April" },
  { value: "05", label: "May" },
  { value: "06", label: "June" },
  { value: "07", label: "July" },
  { value: "08", label: "August" },
  { value: "09", label: "September" },
  { value: "10", label: "October" },
  { value: "11", label: "November" },
  { value: "12", label: "December" },
]

const NOTIFICATION_OPTIONS = [
  { key: "organizations_followed", label: "Organizations you follow" },
  { key: "community_events", label: "Community Events" },
  { key: "volunteer_opportunities", label: "Volunteer Opportunities" },
  { key: "donations_campaigns", label: "Donations & Campaigns" },
  { key: "unity_meet_invitations", label: "Unity Meet Invitations" },
  { key: "marketplace_offers", label: "Marketplace Offers" },
] as const

const ORG_CONNECTION_OPTIONS = [
  { value: "not_now", label: "Not Right Now" },
  { value: "search", label: "Search Organizations" },
  { value: "member", label: "I'm a Member" },
  { value: "volunteer", label: "I Volunteer There" },
  { value: "work", label: "I Work There" },
] as const

function daysInMonth(month: string): number {
  const m = parseInt(month, 10)
  if (!m) return 31
  if (m === 2) return 29
  if ([4, 6, 9, 11].includes(m)) return 30
  return 31
}

function splitDob(dob?: string | null): { month: string; day: string } {
  if (!dob || !dob.includes("/")) return { month: "", day: "" }
  const [month, day] = dob.split("/")
  return { month: month?.padStart(2, "0") ?? "", day: day?.padStart(2, "0") ?? "" }
}

function combineDob(month: string, day: string): string {
  if (!month || !day) return ""
  return `${month}/${day}`
}

function firstValidationError(errors: Record<string, string | string[] | undefined>): string | null {
  for (const value of Object.values(errors)) {
    if (typeof value === "string" && value.trim() !== "") {
      return value
    }
    if (Array.isArray(value) && typeof value[0] === "string" && value[0].trim() !== "") {
      return value[0]
    }
  }

  return null
}

export default function SupporterOnboardingIndex() {
  const {
    step: serverStep,
    totalSteps,
    canFinish,
    user,
    purposes,
    onboardingRoles,
    causes,
    organizations,
    errors: pageErrors,
  } = usePage<PageProps & { errors?: Record<string, string | string[]> }>().props

  const lastToastError = useRef<string | null>(null)

  const [currentStep, setCurrentStep] = useState(serverStep)
  const [saving, setSaving] = useState(false)
  const [previewUrl, setPreviewUrl] = useState<string | null>(user.image ?? null)
  const [imageFile, setImageFile] = useState<File | null>(null)

  const [purpose, setPurpose] = useState(user.onboarding_purpose ?? "")
  const dobParts = splitDob(user.dob)
  const [birthMonth, setBirthMonth] = useState(dobParts.month)
  const [birthDay, setBirthDay] = useState(dobParts.day)
  const [city, setCity] = useState(user.city ?? "")
  const [state, setState] = useState(user.state ?? "")
  const [zipcode, setZipcode] = useState(user.zipcode ?? "")
  const [selectedCauses, setSelectedCauses] = useState<number[]>(user.supporter_interests ?? [])
  const [selectedRoles, setSelectedRoles] = useState<number[]>(user.positions ?? [])
  const [orgConnection, setOrgConnection] = useState<string>(
    user.primary_organization_id ? "search" : "not_now",
  )
  const [primaryOrgId, setPrimaryOrgId] = useState<string>(
    user.primary_organization_id ? String(user.primary_organization_id) : "",
  )
  const [primaryOrg, setPrimaryOrg] = useState<ProfileOrgOption | null>(user.primary_organization ?? null)
  const [secondaryOrgIds, setSecondaryOrgIds] = useState<number[]>(user.secondary_organization_ids ?? [])
  const [orgRows, setOrgRows] = useState<ProfileOrgOption[]>(organizations)
  const [accountVisibility, setAccountVisibility] = useState<"public" | "private">(
    user.account_visibility ?? "public",
  )
  const [messagingPolicy, setMessagingPolicy] = useState<
    "everyone" | "followers_only" | "organizations_i_follow" | "no_one"
  >(user.messaging_policy ?? "everyone")
  const [notificationPrefs, setNotificationPrefs] = useState<Record<string, boolean>>(
    user.notification_preferences ?? {},
  )
  const [preferredTheme, setPreferredTheme] = useState<Appearance>(user.preferred_theme ?? "system")
  const { updateAppearance } = useAppearance()

  const [stepError, setStepError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<{
    purpose?: boolean
    birthMonth?: boolean
    birthDay?: boolean
    city?: boolean
    state?: boolean
  }>({})

  useEffect(() => {
    setStepError(null)
    setFieldErrors({})
  }, [currentStep])

  useEffect(() => {
    setCurrentStep(serverStep)
    setPurpose(user.onboarding_purpose ?? "")
    const parts = splitDob(user.dob)
    setBirthMonth(parts.month)
    setBirthDay(parts.day)
    setCity(user.city ?? "")
    setState(user.state ?? "")
    setZipcode(user.zipcode ?? "")
    setPreviewUrl(user.image ?? null)
  }, [serverStep, user])

  useEffect(() => {
    const message = firstValidationError(pageErrors ?? {})
    if (message && message !== lastToastError.current) {
      lastToastError.current = message
      toast.error(message)
    }
    if (!message) {
      lastToastError.current = null
    }
  }, [pageErrors])

  const progressPercent = useMemo(
    () => Math.round((currentStep / totalSteps) * 100),
    [currentStep, totalSteps],
  )

  const dayOptions = useMemo(() => {
    const max = daysInMonth(birthMonth)
    return Array.from({ length: max }, (_, i) => String(i + 1).padStart(2, "0"))
  }, [birthMonth])

  const dobValue = combineDob(birthMonth, birthDay)

  const validateCurrentStep = (): boolean => {
    setStepError(null)
    setFieldErrors({})

    if (currentStep === 2) {
      if (!purpose) {
        setFieldErrors({ purpose: true })
        setStepError("Please select what brings you here today. This field is required.")
        return false
      }
      return true
    }

    if (currentStep === 4) {
      const missingMonth = !birthMonth
      const missingDay = !birthDay
      if (missingMonth || missingDay || !isValidMmDd(dobValue)) {
        setFieldErrors({ birthMonth: missingMonth, birthDay: missingDay || missingMonth })
        setStepError("Please select your birth month and day. Both fields are required.")
        return false
      }
      return true
    }

    if (currentStep === 5) {
      const trimmedCity = city.trim()
      const trimmedState = state.trim().toUpperCase()
      if (!trimmedCity || trimmedState.length !== 2) {
        setFieldErrors({
          city: !trimmedCity,
          state: trimmedState.length !== 2,
        })
        setStepError("City and a 2-letter state code (e.g. CA) are required.")
        return false
      }
      return true
    }

    return true
  }

  const postStep = useCallback(
    (payload: Record<string, unknown>, onDone?: () => void) => {
      setSaving(true)
      router.post(
        route("user.onboarding.step"),
        { step: currentStep, ...payload },
        {
          preserveScroll: true,
          forceFormData: payload.image instanceof File,
          onSuccess: () => {
            setStepError(null)
            setFieldErrors({})
            onDone?.()
          },
          onError: (errors) => {
            const message = firstValidationError(errors)
            if (message) {
              lastToastError.current = message
              toast.error(message)
            } else {
              toast.error("Could not save this step. Please check your answers and try again.")
            }
          },
          onFinish: () => setSaving(false),
        },
      )
    },
    [currentStep, totalSteps],
  )

  const handleNext = () => {
    if (!validateCurrentStep()) {
      return
    }

    if (currentStep === 1) {
      postStep({})
      return
    }

    if (currentStep === 2) {
      postStep({ onboarding_purpose: purpose })
      return
    }

    if (currentStep === 3) {
      if (imageFile) {
        postStep({ image: imageFile })
      } else {
        postStep({ skip: true })
      }
      return
    }

    if (currentStep === 4) {
      postStep({ dob: dobValue })
      return
    }

    if (currentStep === 5) {
      postStep({
        city: city.trim(),
        state: state.trim().toUpperCase(),
        zipcode: zipcode.trim(),
      })
      return
    }

    if (currentStep === 6) {
      postStep({ supporter_interests: selectedCauses })
      return
    }

    if (currentStep === 7) {
      postStep({ positions: selectedRoles })
      return
    }

    if (currentStep === 8) {
      if (orgConnection === "not_now") {
        postStep({ skip: true, organization_connection: "not_now" })
        return
      }
      if (!primaryOrgId) {
        toast.error("Please select an organization or choose Not Right Now.")
        return
      }
      postStep({
        primary_organization_id: Number(primaryOrgId),
        secondary_organization_ids: secondaryOrgIds,
        organization_connection: orgConnection,
      })
      return
    }

    if (currentStep === 9) {
      postStep({
        account_visibility: accountVisibility,
        messaging_policy: messagingPolicy,
      })
      return
    }

    if (currentStep === 10) {
      postStep({
        notification_preferences: notificationPrefs,
        proximity_notifications_enabled: user.proximity_notifications_enabled !== false,
      })
      return
    }

    if (currentStep === 11) {
      updateAppearance(preferredTheme)
      postStep({ preferred_theme: preferredTheme })
      return
    }
  }

  const handleSkip = () => {
    if (currentStep === 3) {
      postStep({ skip: true })
      return
    }
    if (currentStep === 6 || currentStep === 7 || currentStep === 8 || currentStep === 10) {
      postStep({ skip: true })
      return
    }
  }

  const handleBack = () => {
    if (currentStep > 1) setCurrentStep((s) => s - 1)
  }

  const finish = (destination: "dashboard" | "organizations") => {
    if (saving || !canFinish) {
      if (!canFinish) {
        toast.error("Please complete all required steps before finishing.")
      }
      return
    }

    setSaving(true)
    router.post(
      route("user.onboarding.finish"),
      { destination },
      {
        preserveScroll: true,
        onError: (errors) => {
          const message = firstValidationError(errors)
          if (message) {
            lastToastError.current = message
            toast.error(message)
          } else {
            toast.error("Could not complete setup. Please try again.")
          }
        },
        onFinish: () => setSaving(false),
      },
    )
  }

  const toggleCause = (id: number) => {
    setSelectedCauses((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const toggleRole = (id: number) => {
    setSelectedRoles((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  const toggleNotification = (key: string) => {
    setNotificationPrefs((prev) => ({ ...prev, [key]: !prev[key] }))
  }

  const showOrgPicker = orgConnection !== "not_now"
  const canSkip = [3, 6, 7, 8, 10].includes(currentStep)
  const isFinalStep = currentStep === totalSteps && canFinish

  const stepMeta = useMemo(() => {
    const map: Record<number, { title: string; subtitle?: string; icon: React.ReactNode }> = {
      1: {
        title: "Welcome to Believe In Unity!",
        subtitle:
          "We're excited you're here! Let's personalize your experience so we can connect you with organizations, events, and opportunities that matter to you. This will only take about a minute.",
        icon: <Sparkles className="h-6 w-6" />,
      },
      2: {
        title: "What brings you here today?",
        subtitle: "Select one — we'll tailor your dashboard and recommendations.",
        icon: <Heart className="h-6 w-6" />,
      },
      3: {
        title: "Let's add your profile picture",
        subtitle: "A photo helps organizations and supporters recognize you.",
        icon: <Camera className="h-6 w-6" />,
      },
      4: {
        title: "When is your birthday?",
        subtitle:
          "We love celebrating our community! Organizations you follow may wish you a happy birthday or send special gifts and offers. We only collect month and day — not your birth year.",
        icon: <Calendar className="h-6 w-6" />,
      },
      5: {
        title: "Where are you located?",
        subtitle: "This helps us recommend nearby organizations, volunteer opportunities, and events.",
        icon: <MapPin className="h-6 w-6" />,
      },
      6: {
        title: "Which causes matter most to you?",
        subtitle: "Select all that apply.",
        icon: <Heart className="h-6 w-6" />,
      },
      7: {
        title: "Which roles describe you?",
        subtitle: "Select all that apply.",
        icon: <Users className="h-6 w-6" />,
      },
      8: {
        title: "Are you connected to an organization?",
        subtitle: "Link your primary organization to unlock personalized benefits.",
        icon: <Building2 className="h-6 w-6" />,
      },
      9: {
        title: "Privacy & Messaging",
        subtitle: "You're in control of who sees your profile and who can reach you.",
        icon: <Shield className="h-6 w-6" />,
      },
      10: {
        title: "Choose the updates you'd like to receive",
        subtitle: "Receive notifications about:",
        icon: <Bell className="h-6 w-6" />,
      },
      11: {
        title: "Choose your appearance",
        subtitle: "Pick a theme that feels right — you can change this anytime in your profile.",
        icon: <Palette className="h-6 w-6" />,
      },
      12: {
        title: "You're All Set!",
        subtitle: "Your profile is complete. Welcome to the Believe In Unity community.",
        icon: <PartyPopper className="h-6 w-6" />,
      },
    }
    return map[currentStep] ?? map[1]
  }, [currentStep])

  return (
    <>
      <PageHead title="Welcome — Set Up Your Profile" />
      <Head title="Supporter Onboarding" />

      <div className="relative min-h-dvh bg-gradient-to-br from-purple-700 via-blue-700 to-indigo-800 dark:from-gray-950 dark:via-purple-950 dark:to-gray-900">
        <div
          className="pointer-events-none absolute inset-0 opacity-20"
          style={{ backgroundImage: "url(/images/believe-hero.png)", backgroundSize: "cover", backgroundPosition: "center" }}
        />
        <div className="pointer-events-none absolute inset-0 bg-purple-950/40 dark:bg-black/50" />

        <div className="relative z-10 mx-auto flex min-h-dvh max-w-3xl flex-col px-4 py-6 sm:px-6 sm:py-10">
          {/* Header */}
          <div className="mb-6 flex items-center justify-between gap-4">
            <Link href="/" className="flex items-center gap-2 text-sm font-medium text-white/90 hover:text-white">
              <img src="/images/logo-white.png" alt="Believe In Unity" className="h-8 w-auto" onError={(e) => { (e.target as HTMLImageElement).style.display = "none" }} />
              <span className="hidden sm:inline">Believe In Unity</span>
            </Link>
            <div className="text-right text-sm text-white/80">
              Step {currentStep} of {totalSteps}
            </div>
          </div>

          <Progress value={progressPercent} className="mb-6 h-2 bg-white/20 [&>div]:bg-gradient-to-r [&>div]:from-amber-300 [&>div]:to-pink-300" />

          {/* Card */}
          <div className="flex flex-1 flex-col overflow-hidden rounded-2xl border border-white/10 bg-white/95 shadow-2xl backdrop-blur-md dark:bg-gray-900/95">
            <div className="border-b border-gray-100 bg-gradient-to-r from-purple-600 to-blue-600 px-5 py-5 sm:px-8 sm:py-6 dark:border-gray-800">
              <div className="flex items-start gap-4">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/20 text-white">
                  {stepMeta.icon}
                </div>
                <div className="min-w-0">
                  <h1 className="text-xl font-bold tracking-tight text-white sm:text-2xl">{stepMeta.title}</h1>
                  {stepMeta.subtitle ? (
                    <p className="mt-2 text-sm leading-relaxed text-white/90 sm:text-base">{stepMeta.subtitle}</p>
                  ) : null}
                </div>
              </div>
            </div>

            <div className="flex flex-1 flex-col px-5 py-6 sm:px-8 sm:py-8">
              <AnimatePresence mode="wait">
                <motion.div
                  key={currentStep}
                  initial={{ opacity: 0, x: 16 }}
                  animate={{ opacity: 1, x: 0 }}
                  exit={{ opacity: 0, x: -16 }}
                  transition={{ duration: 0.25 }}
                  className="flex flex-1 flex-col"
                >
                  {currentStep === 1 && (
                    <div className="flex flex-1 flex-col items-center justify-center py-6 text-center">
                      <div className="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-purple-100 to-blue-100 dark:from-purple-900/40 dark:to-blue-900/40">
                        <Sparkles className="h-10 w-10 text-purple-600 dark:text-purple-300" />
                      </div>
                      <p className="max-w-md text-gray-600 dark:text-gray-300">
                        Hi {user.name?.split(" ")[0] || "there"} — we'll walk you through a few quick questions to build your supporter profile.
                      </p>
                    </div>
                  )}

                  {currentStep === 2 && (
                    <RadioGroup
                      value={purpose}
                      onValueChange={(v) => {
                        setPurpose(v)
                        setStepError(null)
                        setFieldErrors({})
                      }}
                      className="space-y-2"
                    >
                      {purposes.map((p) => (
                        <label
                          key={p.value}
                          className={cn(
                            "flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3.5 transition-all",
                            purpose === p.value
                              ? "border-purple-500 bg-purple-50 ring-2 ring-purple-500/20 dark:border-purple-400 dark:bg-purple-950/40"
                              : fieldErrors.purpose
                                ? "border-red-400 bg-red-50/50 dark:border-red-500 dark:bg-red-950/20"
                                : "border-gray-200 hover:border-purple-300 dark:border-gray-700 dark:hover:border-purple-600",
                          )}
                        >
                          <RadioGroupItem value={p.value} id={`purpose-${p.value}`} />
                          <span className="text-sm font-medium text-gray-900 dark:text-gray-100">{p.label}</span>
                        </label>
                      ))}
                    </RadioGroup>
                  )}

                  {currentStep === 3 && (
                    <div className="flex flex-col items-center gap-6 py-4">
                      <div className="relative">
                        <img
                          src={previewUrl || "/placeholder.svg?height=120&width=120"}
                          alt="Profile preview"
                          className="h-28 w-28 rounded-full border-4 border-purple-200 object-cover shadow-lg dark:border-purple-500/40 sm:h-32 sm:w-32"
                        />
                      </div>
                      <Label htmlFor="onboarding-photo" className="cursor-pointer">
                        <div className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:from-purple-700 hover:to-blue-700">
                          <Upload className="h-4 w-4" />
                          Upload Photo
                        </div>
                        <input
                          id="onboarding-photo"
                          type="file"
                          accept="image/jpeg,image/png,image/gif"
                          className="sr-only"
                          onChange={(e) => {
                            const file = e.target.files?.[0]
                            if (!file) return
                            setImageFile(file)
                            setPreviewUrl(URL.createObjectURL(file))
                          }}
                        />
                      </Label>
                      <p className="text-xs text-gray-500 dark:text-gray-400">JPG, PNG, or GIF · Max 10MB</p>
                    </div>
                  )}

                  {currentStep === 4 && (
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div>
                        <Label className="mb-2 block text-sm font-medium">
                          Birth Month <span className="text-red-500">*</span>
                        </Label>
                        <Select
                          value={birthMonth || undefined}
                          onValueChange={(v) => {
                            setBirthMonth(v)
                            setBirthDay("")
                            setStepError(null)
                            setFieldErrors({})
                          }}
                        >
                          <SelectTrigger className={cn(fieldErrors.birthMonth && "border-red-500 ring-1 ring-red-500")}>
                            <SelectValue placeholder="Select month" />
                          </SelectTrigger>
                          <SelectContent>
                            {MONTHS.map((m) => (
                              <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        {fieldErrors.birthMonth ? (
                          <p className="mt-1.5 text-xs text-red-500">Required</p>
                        ) : null}
                      </div>
                      <div>
                        <Label className="mb-2 block text-sm font-medium">
                          Birth Day <span className="text-red-500">*</span>
                        </Label>
                        <Select
                          value={birthDay || undefined}
                          onValueChange={(v) => {
                            setBirthDay(v)
                            setStepError(null)
                            setFieldErrors({})
                          }}
                          disabled={!birthMonth}
                        >
                          <SelectTrigger className={cn(fieldErrors.birthDay && "border-red-500 ring-1 ring-red-500")}>
                            <SelectValue placeholder="Select day" />
                          </SelectTrigger>
                          <SelectContent>
                            {dayOptions.map((d) => (
                              <SelectItem key={d} value={d}>{parseInt(d, 10)}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        {fieldErrors.birthDay ? (
                          <p className="mt-1.5 text-xs text-red-500">Required</p>
                        ) : null}
                      </div>
                    </div>
                  )}

                  {currentStep === 5 && (
                    <div className="grid gap-4 sm:grid-cols-2">
                      <div className="sm:col-span-2">
                        <Label htmlFor="city">
                          City <span className="text-red-500">*</span>
                        </Label>
                        <Input
                          id="city"
                          value={city}
                          onChange={(e) => {
                            setCity(e.target.value)
                            setStepError(null)
                            setFieldErrors({})
                          }}
                          className={cn("mt-1.5", fieldErrors.city && "border-red-500 ring-1 ring-red-500")}
                          placeholder="Your city"
                        />
                        {fieldErrors.city ? <p className="mt-1.5 text-xs text-red-500">Required</p> : null}
                      </div>
                      <div>
                        <Label htmlFor="state">
                          State <span className="text-red-500">*</span>
                        </Label>
                        <Input
                          id="state"
                          value={state}
                          onChange={(e) => {
                            setState(e.target.value.toUpperCase())
                            setStepError(null)
                            setFieldErrors({})
                          }}
                          className={cn("mt-1.5", fieldErrors.state && "border-red-500 ring-1 ring-red-500")}
                          placeholder="CA"
                          maxLength={2}
                        />
                        {fieldErrors.state ? (
                          <p className="mt-1.5 text-xs text-red-500">Enter a 2-letter state code</p>
                        ) : null}
                      </div>
                      <div>
                        <Label htmlFor="zip">Zip Code</Label>
                        <Input id="zip" value={zipcode} onChange={(e) => setZipcode(e.target.value)} className="mt-1.5" placeholder="Optional" maxLength={10} />
                      </div>
                    </div>
                  )}

                  {currentStep === 6 && (
                    <div className="flex flex-wrap gap-2">
                      {causes.map((c) => {
                        const selected = selectedCauses.includes(c.id)
                        return (
                          <button
                            key={c.id}
                            type="button"
                            onClick={() => toggleCause(c.id)}
                            className={cn(
                              "inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-all",
                              selected
                                ? "border-transparent bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md"
                                : "border-gray-200 bg-white text-gray-700 hover:border-purple-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200",
                            )}
                          >
                            {selected ? <Check className="h-3.5 w-3.5" /> : null}
                            {c.name}
                          </button>
                        )
                      })}
                    </div>
                  )}

                  {currentStep === 7 && (
                    <div className="flex flex-wrap gap-2">
                      {onboardingRoles.map((r) => {
                        const selected = selectedRoles.includes(r.id)
                        return (
                          <button
                            key={r.id}
                            type="button"
                            onClick={() => toggleRole(r.id)}
                            className={cn(
                              "inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-all",
                              selected
                                ? "border-transparent bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md"
                                : "border-gray-200 bg-white text-gray-700 hover:border-purple-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200",
                            )}
                          >
                            {selected ? <Check className="h-3.5 w-3.5" /> : null}
                            {r.name}
                          </button>
                        )
                      })}
                    </div>
                  )}

                  {currentStep === 8 && (
                    <div className="space-y-5">
                      <RadioGroup value={orgConnection} onValueChange={setOrgConnection} className="space-y-2">
                        {ORG_CONNECTION_OPTIONS.map((opt) => (
                          <label
                            key={opt.value}
                            className={cn(
                              "flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 transition-all",
                              orgConnection === opt.value
                                ? "border-purple-500 bg-purple-50 dark:border-purple-400 dark:bg-purple-950/40"
                                : "border-gray-200 dark:border-gray-700",
                            )}
                          >
                            <RadioGroupItem value={opt.value} id={`org-${opt.value}`} />
                            <span className="text-sm font-medium">{opt.label}</span>
                          </label>
                        ))}
                      </RadioGroup>

                      {showOrgPicker && (
                        <div className="space-y-4 rounded-xl border border-dashed border-purple-300/60 bg-purple-50/50 p-4 dark:border-purple-500/30 dark:bg-purple-950/20">
                          <div>
                            <Label className="text-sm font-medium">Primary Organization</Label>
                            {primaryOrg ? (
                              <div className="mt-2 flex items-center gap-3 rounded-lg border bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                {primaryOrg.image ? (
                                  <img src={primaryOrg.image} alt="" className="h-10 w-10 rounded-lg object-cover" />
                                ) : (
                                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-600 text-sm font-bold text-white">
                                    {primaryOrg.name.slice(0, 1)}
                                  </div>
                                )}
                                <span className="font-medium">{primaryOrg.name}</span>
                              </div>
                            ) : (
                              <div className="mt-2">
                                <ProfileOrganizationPicker
                                  variant="primary"
                                  reloadRoute="user.onboarding"
                                  excludeIds={secondaryOrgIds}
                                  primaryValue={primaryOrgId || "__none__"}
                                  selectedOrganization={primaryOrg ?? undefined}
                                  onPrimaryChange={(value, org) => {
                                    if (value === "__none__") return
                                    if (org) {
                                      setPrimaryOrg(org)
                                      setOrgRows((rows) => (rows.some((r) => r.id === org.id) ? rows : [...rows, org]))
                                    }
                                    setPrimaryOrgId(value)
                                  }}
                                  placeholder="Search organizations…"
                                />
                              </div>
                            )}
                          </div>

                          <div>
                            <Label className="text-sm font-medium">Add Secondary Organizations (optional)</Label>
                            <div className="mt-2">
                              <ProfileOrganizationPicker
                                variant="secondary-add"
                                reloadRoute="user.onboarding"
                                excludeIds={[
                                  ...secondaryOrgIds,
                                  ...(primaryOrgId ? [Number(primaryOrgId)] : []),
                                ]}
                                onSecondaryAdd={(org) => {
                                  setOrgRows((rows) => (rows.some((r) => r.id === org.id) ? rows : [...rows, org]))
                                  setSecondaryOrgIds((ids) => (ids.includes(org.id) ? ids : [...ids, org.id]))
                                }}
                                placeholder="Add another organization…"
                              />
                            </div>
                            {secondaryOrgIds.length > 0 && (
                              <div className="mt-3 flex flex-wrap gap-2">
                                {secondaryOrgIds.map((id) => {
                                  const org = orgRows.find((r) => r.id === id)
                                  return (
                                    <span
                                      key={id}
                                      className="inline-flex items-center gap-1 rounded-full bg-purple-100 px-3 py-1 text-xs font-medium text-purple-800 dark:bg-purple-900/50 dark:text-purple-200"
                                    >
                                      {org?.name ?? `Org #${id}`}
                                      <button
                                        type="button"
                                        className="ml-1 text-purple-600 hover:text-purple-900"
                                        onClick={() => setSecondaryOrgIds((ids) => ids.filter((x) => x !== id))}
                                        aria-label="Remove"
                                      >
                                        ×
                                      </button>
                                    </span>
                                  )
                                })}
                              </div>
                            )}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {currentStep === 9 && (
                    <div className="space-y-8">
                      <div>
                        <Label className="mb-3 block text-sm font-semibold">Account Visibility</Label>
                        <RadioGroup value={accountVisibility} onValueChange={(v) => setAccountVisibility(v as "public" | "private")} className="space-y-2">
                          {[
                            ["public", "Public Account", "Anyone can follow you and see your public content."],
                            ["private", "Private Account", "You approve follow requests."],
                          ].map(([value, title, help]) => (
                            <label key={value} className="flex cursor-pointer gap-3 rounded-xl border px-4 py-3 dark:border-gray-700">
                              <RadioGroupItem value={value} className="mt-1" />
                              <div>
                                <div className="font-medium">{title}</div>
                                <p className="text-sm text-gray-500 dark:text-gray-400">{help}</p>
                              </div>
                            </label>
                          ))}
                        </RadioGroup>
                      </div>
                      <div>
                        <Label className="mb-3 block text-sm font-semibold">Who Can Message You?</Label>
                        <RadioGroup
                          value={messagingPolicy}
                          onValueChange={(v) =>
                            setMessagingPolicy(v as typeof messagingPolicy)
                          }
                          className="space-y-2"
                        >
                          {(
                            [
                              ["everyone", "Everyone"],
                              ["followers_only", "Followers Only"],
                              ["organizations_i_follow", "Organizations I Follow"],
                              ["no_one", "No One"],
                            ] as const
                          ).map(([value, title]) => (
                            <label key={value} className="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 dark:border-gray-700">
                              <RadioGroupItem value={value} />
                              <span className="text-sm font-medium">{title}</span>
                            </label>
                          ))}
                        </RadioGroup>
                      </div>
                    </div>
                  )}

                  {currentStep === 10 && (
                    <div className="space-y-3">
                      {NOTIFICATION_OPTIONS.map((opt) => (
                        <label
                          key={opt.key}
                          className="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 dark:border-gray-700"
                        >
                          <Checkbox
                            checked={notificationPrefs[opt.key] !== false}
                            onCheckedChange={() => toggleNotification(opt.key)}
                          />
                          <span className="text-sm font-medium">{opt.label}</span>
                        </label>
                      ))}
                    </div>
                  )}

                  {currentStep === 11 && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                      {(
                        [
                          ["system", Monitor, "System Default"],
                          ["light", Sun, "Light"],
                          ["dark", Moon, "Dark"],
                        ] as const
                      ).map(([mode, Icon, label]) => (
                        <button
                          key={mode}
                          type="button"
                          onClick={() => setPreferredTheme(mode)}
                          className={cn(
                            "flex flex-col items-center gap-2 rounded-xl border px-4 py-5 transition-all",
                            preferredTheme === mode
                              ? "border-transparent bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-lg"
                              : "border-gray-200 hover:border-purple-400 dark:border-gray-700",
                          )}
                        >
                          <Icon className="h-6 w-6" />
                          <span className="text-sm font-medium">{label}</span>
                        </button>
                      ))}
                    </div>
                  )}

                  {currentStep === 12 && (
                    <div className="flex flex-1 flex-col items-center justify-center py-4 text-center">
                      <div className="mb-4 text-5xl">🎉</div>
                      <p className="mb-6 max-w-sm text-gray-600 dark:text-gray-300">
                        You've earned <span className="font-semibold text-purple-600 dark:text-purple-400">+2 BRP</span> (Believe Reward Points) for completing your profile!
                      </p>
                      <div className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-200">
                        <Gift className="h-4 w-4" />
                        Profile completion reward
                      </div>
                    </div>
                  )}
                </motion.div>
              </AnimatePresence>

              {stepError ? (
                <Alert variant="destructive" className="mt-4 border-red-300 bg-red-50 text-red-800 dark:border-red-500/40 dark:bg-red-950/40 dark:text-red-200">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription>{stepError}</AlertDescription>
                </Alert>
              ) : null}

              {/* Footer nav */}
              <div className="mt-auto flex flex-col-reverse gap-3 border-t border-gray-100 pt-6 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                <div className="flex gap-2">
                  {currentStep > 1 && !isFinalStep ? (
                    <Button type="button" variant="outline" onClick={handleBack} disabled={saving} className="gap-1">
                      <ArrowLeft className="h-4 w-4" />
                      Back
                    </Button>
                  ) : null}
                  {canSkip && !isFinalStep ? (
                    <Button type="button" variant="ghost" onClick={handleSkip} disabled={saving} className="text-gray-500">
                      Skip for now
                    </Button>
                  ) : null}
                </div>

                {isFinalStep ? (
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <Button
                      type="button"
                      variant="outline"
                      className="gap-1"
                      disabled={saving}
                      onClick={() => finish("organizations")}
                    >
                      Explore Organizations
                      <ChevronRight className="h-4 w-4" />
                    </Button>
                    <Button
                      type="button"
                      className="gap-1 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700"
                      disabled={saving}
                      onClick={() => finish("dashboard")}
                    >
                      {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                      Go to Dashboard
                      <ArrowRight className="h-4 w-4" />
                    </Button>
                  </div>
                ) : (
                  <Button
                    type="button"
                    className="gap-1 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 sm:min-w-[140px]"
                    onClick={handleNext}
                    disabled={saving}
                  >
                    {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                    {currentStep === 1 ? "Continue" : "Next"}
                    <ArrowRight className="h-4 w-4" />
                  </Button>
                )}
              </div>
            </div>
          </div>

          <p className="mt-4 text-center text-xs text-white/60">
            Your answers save automatically as you go. You can update everything later in Profile Settings.
          </p>
        </div>
      </div>
    </>
  )
}

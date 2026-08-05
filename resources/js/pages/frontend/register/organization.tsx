"use client"

import { useEffect, useState } from "react"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  Building2,
  CheckCircle,
  AlertCircle,
  Search,
  ShieldCheck,
  Users,
  Eye,
  EyeOff,
  Gift,
  DollarSign,
  LoaderCircle,
} from "lucide-react"
import { Link, router, usePage } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { PageHead } from "@/components/frontend/PageHead"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Checkbox } from "@/components/frontend/ui/checkbox"
import { Alert, AlertDescription } from "@/components/frontend/ui/alert"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"

const MEMBERSHIP_JOIN_METHODS = [
  { value: "open_enrollment", label: "Open Enrollment" },
  { value: "request_to_join", label: "Request to Join" },
  { value: "invitation_only", label: "Invitation Only" },
] as const

type CommunityOrgTypeOption = { id: number; slug: string; name: string; label: string }

interface EINLookupResponse {
  success: boolean
  data?: Record<string, string | null>
  message?: string
  errors?: Record<string, string[]>
}

interface RegistrationResponse {
  success: boolean
  message?: string
  errors?: Record<string, string[]>
}

interface OrganizationRegisterPageProps {
  seo?: { title: string; description?: string }
  referralCode?: string
  ein?: string
  inviteToken?: string
  organizationName?: string
  csrf_token?: string
  communityOrganizationTypes?: CommunityOrgTypeOption[]
}

export default function OrganizationRegisterPage({
  seo,
  referralCode = "",
  ein: prefilledEin,
  inviteToken,
  organizationName,
  communityOrganizationTypes = [],
}: OrganizationRegisterPageProps) {
  const { csrf_token } = usePage<{ csrf_token?: string }>().props
  const [step, setStep] = useState(1)
  const [isLoading, setIsLoading] = useState(false)
  const [einError, setEinError] = useState("")
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [hasEinChoice, setHasEinChoice] = useState<"yes" | "no" | null>(
    prefilledEin ? "yes" : null
  )
  const [einDigits, setEinDigits] = useState(
    prefilledEin ? prefilledEin.replace(/\D/g, "").slice(0, 9) : ""
  )
  const [lookupStatus, setLookupStatus] = useState<"idle" | "loading" | "success" | "error">("idle")
  const [turnstileToken, setTurnstileToken] = useState("")
  const { turnstileBlocksSubmit } = useTurnstileGate(turnstileToken)

  const [form, setForm] = useState({
    has_ein: true,
    ein: "",
    community_organization_type_id: "",
    community_organization_type_other: "",
    grandfather_name: "",
    grandmother_name: "",
    grandfather_birth_year: "",
    grandfather_death_year: "",
    grandmother_birth_year: "",
    grandmother_death_year: "",
    grandfather_photo: null as File | null,
    grandmother_photo: null as File | null,
    has_members: null as boolean | null,
    memberships_enabled: false,
    membership_type: "free" as "free" | "paid",
    membership_name: "",
    join_method: "request_to_join" as (typeof MEMBERSHIP_JOIN_METHODS)[number]["value"],
    name: organizationName || "",
    street: "",
    city: "",
    state: "",
    zip: "",
    contact_name: "",
    email: "",
    phone: "",
    password: "",
    password_confirmation: "",
    agree_to_terms: false,
    referralCode: referralCode || "",
    invite_token: inviteToken || "",
    ico: "",
    classification: "",
    ruling: "",
    deductibility: "",
    organization: "",
    status: "",
    tax_period: "",
    filing_req: "",
    ntee_code: "",
    has_edited_irs_data: false,
  })

  const selectedOrgType = communityOrganizationTypes.find(
    (t) => String(t.id) === String(form.community_organization_type_id)
  )
  const isFamilyReunion = selectedOrgType?.slug === "family_reunion"
  const isOtherOrgType = selectedOrgType?.slug === "other"

  useEffect(() => {
    if (referralCode) {
      setForm((prev) => ({ ...prev, referralCode }))
    }
  }, [referralCode])

  useEffect(() => {
    if (inviteToken) {
      setForm((prev) => ({ ...prev, invite_token: inviteToken }))
    }
  }, [inviteToken])

  const getCsrfToken = () =>
    csrf_token ||
    document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
    ""

  const formatEIN = (value: string) => {
    const digits = value.replace(/\D/g, "").slice(0, 9)
    if (digits.length <= 2) return digits
    return `${digits.slice(0, 2)}-${digits.slice(2)}`
  }

  const setField = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }))
    if (errors[key]) {
      setErrors((prev) => {
        const next = { ...prev }
        delete next[key]
        return next
      })
    }
  }

  const validateStep = (n: number): boolean => {
    if (n === 1) {
      if (hasEinChoice === "no") return true
      if (hasEinChoice === "yes") return einDigits.length === 9 && lookupStatus === "success"
      return false
    }
    if (n === 2) {
      if (form.has_members === null) return false
      if (form.has_members && form.memberships_enabled) {
        return (
          (form.membership_type === "free" || form.membership_type === "paid") &&
          form.membership_name.trim().length > 0 &&
          MEMBERSHIP_JOIN_METHODS.some((method) => method.value === form.join_method)
        )
      }
      return true
    }
    if (n === 3) {
      const typeOk =
        !!form.community_organization_type_id &&
        (!isOtherOrgType || form.community_organization_type_other.trim().length > 0)
      const familyOk =
        !isFamilyReunion ||
        (form.grandfather_name.trim().length > 0 && form.grandmother_name.trim().length > 0)

      return !!(
        typeOk &&
        familyOk &&
        form.name.trim() &&
        form.street.trim() &&
        form.city.trim() &&
        form.state.trim() &&
        form.zip.trim() &&
        form.contact_name.trim() &&
        form.email.trim() &&
        form.password.length >= 8 &&
        form.password === form.password_confirmation &&
        form.agree_to_terms
      )
    }
    return false
  }

  const handleEINLookup = async () => {
    if (einDigits.length !== 9) {
      setEinError("Please enter a valid 9-digit EIN")
      return
    }
    setIsLoading(true)
    setEinError("")
    setLookupStatus("loading")
    try {
      const response = await fetch("/register/organization/lookup-ein", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN": getCsrfToken(),
        },
        body: JSON.stringify({ ein: einDigits }),
      })
      const data: EINLookupResponse = await response.json()

      if (response.status === 422) {
        setEinError(data.message || data.errors?.ein?.[0] || "This organization is already registered")
        setLookupStatus("error")
        return
      }

      if (data.success && data.data) {
        const irs = data.data
        setForm((prev) => ({
          ...prev,
          has_ein: true,
          ein: einDigits,
          name: (irs.name as string) || prev.name,
          street: (irs.street as string) || "",
          city: (irs.city as string) || "",
          state: (irs.state as string) || "",
          zip: (irs.zip as string) || "",
          ico: (irs.ico as string) || "",
          classification: (irs.classification as string) || "",
          ruling: (irs.ruling as string) || "",
          deductibility: (irs.deductibility as string) || "",
          organization: (irs.organization as string) || "",
          status: (irs.status as string) || "",
          tax_period: (irs.tax_period as string) || "",
          filing_req: (irs.filing_req as string) || "",
          ntee_code: (irs.ntee_code as string) || "",
          has_edited_irs_data: false,
        }))
        setLookupStatus("success")
        setStep(2)
        return
      }

      // Not in IRS file — continue with manual profile
      setForm((prev) => ({
        ...prev,
        has_ein: true,
        ein: einDigits,
        has_edited_irs_data: true,
      }))
      setLookupStatus("success")
      setStep(2)
    } catch {
      setEinError("Error looking up EIN. Please try again.")
      setLookupStatus("error")
    } finally {
      setIsLoading(false)
    }
  }

  const continueWithoutEin = () => {
    setForm((prev) => ({
      ...prev,
      has_ein: false,
      ein: "",
      has_edited_irs_data: true,
    }))
    setLookupStatus("idle")
    setEinError("")
    setStep(2)
  }

  const submitRegistration = async () => {
    if (!validateStep(3)) return
    setIsLoading(true)
    setErrors({})

    const payload = new FormData()
    payload.append("has_ein", form.has_ein ? "1" : "0")
    if (form.has_ein && form.ein) payload.append("ein", form.ein)
    payload.append("has_members", form.has_members ? "1" : "0")
    payload.append("memberships_enabled", form.memberships_enabled ? "1" : "0")
    if (form.memberships_enabled) {
      payload.append("membership_type", form.membership_type)
      payload.append("membership_name", form.membership_name.trim() || form.name.trim())
      payload.append("join_method", form.join_method)
    }
    payload.append("name", form.name)
    payload.append("street", form.street)
    payload.append("city", form.city)
    payload.append("state", form.state)
    payload.append("zip", form.zip)
    payload.append("contact_name", form.contact_name)
    payload.append("email", form.email)
    if (form.phone) payload.append("phone", form.phone)
    payload.append("password", form.password)
    payload.append("password_confirmation", form.password_confirmation)
    payload.append("agree_to_terms", form.agree_to_terms ? "1" : "0")
    payload.append("attestation_officer_on_990", "1")
    payload.append("legal_name_confirmation", form.name)
    payload.append("has_edited_irs_data", form.has_edited_irs_data ? "1" : "0")
    payload.append("community_organization_type_id", form.community_organization_type_id)
    if (isOtherOrgType && form.community_organization_type_other.trim()) {
      payload.append("community_organization_type_other", form.community_organization_type_other.trim())
    }
    if (isFamilyReunion) {
      payload.append("grandfather_name", form.grandfather_name.trim())
      payload.append("grandmother_name", form.grandmother_name.trim())
      if (form.grandfather_birth_year) payload.append("grandfather_birth_year", form.grandfather_birth_year)
      if (form.grandfather_death_year) payload.append("grandfather_death_year", form.grandfather_death_year)
      if (form.grandmother_birth_year) payload.append("grandmother_birth_year", form.grandmother_birth_year)
      if (form.grandmother_death_year) payload.append("grandmother_death_year", form.grandmother_death_year)
      if (form.grandfather_photo) payload.append("grandfather_photo", form.grandfather_photo)
      if (form.grandmother_photo) payload.append("grandmother_photo", form.grandmother_photo)
    }
    if (turnstileToken) {
      payload.append("cf_turnstile_response", turnstileToken)
    }
    ;[
      "ico",
      "classification",
      "ruling",
      "deductibility",
      "organization",
      "status",
      "tax_period",
      "filing_req",
      "ntee_code",
      "referralCode",
      "invite_token",
    ].forEach((key) => {
      const val = form[key as keyof typeof form]
      if (typeof val === "string" && val) payload.append(key, val)
    })

    try {
      const response = await fetch("/register/organization", {
        method: "POST",
        headers: {
          Accept: "application/json",
          "X-CSRF-TOKEN": getCsrfToken(),
        },
        body: payload,
      })
      const data: RegistrationResponse = await response.json()
      if (data.success) {
        router.visit(route("dashboard"))
        return
      }
      if (data.errors) {
        const formatted: Record<string, string> = {}
        Object.entries(data.errors).forEach(([key, value]) => {
          formatted[key] = Array.isArray(value) ? value[0] : String(value)
        })
        setErrors(formatted)
      } else if (data.message) {
        setErrors({ general: data.message })
      }
    } catch {
      setErrors({ general: "Registration failed. Please try again." })
    } finally {
      setIsLoading(false)
    }
  }

  const stepLabel =
    step === 1
      ? "Organization Verification"
      : step === 2
        ? "Membership Information"
        : "Organization Profile"

  // IRS-verified org details are locked; contact/account fields stay editable
  const irsFieldsLocked = form.has_ein && !form.has_edited_irs_data
  const irsInputClass = irsFieldsLocked
    ? "mt-1.5 h-11 cursor-not-allowed bg-muted/60 text-muted-foreground"
    : "mt-1.5 h-11"

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Register Your Organization"} description={seo?.description} />
      <div className="relative min-h-screen overflow-hidden bg-gradient-to-br from-purple-600 via-blue-600 to-purple-700">
        <div
          className="absolute inset-0 bg-cover bg-center opacity-20"
          style={{ backgroundImage: "url(/images/believe-hero.png)" }}
        />
        <div className="absolute inset-0 bg-purple-900/70" />

        <div className="relative z-10 container mx-auto px-4 py-10 sm:py-14">
          <div className="mx-auto max-w-3xl">
            <Link
              href={referralCode ? `/register?ref=${referralCode}` : "/register"}
              className="mb-6 inline-flex cursor-pointer items-center text-white/90 transition hover:text-white"
            >
              <ArrowLeft className="mr-2 h-4 w-4" />
              Back to registration options
            </Link>

            <div className="mb-8">
              <div className="flex items-center justify-center gap-2 sm:gap-4">
                {[1, 2, 3].map((n) => (
                  <div key={n} className="flex items-center">
                    <button
                      type="button"
                      onClick={() => {
                        if (n < step || validateStep(step)) setStep(n)
                      }}
                      className={`flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border-2 text-sm font-semibold sm:h-12 sm:w-12 ${
                        step >= n
                          ? "border-emerald-300 bg-gradient-to-r from-emerald-500 to-green-600 text-white shadow-lg"
                          : "border-white/20 bg-white/10 text-white/70"
                      }`}
                    >
                      {step > n ? <CheckCircle className="h-5 w-5" /> : n}
                    </button>
                    {n < 3 && (
                      <div
                        className={`mx-1 h-1 w-8 rounded-full sm:mx-2 sm:w-14 ${
                          step > n ? "bg-emerald-500" : "bg-white/20"
                        }`}
                      />
                    )}
                  </div>
                ))}
              </div>
              <p className="mt-3 text-center text-sm text-white/90">
                Step {step} of 3: {stepLabel}
              </p>
            </div>

            <Card className="overflow-hidden border-0 bg-white/95 shadow-2xl backdrop-blur-md dark:bg-gray-900/95">
              <div className="bg-[#16a34a] p-5 text-center">
                <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20">
                  {step === 2 ? (
                    <Users className="h-7 w-7 text-white" />
                  ) : (
                    <Building2 className="h-7 w-7 text-white" />
                  )}
                </div>
                <CardTitle className="text-xl font-bold text-white sm:text-2xl">
                  {step === 1 && "Organization Verification"}
                  {step === 2 && "Membership Information"}
                  {step === 3 && "Tell us about your organization"}
                </CardTitle>
                <CardDescription className="mt-1 text-sm text-white/90">
                  {step === 1 &&
                    "This is when we ask about EIN — first, so we can verify your organization (if applicable)."}
                  {step === 2 &&
                    "This is when we ask about membership so we can set up your membership features (if applicable)."}
                  {step === 3 &&
                    "Collect your organization details including address, contact person, and email."}
                </CardDescription>
              </div>

              <CardContent className="space-y-6 p-6 sm:p-8">
                {errors.general && (
                  <Alert className="border-red-200 bg-red-50">
                    <AlertCircle className="h-4 w-4 text-red-600" />
                    <AlertDescription className="text-red-700">{errors.general}</AlertDescription>
                  </Alert>
                )}

                {/* Step 1 — EIN */}
                {step === 1 && (
                  <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="space-y-5"
                  >
                    <div>
                      <p className="mb-3 text-sm font-semibold text-foreground">
                        Does your organization have an EIN?
                      </p>
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {[
                          { value: "yes" as const, label: "Yes, we have an EIN" },
                          { value: "no" as const, label: "No, we do not have an EIN" },
                        ].map((opt) => (
                          <button
                            key={opt.value}
                            type="button"
                            onClick={() => {
                              setHasEinChoice(opt.value)
                              setEinError("")
                              if (opt.value === "no") setLookupStatus("idle")
                            }}
                            className={`flex w-full cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-left transition ${
                              hasEinChoice === opt.value
                                ? "border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40"
                                : "border-border hover:border-emerald-300"
                            }`}
                          >
                            <span
                              className={`h-4 w-4 shrink-0 rounded-full border-2 ${
                                hasEinChoice === opt.value
                                  ? "border-emerald-600 bg-emerald-600"
                                  : "border-muted-foreground/40"
                              }`}
                            />
                            <span className="text-sm font-medium">{opt.label}</span>
                          </button>
                        ))}
                      </div>
                    </div>

                    {hasEinChoice === "yes" && (
                      <div>
                        <Label htmlFor="ein">EIN (9 digits) *</Label>
                        <div className="relative mt-2">
                          <Input
                            id="ein"
                            value={formatEIN(einDigits)}
                            onChange={(e) => {
                              setEinDigits(e.target.value.replace(/\D/g, "").slice(0, 9))
                              setLookupStatus("idle")
                              setEinError("")
                            }}
                            placeholder="XX-XXXXXXX"
                            className="h-12 text-center font-mono text-lg tracking-wider"
                            maxLength={10}
                          />
                          <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        </div>
                        {einError && <p className="mt-2 text-sm text-red-600">{einError}</p>}
                      </div>
                    )}

                    <div className="flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/40">
                      <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                      <p className="text-sm text-emerald-900 dark:text-emerald-100">
                        If you have an EIN, we will verify it with the IRS to confirm your organization.
                      </p>
                    </div>

                    {hasEinChoice === "yes" ? (
                      <Button
                        type="button"
                        disabled={isLoading || einDigits.length !== 9}
                        onClick={handleEINLookup}
                        className="h-12 w-full cursor-pointer bg-gradient-to-r from-emerald-600 to-green-700 text-base font-bold text-white disabled:cursor-not-allowed"
                      >
                        {isLoading ? (
                          <span className="inline-flex items-center justify-center gap-2">
                            <LoaderCircle className="h-5 w-5 animate-spin" />
                            Verifying with IRS…
                          </span>
                        ) : (
                          "Verify & Continue"
                        )}
                      </Button>
                    ) : hasEinChoice === "no" ? (
                      <Button
                        type="button"
                        onClick={continueWithoutEin}
                        className="h-12 w-full cursor-pointer bg-gradient-to-r from-emerald-600 to-green-700 text-base font-bold text-white"
                      >
                        Continue →
                      </Button>
                    ) : null}
                  </motion.div>
                )}

                {/* Step 2 — Membership */}
                {step === 2 && (
                  <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="space-y-5"
                  >
                    <div>
                      <p className="mb-3 text-sm font-semibold">Does your organization have members?</p>
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {[
                          { value: true, label: "Yes, we have members" },
                          { value: false, label: "No, we do not have members" },
                        ].map((opt) => (
                          <button
                            key={String(opt.value)}
                            type="button"
                            onClick={() => {
                              setField("has_members", opt.value)
                              if (!opt.value) {
                                setField("memberships_enabled", false)
                              }
                            }}
                            className={`flex w-full cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-left transition ${
                              form.has_members === opt.value
                                ? "border-emerald-500 bg-emerald-50 dark:bg-emerald-950/40"
                                : "border-border hover:border-emerald-300"
                            }`}
                          >
                            <span
                              className={`h-4 w-4 shrink-0 rounded-full border-2 ${
                                form.has_members === opt.value
                                  ? "border-emerald-600 bg-emerald-600"
                                  : "border-muted-foreground/40"
                              }`}
                            />
                            <span className="text-sm font-medium">{opt.label}</span>
                          </button>
                        ))}
                      </div>
                    </div>

                    {form.has_members && (
                      <>
                        <div className="flex items-center justify-between rounded-xl border border-border px-4 py-3">
                          <div>
                            <p className="text-sm font-semibold">Enable Memberships</p>
                            <p className="text-xs text-muted-foreground">
                              Turn on membership tools for your organization
                            </p>
                          </div>
                          <button
                            type="button"
                            role="switch"
                            aria-checked={form.memberships_enabled}
                            onClick={() => {
                              const next = !form.memberships_enabled
                              setField("memberships_enabled", next)
                              if (next && !form.membership_name.trim() && form.name.trim()) {
                                setField("membership_name", form.name.trim())
                              }
                            }}
                            className={`relative h-7 w-12 cursor-pointer rounded-full transition ${
                              form.memberships_enabled ? "bg-emerald-600" : "bg-muted"
                            }`}
                          >
                            <span
                              className={`absolute top-0.5 h-6 w-6 rounded-full bg-white shadow transition ${
                                form.memberships_enabled ? "left-5" : "left-0.5"
                              }`}
                            />
                          </button>
                        </div>

                        {form.memberships_enabled && (
                          <div>
                            <p className="mb-2 text-sm font-semibold">Membership Type</p>
                            <div className="grid grid-cols-2 gap-3">
                              {(
                                [
                                  { type: "free" as const, label: "Free", Icon: Gift },
                                  { type: "paid" as const, label: "Paid", Icon: DollarSign },
                                ] as const
                              ).map(({ type, label, Icon }) => (
                                <button
                                  key={type}
                                  type="button"
                                  onClick={() => setField("membership_type", type)}
                                  className={`flex cursor-pointer flex-col items-center gap-2 rounded-xl border px-4 py-4 text-sm font-semibold transition ${
                                    form.membership_type === type
                                      ? "border-emerald-500 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40"
                                      : "border-border hover:border-emerald-300"
                                  }`}
                                >
                                  <span
                                    className={`flex h-10 w-10 items-center justify-center rounded-full ${
                                      form.membership_type === type
                                        ? "bg-emerald-600 text-white"
                                        : "bg-muted text-muted-foreground"
                                    }`}
                                  >
                                    <Icon className="h-5 w-5" />
                                  </span>
                                  {label}
                                </button>
                              ))}
                            </div>
                          </div>
                        )}

                        {form.memberships_enabled && (
                          <>
                            <div>
                              <Label htmlFor="membership_name" className="text-sm font-semibold">
                                Membership Name *
                              </Label>
                              <Input
                                id="membership_name"
                                value={form.membership_name}
                                onChange={(e) => setField("membership_name", e.target.value)}
                                placeholder="e.g. Supporter Circle"
                                className="mt-2"
                              />
                              {errors.membership_name && (
                                <p className="mt-1 text-sm text-red-600">{errors.membership_name}</p>
                              )}
                            </div>

                            <div>
                              <p className="mb-2 text-sm font-semibold">Join Method *</p>
                              <Select
                                value={form.join_method}
                                onValueChange={(value) =>
                                  setField(
                                    "join_method",
                                    value as (typeof MEMBERSHIP_JOIN_METHODS)[number]["value"],
                                  )
                                }
                              >
                                <SelectTrigger>
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
                              {errors.join_method && (
                                <p className="mt-1 text-sm text-red-600">{errors.join_method}</p>
                              )}
                            </div>
                          </>
                        )}
                      </>
                    )}

                    <div className="flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-800 dark:bg-emerald-950/40">
                      <Users className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                      <p className="text-sm text-emerald-900 dark:text-emerald-100">
                        We'll configure your membership tools based on your selection.
                      </p>
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                      <Button type="button" variant="outline" className="h-11 cursor-pointer" onClick={() => setStep(1)}>
                        Back
                      </Button>
                      <Button
                        type="button"
                        disabled={!validateStep(2)}
                        onClick={() => setStep(3)}
                        className="h-11 cursor-pointer bg-gradient-to-r from-emerald-600 to-green-700 font-bold text-white"
                      >
                        Continue →
                      </Button>
                    </div>
                  </motion.div>
                )}

                {/* Step 3 — Profile + account */}
                {step === 3 && (
                  <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="space-y-5"
                  >
                    {irsFieldsLocked && (
                      <Alert className="border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/50">
                        <CheckCircle className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        <AlertDescription className="text-emerald-900 dark:text-emerald-100">
                          EIN verified with the IRS. Organization name and address are locked from IRS
                          records — complete your contact and account details below.
                        </AlertDescription>
                      </Alert>
                    )}

                    <div>
                      <Label className="text-sm font-semibold">What kind of organization are you? *</Label>
                      <p className="mt-1 text-xs text-muted-foreground">
                        This helps us show the right tools. Family Reunion unlocks founding couple, branches, and family tree.
                      </p>
                      <div className="mt-3">
                        <Select
                          value={form.community_organization_type_id || undefined}
                          onValueChange={(value) => {
                            setField("community_organization_type_id", value)
                            const next = communityOrganizationTypes.find((t) => String(t.id) === value)
                            if (next?.slug !== "other") {
                              setField("community_organization_type_other", "")
                            }
                          }}
                        >
                          <SelectTrigger className="h-11">
                            <SelectValue placeholder="Select organization type" />
                          </SelectTrigger>
                          <SelectContent>
                            {communityOrganizationTypes.map((type) => (
                              <SelectItem key={type.id} value={String(type.id)}>
                                {type.label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                      {errors.community_organization_type_id && (
                        <p className="mt-1 text-sm text-red-600">{errors.community_organization_type_id}</p>
                      )}
                    </div>

                    {isOtherOrgType && (
                      <div>
                        <Label htmlFor="community_organization_type_other">Please describe your organization type *</Label>
                        <Input
                          id="community_organization_type_other"
                          className="mt-1.5 h-11"
                          value={form.community_organization_type_other}
                          onChange={(e) => setField("community_organization_type_other", e.target.value)}
                          placeholder="e.g. Neighborhood association"
                        />
                        {errors.community_organization_type_other && (
                          <p className="mt-1 text-sm text-red-600">{errors.community_organization_type_other}</p>
                        )}
                      </div>
                    )}

                    {isFamilyReunion && (
                      <div className="space-y-4 rounded-xl border border-blue-200 bg-blue-50/60 p-4 dark:border-blue-800 dark:bg-blue-950/30">
                        <div>
                          <p className="text-sm font-semibold text-foreground">Founding Couple *</p>
                          <p className="mt-1 text-xs text-muted-foreground">
                            Add the founding grandfather and grandmother. They become the top of your family tree.
                          </p>
                        </div>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                          <div className="space-y-3 rounded-lg border border-border bg-card p-3">
                            <p className="text-sm font-medium">Founding Grandfather</p>
                            <div>
                              <Label htmlFor="grandfather_name">Full name *</Label>
                              <Input
                                id="grandfather_name"
                                className="mt-1.5"
                                value={form.grandfather_name}
                                onChange={(e) => setField("grandfather_name", e.target.value)}
                                placeholder="e.g. John Matthews"
                              />
                              {errors.grandfather_name && (
                                <p className="mt-1 text-sm text-red-600">{errors.grandfather_name}</p>
                              )}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                              <div>
                                <Label htmlFor="grandfather_birth_year">Birth year</Label>
                                <Input
                                  id="grandfather_birth_year"
                                  type="number"
                                  className="mt-1.5"
                                  value={form.grandfather_birth_year}
                                  onChange={(e) => setField("grandfather_birth_year", e.target.value)}
                                  placeholder="1920"
                                />
                              </div>
                              <div>
                                <Label htmlFor="grandfather_death_year">Death year</Label>
                                <Input
                                  id="grandfather_death_year"
                                  type="number"
                                  className="mt-1.5"
                                  value={form.grandfather_death_year}
                                  onChange={(e) => setField("grandfather_death_year", e.target.value)}
                                  placeholder="Optional"
                                />
                              </div>
                            </div>
                            <div>
                              <Label htmlFor="grandfather_photo">Photo (optional)</Label>
                              <Input
                                id="grandfather_photo"
                                type="file"
                                accept="image/*"
                                className="mt-1.5"
                                onChange={(e) => setField("grandfather_photo", e.target.files?.[0] ?? null)}
                              />
                            </div>
                          </div>

                          <div className="space-y-3 rounded-lg border border-border bg-card p-3">
                            <p className="text-sm font-medium">Founding Grandmother</p>
                            <div>
                              <Label htmlFor="grandmother_name">Full name *</Label>
                              <Input
                                id="grandmother_name"
                                className="mt-1.5"
                                value={form.grandmother_name}
                                onChange={(e) => setField("grandmother_name", e.target.value)}
                                placeholder="e.g. Mary Matthews"
                              />
                              {errors.grandmother_name && (
                                <p className="mt-1 text-sm text-red-600">{errors.grandmother_name}</p>
                              )}
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                              <div>
                                <Label htmlFor="grandmother_birth_year">Birth year</Label>
                                <Input
                                  id="grandmother_birth_year"
                                  type="number"
                                  className="mt-1.5"
                                  value={form.grandmother_birth_year}
                                  onChange={(e) => setField("grandmother_birth_year", e.target.value)}
                                  placeholder="1922"
                                />
                              </div>
                              <div>
                                <Label htmlFor="grandmother_death_year">Death year</Label>
                                <Input
                                  id="grandmother_death_year"
                                  type="number"
                                  className="mt-1.5"
                                  value={form.grandmother_death_year}
                                  onChange={(e) => setField("grandmother_death_year", e.target.value)}
                                  placeholder="Optional"
                                />
                              </div>
                            </div>
                            <div>
                              <Label htmlFor="grandmother_photo">Photo (optional)</Label>
                              <Input
                                id="grandmother_photo"
                                type="file"
                                accept="image/*"
                                className="mt-1.5"
                                onChange={(e) => setField("grandmother_photo", e.target.files?.[0] ?? null)}
                              />
                            </div>
                          </div>
                        </div>
                      </div>
                    )}

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                      {form.has_ein && form.ein && (
                        <div className="sm:col-span-2">
                          <Label htmlFor="display_ein">EIN</Label>
                          <Input
                            id="display_ein"
                            className="mt-1.5 h-11 cursor-not-allowed bg-muted/60 font-mono tracking-wider text-muted-foreground"
                            value={formatEIN(form.ein)}
                            readOnly
                          />
                        </div>
                      )}
                      <div className="sm:col-span-2">
                        <Label htmlFor="name">Organization Name *</Label>
                        <Input
                          id="name"
                          className={irsInputClass}
                          value={form.name}
                          onChange={(e) => {
                            if (!irsFieldsLocked) setField("name", e.target.value)
                          }}
                          readOnly={irsFieldsLocked}
                          placeholder="Organization Name"
                        />
                        {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                      </div>
                      <div className="sm:col-span-2">
                        <Label htmlFor="street">Street Address *</Label>
                        <Input
                          id="street"
                          className={irsInputClass}
                          value={form.street}
                          onChange={(e) => {
                            if (!irsFieldsLocked) setField("street", e.target.value)
                          }}
                          readOnly={irsFieldsLocked}
                          placeholder="Street Address"
                        />
                        {errors.street && <p className="mt-1 text-sm text-red-600">{errors.street}</p>}
                      </div>
                      <div>
                        <Label htmlFor="city">City *</Label>
                        <Input
                          id="city"
                          className={irsInputClass}
                          value={form.city}
                          onChange={(e) => {
                            if (!irsFieldsLocked) setField("city", e.target.value)
                          }}
                          readOnly={irsFieldsLocked}
                          placeholder="City"
                        />
                      </div>
                      <div>
                        <Label htmlFor="state">State / Province *</Label>
                        <Input
                          id="state"
                          className={irsInputClass}
                          value={form.state}
                          onChange={(e) => {
                            if (!irsFieldsLocked) setField("state", e.target.value)
                          }}
                          readOnly={irsFieldsLocked}
                          placeholder="State / Province"
                        />
                      </div>
                      <div>
                        <Label htmlFor="zip">ZIP / Postal Code *</Label>
                        <Input
                          id="zip"
                          className={irsInputClass}
                          value={form.zip}
                          onChange={(e) => {
                            if (!irsFieldsLocked) setField("zip", e.target.value)
                          }}
                          readOnly={irsFieldsLocked}
                          placeholder="ZIP / Postal Code"
                        />
                      </div>
                      <div>
                        <Label htmlFor="contact_name">Primary Contact Person *</Label>
                        <Input
                          id="contact_name"
                          className="mt-1.5 h-11"
                          value={form.contact_name}
                          onChange={(e) => setField("contact_name", e.target.value)}
                          placeholder="Primary Contact Person"
                        />
                        {errors.contact_name && (
                          <p className="mt-1 text-sm text-red-600">{errors.contact_name}</p>
                        )}
                      </div>
                      <div className="sm:col-span-2">
                        <Label htmlFor="email">Contact Email *</Label>
                        <Input
                          id="email"
                          type="email"
                          className="mt-1.5 h-11"
                          value={form.email}
                          onChange={(e) => setField("email", e.target.value)}
                          placeholder="Contact Email"
                        />
                        {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                      </div>
                      <div>
                        <Label htmlFor="password">Password *</Label>
                        <div className="relative mt-1.5">
                          <Input
                            id="password"
                            type={showPassword ? "text" : "password"}
                            className="h-11 pr-10"
                            value={form.password}
                            onChange={(e) => setField("password", e.target.value)}
                            placeholder="Create a password"
                          />
                          <button
                            type="button"
                            className="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-muted-foreground"
                            onClick={() => setShowPassword((v) => !v)}
                          >
                            {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                          </button>
                        </div>
                        {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
                      </div>
                      <div>
                        <Label htmlFor="password_confirmation">Confirm Password *</Label>
                        <div className="relative mt-1.5">
                          <Input
                            id="password_confirmation"
                            type={showConfirmPassword ? "text" : "password"}
                            className="h-11 pr-10"
                            value={form.password_confirmation}
                            onChange={(e) => setField("password_confirmation", e.target.value)}
                            placeholder="Confirm password"
                          />
                          <button
                            type="button"
                            className="absolute right-3 top-1/2 -translate-y-1/2 cursor-pointer text-muted-foreground"
                            onClick={() => setShowConfirmPassword((v) => !v)}
                          >
                            {showConfirmPassword ? (
                              <EyeOff className="h-4 w-4" />
                            ) : (
                              <Eye className="h-4 w-4" />
                            )}
                          </button>
                        </div>
                      </div>
                    </div>

                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                      <Checkbox
                        checked={form.agree_to_terms}
                        onCheckedChange={(v) => setField("agree_to_terms", v === true)}
                        className="mt-0.5 cursor-pointer"
                      />
                      <span>
                        I agree to the Terms of Service and Privacy Policy *
                      </span>
                    </label>
                    {errors.agree_to_terms && (
                      <p className="text-sm text-red-600">{errors.agree_to_terms}</p>
                    )}

                    <TurnstileField
                      onToken={setTurnstileToken}
                      error={errors.cf_turnstile_response}
                    />

                    <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
                      <Button type="button" variant="outline" className="h-11 cursor-pointer" onClick={() => setStep(2)}>
                        Back
                      </Button>
                      <Button
                        type="button"
                        disabled={!validateStep(3) || isLoading || turnstileBlocksSubmit}
                        onClick={submitRegistration}
                        className="h-11 cursor-pointer bg-gradient-to-r from-emerald-600 to-green-700 font-bold text-white"
                      >
                        {isLoading ? "Creating account…" : "Create Organization"}
                      </Button>
                    </div>
                  </motion.div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}

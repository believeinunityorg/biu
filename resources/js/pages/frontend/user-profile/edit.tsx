"use client"

import type React from "react"
import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import { useState, useEffect, useMemo, useCallback, useRef } from "react"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import BrpParticipationHint from "@/components/brp/BrpParticipationHint"
import {
  Save,
  X,
  Upload,
  CheckCircle,
  AlertCircle,
  User,
  Briefcase,
  Shield,
  Building2,
  Monitor,
  Sun,
  Moon,
  Copy,
  Calendar,
  Info,
  MapPin,
  Plus,
  Lock,
  ArrowRight,
  PenLine,
  LogOut,
} from "lucide-react"
import { Link, useForm, usePage, router } from "@inertiajs/react"
import { toast } from "sonner"
import { Transition } from "@headlessui/react"
import { Alert, AlertDescription } from "@/components/frontend/ui/alert"
import { MultiSelect } from "@/components/ui/multi-select"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import { RadioGroup, RadioGroupItem } from "@/components/frontend/ui/radio-group"
import {
  ProfileOrganizationPicker,
  type ProfileOrgOption,
} from "@/components/frontend/profile-organization-picker"
import { useAppearance, type Appearance } from "@/hooks/use-appearance"
import { cn, formatMmDdInput, isValidMmDd, normalizeMmDd } from "@/lib/utils"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/frontend/ui/alert-dialog"
import {
  getGeolocationPermissionState,
  requestLocationPermissionPrompt,
  type GeolocationPermissionState,
} from "@/lib/location-permissions"
import { isStandalonePwa } from "@/lib/push-environment"
import type { SharedData } from "@/types"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/frontend/ui/dialog"

const PRIMARY_ORG_CHANGE_REASONS = [
  {
    id: "more_involved",
    title: "I am more actively involved with this organization now",
    description: "Volunteering, attending events, or participating more frequently.",
    reasonText:
      "I am more actively involved with this organization now — Volunteering, attending events, or participating more frequently.",
  },
  {
    id: "aligned_interests",
    title: "This organization better aligns with my current interests or causes",
    description: "My priorities have changed.",
    reasonText:
      "This organization better aligns with my current interests or causes — My priorities have changed.",
  },
  {
    id: "personal_connection",
    title: "I have a personal connection to this organization",
    description: "Family member, friend, church, school, team, or community involvement.",
    reasonText:
      "I have a personal connection to this organization — Family member, friend, church, school, team, or community involvement.",
  },
  {
    id: "future_benefits",
    title: "I want my future rewards, purchases, and donations to primarily benefit this organization",
    description: "I am intentionally choosing this organization as my primary beneficiary.",
    reasonText:
      "I want my future rewards, purchases, and donations to primarily benefit this organization — I am intentionally choosing this organization as my primary beneficiary.",
  },
  {
    id: "other",
    title: "Other / Prefer not to say",
    description: "No additional explanation required.",
    reasonText: "Other / Prefer not to say — No additional explanation required.",
  },
] as const

type PrimaryOrgChangeReasonId = (typeof PRIMARY_ORG_CHANGE_REASONS)[number]["id"]

interface AffiliatedOrg {
  id: number
  name: string
  logo_url?: string | null
}

type OrgRow = ProfileOrgOption

interface ProfileEditPageProps {
  user: {
    id: number
    name: string
    email: string
    phone?: string
    dob?: string
    image?: string
    positions: number[]
    supporter_interests?: number[]
    city?: string
    state?: string
    zipcode?: string
    religion?: string | null
    primary_organization_id?: number | null
    primary_organization?: OrgRow | null
    primary_organization_locked?: boolean
    secondary_organization_ids?: number[]
    unity_meeting_id?: string
    account_visibility?: "public" | "private"
    messaging_policy?: "everyone" | "followers_only" | "organizations_i_follow" | "no_one"
    preferred_theme?: Appearance | null
    proximity_notifications_enabled?: boolean
  }
  availablePositions: { id: number; name: string }[]
  availableSupporterInterests: { id: number; name: string }[]
  affiliatedOrganizations: AffiliatedOrg[]
  religionOptions: string[]
  organizations: OrgRow[]
  organizationPicker?: {
    target: "primary" | "secondary"
    items: OrgRow[]
    has_more: boolean
    page: number
    search: string
  } | null
}

const EMPTY_PROFILE_USER: ProfileEditPageProps["user"] = {
  id: 0,
  name: "",
  email: "",
  positions: [],
  supporter_interests: [],
  secondary_organization_ids: [],
  account_visibility: "public",
  messaging_policy: "everyone",
  preferred_theme: "system",
  proximity_notifications_enabled: true,
}

function orgInitial(name: string) {
  return name.trim().slice(0, 1).toUpperCase() || "O"
}

function visitPathname(url: string | URL): string {
  try {
    const resolved = typeof url === "string" ? new URL(url, window.location.origin) : url
    return resolved.pathname.replace(/\/$/, "") || "/"
  } catch {
    return ""
  }
}

/** Same-page reloads and profile mutations from this screen must not trip the leave guard. */
function isAllowedWhileDirty(url: string | URL): boolean {
  const path = visitPathname(url)
  return (
    path.endsWith("/profile/edit") ||
    path.endsWith("/profile/update") ||
    path.endsWith("/profile/primary-organization/change") ||
    path.endsWith("/profile/timezone")
  )
}

export default function ProfileEdit() {
  const pageProps = usePage<SharedData & ProfileEditPageProps>().props
  const user = pageProps.user ?? EMPTY_PROFILE_USER
  const availablePositions = pageProps.availablePositions ?? []
  const availableSupporterInterests = pageProps.availableSupporterInterests ?? []
  const religionOptions = pageProps.religionOptions ?? []
  const organizations = pageProps.organizations ?? []
  const profileReady = Boolean(pageProps.user)
  const setupInfo =
    (typeof pageProps.flash?.info === "string" && pageProps.flash.info) ||
    (typeof (pageProps as { info?: unknown }).info === "string" && (pageProps as { info?: string }).info) ||
    null

  const { updateAppearance } = useAppearance()

  const inertiaForm = useForm({
    name: user.name || "",
    email: user.email || "",
    phone: user.phone || "",
    dob: user.dob || "",
    image: null as File | null,
    positions: user.positions ?? [],
    supporter_interests: user.supporter_interests ?? [],
    _supporter_interests_touched: true,
    city: user.city || "",
    state: user.state || "",
    zipcode: user.zipcode || "",
    religion: user.religion || "",
    account_visibility: (user.account_visibility === "private" ? "private" : "public") as "public" | "private",
    messaging_policy: (user.messaging_policy ?? "everyone") as ProfileEditPageProps["user"]["messaging_policy"],
    primary_organization_id: user.primary_organization_id ?? "",
    secondary_organization_ids: user.secondary_organization_ids ?? [],
    preferred_theme: ((user.preferred_theme as Appearance) ?? "system") as Appearance,
    proximity_notifications_enabled: user.proximity_notifications_enabled !== false,
  })

  inertiaForm.transform((payload) => {
    const next: Record<string, unknown> = {
      ...payload,
      dob: normalizeMmDd(String(payload.dob ?? "")),
      primary_organization_id:
        payload.primary_organization_id === "" || payload.primary_organization_id === undefined
          ? null
          : typeof payload.primary_organization_id === "number"
            ? payload.primary_organization_id
            : Number(payload.primary_organization_id),
    }

    if (!(payload.image instanceof File)) {
      delete next.image
    }

    next.positions = Array.isArray(payload.positions) ? payload.positions : []
    next.supporter_interests = Array.isArray(payload.supporter_interests) ? payload.supporter_interests : []
    next.secondary_organization_ids = Array.isArray(payload.secondary_organization_ids)
      ? payload.secondary_organization_ids
      : []

    return next
  })

  const { data, setData, post, processing, errors, reset, recentlySuccessful, isDirty, setDefaults } =
    inertiaForm

  const positions = data.positions ?? []
  const supporterInterests = data.supporter_interests ?? []
  const secondaryOrgIds = data.secondary_organization_ids ?? []

  const [previewUrl, setPreviewUrl] = useState(user.image || null)
  const [copiedUnity, setCopiedUnity] = useState(false)
  const [orgCache, setOrgCache] = useState<Record<number, OrgRow>>({})
  const [changePrimaryOpen, setChangePrimaryOpen] = useState(false)
  const [changePrimaryOrgId, setChangePrimaryOrgId] = useState("")
  const [changeReasonOption, setChangeReasonOption] = useState<PrimaryOrgChangeReasonId | "">("")
  const [changingPrimary, setChangingPrimary] = useState(false)
  const [locationPermission, setLocationPermission] = useState<GeolocationPermissionState>("prompt")
  const [leaveConfirmOpen, setLeaveConfirmOpen] = useState(false)
  const isPrimaryLocked = Boolean(user.primary_organization_locked)

  const isDirtyRef = useRef(isDirty)
  const allowNavigationRef = useRef(false)
  const pendingVisitUrlRef = useRef<string | null>(null)
  isDirtyRef.current = isDirty

  useEffect(() => {
    void getGeolocationPermissionState().then(setLocationPermission)
  }, [])

  useEffect(() => {
    if (!user) return
    const synced = {
      name: user.name || "",
      email: user.email || "",
      phone: user.phone || "",
      dob: user.dob || "",
      city: user.city || "",
      state: user.state || "",
      zipcode: user.zipcode || "",
      religion: user.religion || "",
      account_visibility: (user.account_visibility === "private" ? "private" : "public") as "public" | "private",
      messaging_policy: (user.messaging_policy ?? "everyone") as ProfileEditPageProps["user"]["messaging_policy"],
      primary_organization_id: user.primary_organization_id ?? "",
      secondary_organization_ids: user.secondary_organization_ids ?? [],
      preferred_theme: ((user.preferred_theme as Appearance) || "system") as Appearance,
      positions: user.positions || [],
      supporter_interests: user.supporter_interests || [],
      image: null as File | null,
      proximity_notifications_enabled: user.proximity_notifications_enabled !== false,
      _supporter_interests_touched: true,
    }
    setData(synced)
    setDefaults(synced)
    if (user.image) {
      setPreviewUrl(user.image)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- sync form when loaded user identity changes, not on every prop churn
  }, [user?.id])

  useEffect(() => {
    if (!user) return
    const primaryId = user.primary_organization_id ?? ""
    setData("primary_organization_id", primaryId)
    setDefaults("primary_organization_id", primaryId)
    if (user.primary_organization) {
      setOrgCache((prev) => ({ ...prev, [user.primary_organization!.id]: user.primary_organization! }))
    }
  }, [user?.primary_organization_id, user?.primary_organization?.id, setData, setDefaults])

  useEffect(() => {
    if (!user) return
    const nextPositions = user.positions ?? []
    const nextInterests = user.supporter_interests ?? []
    setData("positions", nextPositions)
    setData("supporter_interests", nextInterests)
    setDefaults({
      positions: nextPositions,
      supporter_interests: nextInterests,
    })
  }, [user?.positions, user?.supporter_interests, setData, setDefaults])

  useEffect(() => {
    const remove = router.on("before", (event) => {
      if (allowNavigationRef.current) {
        allowNavigationRef.current = false
        return
      }
      if (!isDirtyRef.current) return

      const visit = event.detail.visit
      if (isAllowedWhileDirty(visit.url)) return

      event.preventDefault()
      pendingVisitUrlRef.current =
        typeof visit.url === "string" ? visit.url : visit.url.href
      setLeaveConfirmOpen(true)
    })
    return remove
  }, [])

  useEffect(() => {
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (!isDirtyRef.current || allowNavigationRef.current) return
      e.preventDefault()
      e.returnValue = ""
    }
    window.addEventListener("beforeunload", onBeforeUnload)
    return () => window.removeEventListener("beforeunload", onBeforeUnload)
  }, [])

  const profileUpdateErrorMessage = (errs: Record<string, string | string[]>) => {
    const first = Object.values(errs)[0]
    if (Array.isArray(first)) {
      return first[0] ?? "Failed to update profile. Please check the form."
    }
    return typeof first === "string" ? first : "Failed to update profile. Please check the form."
  }

  const validateProfileForm = (): boolean => {
    const normalizedDob = normalizeMmDd(data.dob ?? "")
    if (!isValidMmDd(normalizedDob)) {
      toast.error("Enter a valid date of birth (MM/DD).")
      return false
    }

    if (!data.name.trim()) {
      toast.error("Full name is required.")
      return false
    }

    if (!data.email.trim()) {
      toast.error("Email address is required.")
      return false
    }

    if (!data.city.trim()) {
      toast.error("City is required.")
      return false
    }

    if (!data.state.trim()) {
      toast.error("State is required.")
      return false
    }

    if (!data.zipcode.trim()) {
      toast.error("Zip code is required.")
      return false
    }

    return true
  }

  const submitProfile = (options?: { continueTo?: string | null }) => {
    if (!validateProfileForm()) return

    const continueTo = options?.continueTo ?? null
    const hasImageUpload = data.image instanceof File

    post(route("user.profile.update"), {
      preserveScroll: !continueTo,
      forceFormData: hasImageUpload,
      onSuccess: (page) => {
        const savedUser = (page.props as ProfileEditPageProps).user
        setData("image", null)
        if (savedUser?.image) {
          setPreviewUrl(savedUser.image)
        }
        if (savedUser) {
          setData("secondary_organization_ids", savedUser.secondary_organization_ids ?? [])
        }
        updateAppearance(data.preferred_theme)
        toast.success("Profile updated successfully!")
        queueMicrotask(() => {
          setDefaults()
          if (continueTo) {
            allowNavigationRef.current = true
            setLeaveConfirmOpen(false)
            pendingVisitUrlRef.current = null
            router.visit(continueTo)
          }
        })
      },
      onError: (errs) => {
        console.error("Update errors:", errs)
        toast.error(profileUpdateErrorMessage(errs))
      },
    })
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    submitProfile()
  }

  const handleStayOnPage = () => {
    pendingVisitUrlRef.current = null
    setLeaveConfirmOpen(false)
  }

  const handleLeaveWithoutSaving = () => {
    const nextUrl = pendingVisitUrlRef.current
    pendingVisitUrlRef.current = null
    setLeaveConfirmOpen(false)
    if (!nextUrl) return
    allowNavigationRef.current = true
    router.visit(nextUrl)
  }

  const handleSaveAndLeave = () => {
    submitProfile({ continueTo: pendingVisitUrlRef.current })
  }

  const handleCancel = () => {
    reset()
    setPreviewUrl(user?.image ?? null)
  }

  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    if (!file.type.startsWith("image/")) {
      toast.error("Please select a valid image file.")
      e.target.value = ""
      return
    }

    if (file.size > 10 * 1024 * 1024) {
      toast.error("Image size must be less than 10MB.")
      e.target.value = ""
      return
    }

    setData("image", file)
    setPreviewUrl(URL.createObjectURL(file))
  }

  const copyUnityId = async () => {
    const id = user?.unity_meeting_id ?? ""
    if (!id) return
    try {
      await navigator.clipboard.writeText(id)
      setCopiedUnity(true)
      toast.success("Unity Meeting ID copied")
      setTimeout(() => setCopiedUnity(false), 2000)
    } catch {
      toast.error("Could not copy to clipboard")
    }
  }

  const positionOptions = availablePositions.map((position) => ({
    label: position.name,
    value: position.id.toString(),
  }))

  const primaryOrgIdNum =
    data.primary_organization_id === "" || data.primary_organization_id === undefined
      ? null
      : Number(data.primary_organization_id)

  const resolvedOrganizations = useMemo(() => {
    const map = new Map<number, OrgRow>()
    for (const o of organizations) map.set(o.id, o)
    for (const o of Object.values(orgCache)) map.set(o.id, o)
    return Array.from(map.values())
  }, [organizations, orgCache])

  const mergeOrg = useCallback((org: OrgRow) => {
    setOrgCache((prev) => ({ ...prev, [org.id]: org }))
  }, [])

  const primaryOrganizationDisplay = useMemo(() => {
    if (primaryOrgIdNum === null) return null
    const fromList = resolvedOrganizations.find((o) => o.id === primaryOrgIdNum)
    if (fromList) return fromList
    if (user.primary_organization?.id === primaryOrgIdNum) {
      return user.primary_organization
    }
    return null
  }, [resolvedOrganizations, primaryOrgIdNum, user.primary_organization])

  const secondaryExcludeIds = useMemo(() => {
    const ids = [...secondaryOrgIds]
    if (primaryOrgIdNum) ids.push(primaryOrgIdNum)
    return [...new Set(ids)]
  }, [secondaryOrgIds, primaryOrgIdNum])

  const primaryPickerExcludeIds = useMemo(() => {
    if (primaryOrgIdNum === null) return []
    return [primaryOrgIdNum]
  }, [primaryOrgIdNum])

  const changePrimaryExcludeIds = useMemo(() => {
    if (primaryOrgIdNum) return [primaryOrgIdNum]
    return []
  }, [primaryOrgIdNum])

  const changePrimarySelectedOrg = useMemo(() => {
    if (!changePrimaryOrgId) return undefined
    const id = Number(changePrimaryOrgId)
    if (!Number.isFinite(id) || id <= 0) return undefined
    return (
      resolvedOrganizations.find((o) => o.id === id) ??
      (user.primary_organization?.id === id ? user.primary_organization : undefined) ??
      (primaryOrganizationDisplay?.id === id ? primaryOrganizationDisplay : undefined)
    )
  }, [changePrimaryOrgId, resolvedOrganizations, user.primary_organization, primaryOrganizationDisplay])

  const openChangePrimaryModal = useCallback(() => {
    setChangePrimaryOrgId(primaryOrgIdNum ? String(primaryOrgIdNum) : "")
    setChangeReasonOption("")
    setChangePrimaryOpen(true)
  }, [primaryOrgIdNum])

  const selectedSecondaryOrganizations = useMemo(() => {
    const rows: OrgRow[] = []
    const seen = new Set<number>()
    for (const id of secondaryOrgIds) {
      if (seen.has(id)) continue
      seen.add(id)
      const org =
        resolvedOrganizations.find((o) => o.id === id) ??
        orgCache[id] ??
        null
      if (org) rows.push(org)
    }
    return rows
  }, [secondaryOrgIds, resolvedOrganizations, orgCache])

  const addSecondaryOrganization = useCallback(
    (id: number) => {
      if (primaryOrgIdNum && id === primaryOrgIdNum) return
      if (secondaryOrgIds.includes(id)) return
      setData("secondary_organization_ids", [...secondaryOrgIds, id])
    },
    [secondaryOrgIds, primaryOrgIdNum, setData],
  )

  const removeSecondaryOrganization = useCallback(
    (id: number) =>
      setData(
        "secondary_organization_ids",
        secondaryOrgIds.filter((x) => x !== id),
      ),
    [secondaryOrgIds, setData],
  )

  const submitPrimaryOrganizationChange = () => {
    const reasonEntry = PRIMARY_ORG_CHANGE_REASONS.find((entry) => entry.id === changeReasonOption)
    if (!changePrimaryOrgId || !reasonEntry) {
      toast.error("Select an organization and a reason for the change.")
      return
    }
    const newId = Number(changePrimaryOrgId)
    if (primaryOrgIdNum && newId === primaryOrgIdNum) {
      toast.error("Select a different organization to change your primary.")
      return
    }
    const selectedOrg =
      orgCache[newId] ??
      resolvedOrganizations.find((o) => o.id === newId) ??
      (user.primary_organization?.id === newId ? user.primary_organization : null)

    setChangingPrimary(true)
    router.post(
      route("user.profile.primary-organization.change"),
      {
        primary_organization_id: newId,
        reason: reasonEntry.reasonText,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          if (selectedOrg) {
            setOrgCache((prev) => ({ ...prev, [selectedOrg.id]: selectedOrg }))
          }
          setData((current) => ({
            ...current,
            primary_organization_id: newId,
            secondary_organization_ids: (current.secondary_organization_ids ?? []).filter((sid) => sid !== newId),
          }))
          setChangePrimaryOpen(false)
          setChangeReasonOption("")
          setChangePrimaryOrgId("")
          toast.success("Primary organization updated.")
          router.reload({ preserveScroll: true })
        },
        onError: (errs) => {
          const msg =
            errs.primary_organization_change ??
            errs.reason ??
            errs.primary_organization_id ??
            "Could not update primary organization."
          toast.error(String(msg))
        },
        onFinish: () => setChangingPrimary(false),
      },
    )
  }

  const setPreferredTheme = useCallback(
    (mode: Appearance) => {
      setData("preferred_theme", mode)
      updateAppearance(mode)
    },
    [setData, updateAppearance],
  )

  const selectedSupporterInterestCategories = useMemo(
    () => availableSupporterInterests.filter((c) => supporterInterests.includes(c.id)),
    [availableSupporterInterests, supporterInterests],
  )

  const remainingSupporterInterestCategories = useMemo(
    () => availableSupporterInterests.filter((c) => !supporterInterests.includes(c.id)),
    [availableSupporterInterests, supporterInterests],
  )

  const addSupporterInterestTag = useCallback(
    (id: number) => {
      if (supporterInterests.includes(id)) return
      setData("supporter_interests", [...supporterInterests, id])
    },
    [supporterInterests, setData],
  )

  const removeSupporterInterestTag = useCallback(
    (id: number) => {
      setData(
        "supporter_interests",
        supporterInterests.filter((x) => x !== id),
      )
    },
    [supporterInterests, setData],
  )

  const cardClass =
    "min-w-0 overflow-hidden border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900/40"
  const cardHeaderPad = "px-4 pb-4 pt-4 sm:px-6 sm:pt-6"
  const cardContentPad = "px-4 pb-4 sm:px-6 sm:pb-6"
  const inputClass =
    "mt-1 border-gray-300 bg-white text-gray-900 focus-visible:ring-purple-500/30 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
  const labelClass = "text-gray-700 dark:text-gray-200"
  const helperClass = "text-sm text-gray-500 dark:text-gray-400"
  const orgPanelClass =
    "relative overflow-hidden rounded-xl border border-purple-200 bg-gradient-to-br from-purple-50 via-white to-blue-50 shadow-sm dark:border-purple-500/30 dark:from-purple-600/[0.12] dark:via-gray-900/80 dark:to-blue-600/[0.12]"
  const orgPanelAccent = "absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-purple-600 to-blue-600"
  const radioOptionClass =
    "flex cursor-pointer gap-3 rounded-lg border border-gray-200 bg-gray-50/80 p-3 transition-colors hover:border-purple-400/60 dark:border-gray-600 dark:bg-gray-800/50 dark:hover:border-purple-500/40"
  const emptyStateClass =
    "rounded-xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800/40 dark:text-gray-400"
  const changeOrgButtonClass =
    "w-full shrink-0 border-purple-300 bg-white text-gray-900 hover:border-purple-400 hover:bg-purple-50 sm:w-auto dark:border-purple-500/40 dark:bg-gray-900/50 dark:text-gray-100 dark:hover:bg-purple-500/10"

  const sectionTitle = (icon: React.ReactNode, n: number, title: string) => (
    <CardTitle className="flex min-w-0 items-start gap-2 text-base font-semibold text-gray-900 sm:items-center sm:text-lg dark:text-white">
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-100 to-blue-100 text-purple-600 dark:from-purple-600/25 dark:to-blue-600/25 dark:text-purple-300">
        {icon}
      </span>
      <span className="min-w-0 break-words leading-snug">
        <span className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent">{n}.</span>{" "}
        {title}
      </span>
    </CardTitle>
  )

  return (
    <ProfileLayout
      title="Profile Settings"
      description="Manage your personal information and account settings."
    >
      {!profileReady ? (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-8 text-center text-gray-500 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-400">
          Loading profile…
        </div>
      ) : (
        <>
      <Transition
        show={recentlySuccessful}
        enter="transition ease-in-out"
        enterFrom="opacity-0"
        leave="transition ease-in-out"
        leaveTo="opacity-0"
      >
        <Alert className="mb-2 border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950/80">
          <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" />
          <AlertDescription className="text-green-800 dark:text-green-200">Profile updated successfully!</AlertDescription>
        </Alert>
      </Transition>

      {setupInfo ? (
        <Alert className="mb-2 border-purple-200 bg-purple-50 dark:border-purple-800 dark:bg-purple-950/80">
          <Info className="h-4 w-4 text-purple-600 dark:text-purple-400" />
          <AlertDescription className="text-purple-900 dark:text-purple-100">{setupInfo}</AlertDescription>
        </Alert>
      ) : null}

      <form onSubmit={handleSubmit} noValidate className="min-w-0 w-full max-w-full space-y-4 sm:space-y-6">
        {/* 1. Personal Information */}
        <Card className={cardClass}>
          <CardHeader className={cardHeaderPad}>{sectionTitle(<User className="h-5 w-5" />, 1, "Personal Information")}</CardHeader>
          <CardContent className={cn(cardContentPad, "space-y-5")}>
            <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center">
              <div className="relative shrink-0">
                <img
                  src={previewUrl || "/placeholder.svg?height=80&width=80"}
                  alt="Profile"
                  className="h-20 w-20 rounded-full border-2 border-purple-300 object-cover dark:border-purple-500/40"
                />
              </div>
              <div className="min-w-0">
                <Label htmlFor="image" className={cn("cursor-pointer", labelClass)}>
                  <div className="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 px-4 py-2 text-white hover:from-purple-700 hover:to-blue-700">
                    <Upload className="h-4 w-4" />
                    Upload photo
                  </div>
                  <Input id="image" type="file" accept="image/*" onChange={handleImageChange} className="hidden" />
                </Label>
                <p className={cn("mt-2", helperClass)}>JPG, PNG or GIF. Max 10MB.</p>
                {errors.image && <p className="mt-1 text-sm text-red-400">{errors.image}</p>}
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <Label htmlFor="name" className={labelClass}>
                Full Name *
              </Label>
              <Input
                id="name"
                value={data.name}
                onChange={(e) => setData("name", e.target.value)}
                  className={inputClass}
                required
              />
                {errors.name && <p className="mt-1 text-sm text-red-400">{errors.name}</p>}
            </div>
              <div className="sm:col-span-2">
                <Label htmlFor="email" className={labelClass}>
                Email Address *
              </Label>
              <Input
                id="email"
                type="email"
                value={data.email}
                onChange={(e) => setData("email", e.target.value)}
                  className={inputClass}
                required
              />
                {errors.email && <p className="mt-1 text-sm text-red-400">{errors.email}</p>}
            </div>
            <div>
                <Label htmlFor="phone" className={labelClass}>
                Phone Number
              </Label>
              <Input
                id="phone"
                type="tel"
                value={data.phone}
                onChange={(e) => setData("phone", e.target.value)}
                  className={inputClass}
                placeholder="+1 (555) 123-4567"
              />
                {errors.phone && <p className="mt-1 text-sm text-red-400">{errors.phone}</p>}
            </div>
            <div>
                <Label htmlFor="dob" className={labelClass}>
                Date of Birth (MM/DD) *
              </Label>
                <div className="relative mt-1">
                  <Calendar className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <Input
                id="dob"
                type="text"
                    inputMode="numeric"
                    value={data.dob ?? ""}
                    onChange={(e) => setData("dob", formatMmDdInput(e.target.value))}
                    className="border-gray-300 bg-white pl-10 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                placeholder="MM/DD"
                    maxLength={5}
              />
            </div>
                {errors.dob && <p className="mt-1 text-sm text-red-400">{errors.dob}</p>}
              </div>
              <div className="sm:col-span-2">
                <Label htmlFor="religion" className={labelClass}>
                  Major World Religions (Optional)
              </Label>
                <p className={cn("mb-2 mt-1", helperClass)}>Optional — choose the tradition that best describes you.</p>
              <Select
                value={data.religion ? data.religion : "__none__"}
                onValueChange={(v) => setData("religion", v === "__none__" ? "" : v)}
              >
                  <SelectTrigger id="religion" className="border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                  <SelectValue placeholder="Select…" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">Prefer not to say</SelectItem>
                  {religionOptions.map((r) => (
                    <SelectItem key={r} value={r}>
                      {r}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
                {errors.religion && <p className="mt-1 text-sm text-red-400">{errors.religion}</p>}
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <div className="min-w-0">
                <Label htmlFor="city" className={labelClass}>
                  City *
                </Label>
                <Input
                  id="city"
                  value={data.city}
                  onChange={(e) => setData("city", e.target.value)}
                  className={inputClass}
                  required
                />
                {errors.city && <p className="mt-1 text-sm text-red-400">{errors.city}</p>}
              </div>
              <div className="min-w-0">
                <Label htmlFor="state" className={labelClass}>
                  State *
                </Label>
                <Input
                  id="state"
                  value={data.state}
                  onChange={(e) => setData("state", e.target.value.toUpperCase())}
                  className={inputClass}
                  maxLength={2}
                  required
                />
                {errors.state && <p className="mt-1 text-sm text-red-400">{errors.state}</p>}
              </div>
              <div className="min-w-0">
                <Label htmlFor="zipcode" className={labelClass}>
                  Zip Code *
                </Label>
                <Input
                  id="zipcode"
                  value={data.zipcode}
                  onChange={(e) => setData("zipcode", e.target.value)}
                  className={inputClass}
                  maxLength={10}
                  required
                />
                {errors.zipcode && <p className="mt-1 text-sm text-red-400">{errors.zipcode}</p>}
              </div>
            </div>

            <div className="rounded-lg border border-purple-200/80 bg-gradient-to-br from-purple-50/80 to-blue-50/50 p-4 dark:border-purple-500/25 dark:from-gray-800/50 dark:to-gray-800/50">
              <div className="mb-2 flex items-center gap-2 text-gray-800 dark:text-gray-200">
                <Info className="h-4 w-4 text-purple-600 dark:text-purple-300" />
                <span className="font-medium">Unity Meeting ID</span>
              </div>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Input
                  readOnly
                  value={user?.unity_meeting_id ?? ""}
                  className="min-w-0 font-mono text-sm border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:flex-1"
                />
                <Button type="button" variant="secondary" className="w-full shrink-0 sm:w-auto" onClick={copyUnityId}>
                  {copiedUnity ? (
                    <>
                      <CheckCircle className="mr-2 h-4 w-4" />
                      Copied
                    </>
                  ) : (
                    <>
                      <Copy className="mr-2 h-4 w-4" />
                      Copy
                    </>
                  )}
                </Button>
              </div>
              <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Your unique identity for Unity Meet (video meetings and events).
              </p>
            </div>
          </CardContent>
        </Card>

        {/* 2. Roles & Interests */}
        <Card className={cardClass}>
          <CardHeader className={cardHeaderPad}>{sectionTitle(<Briefcase className="h-5 w-5" />, 2, "Roles & Interests")}</CardHeader>
          <CardContent className={cn(cardContentPad, "space-y-6")}>
            <div>
              <Label className={cn("mb-2 block", labelClass)}>Supporter Positions</Label>
              <MultiSelect
                options={positionOptions}
                selected={positions.map(String)}
                onChange={(selected) => setData("positions", selected.map(Number))}
                placeholder="Select your supporter role(s)"
              />
              {errors.positions && <p className="mt-2 text-sm text-red-400">{errors.positions}</p>}
              <p className={cn("mt-2", helperClass)}>You can select multiple roles (e.g., Doctor + Volunteer)</p>
            </div>

            <div className="min-w-0 space-y-2">
              <Label className={cn("text-sm font-medium", labelClass)}>Cause groups</Label>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Each cause is its own group. When you pick one here, you <span className="font-medium text-gray-600 dark:text-gray-400">join</span> that
                group (same categories as on organization profiles).
              </p>
              {availableSupporterInterests.length === 0 ? (
                <Alert className="border-amber-800 bg-amber-950/50">
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertDescription className="text-sm text-amber-100">
                    No cause groups are available yet. An administrator must add them under Admin → Org Primary Action
                    Categories, or run the database seeder.
                  </AlertDescription>
                </Alert>
              ) : (
                <div
                  role="group"
                  aria-label="Cause groups you have joined"
                  className="flex min-h-10 w-full min-w-0 flex-wrap items-center gap-1 overflow-x-auto rounded-md border border-gray-300 bg-white px-2 py-1 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                  {selectedSupporterInterestCategories.map((c) => (
                    <span
                      key={c.id}
                      className="tagify-tag inline-flex max-w-full items-center gap-0.5 rounded-md border border-white/25 bg-gradient-to-r from-purple-600 to-blue-600 px-1.5 py-0.5 text-[13px] leading-tight text-white shadow-sm"
                    >
                      <span className="truncate">{c.name}</span>
                      <button
                        type="button"
                        onClick={() => removeSupporterInterestTag(c.id)}
                        className="tagify-tag__removeBtn ml-0.5 inline-flex size-[14px] shrink-0 cursor-pointer items-center justify-center rounded-sm text-white/85 hover:bg-white/20"
                        aria-label={`Leave ${c.name} group`}
                      >
                        <X className="h-2.5 w-2.5" strokeWidth={2.5} />
                      </button>
                    </span>
                  ))}
                  {remainingSupporterInterestCategories.length > 0 ? (
                    <>
                      <label className="sr-only" htmlFor="supporter-interest-add">
                        Add another cause group
                      </label>
                      <Select
                        key={supporterInterests.join(",")}
                        onValueChange={(v) => {
                          if (v) addSupporterInterestTag(Number(v))
                        }}
                      >
                        <SelectTrigger
                          id="supporter-interest-add"
                          className="tagify__input h-7 min-w-[7rem] flex-1 justify-start border-0 bg-transparent px-1 py-0.5 text-sm text-gray-500 shadow-none [&_svg]:hidden dark:text-gray-400"
                        >
                          <SelectValue placeholder="Add a group…" />
                        </SelectTrigger>
                        <SelectContent>
                          {remainingSupporterInterestCategories.map((c) => (
                            <SelectItem key={c.id} value={String(c.id)}>
                              {c.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </>
                  ) : selectedSupporterInterestCategories.length > 0 ? (
                    <span className="px-1 text-xs text-gray-500 dark:text-gray-400">{"You're in every available cause group"}</span>
                  ) : null}
                </div>
              )}
              {errors.supporter_interests && (
                <p className="text-sm text-red-400">{errors.supporter_interests}</p>
              )}
              <p className={helperClass}>
                Saving your profile updates which groups you belong to (Housing, Food, Mental Health, Education, and more
                as admins add them).
              </p>
            </div>
          </CardContent>
        </Card>

        {/* 3 & 4 */}
        <div className="grid min-w-0 grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-2">
          <Card className={cardClass}>
            <CardHeader className={cardHeaderPad}>{sectionTitle(<Shield className="h-5 w-5" />, 3, "Account Settings")}</CardHeader>
            <CardContent className={cn(cardContentPad, "space-y-6")}>
              <div className="space-y-3">
                <Label className={labelClass}>Account Visibility</Label>
                <RadioGroup
                  value={data.account_visibility}
                  onValueChange={(v) => setData("account_visibility", v as "public" | "private")}
                  className="space-y-3"
                >
                  <label className={radioOptionClass}>
                    <RadioGroupItem value="public" id="vis-public" className="mt-1 shrink-0" />
                    <div className="min-w-0">
                      <div className="font-medium text-gray-900 dark:text-gray-100">Public Account</div>
                      <p className="text-sm text-gray-500 dark:text-gray-400">Anyone can follow you and see your public content.</p>
                    </div>
                  </label>
                  <label className={radioOptionClass}>
                    <RadioGroupItem value="private" id="vis-private" className="mt-1 shrink-0" />
                    <div className="min-w-0">
                      <div className="font-medium text-gray-900 dark:text-gray-100">Private Account</div>
              <p className="text-sm text-gray-500 dark:text-gray-400">
                        You approve follow requests. Only approved followers can see your content.
                      </p>
                    </div>
                  </label>
                </RadioGroup>
                {errors.account_visibility && (
                  <p className="text-sm text-red-400">{errors.account_visibility}</p>
                )}
              </div>

              <div className="space-y-3">
                <Label className={labelClass}>Who can message me?</Label>
                <RadioGroup
                  value={data.messaging_policy ?? "everyone"}
                  onValueChange={(v) =>
                    setData(
                      "messaging_policy",
                      v as "everyone" | "followers_only" | "organizations_i_follow" | "no_one",
                    )
                  }
                  className="space-y-3"
                >
                  {(
                    [
                      ["everyone", "Everyone", "Anyone on BIU can message you."],
                      ["followers_only", "Followers Only", "Only people who follow you can message you."],
                      [
                        "organizations_i_follow",
                        "Organizations I Follow",
                        "Only organizations you follow can message you.",
                      ],
                      ["no_one", "No One", "No one can message you."],
                    ] as const
                  ).map(([value, title, help]) => (
                    <label key={value} className={radioOptionClass}>
                      <RadioGroupItem value={value} id={`msg-${value}`} className="mt-1 shrink-0" />
                      <div className="min-w-0">
                        <div className="font-medium text-gray-900 dark:text-gray-100">{title}</div>
                        <p className="text-sm text-gray-500 dark:text-gray-400">{help}</p>
                      </div>
                    </label>
                  ))}
                </RadioGroup>
                {errors.messaging_policy && <p className="text-sm text-red-400">{errors.messaging_policy}</p>}
            </div>
          </CardContent>
        </Card>

          <Card className={cardClass}>
            <CardHeader className={cardHeaderPad}>{sectionTitle(<Building2 className="h-5 w-5" />, 4, "Organization Affiliation")}</CardHeader>
            <CardContent className={cn(cardContentPad, "space-y-6")}>
              <div className="space-y-2">
                <Label htmlFor="primary_organization" className={labelClass}>
                  Primary Organization *
                </Label>
                {isPrimaryLocked && primaryOrganizationDisplay ? (
                  <div className={cn(orgPanelClass, "shadow-md shadow-purple-900/10 dark:shadow-purple-900/20")}>
                    <div className={orgPanelAccent} />
                    <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                      <div className="flex min-w-0 items-center gap-4">
                        {primaryOrganizationDisplay.image ? (
                          <img
                            src={primaryOrganizationDisplay.image}
                            alt=""
                            className="h-14 w-14 shrink-0 rounded-xl object-cover ring-2 ring-white/10 shadow-sm"
                          />
                        ) : (
                          <div
                            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-lg font-bold text-white shadow-sm ring-2 ring-white/10"
                          >
                            {orgInitial(primaryOrganizationDisplay.name)}
                          </div>
                        )}
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-wider text-purple-700 dark:text-purple-300">
                              Primary organization
                            </span>
                            <span className="inline-flex items-center gap-1 rounded-full border border-purple-300 bg-purple-100 px-2 py-0.5 text-[10px] font-medium text-purple-700 dark:border-purple-500/30 dark:bg-purple-500/15 dark:text-purple-200">
                              <Lock className="h-3 w-3" aria-hidden />
                              Locked
                            </span>
                          </div>
                          <p className="mt-1 break-words text-base font-semibold text-gray-900 sm:truncate sm:text-lg dark:text-slate-50">
                            {primaryOrganizationDisplay.name}
                          </p>
                        </div>
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        className={changeOrgButtonClass}
                        onClick={openChangePrimaryModal}
                      >
                        Change
                      </Button>
                    </div>
                  </div>
                ) : isPrimaryLocked ? (
                  <div className={emptyStateClass}>
                    No primary organization selected.
                  </div>
                ) : primaryOrganizationDisplay ? (
                  <div className={orgPanelClass}>
                    <div className={orgPanelAccent} />
                    <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                      <div className="flex min-w-0 items-center gap-4">
                        {primaryOrganizationDisplay.image ? (
                          <img
                            src={primaryOrganizationDisplay.image}
                            alt=""
                            className="h-14 w-14 shrink-0 rounded-xl object-cover ring-2 ring-white/10"
                          />
                        ) : (
                          <div
                            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-lg font-bold text-white ring-2 ring-white/10"
                          >
                            {orgInitial(primaryOrganizationDisplay.name)}
                          </div>
                        )}
                        <div className="min-w-0">
                          <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-700 dark:text-purple-300">
                            Primary organization
                          </p>
                          <p className="mt-1 break-words text-base font-semibold text-gray-900 sm:truncate sm:text-lg dark:text-slate-50">
                            {primaryOrganizationDisplay.name}
                          </p>
                        </div>
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        className={changeOrgButtonClass}
                        onClick={openChangePrimaryModal}
                      >
                        Change
                      </Button>
                    </div>
                  </div>
                ) : (
                  <ProfileOrganizationPicker
                    key={primaryPickerExcludeIds.join(",")}
                    variant="primary"
                    triggerId="primary_organization"
                    excludeIds={primaryPickerExcludeIds}
                    primaryValue="__none__"
                    selectedOrganization={undefined}
                    onPrimaryChange={(value, org) => {
                      if (value === "__none__") return
                      if (org) mergeOrg(org)
                      setChangePrimaryOrgId(value)
                      setChangeReasonOption("")
                      setChangePrimaryOpen(true)
                    }}
                    placeholder="Select organization"
                  />
                )}
                {!isPrimaryLocked && !primaryOrganizationDisplay ? (
                  <p className={helperClass}>Select the organization you primarily represent.</p>
                ) : null}
                {errors.primary_organization_id && (
                  <p className="text-sm text-red-400">{errors.primary_organization_id}</p>
                )}
              </div>

              <Dialog open={changePrimaryOpen} onOpenChange={setChangePrimaryOpen}>
                <DialogContent
                  className={cn(
                    "top-[max(1rem,env(safe-area-inset-top))] max-h-[90dvh] w-[calc(100vw-1.5rem)] max-w-[calc(100vw-1.5rem)] translate-y-0 gap-5 overflow-y-auto rounded-2xl border border-gray-200 bg-white p-4 text-gray-900 shadow-2xl dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 sm:top-[50%] sm:max-w-[640px] sm:translate-y-[-50%] sm:p-8",
                    "[&>button]:text-gray-500 [&>button]:hover:text-gray-900 dark:[&>button]:text-gray-400 dark:[&>button]:hover:text-white",
                  )}
                >
                  <DialogHeader className="space-y-2 text-left">
                    <DialogTitle className="text-xl font-bold tracking-normal text-gray-900 dark:text-white">
                      {primaryOrganizationDisplay ? "Change primary organization" : "Set primary organization"}
                    </DialogTitle>
                    <DialogDescription className="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                      {primaryOrganizationDisplay
                        ? "Select your new primary organization and choose a reason for the change."
                        : "Select your primary organization and choose a reason for your choice."}
                    </DialogDescription>
                  </DialogHeader>

                  <div className="space-y-5">
                    <div className="space-y-2">
                      <Label className="text-sm font-semibold text-gray-900 dark:text-white">Primary organization</Label>
                      <ProfileOrganizationPicker
                        key={changePrimaryExcludeIds.join(",")}
                        variant="primary"
                        triggerId="change_primary_organization"
                        excludeIds={changePrimaryExcludeIds}
                        primaryValue={changePrimaryOrgId || "__none__"}
                        selectedOrganization={changePrimarySelectedOrg}
                        onPrimaryChange={(value, org) => {
                          if (org) mergeOrg(org)
                          setChangePrimaryOrgId(value === "__none__" ? "" : value)
                        }}
                        placeholder="Select organization"
                      />
                    </div>

                    <div className="space-y-3">
                      <Label className="text-sm font-semibold text-gray-900 dark:text-white">
                        Reason for change <span className="text-purple-600 dark:text-purple-400">*</span>
                      </Label>
                      <RadioGroup
                        value={changeReasonOption}
                        onValueChange={(value) => setChangeReasonOption(value as PrimaryOrgChangeReasonId)}
                        className="gap-0"
                      >
                        {PRIMARY_ORG_CHANGE_REASONS.map((reason) => (
                          <label
                            key={reason.id}
                            htmlFor={`primary-change-reason-${reason.id}`}
                            className="flex cursor-pointer gap-3 py-2.5"
                          >
                            <RadioGroupItem
                              value={reason.id}
                              id={`primary-change-reason-${reason.id}`}
                            className="mt-1 h-4 w-4 shrink-0 border-gray-400 text-purple-600 data-[state=checked]:border-purple-600 data-[state=checked]:text-purple-600 dark:border-gray-500"
                          />
                            <div className="min-w-0">
                              <p className="text-sm font-medium leading-snug text-gray-900 dark:text-white">{reason.title}</p>
                              <p className="mt-0.5 text-sm leading-snug text-gray-500 dark:text-gray-400">{reason.description}</p>
                            </div>
                          </label>
                        ))}
                      </RadioGroup>
                    </div>

                    <div className="flex gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 dark:border-blue-500/30 dark:bg-blue-500/[0.08]">
                      <Info className="mt-0.5 h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden />
                      <p className="text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        <span className="font-semibold text-gray-900 dark:text-white">Important:</span> Changing your Primary
                        Organization will affect where future supporter benefits, purchases, rewards, and default
                        donations are directed. Previous donations and transactions will remain with the organizations
                        that originally received them.
                      </p>
                    </div>
                  </div>

                  <DialogFooter className="flex-col gap-3 pt-1 sm:flex-row sm:justify-end">
                    <Button
                      type="button"
                      variant="ghost"
                      className="h-10 w-full px-4 text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800 sm:w-auto"
                      onClick={() => setChangePrimaryOpen(false)}
                    >
                      Cancel
                    </Button>
                    <Button
                      type="button"
                      disabled={
                        changingPrimary ||
                        !changePrimaryOrgId ||
                        !changeReasonOption ||
                        (primaryOrgIdNum !== null && Number(changePrimaryOrgId) === primaryOrgIdNum)
                      }
                      className="h-10 w-full rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 px-6 text-sm font-medium text-white shadow-none hover:from-purple-700 hover:to-blue-700 disabled:opacity-50 sm:w-auto"
                      onClick={submitPrimaryOrganizationChange}
                    >
                      {changingPrimary ? "Saving..." : "Save change"}
                    </Button>
                  </DialogFooter>
                </DialogContent>
              </Dialog>

              <div className="space-y-3">
                <Label className={labelClass}>Secondary Organizations</Label>
                <p className={helperClass}>Select additional organizations you are affiliated with.</p>

                <ProfileOrganizationPicker
                  key={secondaryExcludeIds.join(",")}
                  variant="secondary-add"
                  triggerId="secondary-org-add"
                  excludeIds={secondaryExcludeIds}
                  placeholder="Add organization"
                  onSecondaryAdd={(org) => {
                    mergeOrg(org)
                    addSecondaryOrganization(org.id)
                  }}
                />

                {selectedSecondaryOrganizations.length > 0 ? (
                  <div className="space-y-3">
                    {selectedSecondaryOrganizations.map((org) => (
                      <div key={org.id} className={orgPanelClass}>
                        <div className={orgPanelAccent} />
                        <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                          <div className="flex min-w-0 items-center gap-4">
                            {org.image ? (
                              <img
                                src={org.image}
                                alt=""
                                className="h-14 w-14 shrink-0 rounded-xl object-cover ring-2 ring-white/10"
                              />
                            ) : (
                              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-lg font-bold text-white ring-2 ring-white/10">
                                {orgInitial(org.name)}
                              </div>
                            )}
                            <div className="min-w-0">
                              <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-700 dark:text-purple-300">
                                Secondary organization
                              </p>
                              <p className="mt-1 break-words text-base font-semibold text-gray-900 sm:truncate sm:text-lg dark:text-slate-50">{org.name}</p>
                            </div>
                          </div>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 shrink-0 self-end text-gray-500 hover:bg-gray-100 hover:text-red-600 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-red-300 sm:self-auto"
                            onClick={() => removeSecondaryOrganization(org.id)}
                            aria-label={`Remove ${org.name}`}
                          >
                            <X className="h-4 w-4" />
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className={emptyStateClass}>
                    No secondary organizations added yet.
                  </div>
                )}

                {errors.secondary_organization_ids && (
                  <p className="text-sm text-red-400">{errors.secondary_organization_ids}</p>
                )}
              </div>

              <div className="rounded-lg border border-dashed border-purple-300/70 bg-purple-50/50 p-4 text-center dark:border-purple-500/40 dark:bg-purple-950/20">
                <Link
                  href={route("organizations")}
                  className="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-purple-700 hover:text-purple-800 hover:underline dark:text-purple-300 dark:hover:text-purple-200"
                >
                  <Plus className="h-4 w-4" aria-hidden />
                  Request to Join Organization
                </Link>
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                  Can&apos;t find your organization? Browse organizations and connect from the directory.
                </p>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* 5. Preferences */}
        <Card className={cardClass}>
          <CardHeader className={cardHeaderPad}>
            {sectionTitle(<Monitor className="h-5 w-5" />, 5, "Preferences (Global Settings)")}
          </CardHeader>
          <CardContent className={cn(cardContentPad, "space-y-3")}>
            <Label className={labelClass}>Scene View (Theme)</Label>
            <div className="mt-1 grid grid-cols-1 gap-2 min-[420px]:grid-cols-3 sm:flex sm:flex-wrap">
              {(
                [
                  ["system", <Monitor key="ic-sys" className="h-4 w-4" aria-hidden />, "Auto (System)"],
                  ["light", <Sun key="ic-light" className="h-4 w-4" aria-hidden />, "Light"],
                  ["dark", <Moon key="ic-dark" className="h-4 w-4" aria-hidden />, "Dark"],
                ] as const
              ).map(([mode, icon, label]) => (
                <button
                  key={mode}
                  type="button"
                  onClick={() => setPreferredTheme(mode)}
                  className={cn(
                    "inline-flex w-full min-w-0 items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition-all min-[420px]:w-auto sm:min-w-[8rem] sm:px-4",
                    data.preferred_theme === mode
                      ? "border-transparent bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md"
                      : "border-gray-300 bg-white text-gray-700 hover:border-purple-400/60 hover:bg-purple-50 dark:border-gray-600 dark:bg-gray-800/80 dark:text-gray-200 dark:hover:border-purple-500/40 dark:hover:bg-gray-800",
                  )}
                >
                  {icon}
                  {label}
                </button>
              ))}
            </div>
            <p className={helperClass}>These settings apply across the entire BIU platform.</p>
            {errors.preferred_theme && <p className="text-sm text-red-400">{errors.preferred_theme}</p>}

            <div className="mt-6 border-t border-gray-200 pt-5 dark:border-gray-700/60">
              <div className="flex items-start gap-3">
                <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-100 to-blue-100 text-purple-600 dark:from-purple-600/20 dark:to-blue-600/20 dark:text-purple-300">
                  <MapPin className="h-4 w-4" aria-hidden />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                      <p className="font-medium text-gray-900 dark:text-gray-100">Nearby organization alerts</p>
                      <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Alerts are on when you use the app. Tap below to allow location when prompted — nearby alerts
                        need a separate location permission from notifications.
                      </p>
                      {locationPermission !== "granted" ? (
                        <p className="mt-2 text-xs text-amber-700 dark:text-amber-300/90">
                          Location:{" "}
                          {locationPermission === "denied"
                            ? "blocked"
                            : locationPermission === "unsupported"
                              ? "unavailable on this device"
                              : "not allowed yet"}
                          {isStandalonePwa() ? (
                            <>
                              {" "}
                              — installed apps usually only show Notifications under App permissions; enable location in
                              Chrome for believeinunity.org (lock icon → Location → Allow).
                            </>
                          ) : null}
                        </p>
                      ) : (
                        <p className="mt-2 text-xs text-emerald-700 dark:text-emerald-300/90">Location: allowed</p>
                      )}
                    </div>
                    <label className="relative inline-flex shrink-0 cursor-pointer items-center self-start sm:self-center">
                      <input
                        type="checkbox"
                        className="peer sr-only"
                        checked={data.proximity_notifications_enabled !== false}
                        onChange={(e) => {
                          const enabled = e.target.checked
                          setData("proximity_notifications_enabled", enabled)
                          if (enabled) {
                            requestLocationPermissionPrompt()
                            void getGeolocationPermissionState().then(setLocationPermission)
                          }
                        }}
                      />
                      <span className="peer h-6 w-11 rounded-full bg-gray-300 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all peer-checked:bg-gradient-to-r peer-checked:from-purple-600 peer-checked:to-blue-600 peer-checked:after:translate-x-full dark:bg-gray-600" />
                    </label>
                  </div>
                  {errors.proximity_notifications_enabled ? (
                    <p className="mt-2 text-sm text-red-400">{errors.proximity_notifications_enabled}</p>
                  ) : null}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <BrpParticipationHint module="profile_completion" variant="alert" className="mb-4" />

        <div className="sticky bottom-[calc(5.25rem+env(safe-area-inset-bottom))] z-10 -mx-1 flex flex-col-reverse gap-3 border-t border-gray-200 bg-white/95 px-1 py-3 backdrop-blur-sm dark:border-gray-700 dark:bg-gray-900/95 sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:px-0 sm:py-0 sm:backdrop-blur-none sm:flex-row sm:justify-end lg:bottom-auto">
          <Button
            type="button"
            variant="outline"
            onClick={handleCancel}
            disabled={processing}
            className="w-full border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:w-auto"
          >
            <X className="mr-2 h-4 w-4" />
            Cancel
          </Button>
          <Button
            type="submit"
            disabled={processing}
            className="w-full bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700 sm:w-auto"
          >
            <Save className="mr-2 h-4 w-4" />
            {processing ? "Saving..." : "Save Changes"}
          </Button>
        </div>
      </form>
        </>
      )}

      <AlertDialog
        open={leaveConfirmOpen}
        onOpenChange={(open) => {
          if (!open) {
            if (processing) return
            handleStayOnPage()
            return
          }
          setLeaveConfirmOpen(true)
        }}
      >
        <AlertDialogContent className="max-w-[22rem] gap-0 overflow-hidden border-0 bg-transparent p-0 shadow-none sm:rounded-3xl">
          <div className="relative overflow-hidden rounded-3xl border border-white/60 bg-white shadow-[0_24px_80px_-20px_rgba(79,70,229,0.45)] dark:border-gray-700/80 dark:bg-gray-950">
            {/* Brand atmosphere */}
            <div
              aria-hidden
              className="pointer-events-none absolute inset-x-0 top-0 h-40 bg-[radial-gradient(ellipse_at_top,_rgba(147,51,234,0.28),_transparent_60%),radial-gradient(ellipse_at_80%_20%,_rgba(37,99,235,0.22),_transparent_55%)]"
            />
            <div
              aria-hidden
              className="pointer-events-none absolute -right-10 -top-10 h-36 w-36 rounded-full bg-gradient-to-br from-purple-500/30 to-blue-500/20 blur-2xl"
            />

            <div className="relative px-5 pb-5 pt-7 sm:px-6">
              <div className="mb-5 flex items-start gap-4">
                <div className="relative shrink-0">
                  <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-lg shadow-purple-500/30">
                    <User className="h-7 w-7" strokeWidth={1.75} />
                  </div>
                  <span className="absolute -bottom-1 -right-1 flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-amber-400 text-amber-950 shadow-sm dark:border-gray-950">
                    <PenLine className="h-3.5 w-3.5" strokeWidth={2.5} />
                  </span>
                </div>
                <AlertDialogHeader className="space-y-1.5 text-left">
                  <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-purple-600 dark:text-purple-400">
                    Almost gone
                  </p>
                  <AlertDialogTitle className="text-xl font-bold leading-snug tracking-tight text-gray-900 dark:text-white">
                    Your profile edits are still open
                  </AlertDialogTitle>
                  <AlertDialogDescription className="text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    Leave now and those changes won’t be kept. Save first if you want them on your
                    profile.
                  </AlertDialogDescription>
                </AlertDialogHeader>
              </div>

              <AlertDialogFooter className="mt-1 flex flex-col gap-2.5 space-x-0 sm:flex-col sm:space-x-0">
                <button
                  type="button"
                  disabled={processing}
                  onClick={handleSaveAndLeave}
                  className="group relative flex w-full items-center gap-3 overflow-hidden rounded-2xl bg-gradient-to-r from-purple-600 to-blue-600 px-4 py-3.5 text-left text-white shadow-md shadow-purple-500/25 transition hover:from-purple-500 hover:to-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-400 focus-visible:ring-offset-2 disabled:opacity-60 dark:focus-visible:ring-offset-gray-950"
                >
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/15 backdrop-blur-sm">
                    <Save className="h-5 w-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-semibold">
                      {processing ? "Saving…" : "Save & continue"}
                    </span>
                    <span className="block text-xs text-white/75">Keep your updates, then go</span>
                  </span>
                  <ArrowRight className="h-4 w-4 shrink-0 opacity-80 transition group-hover:translate-x-0.5" />
                </button>

                <AlertDialogCancel
                  disabled={processing}
                  onClick={handleStayOnPage}
                  className="mt-0 flex h-auto w-full items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50/80 px-4 py-3.5 text-left font-normal text-gray-900 shadow-none hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-purple-400 dark:border-gray-700 dark:bg-gray-900/60 dark:text-gray-100 dark:hover:bg-gray-800 dark:hover:text-white"
                >
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-purple-600 shadow-sm dark:bg-gray-800 dark:text-purple-400">
                    <PenLine className="h-5 w-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-semibold">Keep editing</span>
                    <span className="block text-xs text-gray-500 dark:text-gray-400">
                      Stay on this page
                    </span>
                  </span>
                </AlertDialogCancel>

                <button
                  type="button"
                  disabled={processing}
                  onClick={handleLeaveWithoutSaving}
                  className="flex w-full items-center justify-center gap-2 rounded-xl px-3 py-2.5 text-sm text-gray-500 transition hover:bg-red-50 hover:text-red-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:opacity-60 dark:text-gray-400 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                >
                  <LogOut className="h-3.5 w-3.5" />
                  Leave without saving
                </button>
              </AlertDialogFooter>
            </div>
          </div>
        </AlertDialogContent>
      </AlertDialog>
    </ProfileLayout>
  )
}

"use client"

import { useEffect, useRef, useState } from "react"
import { Search, ChevronLeft, ChevronRight, Heart, MapPin, BadgeCheck, Gift, Users, TrendingUp } from "lucide-react"
import { EinVerifiedBadge, isEinVerifiedOrg } from "@/components/frontend/organization/EinVerifiedBadge"
import { Button } from "@/components/frontend/ui/button"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/frontend/ui/select"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/frontend/ui/avatar"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/frontend/ui/popover"
import { motion } from "framer-motion"
import { router, usePage, Link } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import SearchSection from "@/components/frontend/SearchSection"
import { CauseBadge } from "@/components/frontend/cause-badge"
import SignInPopup from "@/components/frontend/SignInPopup"
import { PageHead } from "@/components/frontend/PageHead"
import toast from "react-hot-toast"
import InviteOrganizationPopup from "@/components/frontend/InviteOrganizationPopup"

type PrimaryActionCategory = {
  id: number
  name: string
  slug: string
}

interface Organization {
  id: number
  ein: string
  name: string
  city: string
  state: string
  zip: string
  ntee_code?: string
  ntee_code_raw?: string
  ntee_category?: string
  ntee_category_description?: string
  classification?: string
  created_at: string
  is_registered?: boolean
  is_favorited?: boolean
  verified?: boolean
  has_ein?: boolean
  ein_verified?: boolean
  logo_url?: string | null
  primary_action_categories?: PrimaryActionCategory[]
}

interface PageProps {
  organizations: {
    data: Organization[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number
    to: number
  }
  filters: {
    search?: string
    category?: string
    category_description?: string
    state?: string
    city?: string
    zip?: string
    cause_id?: string | null
    status?: string
    sort?: string
    per_page?: string
  }
  filterOptions: {
    categories: string[]
    states: string[]
    cities: string[]
    categoryDescriptions: string[]
    causes?: { id: number; name: string }[]
  }
  myCauses?: PrimaryActionCategory[]
  hasActiveFilters: boolean
  auth?: { user?: { id: number; name: string; email: string; organization?: { ein: string } } | null }
}

const sortOptions = [
  { value: "relevance", label: "Most Relevant" },
  { value: "name", label: "Name (A-Z)" },
  { value: "state", label: "State" },
  { value: "city", label: "City" },
]

const perPageOptions = [6, 12, 24, 48]

declare global {
  function route(name: string, params?: number | string): string
}

function initialsFromName(name: string): string {
  const parts = name
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (parts.length === 0) return "?"
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ""}${parts[parts.length - 1][0] ?? ""}`.toUpperCase()
}

/** Public-disk profile/org images — mirrors backend `publicStorageHref` and user-show.tsx conventions */
function avatarPublicImgSrc(raw: string | null | undefined): string | undefined {
  if (raw === null || raw === undefined) return undefined
  const s = String(raw).trim()
  if (s === "") return undefined
  if (s.startsWith("http://") || s.startsWith("https://") || s.startsWith("//")) return s
  if (s.startsWith("/storage/")) return s
  let path = s.replace(/^\/+/, "")
  if (path.startsWith("storage/")) path = path.slice("storage/".length)
  return `/storage/${path}`
}

/** Only Primary Action Categories (org profile causes) — no NTEE/classification fallbacks. */
function getDisplayCauses(org: Organization): PrimaryActionCategory[] {
  const fromPac = org.primary_action_categories ?? []
  const seen = new Set<number>()
  return fromPac.filter((c) => {
    if (seen.has(c.id)) return false
    seen.add(c.id)
    return true
  })
}

function OrgNameMeta({ org }: { org: Organization }) {
  const einVerified = isEinVerifiedOrg(org)
  return (
    <div className="min-w-0">
      <p className="break-words font-semibold leading-snug text-slate-900 dark:text-white">{org.name || "—"}</p>
      <div className="mt-1 flex flex-wrap items-center gap-2">
        {org.is_registered && (
          <p className="inline-flex items-center gap-1 text-xs text-sky-700 dark:text-sky-300">
            <BadgeCheck className="h-3.5 w-3.5" aria-hidden />
            Registered on BIU
          </p>
        )}
        <EinVerifiedBadge verified={einVerified} size="sm" />
      </div>
    </div>
  )
}

export default function OrganizationsPage() {
  const { organizations, filters, filterOptions, hasActiveFilters, auth, myCauses = [] } = usePage<PageProps>().props

  const initialSort =
    filters.sort && sortOptions.some((o) => o.value === filters.sort) ? filters.sort : "relevance"
  const [sortBy, setSortBy] = useState(initialSort)
  const [resultsPerPage, setResultsPerPage] = useState(Number(filters.per_page) || 6)

  useEffect(() => {
    const nextSort =
      filters.sort && sortOptions.some((o) => o.value === filters.sort) ? filters.sort : "relevance"
    setSortBy(nextSort)
    setResultsPerPage(Number(filters.per_page) || 6)
  }, [filters.sort, filters.per_page])
  const [isLoading, setIsLoading] = useState(false)
  const [showSignInPopup, setShowSignInPopup] = useState(false)
  const [favoritingId, setFavoritingId] = useState<number | null>(null)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [inviteOrg, setInviteOrg] = useState<{ id: number; name: string; ein?: string } | null>(null)
  const organizationsTableRef = useRef<HTMLDivElement>(null)

  const isAuthenticated = !!auth?.user
  const scrollToOrganizationsTable = () => {
    organizationsTableRef.current?.scrollIntoView({ behavior: "smooth", block: "start" })
  }
  const openInviteFor = (org: Organization) => {
    if (!isAuthenticated) {
      setShowSignInPopup(true)
      return
    }
    setInviteOrg({ id: org.id, name: org.name, ein: org.ein })
    setInviteOpen(true)
  }

  const handleSearch = (params: Record<string, string>) => {
    const hasSearchQuery = params.search?.trim()
    const hasFilters =
      (params.category && params.category !== "All Categories") ||
      (params.category_description && params.category_description !== "All Descriptions") ||
      (params.state && params.state !== "All States") ||
      (params.city && params.city !== "All Cities") ||
      Boolean(params.zip?.trim()) ||
      Boolean(params.cause_id && params.cause_id !== "0") ||
      (params.status && params.status !== "All Status")

    if (!isAuthenticated && (hasSearchQuery || hasFilters)) {
      setShowSignInPopup(true)
      return
    }

    setIsLoading(true)
    const searchParams = new URLSearchParams()
    const allParams = { ...params, sort: sortBy, per_page: resultsPerPage.toString() }
    Object.entries(allParams).forEach(([key, value]) => {
      if (
        value &&
        value !== "All Categories" &&
        value !== "All States" &&
        value !== "All Cities" &&
        value !== "All Descriptions" &&
        value !== "All Status"
      )
        searchParams.set(key, value)
    })
    const url = searchParams.toString() ? `/organizations?${searchParams.toString()}` : "/organizations"
    router.visit(url, { preserveState: true, preserveScroll: true, onFinish: () => setIsLoading(false) })
  }

  const clearFilters = () => {
    setSortBy("relevance")
    setResultsPerPage(6)
    router.visit("/organizations", { preserveState: true, preserveScroll: true })
  }

  const handlePageChange = (page: number) => {
    const params = new URLSearchParams(window.location.search)
    params.set("page", page.toString())
    router.visit(`/organizations?${params.toString()}`, { preserveState: true, preserveScroll: false })
    window.scrollTo({ top: 0, behavior: "smooth" })
  }

  const handleResultsPerPageChange = (value: string) => {
    const num = Math.min(50, Math.max(1, Number(value) || 6))
    setResultsPerPage(num)
    const params = new URLSearchParams(window.location.search)
    params.set("per_page", num.toString())
    params.delete("page")
    router.visit(`/organizations?${params.toString()}`, { preserveState: true, preserveScroll: true })
  }

  const handleSortChange = (value: string) => {
    setSortBy(value)
    const params = new URLSearchParams(window.location.search)
    params.set("sort", value)
    params.delete("page")
    router.visit(`/organizations?${params.toString()}`, { preserveState: true, preserveScroll: true })
  }

  const handleToggleFavorite = (org: Organization, e: React.MouseEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (favoritingId !== null) return
    if (!isAuthenticated) {
      setShowSignInPopup(true)
      return
    }
    setFavoritingId(org.id)
    router.post(route("organizations.toggle-favorite", org.id), { toggle_favorite_context: "excel" }, {
      preserveScroll: true,
      preserveState: false,
      onSuccess: page => {
        const flash = (page.props as { flash?: { error?: string } }).flash
        if (flash?.error) {
          toast.error(flash.error)
        }
        setFavoritingId(null)
      },
      onError: () => {
        toast.error("Following is for supporter accounts only.")
        setFavoritingId(null)
      },
      onFinish: () => setFavoritingId(null),
    })
  }

  const getPageNumbers = () => {
    const pages: (number | "ellipsis")[] = []
    const cur = organizations.current_page
    const last = organizations.last_page
    const show = 5
    let start = Math.max(1, cur - Math.floor(show / 2))
    let end = Math.min(last, start + show - 1)
    if (end - start + 1 < show) start = Math.max(1, end - show + 1)
    if (start > 1) {
      pages.push(1)
      if (start > 2) pages.push("ellipsis")
    }
    for (let i = start; i <= end; i++) pages.push(i)
    if (end < last) {
      if (end < last - 1) pages.push("ellipsis")
      pages.push(last)
    }
    return pages
  }

  return (
    <FrontendLayout>
      <PageHead
        title="Organizations"
        description="Search verified non-profit organizations. Filter by cause, location, and more."
      />
      <SignInPopup isOpen={showSignInPopup} onClose={() => setShowSignInPopup(false)} onSignIn={() => setShowSignInPopup(false)} />
      {inviteOrg ? (
        <InviteOrganizationPopup isOpen={inviteOpen} onClose={() => setInviteOpen(false)} organization={inviteOrg} />
      ) : null}

      <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div className="relative border-b border-slate-200/80 bg-gradient-to-b from-white via-slate-50 to-slate-100 dark:border-white/5 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950">
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,_rgba(59,130,246,0.08),_transparent_55%),radial-gradient(ellipse_at_bottom,_rgba(147,51,234,0.06),_transparent_55%)] dark:bg-[radial-gradient(ellipse_at_top,_rgba(59,130,246,0.18),_transparent_55%),radial-gradient(ellipse_at_bottom,_rgba(147,51,234,0.16),_transparent_55%)]" />
          <div className="relative container mx-auto px-4 py-7">
            <motion.header
              initial={{ opacity: 0, y: 16 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.4 }}
              className="mb-4 max-w-3xl"
            >
              <h1 className="text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white">
                Find Causes. <span className="text-violet-700 dark:text-violet-300">Support What Matters.</span>
              </h1>
              <p className="mt-3 text-base text-slate-600 dark:text-slate-300">
                Search our directory of 1.8M+ nonprofits and help create stronger communities.
              </p>
            </motion.header>

            <div className="grid gap-6 lg:grid-cols-[1fr_360px] lg:items-start">
              <div>
                <SearchSection
                  variant="directory"
                  autoSearchDebounceMs={450}
                  showCauseFilter={true}
                  showCategoryFilter={true}
                  showCategoryDescriptionFilter={false}
                  showQuickFilters={false}
                  showZipFilter={false}
                  showStatusFilter={true}
                  filters={filters}
                  filterOptions={filterOptions}
                  hasActiveFilters={hasActiveFilters}
                  onSearch={handleSearch}
                  onClearFilters={clearFilters}
                  isLoading={isLoading}
                  popularCauses={myCauses}
                />
              </div>

              <aside className="hidden lg:row-span-2 lg:block lg:sticky lg:top-20 lg:z-20 lg:max-h-[calc(100vh-5.5rem)] lg:overflow-y-auto lg:self-start">
                <div className="space-y-4">
                  <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-lg shadow-slate-200/40 backdrop-blur-sm dark:border-white/10 dark:bg-slate-950/70 dark:shadow-none">
                  <div className="flex items-start gap-3">
                    <div className="flex h-11 w-11 flex-none items-center justify-center rounded-2xl bg-gradient-to-r from-violet-600 to-indigo-600 shadow-lg shadow-violet-600/25 dark:shadow-none">
                      <Gift className="h-6 w-6 text-white" />
                    </div>
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-900 dark:text-white">Earn Believe Points for Helping Organizations Join</p>
                      <p className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                        When you invite a nonprofit to Believe In Unity and they join, you earn{" "}
                        <span className="font-medium text-slate-800 dark:text-slate-200">Believe Points</span> on the
                        schedule below.
                      </p>
                    </div>
                  </div>

                  <div className="mt-4 rounded-xl border border-slate-200/80 bg-slate-50/90 px-3 py-3 dark:border-white/10 dark:bg-white/5">
                    <p className="text-xs font-semibold text-slate-900 dark:text-white">Believe Points schedule</p>
                    <p className="mt-1 text-[11px] leading-snug text-slate-600 dark:text-slate-400">
                      Monthly credits after the organization joins BIU (24 months total).
                    </p>
                    <ul className="mt-3 space-y-3 border-t border-slate-200/80 pt-3 dark:border-white/10">
                      <li className="flex gap-3">
                        <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-600 text-[10px] font-bold text-white">
                          1
                        </span>
                        <div className="min-w-0">
                          <p className="text-xs font-semibold text-slate-900 dark:text-white">Months 1–12</p>
                          <p className="text-sm font-semibold text-violet-700 dark:text-violet-300">10 Believe Points / month</p>
                        </div>
                      </li>
                      <li className="flex gap-3">
                        <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white">
                          2
                        </span>
                        <div className="min-w-0">
                          <p className="text-xs font-semibold text-slate-900 dark:text-white">Months 13–24</p>
                          <p className="text-sm font-semibold text-indigo-700 dark:text-indigo-300">5 Believe Points / month</p>
                        </div>
                      </li>
                    </ul>
                  </div>

                  <Button
                    type="button"
                    className="mt-4 h-11 w-full bg-gradient-to-r from-violet-600 to-indigo-600 font-semibold text-white hover:from-violet-500 hover:to-indigo-500"
                    onClick={scrollToOrganizationsTable}
                  >
                    Invite an Organization
                  </Button>

                  <p className="mt-3 text-center text-xs text-slate-600 dark:text-slate-300">
                    Learn more about Believe Points →
                  </p>
                </div>
                
                  <div className="rounded-2xl border border-slate-200/80 bg-white/95 p-5 shadow-lg shadow-slate-200/40 backdrop-blur-sm dark:border-white/10 dark:bg-slate-950/60 dark:shadow-none">
                    <h3 className="text-base font-semibold text-slate-900 dark:text-white">Why Invite Organizations?</h3>
                    <div className="mt-4 space-y-4">
                      <div className="flex items-start gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-white/5 dark:text-slate-200">
                          <TrendingUp className="h-5 w-5" />
                        </div>
                        <div>
                          <p className="text-sm font-semibold text-slate-900 dark:text-white">Strengthen Communities</p>
                          <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">More organizations on BIU means more impact.</p>
                        </div>
                      </div>
                      <div className="flex items-start gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-white/5 dark:text-slate-200">
                          <Users className="h-5 w-5" />
                        </div>
                        <div>
                          <p className="text-sm font-semibold text-slate-900 dark:text-white">Grow the Movement</p>
                          <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Help unite organizations and supporters on one platform.</p>
                        </div>
                      </div>
                      <div className="flex items-start gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-white/5 dark:text-slate-200">
                          <Gift className="h-5 w-5" />
                        </div>
                        <div>
                          <p className="text-sm font-semibold text-slate-900 dark:text-white">Earn Believe Points</p>
                          <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                            Same Believe Points schedule as in the sidebar card above.
                          </p>
                        </div>
                      </div>
                    </div>
                    <p className="mt-4 text-center text-xs text-slate-600 dark:text-slate-300">
                      Learn how Believe Points work →
                    </p>
                  </div>
                </div>
              </aside>
              
            <div
              ref={organizationsTableRef}
              id="organizations-directory-results"
              className="mt-3 scroll-mt-24 lg:mt-0 lg:col-start-1 lg:row-start-2"
            >
          {/* Results bar */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.1 }}
            className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2"
          >
            <p className="text-sm sm:text-base text-slate-600 dark:text-slate-400">
              Showing{" "}
              <span className="font-semibold text-slate-900 dark:text-white">
                {organizations.from ?? 0}-{organizations.to ?? 0}
              </span>{" "}
              of{" "}
              <span className="font-semibold text-slate-900 dark:text-white">{organizations.total.toLocaleString()}</span> organizations
            </p>
            <div className="flex flex-wrap items-center gap-3">
              <div className="flex items-center gap-2">
                <label className="whitespace-nowrap text-sm text-slate-600 dark:text-slate-400">Results per page</label>
                <Select
                  value={String(resultsPerPage)}
                  onValueChange={handleResultsPerPageChange}
                >
                  <SelectTrigger className="h-9 w-[72px] border-slate-200 bg-white text-sm text-slate-900 dark:border-white/10 dark:bg-slate-900/80 dark:text-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent className="border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900 dark:text-white">
                    {perPageOptions.map((n) => (
                      <SelectItem key={n} value={String(n)} className="focus:bg-slate-100 dark:focus:bg-slate-800">
                        {n}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-center gap-2">
                <label className="whitespace-nowrap text-sm text-slate-600 dark:text-slate-400">Sort by</label>
                <Select value={sortBy} onValueChange={handleSortChange}>
                  <SelectTrigger className="h-9 w-[200px] border-slate-200 bg-white text-sm text-slate-900 dark:border-white/10 dark:bg-slate-900/80 dark:text-white">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent className="border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900 dark:text-white">
                    {sortOptions.map((opt) => (
                      <SelectItem key={opt.value} value={opt.value} className="focus:bg-slate-100 dark:focus:bg-slate-800">
                        {opt.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </motion.div>

          {/* Table (desktop) / Card list (mobile) */}
          {organizations.data.length > 0 ? (
            <>
              {/* Desktop table */}
              <div className="hidden overflow-hidden rounded-2xl border border-slate-200/90 bg-white shadow-lg shadow-slate-200/50 backdrop-blur-sm lg:block dark:border-white/10 dark:bg-slate-900/40 dark:shadow-black/40">
                <table className="w-full max-w-full table-auto border-collapse text-left text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-slate-900/80">
                      <th className="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                        Organization
                      </th>
                      <th className="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                        Cause(s)
                      </th>
                      <th className="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                        Category
                      </th>
                      <th className="px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                        Location
                      </th>
                      <th className="whitespace-nowrap px-4 py-3 font-semibold text-slate-700 dark:text-slate-200">
                        BIU Status
                      </th>
                      <th className="whitespace-nowrap px-4 py-3 font-semibold text-slate-700 dark:text-slate-200 text-right">
                        Action
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {organizations.data.map((org) => {
                      const causes = getDisplayCauses(org)
                      const maxCauses = 2
                      const visibleCauses = causes.slice(0, maxCauses)
                      const overflowCauses = Math.max(0, causes.length - maxCauses)
                      const avatarSrc = avatarPublicImgSrc(org.logo_url ?? null)
                      const categoryLine = org.ntee_category_description?.trim() || org.ntee_category?.trim() || "—"
                      return (
                        <tr
                          key={org.id}
                          className="border-b border-slate-100 transition-colors hover:bg-slate-50/80 dark:border-white/5 dark:hover:bg-white/[0.03]"
                        >
                          <td className="min-w-0 px-4 py-4">
                            <div className="flex min-w-0 items-center gap-3">
                              <Avatar className="h-12 w-12 border border-slate-200 bg-slate-100 dark:border-white/10 dark:bg-slate-800">
                                {avatarSrc ? (
                                  <AvatarImage
                                    src={avatarSrc}
                                    alt={org.name || ""}
                                    className="object-cover"
                                  />
                                ) : null}
                                <AvatarFallback className="bg-gradient-to-br from-blue-600 to-purple-600 text-sm font-semibold text-white">
                                  {initialsFromName(org.name || "?")}
                                </AvatarFallback>
                              </Avatar>
                              <OrgNameMeta org={org} />
                            </div>
                          </td>
                          <td className="min-w-0 align-middle px-4 py-4">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                              {causes.length > 0 ? (
                                <>
                                  {visibleCauses.map((c) => <CauseBadge key={`${org.id}-${c.id}-${c.slug}`} c={c} />)}
                                  {overflowCauses > 0 && (
                                    <Popover>
                                      <PopoverTrigger asChild>
                                        <button
                                          type="button"
                                          aria-label={`Show all causes (${causes.length})`}
                                          className="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-200/60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/60"
                                        >
                                          +{overflowCauses}
                                        </button>
                                      </PopoverTrigger>
                                      <PopoverContent
                                        align="start"
                                        className="w-[min(100vw-2rem,22rem)] rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-xl shadow-slate-200/60 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100 dark:shadow-none"
                                        onOpenAutoFocus={(e) => e.preventDefault()}
                                      >
                                        <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-3 py-2.5 dark:border-white/10">
                                          <div className="min-w-0">
                                            <p className="truncate text-xs font-semibold">All causes</p>
                                            <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{org.name}</p>
                                          </div>
                                          <span className="shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
                                            {causes.length}
                                          </span>
                                        </div>
                                        <div className="max-h-44 overflow-y-auto px-3 py-3">
                                          <div className="flex flex-wrap gap-2">
                                            {causes.map((c) => (
                                              <CauseBadge key={`all-${org.id}-${c.id}-${c.slug}`} c={c} />
                                            ))}
                                          </div>
                                        </div>
                                      </PopoverContent>
                                    </Popover>
                                  )}
                                </>
                              ) : (
                                <span className="text-slate-400 dark:text-slate-500">—</span>
                              )}
                            </div>
                          </td>
                          <td className="min-w-0 px-4 py-4 text-slate-700 dark:text-slate-300">
                            <span className="text-sm text-slate-700 dark:text-slate-300">{categoryLine}</span>
                          </td>
                          <td className="min-w-0 px-4 py-4 text-slate-700 dark:text-slate-300">
                            <span className="inline-flex items-center gap-1.5">
                              <MapPin className="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden />
                              <span className="truncate">
                                {[org.city, org.state].filter(Boolean).join(", ") || "—"}
                              </span>
                            </span>
                          </td>
                          <td className="px-4 py-4 whitespace-nowrap">
                            {org.is_registered ? (
                              <span className="inline-flex items-center gap-2 text-slate-700 dark:text-slate-200">
                                <span className="h-2 w-2 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.6)]" />
                                Joined BIU
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                <span className="h-2 w-2 rounded-full bg-slate-400" />
                                Not Yet Joined
                              </span>
                            )}
                          </td>
                          <td className="min-w-[12rem] px-4 py-4 text-right align-top">
                            <div className="ml-auto flex w-full max-w-[14rem] flex-col gap-2">
                              {org.is_registered ? (
                                <>
                                  <Button
                                    asChild
                                    size="sm"
                                    className="h-8 w-full justify-center bg-gradient-to-r from-violet-600 to-indigo-600 text-white hover:from-violet-500 hover:to-indigo-500"
                                  >
                                    <Link href={`/organizations/${org.id}`}>View Details</Link>
                                  </Button>
                                  <div className="flex w-full gap-2">
                                    <Button
                                      type="button"
                                      variant="outline"
                                      size="sm"
                                      className={`h-8 min-w-0 flex-1 border-violet-300 text-violet-700 hover:bg-violet-50 dark:border-violet-500/50 dark:text-violet-200 dark:hover:bg-violet-500/10 ${org.is_favorited ? "bg-violet-50 dark:bg-violet-950/30" : "bg-transparent"}`}
                                      onClick={(e) => handleToggleFavorite(org, e)}
                                      disabled={favoritingId === org.id}
                                      aria-pressed={org.is_favorited}
                                    >
                                      <Heart className={`mr-1 h-4 w-4 shrink-0 ${org.is_favorited ? "fill-current" : ""}`} />
                                      {org.is_favorited ? "Following" : "Follow"}
                                    </Button>
                                    <Button
                                      asChild
                                      variant="outline"
                                      size="sm"
                                      className="h-8 min-w-0 flex-1 justify-center border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                                    >
                                      <Link href={`/organizations/${org.id}`}>Donate</Link>
                                    </Button>
                                  </div>
                                </>
                              ) : (
                                <>
                                  <Button
                                    type="button"
                                    size="sm"
                                    className="h-8 w-full justify-center bg-gradient-to-r from-violet-600 to-indigo-600 text-white hover:from-violet-500 hover:to-indigo-500"
                                    onClick={() => openInviteFor(org)}
                                  >
                                    Invite to Join
                                  </Button>
                                  <Button
                                    asChild
                                    variant="outline"
                                    size="sm"
                                    className="h-8 w-full justify-center border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                                  >
                                    <Link href={`/organizations/${org.id}`}>View Details</Link>
                                  </Button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>

              {/* Mobile card list */}
              <div className="lg:hidden space-y-3">
                {organizations.data.map((org) => {
                  const causes = getDisplayCauses(org)
                  const maxCauses = 2
                  const visibleCauses = causes.slice(0, maxCauses)
                  const overflowCauses = Math.max(0, causes.length - maxCauses)
                  const avatarSrc = avatarPublicImgSrc(org.logo_url ?? null)
                  const categoryLine = org.ntee_category_description?.trim() || org.ntee_category?.trim() || "—"
                  return (
                    <motion.div
                      key={org.id}
                      initial={{ opacity: 0, y: 8 }}
                      animate={{ opacity: 1, y: 0 }}
                      className="rounded-2xl border border-slate-200 bg-white p-4 shadow-md shadow-slate-200/60 backdrop-blur-sm dark:border-white/10 dark:bg-slate-900/45 dark:shadow-black/30"
                    >
                      <div className="flex items-center gap-3">
                        <Avatar className="h-11 w-11 shrink-0 border border-slate-200 bg-slate-100 dark:border-white/10 dark:bg-slate-800">
                          {avatarSrc ? (
                            <AvatarImage
                              src={avatarSrc}
                              alt={org.name || ""}
                              className="object-cover"
                            />
                          ) : null}
                          <AvatarFallback className="bg-gradient-to-br from-blue-600 to-purple-600 text-xs font-semibold text-white">
                            {initialsFromName(org.name || "?")}
                          </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                          <OrgNameMeta org={org} />
                          <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">{categoryLine}</p>
                          <p className="mt-2 inline-flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400">
                            <MapPin className="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" aria-hidden />
                            {[org.city, org.state].filter(Boolean).join(", ") || "—"}
                          </p>
                          <div className="mt-3 flex min-w-0 flex-wrap items-center gap-2">
                            {causes.length > 0 ? (
                              <>
                                {visibleCauses.map((c) => <CauseBadge key={`m-${org.id}-${c.id}-${c.slug}`} c={c} />)}
                                {overflowCauses > 0 && (
                                  <Popover>
                                    <PopoverTrigger asChild>
                                      <button
                                        type="button"
                                        aria-label={`Show all causes (${causes.length})`}
                                        className="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-200/60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700/60"
                                      >
                                        +{overflowCauses}
                                      </button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                      align="start"
                                      className="w-[min(100vw-2rem,22rem)] rounded-xl border border-slate-200 bg-white p-0 text-slate-900 shadow-xl shadow-slate-200/60 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100 dark:shadow-none"
                                      onOpenAutoFocus={(e) => e.preventDefault()}
                                    >
                                      <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-3 py-2.5 dark:border-white/10">
                                        <div className="min-w-0">
                                          <p className="truncate text-xs font-semibold">All causes</p>
                                          <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{org.name}</p>
                                        </div>
                                        <span className="shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
                                          {causes.length}
                                        </span>
                                      </div>
                                      <div className="max-h-44 overflow-y-auto px-3 py-3">
                                        <div className="flex flex-wrap gap-2">
                                          {causes.map((c) => (
                                            <CauseBadge key={`mall-${org.id}-${c.id}-${c.slug}`} c={c} />
                                          ))}
                                        </div>
                                      </div>
                                    </PopoverContent>
                                  </Popover>
                                )}
                              </>
                            ) : (
                              <span className="text-sm text-slate-400 dark:text-slate-500">—</span>
                            )}
                          </div>
                          <div className="mt-3 flex flex-col gap-3">
                            {org.is_registered ? (
                              <span className="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                Joined BIU
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                                Not Yet Joined
                              </span>
                            )}
                            <div className="flex flex-col gap-2">
                              {org.is_registered ? (
                                <>
                                  <Link
                                    href={`/organizations/${org.id}`}
                                    className="inline-flex w-full items-center justify-center rounded-lg bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:from-violet-500 hover:to-indigo-500"
                                  >
                                    View Details
                                  </Link>
                                  <div className="flex gap-2">
                                    <Button
                                      type="button"
                                      variant="outline"
                                      size="sm"
                                      className={`h-10 min-w-0 flex-1 border-violet-300 text-violet-700 dark:border-violet-500/50 dark:text-violet-200 ${org.is_favorited ? "bg-violet-50 dark:bg-violet-950/30" : "bg-transparent"}`}
                                      onClick={(e) => handleToggleFavorite(org, e)}
                                      disabled={favoritingId === org.id}
                                      aria-pressed={org.is_favorited}
                                    >
                                      <Heart className={`mr-1 h-4 w-4 shrink-0 ${org.is_favorited ? "fill-current" : ""}`} />
                                      {org.is_favorited ? "Following" : "Follow"}
                                    </Button>
                                    <Button
                                      asChild
                                      variant="outline"
                                      size="sm"
                                      className="h-10 min-w-0 flex-1 justify-center border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                                    >
                                      <Link href={`/organizations/${org.id}`}>Donate</Link>
                                    </Button>
                                  </div>
                                </>
                              ) : (
                                <Button
                                  type="button"
                                  className="w-full bg-gradient-to-r from-violet-600 to-indigo-600 py-2.5 text-white hover:from-violet-500 hover:to-indigo-500"
                                  onClick={() => openInviteFor(org)}
                                >
                                  Invite to Join
                                </Button>
                              )}
                            </div>
                          </div>
                        </div>
                      </div>
                    </motion.div>
                  )
                })}
              </div>

              {/* Pagination */}
              <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ delay: 0.2 }}
                className="mt-8 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4"
              >
                <div className="flex flex-wrap items-center gap-3 order-2 sm:order-1">
                  <span className="text-sm text-slate-600 dark:text-slate-400">Results per page</span>
                  <Select value={String(resultsPerPage)} onValueChange={handleResultsPerPageChange}>
                    <SelectTrigger className="h-9 w-[72px] border-slate-200 bg-white text-sm text-slate-900 dark:border-white/10 dark:bg-slate-900/80 dark:text-white">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent className="border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900 dark:text-white">
                      {perPageOptions.map((n) => (
                        <SelectItem key={n} value={String(n)} className="focus:bg-slate-100 dark:focus:bg-slate-800">
                          {n}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex flex-wrap items-center justify-center gap-1 order-1 sm:order-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                    onClick={() => handlePageChange(organizations.current_page - 1)}
                    disabled={organizations.current_page === 1}
                  >
                    <ChevronLeft className="h-4 w-4 mr-0.5" />
                    Previous
                  </Button>
                  {getPageNumbers().map((page, i) =>
                    page === "ellipsis" ? (
                      <span key={`e-${i}`} className="px-2 text-slate-400 dark:text-slate-500">
                        …
                      </span>
                    ) : (
                      <Button
                        key={page}
                        variant={organizations.current_page === page ? "default" : "outline"}
                        size="sm"
                        className={`h-9 min-w-9 ${
                          organizations.current_page === page
                            ? "border-0 bg-gradient-to-r from-blue-600 to-purple-600 text-white hover:from-blue-500 hover:to-purple-500"
                            : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                        }`}
                        onClick={() => handlePageChange(page)}
                      >
                        {page}
                      </Button>
                    )
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-slate-900/60 dark:text-slate-200 dark:hover:bg-slate-800"
                    onClick={() => handlePageChange(organizations.current_page + 1)}
                    disabled={organizations.current_page === organizations.last_page}
                  >
                    Next
                    <ChevronRight className="h-4 w-4 ml-0.5" />
                  </Button>
                </div>
              </motion.div>
            </>
          ) : (
            <motion.div
              initial={{ opacity: 0, y: 16 }}
              animate={{ opacity: 1, y: 0 }}
              className="rounded-2xl border border-slate-200 bg-white p-12 text-center shadow-sm backdrop-blur-sm dark:border-white/10 dark:bg-slate-900/40"
            >
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                <Search className="h-8 w-8 text-slate-400 dark:text-slate-500" />
              </div>
              <h3 className="mb-2 text-lg font-semibold text-slate-900 dark:text-white">No organizations found</h3>
              <p className="mx-auto mb-6 max-w-sm text-slate-600 dark:text-slate-400">
                Try adjusting your search or filters to discover organizations.
              </p>
              <Button
                onClick={clearFilters}
                className="bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500"
              >
                Clear filters
              </Button>
            </motion.div>
          )}
            </div>
          </div>
        </div>
      </div>
      </div>
    </FrontendLayout>
  )
}

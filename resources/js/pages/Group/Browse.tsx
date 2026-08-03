"use client"

import { FormEvent, useMemo, useState } from "react"
import { Link, router, usePage } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { PageHead } from "@/components/frontend/PageHead"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Card, CardContent } from "@/components/frontend/ui/card"
import GroupDirectoryCard, {
  type GroupDirectoryCardData,
} from "@/components/community/GroupDirectoryCard"
import { Check, ChevronDown, Globe2, Lock, Plus, Search, UsersRound, X } from "lucide-react"

const SORT_OPTIONS = [
  { value: "newest", label: "Newest" },
  { value: "most_members", label: "Most Members" },
  { value: "name", label: "Name A–Z" },
  { value: "featured", label: "Featured" },
] as const

const VISIBILITY_OPTIONS = [
  { value: "public", label: "Public" },
  { value: "private", label: "Private" },
] as const

type PaginatedGroups = {
  data: (GroupDirectoryCardData & { description_preview?: string | null })[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  next_page_url: string | null
  prev_page_url: string | null
  links: { url: string | null; label: string; active: boolean }[]
}

type Filters = {
  category: string
  visibility: string
  sort: string
}

type PageProps = {
  seo?: { title?: string; description?: string }
  groups: PaginatedGroups
  categories: string[]
  searchQuery: string
  filters: Filters
  canCreate?: boolean
  createUrl?: string | null
  authUser?: { id: number; name: string } | null
}

function buildParams(q: string, filters: Filters): Record<string, string | undefined> {
  const params: Record<string, string | undefined> = {}
  if (q.trim()) params.q = q.trim()
  if (filters.category) params.category = filters.category
  if (filters.visibility) params.visibility = filters.visibility
  if (filters.sort && filters.sort !== "newest") params.sort = filters.sort
  return params
}

const brandBtn =
  "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-500/20 hover:from-purple-700 hover:to-blue-700"
const brandChipActive =
  "border-purple-500 bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm shadow-purple-500/20"
const brandChipIdle =
  "border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 text-purple-800 hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/30 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100 dark:hover:from-purple-500/25 dark:hover:to-blue-500/25"

export default function GroupBrowse() {
  const {
    seo,
    groups,
    categories = [],
    searchQuery: initialQuery,
    filters: initialFilters,
    canCreate = false,
    createUrl = null,
    authUser = null,
  } = usePage<PageProps>().props

  const [searchQuery, setSearchQuery] = useState(initialQuery ?? "")
  const [filters, setFilters] = useState<Filters>({
    category: initialFilters?.category ?? "",
    visibility: initialFilters?.visibility ?? "",
    sort: initialFilters?.sort ?? "newest",
  })
  const [categoriesExpanded, setCategoriesExpanded] = useState(true)
  const [visibilityExpanded, setVisibilityExpanded] = useState(true)
  const [showAllCategories, setShowAllCategories] = useState(false)
  const [sortDropdownOpen, setSortDropdownOpen] = useState(false)

  const CATEGORIES_VISIBLE = 8
  const visibleCategories = showAllCategories ? categories : categories.slice(0, CATEGORIES_VISIBLE)
  const hasMoreCategories = categories.length > CATEGORIES_VISIBLE

  const sortLabel = useMemo(
    () => SORT_OPTIONS.find((o) => o.value === filters.sort)?.label ?? "Newest",
    [filters.sort],
  )

  const apply = (nextQ: string, nextFilters: Filters) => {
    router.get(route("groups"), buildParams(nextQ, nextFilters), {
      preserveState: false,
    })
  }

  const handleSearch = (e: FormEvent) => {
    e.preventDefault()
    apply(searchQuery, filters)
  }

  const setCategory = (category: string) => {
    const next = {
      ...filters,
      category: filters.category === category ? "" : category,
    }
    setFilters(next)
    apply(searchQuery, next)
  }

  const setVisibility = (visibility: string) => {
    const next = {
      ...filters,
      visibility: filters.visibility === visibility ? "" : visibility,
    }
    setFilters(next)
    apply(searchQuery, next)
  }

  const setSort = (sort: string) => {
    const next = { ...filters, sort }
    setFilters(next)
    setSortDropdownOpen(false)
    apply(searchQuery, next)
  }

  const clearCategory = () => {
    const next = { ...filters, category: "" }
    setFilters(next)
    apply(searchQuery, next)
  }

  const clearVisibility = () => {
    const next = { ...filters, visibility: "" }
    setFilters(next)
    apply(searchQuery, next)
  }

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Community Groups"} description={seo?.description} />
      <div className="min-h-screen bg-gradient-to-b from-purple-50 via-blue-50/40 to-slate-50 text-slate-900 dark:from-[#0a0f1a] dark:via-[#0c1222] dark:to-[#0a0f1a] dark:text-white">
        <div className="mx-auto max-w-[95rem] px-4 py-8 sm:px-6 lg:px-8">
          <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h1 className="mb-2 bg-gradient-to-r from-purple-700 to-blue-700 bg-clip-text text-3xl font-bold tracking-tight text-transparent sm:text-4xl dark:from-purple-300 dark:to-blue-300">
                Community Groups
              </h1>
              <p className="text-base text-slate-600 sm:text-lg dark:text-slate-400">
                Discover groups by category and activity — join discussions, events, photos, and more.
              </p>
            </div>
            {canCreate && createUrl ? (
              <Button asChild className={`h-11 shrink-0 cursor-pointer rounded-xl px-5 font-semibold ${brandBtn}`}>
                <Link href={createUrl}>
                  <Plus className="mr-1.5 h-4 w-4" />
                  Create group
                </Link>
              </Button>
            ) : authUser ? (
              <p className="max-w-xs text-sm text-slate-500 sm:text-right dark:text-slate-400">
                Follow an organization or Unity Impact Alliance to create a group.
              </p>
            ) : (
              <Button asChild className={`h-11 shrink-0 cursor-pointer rounded-xl px-5 font-semibold ${brandBtn}`}>
                <Link href={route("login")}>
                  <Plus className="mr-1.5 h-4 w-4" />
                  Sign in to create
                </Link>
              </Button>
            )}
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:gap-8">
            <aside className="lg:col-span-3">
              <Card className="sticky top-6 overflow-hidden rounded-2xl border border-purple-100 bg-white shadow-lg shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
                <CardContent className="p-6">
                  <h2 className="mb-6 bg-gradient-to-r from-purple-700 to-blue-700 bg-clip-text text-lg font-semibold text-transparent dark:from-purple-300 dark:to-blue-300">
                    Filter Groups
                  </h2>

                  <div className="mb-6">
                    <button
                      type="button"
                      onClick={() => setCategoriesExpanded(!categoriesExpanded)}
                      className="mb-3 flex w-full cursor-pointer items-center justify-between text-sm font-semibold text-slate-900 transition-colors hover:text-purple-700 dark:text-white dark:hover:text-purple-300"
                    >
                      Category
                      <ChevronDown
                        className={`h-4 w-4 transition-transform duration-200 ${categoriesExpanded ? "" : "-rotate-90"}`}
                      />
                    </button>
                    {categoriesExpanded && (
                      <div className="mt-2 flex flex-wrap gap-2">
                        {visibleCategories.length === 0 ? (
                          <p className="text-sm text-slate-500 dark:text-slate-400">No categories yet</p>
                        ) : (
                          visibleCategories.map((cat) => {
                            const isSelected = filters.category === cat
                            return (
                              <button
                                key={cat}
                                type="button"
                                onClick={() => setCategory(cat)}
                                className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-all duration-200 ${
                                  isSelected ? brandChipActive : brandChipIdle
                                }`}
                              >
                                {isSelected && <Check className="h-3.5 w-3.5" />}
                                {cat}
                              </button>
                            )
                          })
                        )}
                        {hasMoreCategories && (
                          <button
                            type="button"
                            onClick={() => setShowAllCategories(!showAllCategories)}
                            className="mt-3 flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-purple-200 px-4 py-2.5 text-sm font-medium text-purple-700 transition-all duration-200 hover:border-purple-400 hover:bg-purple-50 dark:border-purple-500/40 dark:text-purple-300 dark:hover:bg-purple-500/10"
                          >
                            <ChevronDown
                              className={`h-4 w-4 transition-transform duration-200 ${showAllCategories ? "rotate-180" : ""}`}
                            />
                            {showAllCategories
                              ? "Show less"
                              : `Show more (${categories.length - CATEGORIES_VISIBLE} more)`}
                          </button>
                        )}
                      </div>
                    )}
                  </div>

                  <div className="mb-2">
                    <button
                      type="button"
                      onClick={() => setVisibilityExpanded(!visibilityExpanded)}
                      className="mb-3 flex w-full cursor-pointer items-center justify-between text-sm font-semibold text-slate-900 transition-colors hover:text-purple-700 dark:text-white dark:hover:text-purple-300"
                    >
                      Visibility
                      <ChevronDown
                        className={`h-4 w-4 transition-transform duration-200 ${visibilityExpanded ? "" : "-rotate-90"}`}
                      />
                    </button>
                    {visibilityExpanded && (
                      <div className="mt-2 flex flex-wrap gap-2">
                        {VISIBILITY_OPTIONS.map((opt) => {
                          const isSelected = filters.visibility === opt.value
                          const Icon = opt.value === "public" ? Globe2 : Lock
                          return (
                            <button
                              key={opt.value}
                              type="button"
                              onClick={() => setVisibility(opt.value)}
                              className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-all duration-200 ${
                                isSelected ? brandChipActive : brandChipIdle
                              }`}
                            >
                              {isSelected ? <Check className="h-3.5 w-3.5" /> : <Icon className="h-3.5 w-3.5" />}
                              {opt.label}
                            </button>
                          )
                        })}
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            </aside>

            <div className="space-y-6 lg:col-span-9">
              <form onSubmit={handleSearch} className="group relative">
                <Search className="absolute top-1/2 left-5 h-5 w-5 -translate-y-1/2 text-slate-400 transition-colors group-focus-within:text-purple-600" />
                <Input
                  type="text"
                  placeholder="Search groups by name, category, or keywords..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="rounded-2xl border-2 border-purple-100 bg-white py-4 pr-5 pl-12 text-base shadow-sm shadow-purple-500/5 transition-all focus:border-purple-400 focus:ring-4 focus:ring-purple-500/10 dark:border-purple-500/20 dark:bg-[#111827] dark:focus:border-purple-400 dark:focus:ring-purple-500/20"
                />
              </form>

              <div className="flex flex-wrap items-center justify-between gap-4 border-b border-purple-100 pb-4 dark:border-purple-500/20">
                <div className="flex flex-wrap items-center gap-2.5">
                  {filters.category && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm">
                      {filters.category}
                      <button
                        type="button"
                        onClick={clearCategory}
                        className="ml-0.5 cursor-pointer rounded-full p-0.5 transition-colors hover:bg-white/20"
                      >
                        <X className="h-3.5 w-3.5" />
                      </button>
                    </span>
                  )}
                  {filters.visibility && (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-2 text-sm font-medium text-purple-800 dark:border-purple-500/30 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100">
                      {filters.visibility === "public" ? (
                        <Globe2 className="h-3.5 w-3.5" />
                      ) : (
                        <Lock className="h-3.5 w-3.5" />
                      )}
                      {filters.visibility === "public" ? "Public" : "Private"}
                      <button
                        type="button"
                        onClick={clearVisibility}
                        className="ml-0.5 cursor-pointer rounded-full p-0.5 transition-colors hover:bg-purple-200/50 dark:hover:bg-purple-500/20"
                      >
                        <X className="h-3.5 w-3.5" />
                      </button>
                    </span>
                  )}
                  {SORT_OPTIONS.filter((o) => o.value !== "newest").map((opt) => (
                    <button
                      key={opt.value}
                      type="button"
                      onClick={() => setSort(opt.value)}
                      className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-medium transition-all ${
                        filters.sort === opt.value ? brandChipActive : brandChipIdle
                      }`}
                    >
                      <UsersRound className="h-3.5 w-3.5" />
                      {opt.label}
                    </button>
                  ))}
                </div>

                <div className="relative">
                  <span className="mr-2 text-sm font-medium text-slate-600 dark:text-slate-400">Sort:</span>
                  <button
                    type="button"
                    onClick={() => setSortDropdownOpen(!sortDropdownOpen)}
                    className="inline-flex cursor-pointer items-center gap-1 font-semibold text-slate-900 transition-colors hover:text-purple-700 dark:text-white dark:hover:text-purple-300"
                  >
                    {sortLabel}
                    <ChevronDown
                      className={`h-4 w-4 transition-transform duration-200 ${sortDropdownOpen ? "rotate-180" : ""}`}
                    />
                  </button>
                  {sortDropdownOpen && (
                    <>
                      <div className="fixed inset-0 z-10" onClick={() => setSortDropdownOpen(false)} />
                      <div className="absolute top-full right-0 z-20 mt-2 min-w-[180px] rounded-xl border border-purple-100 bg-white py-2 shadow-xl dark:border-purple-500/20 dark:bg-[#111827]">
                        {SORT_OPTIONS.map((opt) => (
                          <button
                            key={opt.value}
                            type="button"
                            onClick={() => setSort(opt.value)}
                            className={`w-full cursor-pointer px-4 py-2.5 text-left text-sm transition-colors hover:bg-purple-50 dark:hover:bg-purple-500/10 ${
                              filters.sort === opt.value
                                ? "bg-gradient-to-r from-purple-50 to-blue-50 font-semibold text-purple-700 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-200"
                                : "text-slate-700 dark:text-slate-300"
                            }`}
                          >
                            {opt.label}
                          </button>
                        ))}
                      </div>
                    </>
                  )}
                </div>
              </div>

              <p className="text-sm text-slate-500 dark:text-slate-400">
                {groups.total.toLocaleString()} group{groups.total === 1 ? "" : "s"}
                {searchQuery.trim() ? (
                  <>
                    {" "}
                    matching{" "}
                    <span className="font-medium text-purple-800 dark:text-purple-200">
                      “{searchQuery.trim()}”
                    </span>
                  </>
                ) : null}
              </p>

              {groups?.data?.length ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                  {groups.data.map((group) => (
                    <GroupDirectoryCard key={group.id} group={group} />
                  ))}
                </div>
              ) : (
                <Card className="border-2 border-dashed border-purple-200 bg-white dark:border-purple-500/30 dark:bg-[#111827]">
                  <CardContent className="p-12 text-center">
                    <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-purple-100 to-blue-100 dark:from-purple-500/20 dark:to-blue-500/20">
                      <Search className="h-8 w-8 text-purple-500" />
                    </div>
                    <h3 className="mb-2 text-lg font-semibold text-slate-900 dark:text-white">
                      No Community Groups found
                    </h3>
                    <p className="text-slate-500 dark:text-slate-400">
                      Try adjusting your filters or search terms to find more groups.
                    </p>
                    {canCreate && createUrl ? (
                      <Button asChild className={`mt-6 cursor-pointer rounded-xl font-semibold ${brandBtn}`}>
                        <Link href={createUrl}>
                          <Plus className="mr-1.5 h-4 w-4" />
                          Create your first group
                        </Link>
                      </Button>
                    ) : null}
                  </CardContent>
                </Card>
              )}

              {groups?.next_page_url && (
                <div className="flex justify-center pt-6">
                  <Button
                    variant="outline"
                    onClick={() => router.get(groups.next_page_url!)}
                    className="cursor-pointer rounded-xl border-2 border-purple-200 px-8 py-2.5 font-semibold text-purple-800 transition-all hover:border-purple-400 hover:bg-purple-50 dark:border-purple-500/30 dark:text-purple-200 dark:hover:bg-purple-500/10"
                  >
                    Load more groups
                  </Button>
                </div>
              )}

              {groups.last_page > 1 && !groups.next_page_url && groups.links.length > 0 && (
                <div className="flex flex-wrap items-center justify-center gap-2 pt-2">
                  {groups.links.map((link, i) => {
                    const label = link.label.replace(/&laquo;/g, "«").replace(/&raquo;/g, "»")
                    if (!link.url) {
                      return (
                        <span
                          key={`${label}-${i}`}
                          className="inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm text-slate-400"
                          dangerouslySetInnerHTML={{ __html: label }}
                        />
                      )
                    }
                    return (
                      <Link
                        key={`${label}-${i}`}
                        href={link.url}
                        className={`inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm font-semibold transition ${
                          link.active
                            ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                            : "border border-purple-100 bg-white text-purple-800 hover:border-purple-300 hover:bg-purple-50 dark:border-purple-500/20 dark:bg-[#111827] dark:text-purple-200 dark:hover:bg-purple-500/10"
                        }`}
                        dangerouslySetInnerHTML={{ __html: label }}
                        preserveScroll
                      />
                    )
                  })}
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}

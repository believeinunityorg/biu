"use client"

import { router, usePage } from "@inertiajs/react"
import { Check, ChevronsUpDown, Search, X } from "lucide-react"
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react"

import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/frontend/ui/popover"
import { cn } from "@/lib/utils"

export type ProfileOrgOption = {
  id: number
  name: string
  image: string | null
}

export type OrganizationPickerPayload = {
  target: "primary" | "secondary"
  items: ProfileOrgOption[]
  has_more: boolean
  page: number
  search: string
}

interface ProfileOrganizationPickerProps {
  variant: "primary" | "secondary-add"
  excludeIds?: number[]
  /** Primary combobox — current value ('__none__' | org id string) aligned with Radix Select */
  primaryValue?: string
  /** Primary only — resolved row for trigger label */
  selectedOrganization?: ProfileOrgOption | null
  onPrimaryChange?: (value: string, selectedOrg?: ProfileOrgOption | null) => void
  /** Secondary — called with picked org */
  onSecondaryAdd?: (org: ProfileOrgOption) => void
  placeholder?: string
  className?: string
  compactTrigger?: boolean
  /** Outline action button for card actions (e.g. Primary org "Change"). */
  actionTrigger?: boolean
  /** For <Label htmlFor> accessibility */
  triggerId?: string
  /** Dark modal dropdown (Change primary organization). */
  modalTrigger?: boolean
  /** Route name for Inertia partial reload (default: user.profile.edit). */
  reloadRoute?: string
}

/**
 * Search + infinite scroll via Inertia partial reload on `user.profile.edit` (supporter `/profile/edit`, not `profile.edit` which is org settings).
 */
export function ProfileOrganizationPicker({
  variant,
  excludeIds = [],
  primaryValue,
  selectedOrganization,
  onPrimaryChange,
  onSecondaryAdd,
  placeholder = "Select organization",
  className,
  compactTrigger = false,
  actionTrigger = false,
  triggerId,
  modalTrigger = false,
  reloadRoute = "user.profile.edit",
}: ProfileOrganizationPickerProps) {
  const pickerTarget = variant === "primary" ? "primary" : "secondary"
  const { organizationPicker: pageOrganizationPicker } = usePage<{
    organizationPicker?: OrganizationPickerPayload | null
  }>().props

  const [open, setOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState("")
  const [items, setItems] = useState<ProfileOrgOption[]>([])
  const [hasMore, setHasMore] = useState(false)
  const [loadedPage, setLoadedPage] = useState(0)
  const [loading, setLoading] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const listRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const lastRequestedSearch = useRef("")
  const firstOpenLoadRef = useRef(true)
  const requestIdRef = useRef(0)
  const lastAppliedPickerPage = useRef(0)

  const excludeParam = useMemo(() => excludeIds.filter((id) => id > 0).join(","), [excludeIds])

  const applyPickerPayload = useCallback(
    (raw: OrganizationPickerPayload, mode: "reset" | "append") => {
      if (raw.target !== pickerTarget) return

      if (mode === "reset" || raw.page <= 1) {
        setItems(raw.items)
      } else {
        setItems((prev) => {
          const seen = new Set(prev.map((x) => x.id))
          const next = [...prev]
          for (const row of raw.items) {
            if (!seen.has(row.id)) {
              seen.add(row.id)
              next.push(row)
            }
          }
          return next
        })
      }
      setHasMore(raw.has_more)
      setLoadedPage(raw.page)
      lastRequestedSearch.current = raw.search
      lastAppliedPickerPage.current = raw.page
    },
    [pickerTarget],
  )

  const visitPicker = useCallback(
    (page: number, q: string, mode: "reset" | "append") => {
      const rid = ++requestIdRef.current
      const params: Record<string, string> = {
        org_picker_page: String(page),
        org_picker_q: q,
        org_picker_exclude: excludeParam,
        org_picker_target: pickerTarget,
      }
      router.get(route(reloadRoute), params, {
        only: ["organizationPicker"],
        preserveScroll: true,
        preserveState: true,
        preserveUrl: true,
        replace: true,
        onStart: () => {
          if (rid !== requestIdRef.current) return
          if (mode === "reset") setLoading(true)
          else setLoadingMore(true)
        },
        onCancel: () => {
          if (rid !== requestIdRef.current) return
          setLoading(false)
          setLoadingMore(false)
        },
        onFinish: () => {
          if (rid !== requestIdRef.current) return
          setLoading(false)
          setLoadingMore(false)
        },
        onError: () => {
          if (rid !== requestIdRef.current) return
          if (mode === "reset") setItems([])
          setHasMore(false)
        },
        onSuccess: (pageResult) => {
          if (rid !== requestIdRef.current) return
          const raw = (pageResult.props as { organizationPicker?: OrganizationPickerPayload | null })
            .organizationPicker
          if (!raw) {
            if (mode === "reset") setItems([])
            setHasMore(false)
            return
          }
          applyPickerPayload(raw, mode)
        },
      })
    },
    [applyPickerPayload, excludeParam, pickerTarget, reloadRoute],
  )

  useEffect(() => {
    if (!open || !pageOrganizationPicker) return
    if (pageOrganizationPicker.target !== pickerTarget) return
    if (pageOrganizationPicker.page === lastAppliedPickerPage.current && pageOrganizationPicker.search === lastRequestedSearch.current) {
      return
    }
    applyPickerPayload(
      pageOrganizationPicker,
      pageOrganizationPicker.page <= 1 ? "reset" : "append",
    )
  }, [applyPickerPayload, open, pageOrganizationPicker, pickerTarget])

  useEffect(() => {
    if (!open) {
      firstOpenLoadRef.current = true
      requestIdRef.current += 1
      lastAppliedPickerPage.current = 0
      setItems([])
      setLoadedPage(0)
      setHasMore(false)
      setSearchQuery("")
      return
    }

    const q = searchQuery.trim()
    const immediate = firstOpenLoadRef.current && q === ""
    if (immediate) {
      firstOpenLoadRef.current = false
    }
    const delay = immediate ? 0 : 320

    const id = window.setTimeout(() => {
      visitPicker(1, q, "reset")
    }, delay)

    return () => window.clearTimeout(id)
  }, [open, searchQuery, visitPicker])

  const handleScroll = () => {
    const el = listRef.current
    if (!el || !hasMore || loadingMore || loading) return
    if (loadedPage < 1) return
    const { scrollTop, scrollHeight, clientHeight } = el
    if (scrollHeight - (scrollTop + clientHeight) < 48) {
      visitPicker(loadedPage + 1, lastRequestedSearch.current, "append")
    }
  }

  const handleSelectPrimary = (value: string, org: ProfileOrgOption | null) => {
    onPrimaryChange?.(value, org)
    setOpen(false)
    setSearchQuery("")
  }

  const handleSelectSecondary = (org: ProfileOrgOption) => {
    onSecondaryAdd?.(org)
    setOpen(false)
    setSearchQuery("")
  }

  const primarySelectedValue =
    primaryValue === "__none__" || primaryValue === undefined || primaryValue === ""
      ? "__none__"
      : primaryValue

  const displayRows = items

  const triggerLabel =
    variant === "primary"
      ? primarySelectedValue === "__none__"
        ? placeholder
        : selectedOrganization?.name ?? placeholder
      : placeholder

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={triggerId}
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className={cn(
            actionTrigger
              ? "h-9 shrink-0 border-purple-500/40 bg-slate-900/50 px-4 text-slate-100 shadow-sm hover:border-purple-400 hover:bg-purple-500/10"
              : compactTrigger
              ? "h-7 w-auto shrink-0 justify-center border-0 bg-transparent px-2 shadow-none ring-0 hover:bg-transparent dark:hover:bg-transparent [&_svg]:hidden font-normal text-gray-900 dark:text-gray-100"
              : "w-full justify-between h-12 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all duration-200",
            compactTrigger && !actionTrigger && !triggerLabel?.includes("…") && triggerLabel === placeholder && "text-muted-foreground",
            className,
          )}
        >
          {variant === "primary" && selectedOrganization && primarySelectedValue !== "__none__" ? (
            <span className="flex min-w-0 flex-1 items-center gap-3 truncate text-left">
              {selectedOrganization.image ? (
                <img
                  src={selectedOrganization.image}
                  alt=""
                  className={cn(
                    "shrink-0 rounded-full object-cover",
                    modalTrigger ? "h-8 w-8" : "h-7 w-7",
                  )}
                />
              ) : (
                <span
                  className={cn(
                    "flex shrink-0 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600 text-xs font-semibold text-white",
                    modalTrigger ? "h-8 w-8" : "h-7 w-7",
                  )}
                >
                  {selectedOrganization.name.slice(0, 1)}
                </span>
              )}
              <span className={cn("truncate", modalTrigger && "text-sm font-medium uppercase tracking-wide")}>
                {selectedOrganization.name}
              </span>
            </span>
          ) : (
            <span className={cn("truncate text-left", compactTrigger && !actionTrigger && "text-sm")}>{triggerLabel}</span>
          )}
          {!compactTrigger && !actionTrigger ? (
            <ChevronsUpDown className={cn("ml-2 h-4 w-4 shrink-0", modalTrigger ? "text-slate-400" : "opacity-50")} />
          ) : null}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[min(100vw-2rem,var(--radix-popover-trigger-width))] max-w-md p-0 sm:w-auto sm:min-w-[var(--radix-popover-trigger-width)]"
        align="start"
        sideOffset={4}
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <div className="flex flex-col rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-800">
          <div className="flex items-center border-b border-gray-200 px-3 dark:border-gray-700">
            <Search className="mr-2 h-4 w-4 shrink-0 opacity-50" aria-hidden />
            <Input
              ref={searchInputRef}
              type="text"
              placeholder="Search organizations…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="h-10 border-0 bg-transparent px-0 text-gray-900 shadow-none outline-none ring-0 ring-offset-0 focus:border-0 focus:outline-none focus:ring-0 focus:ring-offset-0 focus-visible:border-0 focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:text-white"
              onMouseDown={(e) => {
                // Avoid document scroll jump when focusing search inside a portaled popover (esp. after Inertia visits).
                e.preventDefault()
                searchInputRef.current?.focus({ preventScroll: true })
              }}
              onKeyDown={(e: React.KeyboardEvent<HTMLInputElement>) => {
                if (e.key === "Enter" && variant === "secondary-add" && displayRows.length === 1) {
                  handleSelectSecondary(displayRows[0])
                }
              }}
            />
            {searchQuery ? (
              <button
                type="button"
                title="Clear search"
                aria-label="Clear search"
                className="ml-2 text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                onClick={(e) => {
                  e.stopPropagation()
                  setSearchQuery("")
                }}
              >
                <X className="h-4 w-4" />
              </button>
            ) : null}
          </div>
          <div
            ref={listRef}
            className="max-h-[280px] overflow-y-auto overflow-x-hidden p-1"
            onScroll={handleScroll}
          >
            {loading && displayRows.length === 0 ? (
              <div className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</div>
            ) : (
              <>
                {variant === "primary" ? (
                  <button
                    type="button"
                    onClick={() => handleSelectPrimary("__none__", null)}
                    className={cn(
                      "relative flex w-full cursor-default select-none items-center rounded-sm px-2 py-2 text-sm outline-none hover:bg-gray-100 focus:bg-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700",
                      primarySelectedValue === "__none__" && "bg-gray-100 dark:bg-gray-700",
                    )}
                  >
                    <Check
                      className={cn(
                        "mr-2 h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400",
                        primarySelectedValue === "__none__" ? "opacity-100" : "opacity-0",
                      )}
                    />
                    <span className="flex-1 truncate text-left text-gray-700 dark:text-gray-200">
                      None selected
                    </span>
                  </button>
                ) : null}

                {displayRows.length === 0 && !(variant === "primary") ? (
                  <div className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    No organizations match.
                  </div>
                ) : null}

                {displayRows.length === 0 && variant === "primary" && !loading ? (
                  <div className="py-4 text-center text-xs text-gray-500 dark:text-gray-400">
                    No additional organizations match your search.
                  </div>
                ) : null}

                {displayRows.map((option) => {
                  const selected =
                    variant === "primary" && primarySelectedValue === String(option.id)
                  return (
                    <button
                      key={option.id}
                      type="button"
                      onClick={() =>
                        variant === "primary"
                          ? handleSelectPrimary(String(option.id), option)
                          : handleSelectSecondary(option)
                      }
                      className={cn(
                        "relative flex w-full cursor-default select-none items-center rounded-sm px-2 py-2 text-sm outline-none hover:bg-gray-100 focus:bg-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700",
                        variant === "primary" && selected && "bg-gray-100 dark:bg-gray-700",
                      )}
                    >
                      <Check
                        className={cn(
                          "mr-2 h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400",
                          selected ? "opacity-100" : "opacity-0",
                        )}
                      />
                      <span className="flex min-w-0 flex-1 items-center gap-2 truncate text-left">
                        {option.image ? (
                          <img
                            src={option.image}
                            alt=""
                            className="h-8 w-8 shrink-0 rounded-full object-cover"
                          />
                        ) : (
                          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600 text-xs font-semibold text-white">
                            {option.name.slice(0, 1)}
                          </span>
                        )}
                        <span className="truncate text-gray-900 dark:text-gray-100">
                          {option.name}
                        </span>
                      </span>
                    </button>
                  )
                })}
                {loadingMore ? (
                  <div className="py-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    Loading more...
                  </div>
                ) : null}
              </>
            )}
          </div>
        </div>
      </PopoverContent>
    </Popover>
  )
}

"use client"

import { Head, Link, router } from "@inertiajs/react"
import { useCallback, useEffect, useRef, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import {
  Gift,
  Search,
  ShoppingBag,
  Globe,
  ArrowRight,
  Sparkles,
  Wallet,
  Package,
  Loader2,
  ShieldCheck,
  Zap,
  Tag,
} from "lucide-react"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"

interface Brand {
  productId?: number
  productName?: string
  /** False for Visa/Mastercard — Gift BP cannot pay */
  allowsGiftBp?: boolean
  productImage?: string
  denominations?: number[]
  valueRestrictions?: {
    minVal?: number
    maxVal?: number
  }
  productDescription?: string
  termsAndConditions?: string
  howToUse?: string
  expiryAndValidity?: string
  discount?: number
}

interface PaginationData {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
  has_more?: boolean
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
}

interface GiftCardsIndexProps {
  giftCards: {
    data: Brand[]
  } & PaginationData
  user?: {
    id: number
    name: string
    email: string
    role: string
  } | null
  filters: {
    search: string
    country: string
    per_page: number
  }
  availableCountries: Record<string, string>
}

function formatCurrency(amount: number, currency: string = "USD") {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency,
    maximumFractionDigits: 0,
  }).format(amount)
}

function BrandCardSkeleton() {
  return (
    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card dark:border-gray-800">
      <div className="aspect-[16/10] animate-pulse bg-muted/80 dark:bg-gray-800" />
      <div className="space-y-3 p-4">
        <div className="h-4 w-3/4 animate-pulse rounded bg-muted dark:bg-gray-800" />
        <div className="h-3 w-1/2 animate-pulse rounded bg-muted dark:bg-gray-800" />
        <div className="h-10 w-full animate-pulse rounded-xl bg-muted dark:bg-gray-800" />
      </div>
    </div>
  )
}

export default function GiftCardsIndex({
  giftCards,
  user,
  filters,
  availableCountries,
}: GiftCardsIndexProps) {
  const [searchQuery, setSearchQuery] = useState(filters.search || "")
  const [selectedCountry, setSelectedCountry] = useState<string>(filters.country || "USA")
  const [isLoading, setIsLoading] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [brands, setBrands] = useState<Brand[]>(giftCards.data ?? [])
  const [totalBrands, setTotalBrands] = useState(giftCards.total ?? 0)
  const [hasMore, setHasMore] = useState(
    giftCards.has_more ?? giftCards.current_page < giftCards.last_page,
  )
  const [page, setPage] = useState(giftCards.current_page || 1)

  const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const loadMoreSentinelRef = useRef<HTMLDivElement>(null)
  const loadingMoreRef = useRef(false)
  const hasMoreRef = useRef(hasMore)
  const pageRef = useRef(page)
  const isLoadingRef = useRef(false)
  const searchQueryRef = useRef(searchQuery)
  const selectedCountryRef = useRef(selectedCountry)

  const isOrgOrAdmin =
    Boolean(user) && user!.role !== "user" && user!.role !== null
  const Layout = isOrgOrAdmin ? AppSidebarLayout : FrontendLayout
  const countryLabel = availableCountries[selectedCountry] || selectedCountry
  const myCardsHref = isOrgOrAdmin ? route("gift-cards.created") : route("gift-cards.my-cards")
  const myCardsLabel = isOrgOrAdmin ? "Purchased cards" : "My cards"

  useEffect(() => {
    hasMoreRef.current = hasMore
  }, [hasMore])

  useEffect(() => {
    pageRef.current = page
  }, [page])

  useEffect(() => {
    isLoadingRef.current = isLoading
  }, [isLoading])

  useEffect(() => {
    searchQueryRef.current = searchQuery
  }, [searchQuery])

  useEffect(() => {
    selectedCountryRef.current = selectedCountry
  }, [selectedCountry])

  // Keep the address bar clean: /gift-cards (no ?country=&page=&search=)
  useEffect(() => {
    if (typeof window === "undefined") return
    if (window.location.search) {
      window.history.replaceState(window.history.state, "", window.location.pathname)
    }
  }, [])

  const fetchBrandsPage = useCallback(
    async (
      pageNum: number,
      options: { search?: string; country?: string; append?: boolean } = {},
    ) => {
      const country = options.country ?? selectedCountryRef.current
      const search = (options.search ?? searchQueryRef.current).trim()
      const append = Boolean(options.append)

      const params = new URLSearchParams()
      params.set("page", String(pageNum))
      params.set("country", country)
      if (search !== "") {
        params.set("search", search)
      }

      const response = await fetch(`${route("gift-cards.brands")}?${params.toString()}`, {
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
      })

      if (!response.ok) {
        throw new Error("Failed to load gift cards")
      }

      const json = (await response.json()) as {
        brands?: Brand[]
        current_page?: number
        has_more?: boolean
        total?: number | null
      }

      const incoming = Array.isArray(json.brands) ? json.brands : []
      const nextHasMore = Boolean(json.has_more)
      const nextPage = json.current_page ?? pageNum

      if (append) {
        setBrands((prev) => {
          const seen = new Set(prev.map((b) => b.productId).filter(Boolean))
          const next = incoming.filter((b) => b.productId == null || !seen.has(b.productId))
          return next.length ? [...prev, ...next] : prev
        })
      } else {
        setBrands(incoming)
      }

      if (typeof json.total === "number") {
        setTotalBrands(json.total)
      }

      setHasMore(nextHasMore)
      hasMoreRef.current = nextHasMore
      setPage(nextPage)
      pageRef.current = nextPage

      return incoming
    },
    [],
  )

  const runFilter = (overrides: { search?: string; country?: string } = {}) => {
    if (isLoadingRef.current) return
    setIsLoading(true)
    isLoadingRef.current = true
    loadingMoreRef.current = false
    setLoadingMore(false)

    void fetchBrandsPage(1, {
      search: overrides.search ?? searchQueryRef.current,
      country: overrides.country ?? selectedCountryRef.current,
      append: false,
    })
      .catch(() => {
        setBrands([])
        setHasMore(false)
        hasMoreRef.current = false
      })
      .finally(() => {
        setIsLoading(false)
        isLoadingRef.current = false
      })
  }

  const handleSearch = (value: string) => {
    setSearchQuery(value)
    if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current)
    searchTimeoutRef.current = setTimeout(() => {
      runFilter({ search: value })
    }, 400)
  }

  const handleCountryFilter = (country: string) => {
    setSelectedCountry(country)
    window.requestAnimationFrame(() => {
      runFilter({ country })
    })
  }

  const loadMore = useCallback(() => {
    if (loadingMoreRef.current || !hasMoreRef.current || isLoadingRef.current) return

    const nextPage = pageRef.current + 1
    loadingMoreRef.current = true
    setLoadingMore(true)

    void fetchBrandsPage(nextPage, { append: true })
      .catch(() => {
        // Keep existing list; allow retry on next scroll.
      })
      .finally(() => {
        loadingMoreRef.current = false
        setLoadingMore(false)
      })
  }, [fetchBrandsPage])

  useEffect(() => {
    const el = loadMoreSentinelRef.current
    if (!el) return

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) loadMore()
      },
      { root: null, rootMargin: "240px 0px", threshold: 0 },
    )

    observer.observe(el)
    return () => observer.disconnect()
  }, [loadMore, brands.length, hasMore])

  const handleViewBrand = (brand: Brand) => {
    if (brand.productId) {
      router.visit(
        route("gift-cards.show") + `?productId=${brand.productId}&country=${selectedCountry}`,
      )
    }
  }

  useEffect(() => {
    return () => {
      if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current)
    }
  }, [])

  return (
    <Layout>
      <Head title="Gift Cards" />

      <div className="flex h-full min-w-0 flex-1 flex-col">
        {/* Full-bleed hero */}
        <header className="relative w-full overflow-hidden bg-gradient-to-br from-purple-600 via-purple-600 to-blue-600 text-white shadow-lg">
          <div
            aria-hidden
            className="pointer-events-none absolute inset-0 opacity-30"
            style={{
              backgroundImage:
                "radial-gradient(circle at 15% 20%, rgba(255,255,255,0.35), transparent 45%), radial-gradient(circle at 85% 10%, rgba(255,255,255,0.2), transparent 40%), radial-gradient(circle at 70% 90%, rgba(59,130,246,0.5), transparent 50%)",
            }}
          />
          <div
            aria-hidden
            className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl"
          />

          <div className="relative mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 sm:py-10 md:px-10 md:py-12">
            <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
              <div className="min-w-0 max-w-xl">
                <p className="text-xs font-medium uppercase tracking-[0.16em] text-white/75 sm:text-sm">
                  Marketplace
                </p>
                <h1 className="mt-1.5 text-2xl font-semibold tracking-tight sm:text-3xl md:text-4xl">
                  Gift Cards
                </h1>
                <p className="mt-2 text-sm leading-relaxed text-white/85 sm:mt-3 sm:text-base">
                  Browse trusted brands, pick an amount, and check out securely — for yourself or
                  someone you care about.
                </p>
              </div>

              <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                {user ? (
                  <>
                    <Link
                      href="/gift-bp"
                      className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-purple-700 shadow-md transition hover:bg-white/95"
                    >
                      <Sparkles className="h-4 w-4" />
                      Gift BP
                      <ArrowRight className="h-4 w-4 opacity-70" />
                    </Link>
                    <Link
                      href={myCardsHref}
                      className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-white/35 bg-white/10 px-4 text-sm font-semibold text-white backdrop-blur-sm transition hover:bg-white/20"
                    >
                      <Wallet className="h-4 w-4" />
                      {myCardsLabel}
                    </Link>
                  </>
                ) : (
                  <div className="flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm text-white/90 backdrop-blur-sm">
                    <ShieldCheck className="h-4 w-4 shrink-0" />
                    Instant delivery after payment
                  </div>
                )}
              </div>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-2 sm:mt-8 sm:grid-cols-3 sm:gap-3">
              <div className="flex items-center gap-3 rounded-xl bg-white/12 px-3.5 py-3 backdrop-blur-sm">
                <Zap className="h-4 w-4 shrink-0 text-white/90" />
                <div>
                  <p className="text-xs font-semibold text-white">Fast checkout</p>
                  <p className="text-[11px] text-white/70">Card or Believe Points</p>
                </div>
              </div>
              <div className="flex items-center gap-3 rounded-xl bg-white/12 px-3.5 py-3 backdrop-blur-sm">
                <Globe className="h-4 w-4 shrink-0 text-white/90" />
                <div>
                  <p className="text-xs font-semibold text-white">{countryLabel}</p>
                  <p className="text-[11px] text-white/70">Selected marketplace</p>
                </div>
              </div>
              <div className="flex items-center gap-3 rounded-xl bg-white/12 px-3.5 py-3 backdrop-blur-sm">
                <Package className="h-4 w-4 shrink-0 text-white/90" />
                <div>
                  <p className="text-xs font-semibold text-white tabular-nums">
                    {totalBrands.toLocaleString()} brands
                  </p>
                  <p className="text-[11px] text-white/70">Available to browse</p>
                </div>
              </div>
            </div>
          </div>
        </header>

        <div className="container mx-auto flex min-w-0 flex-1 flex-col gap-6 px-3 py-4 sm:gap-8 sm:px-4 md:px-10 md:py-6">
        {user ? (
          <Link
            href="/gift-bp"
            className="group flex items-center gap-4 rounded-2xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.06] to-blue-600/[0.06] p-4 transition hover:border-purple-500/40 hover:shadow-md dark:from-purple-500/10 dark:to-blue-500/10"
          >
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/25">
              <Gift className="h-5 w-5" />
            </div>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-foreground">Prefer to gift Believe Points?</p>
              <p className="mt-0.5 text-sm text-muted-foreground">
                Send BP instead — the recipient chooses any gift card they want.
              </p>
            </div>
            <ArrowRight className="h-5 w-5 shrink-0 text-purple-600 transition group-hover:translate-x-0.5" />
          </Link>
        ) : null}

        {/* Filters */}
        <section className="relative z-20 rounded-2xl border border-border/70 bg-card p-3 shadow-sm sm:p-4 dark:border-gray-800 dark:bg-gray-900/80">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search all brands…"
                value={searchQuery}
                onChange={(e) => handleSearch(e.target.value)}
                className="h-11 rounded-xl border-border/80 bg-background pl-10 dark:border-gray-700 dark:bg-gray-900"
                aria-label="Search gift cards"
              />
            </div>

            <Select value={selectedCountry} onValueChange={handleCountryFilter}>
              <SelectTrigger className="h-11 w-full rounded-xl dark:border-gray-700 dark:bg-gray-900 sm:w-[220px]">
                <Globe className="mr-2 h-4 w-4 shrink-0 text-muted-foreground" />
                <SelectValue placeholder="Select country" />
              </SelectTrigger>
              <SelectContent
                position="popper"
                sideOffset={6}
                className="z-[200] max-h-72 dark:border-gray-700 dark:bg-gray-900"
              >
                {Object.entries(availableCountries).map(([code, name]) => (
                  <SelectItem key={code} value={code} className="dark:focus:bg-gray-800 dark:hover:bg-gray-800">
                    {name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <div className="hidden items-center gap-2 text-sm text-muted-foreground xl:flex">
              {isLoading ? (
                <>
                  <Loader2 className="h-3.5 w-3.5 animate-spin" />
                  Updating…
                </>
              ) : (
                <span className="tabular-nums">
                  {brands.length.toLocaleString()}
                  {totalBrands > 0 ? ` of ${totalBrands.toLocaleString()}` : ""} shown
                </span>
              )}
            </div>
          </div>
        </section>

        {/* Catalog — each scroll page fetches the next 20 from Phaze via the server */}
        {isLoading && brands.length === 0 ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => (
              <BrandCardSkeleton key={i} />
            ))}
          </div>
        ) : brands.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-3xl border border-dashed border-border/80 bg-muted/20 px-6 py-16 text-center dark:border-gray-700 dark:bg-gray-900/40">
            <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600/15 to-blue-600/15 text-purple-600 dark:text-purple-400">
              <Gift className="h-7 w-7" />
            </div>
            <h2 className="text-lg font-semibold text-foreground">No gift cards found</h2>
            <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
              Try another search term or switch country to see more brands.
            </p>
            {(searchQuery || selectedCountry !== "USA") && (
              <Button
                type="button"
                variant="outline"
                className="mt-5 rounded-xl"
                onClick={() => {
                  setSearchQuery("")
                  setSelectedCountry("USA")
                  runFilter({ search: "", country: "USA" })
                }}
              >
                Clear filters
              </Button>
            )}
          </div>
        ) : (
          <>
            <div
              className={cn(
                "grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4",
                isLoading && "opacity-60 transition-opacity",
              )}
            >
              {brands.map((brand, index) => {
                const minVal = brand.valueRestrictions?.minVal
                const denomCount = brand.denominations?.length ?? 0
                const hasDiscount = Boolean(brand.discount && brand.discount > 0)

                return (
                  <article
                    key={brand.productId ?? `brand-${index}`}
                    className="group flex flex-col overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-purple-500/35 hover:shadow-lg hover:shadow-purple-600/10 dark:border-gray-800 dark:bg-gray-900/80"
                  >
                    <button
                      type="button"
                      onClick={() => handleViewBrand(brand)}
                      disabled={isLoading || !brand.productId}
                      className="relative aspect-[16/10] w-full overflow-hidden bg-muted text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500 focus-visible:ring-offset-2 dark:bg-gray-800"
                    >
                      {brand.productImage ? (
                        <img
                          src={brand.productImage}
                          alt={brand.productName || "Gift card"}
                          className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                          loading="lazy"
                        />
                      ) : (
                        <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-purple-600/15 to-blue-600/15">
                          <Gift className="h-12 w-12 text-purple-600/40" />
                        </div>
                      )}

                      <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/35 via-transparent to-transparent opacity-80" />

                      <div className="absolute top-2.5 left-2.5 flex max-w-[70%] flex-wrap gap-1.5">
                        {brand.allowsGiftBp === false && (
                          <Badge className="border-0 bg-slate-950/80 text-[10px] font-medium text-white backdrop-blur-sm">
                            Purchased BP only
                          </Badge>
                        )}
                      </div>

                      {hasDiscount && (
                        <Badge className="absolute top-2.5 right-2.5 border-0 bg-rose-500 text-[10px] font-semibold text-white shadow-sm">
                          <Tag className="mr-1 h-3 w-3" />
                          {brand.discount}% off
                        </Badge>
                      )}
                    </button>

                    <div className="flex flex-1 flex-col gap-3 p-4">
                      <div className="min-w-0 flex-1 space-y-1">
                        <h2 className="line-clamp-2 text-[15px] font-semibold leading-snug tracking-tight text-foreground transition group-hover:text-purple-700 dark:group-hover:text-purple-300">
                          {brand.productName || "Gift Card"}
                        </h2>
                        {denomCount > 0 && (
                          <p className="text-xs text-muted-foreground">
                            {denomCount} amount{denomCount === 1 ? "" : "s"} available
                          </p>
                        )}
                      </div>

                      <div className="flex items-end justify-between gap-3 border-t border-border/60 pt-3 dark:border-gray-800">
                        <div>
                          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
                            From
                          </p>
                          <p className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-lg font-bold tabular-nums text-transparent">
                            {formatCurrency(minVal ?? 0)}
                          </p>
                        </div>
                        <Button
                          type="button"
                          size="sm"
                          disabled={isLoading || !brand.productId}
                          onClick={() => handleViewBrand(brand)}
                          className="h-9 rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 px-3.5 text-white shadow-md shadow-purple-600/20 hover:from-purple-500 hover:to-blue-500"
                        >
                          <ShoppingBag className="mr-1.5 h-3.5 w-3.5" />
                          View
                          <ArrowRight className="ml-1 h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                        </Button>
                      </div>
                    </div>
                  </article>
                )
              })}
            </div>

            <div ref={loadMoreSentinelRef} className="flex flex-col items-center gap-3 py-6">
              {loadingMore && (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Loader2 className="h-4 w-4 animate-spin text-purple-600" />
                  Fetching next 20 from Phaze…
                </div>
              )}
              {!loadingMore && hasMore && (
                <p className="text-xs text-muted-foreground">Scroll to load more</p>
              )}
              {!hasMore && brands.length > 0 && (
                <p className="text-sm text-muted-foreground">
                  You’ve reached the end
                  {totalBrands > 0
                    ? ` · ${totalBrands.toLocaleString()} brands`
                    : ` · ${brands.length.toLocaleString()} brands`}
                </p>
              )}
            </div>
          </>
        )}

        {/* Trust strip */}
        <aside className="rounded-2xl border border-purple-500/15 bg-gradient-to-r from-purple-600/[0.05] to-blue-600/[0.05] px-4 py-4 sm:px-5 dark:from-purple-500/10 dark:to-blue-500/10">
          <div className="flex items-start gap-3">
            <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white">
              <Sparkles className="h-4 w-4" />
            </div>
            <div>
              <h3 className="text-sm font-semibold text-foreground">How gift cards work</h3>
              <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                Choose a brand and amount, pay with card or Believe Points, and your voucher is
                delivered after confirmation — ready to use or share.
              </p>
            </div>
          </div>
        </aside>
        </div>
      </div>
    </Layout>
  )
}

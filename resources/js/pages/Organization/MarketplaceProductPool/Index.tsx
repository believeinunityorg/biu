import React, { useEffect, useState } from "react"
import { Head, router, usePage } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import type { BreadcrumbItem } from "@/types"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Info, Package, Search } from "lucide-react"
import { toast } from "sonner"
import { route } from "ziggy-js"

const breadcrumbs: BreadcrumbItem[] = [
  { title: "Dashboard", href: "/dashboard" },
  { title: "Merchant product pool", href: route("marketplace.product-pool.index") },
]

interface MerchantMini {
  id: number
  business_name: string | null
  name: string | null
}

interface PoolRow {
  id: number
  name: string
  description: string | null
  category: string | null
  base_price: string
  min_resale_price: string | null
  suggested_retail_price: string | null
  product_type: string
  images?: string[] | null
  nonprofit_approval_type: string
  pickup_available?: boolean
  already_adopted: boolean
  /** When already_adopted: active = live on marketplace; pending_merchant_approval = not public yet */
  adoption_status?: string | null
  merchant?: MerchantMini | null
  organization_product_id?: number | null
  listing_pickup_available?: boolean
  listing_supporter_message?: string | null
  listing_is_featured?: boolean
}

interface Paginated {
  data: PoolRow[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  links: { url: string | null; label: string; active: boolean }[]
}

interface PoolCategoryOption {
  id: number
  name: string
}

interface Props {
  products: Paginated
  categories: PoolCategoryOption[]
  filters: { search: string; category: string }
}

export default function MarketplaceProductPoolIndex({ products, categories, filters: initial }: Props) {
  const [search, setSearch] = useState(initial.search || "")
  const [category, setCategory] = useState(initial.category || "")
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selected, setSelected] = useState<PoolRow | null>(null)
  const [customPrice, setCustomPrice] = useState("")
  const [message, setMessage] = useState("")
  const [featured, setFeatured] = useState(false)
  const [pickupAtOrg, setPickupAtOrg] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [settingsRow, setSettingsRow] = useState<PoolRow | null>(null)
  const [settingsPickup, setSettingsPickup] = useState(false)
  const [settingsMessage, setSettingsMessage] = useState("")
  const [settingsFeatured, setSettingsFeatured] = useState(false)
  const [settingsSubmitting, setSettingsSubmitting] = useState(false)

  const { success, flash, errors, merchantDomain } = usePage().props as {
    success?: string
    flash?: { success?: string }
    errors?: Record<string, string>
    merchantDomain?: string | null
  }

  const merchantPoolApprovalsUrl =
    merchantDomain && String(merchantDomain).trim() !== ""
      ? `https://${String(merchantDomain).replace(/^https?:\/\//, "")}/marketplace-pool-approvals`
      : null

  useEffect(() => {
    const msg = success || flash?.success
    if (msg) toast.success(msg)
  }, [success, flash?.success])

  useEffect(() => {
    if (errors?.pickup_available) toast.error(errors.pickup_available)
    if (errors?.error) toast.error(errors.error)
  }, [errors])

  const applyFilters = () => {
    router.get(
      route("marketplace.product-pool.index"),
      { search: search || undefined, category: category || undefined },
      { preserveState: true, replace: true },
    )
  }

  const openSell = (p: PoolRow) => {
    setSelected(p)
    const suggested = p.suggested_retail_price || p.base_price
    setCustomPrice(String(Number(suggested)))
    setMessage("")
    setFeatured(false)
    const merchantAllowsPickup =
      !!p.pickup_available && ["physical", "service", "media"].includes(String(p.product_type))
    setPickupAtOrg(merchantAllowsPickup)
    setDialogOpen(true)
  }

  const openListingSettings = (p: PoolRow) => {
    if (!p.organization_product_id) return
    setSettingsRow(p)
    setSettingsPickup(!!p.listing_pickup_available)
    setSettingsMessage(p.listing_supporter_message ?? "")
    setSettingsFeatured(!!p.listing_is_featured)
    setSettingsOpen(true)
  }

  const submitListingSettings = () => {
    if (!settingsRow?.organization_product_id) return
    setSettingsSubmitting(true)
    router.patch(
      route("marketplace.product-pool.listing.update", settingsRow.organization_product_id),
      {
        pickup_available: settingsPickup,
        supporter_message: settingsMessage || null,
        is_featured: settingsFeatured,
      },
      {
        preserveScroll: true,
        onFinish: () => setSettingsSubmitting(false),
        onSuccess: () => {
          setSettingsOpen(false)
          setSettingsRow(null)
        },
      },
    )
  }

  const submitAdopt = () => {
    if (!selected) return
    setSubmitting(true)
    router.post(
      route("marketplace.product-pool.adopt"),
      {
        marketplace_product_id: selected.id,
        custom_price: customPrice,
        supporter_message: message || null,
        is_featured: featured,
        pickup_available: pickupAtOrg,
      },
      {
        preserveScroll: true,
        onFinish: () => setSubmitting(false),
        onSuccess: () => {
          setDialogOpen(false)
          setSelected(null)
        },
      },
    )
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Merchant product pool" />
      <div className="mx-auto max-w-6xl space-y-6 p-4 md:p-6">
        <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight flex items-center gap-2">
              <Package className="h-7 w-7" />
              Add products from the merchant pool
            </h1>
            <p className="text-muted-foreground text-sm mt-1">
              Browse items merchants have listed for nonprofits. When you sell one, it appears as your organization&apos;s listing with your price and message.
              Physical shipments use Shippo at checkout with the <span className="font-medium text-foreground">merchant&apos;s</span> address as the ship-from location.
            </p>
          </div>
        </div>

        <Card className="border-blue-200/80 bg-blue-50/50 dark:border-blue-900/50 dark:bg-blue-950/20">
          <CardHeader className="pb-2">
            <CardTitle className="text-base flex items-center gap-2">
              <Info className="h-4 w-4 shrink-0" />
              When a listing says &quot;Pending merchant approval&quot;
            </CardTitle>
            <CardDescription className="text-sm leading-relaxed">
              The merchant chose manual approval for that product. They must approve your listing in their{" "}
              <span className="font-medium text-foreground">merchant dashboard</span> under{" "}
              <span className="font-medium text-foreground">Pool listing approvals</span>
              {merchantPoolApprovalsUrl ? (
                <>
                  :{" "}
                  <a
                    href={merchantPoolApprovalsUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="font-medium text-primary underline underline-offset-2"
                  >
                    open approvals (merchant portal)
                  </a>
                </>
              ) : (
                <>
                  . On the merchant site, open <code className="rounded bg-muted px-1 py-0.5 text-xs">/marketplace-pool-approvals</code> after signing in.
                </>
              )}
            </CardDescription>
          </CardHeader>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Filters</CardTitle>
            <CardDescription>Search by name or narrow by category.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col sm:flex-row gap-3">
            <div className="relative flex-1">
              <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="pl-8"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search…"
                onKeyDown={(e) => e.key === "Enter" && applyFilters()}
              />
            </div>
            <select
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
            >
              <option value="">All categories</option>
              {categories.map((c) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}
                </option>
              ))}
            </select>
            <Button type="button" variant="secondary" onClick={applyFilters}>
              Apply
            </Button>
          </CardContent>
        </Card>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {products.data.length === 0 && (
            <p className="col-span-full text-center text-muted-foreground py-12">No products in the pool yet.</p>
          )}
          {products.data.map((p) => (
            <Card key={p.id} className="overflow-hidden flex flex-col">
              <div className="aspect-video bg-muted flex items-center justify-center">
                {p.images?.[0] ? (
                  <img src={p.images[0]} alt="" className="h-full w-full object-cover" />
                ) : (
                  <Package className="h-12 w-12 text-muted-foreground/50" />
                )}
              </div>
              <CardHeader className="pb-2">
                <CardTitle className="text-base leading-snug">{p.name}</CardTitle>
                <CardDescription className="line-clamp-2">{p.description || "—"}</CardDescription>
              </CardHeader>
              <CardContent className="mt-auto space-y-3">
                <p className="text-sm text-muted-foreground">
                  From {p.merchant?.business_name || p.merchant?.name || "Merchant"} · {p.category || "General"}
                </p>
                <p className="text-sm font-medium">Base ${Number(p.base_price).toFixed(2)}</p>
                {p.already_adopted ? (
                  <div className="space-y-2">
                    <p className="text-xs text-muted-foreground text-center">
                      {p.adoption_status === "pending_merchant_approval"
                        ? "Pending merchant approval"
                        : "You are selling this product"}
                    </p>
                    {p.organization_product_id ? (
                      <Button variant="outline" size="sm" className="w-full" type="button" onClick={() => openListingSettings(p)}>
                        Listing settings (pickup, message)
                      </Button>
                    ) : null}
                  </div>
                ) : (
                  <Button size="sm" className="w-full" onClick={() => openSell(p)}>
                    Sell this product
                  </Button>
                )}
              </CardContent>
            </Card>
          ))}
        </div>

        {products.last_page > 1 && (
          <div className="flex flex-wrap justify-center gap-2">
            {products.links.map((l, i) =>
              l.url ? (
                <Button
                  key={i}
                  type="button"
                  variant={l.active ? "default" : "outline"}
                  size="sm"
                  onClick={() => l.url && router.visit(l.url)}
                  dangerouslySetInnerHTML={{ __html: l.label }}
                />
              ) : (
                <span key={i} className="px-2 py-1 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: l.label }} />
              ),
            )}
          </div>
        )}
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>List “{selected?.name}”</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div>
              <Label htmlFor="price">Your selling price (USD)</Label>
              <Input
                id="price"
                type="number"
                step="0.01"
                min="0.01"
                value={customPrice}
                onChange={(e) => setCustomPrice(e.target.value)}
              />
              {selected?.min_resale_price != null && (
                <p className="text-xs text-muted-foreground mt-1">Minimum: ${Number(selected.min_resale_price).toFixed(2)}</p>
              )}
              {errors?.custom_price && <p className="text-sm text-destructive mt-1">{errors.custom_price}</p>}
              {errors?.marketplace_product_id && (
                <p className="text-sm text-destructive mt-1">{errors.marketplace_product_id}</p>
              )}
            </div>
            <div>
              <Label htmlFor="msg">Message to supporters (optional)</Label>
              <Textarea
                id="msg"
                rows={3}
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder="e.g. Proceeds support our youth programs"
              />
            </div>
            <div className="flex items-center gap-2">
              <input id="feat" type="checkbox" checked={featured} onChange={(e) => setFeatured(e.target.checked)} />
              <label htmlFor="feat" className="text-sm">
                Feature on our profile (optional)
              </label>
            </div>
            {selected?.pickup_available && ["physical", "service", "media"].includes(String(selected.product_type)) && (
              <div className="flex items-start gap-2 rounded-md border border-border/80 bg-muted/30 p-3">
                <input
                  id="pickup_org"
                  type="checkbox"
                  checked={pickupAtOrg}
                  onChange={(e) => setPickupAtOrg(e.target.checked)}
                  className="mt-0.5"
                />
                <label htmlFor="pickup_org" className="text-sm leading-snug">
                  Offer local pickup at our organization address (no shipping when buyers choose pickup). Requires the merchant to allow pickup on this SKU. Uncheck to disable for this listing only.
                </label>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button type="button" onClick={submitAdopt} disabled={submitting}>
              {submitting ? "Saving…" : "Confirm"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={settingsOpen} onOpenChange={setSettingsOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Listing settings — {settingsRow?.name}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            {settingsRow?.pickup_available &&
              ["physical", "service", "media"].includes(String(settingsRow.product_type)) && (
                <div className="flex items-start gap-2 rounded-md border border-border/80 bg-muted/30 p-3">
                  <input
                    id="settings_pickup"
                    type="checkbox"
                    checked={settingsPickup}
                    onChange={(e) => setSettingsPickup(e.target.checked)}
                    className="mt-0.5"
                  />
                  <label htmlFor="settings_pickup" className="text-sm leading-snug">
                    Offer local pickup at our organization (checkout shows our address; $0 shipping when selected). Only if the merchant allows pickup on this product.
                  </label>
                </div>
              )}
            <div>
              <Label htmlFor="settings_msg">Message to supporters</Label>
              <Textarea
                id="settings_msg"
                rows={3}
                value={settingsMessage}
                onChange={(e) => setSettingsMessage(e.target.value)}
                placeholder="Shown on the public listing"
              />
            </div>
            <div className="flex items-center gap-2">
              <input
                id="settings_feat"
                type="checkbox"
                checked={settingsFeatured}
                onChange={(e) => setSettingsFeatured(e.target.checked)}
              />
              <label htmlFor="settings_feat" className="text-sm">
                Feature on our profile
              </label>
            </div>
            {errors?.pickup_available && <p className="text-sm text-destructive">{errors.pickup_available}</p>}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setSettingsOpen(false)}>
              Cancel
            </Button>
            <Button type="button" onClick={submitListingSettings} disabled={settingsSubmitting}>
              {settingsSubmitting ? "Saving…" : "Save"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  )
}

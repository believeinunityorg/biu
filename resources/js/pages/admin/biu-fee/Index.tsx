"use client"

import { Head, Link, useForm, usePage } from "@inertiajs/react"
import { useMemo, useState } from "react"
import AppLayout from "@/layouts/app-layout"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Separator } from "@/components/ui/separator"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import type { BreadcrumbItem } from "@/types"
import {
  ArrowUpRight,
  BookOpen,
  CheckCircle2,
  Coins,
  Gift,
  Globe,
  Info,
  Percent,
  Save,
  ShoppingBag,
  Store,
  Ticket,
  Wrench,
} from "lucide-react"
import { cn } from "@/lib/utils"

interface FeeGroup {
  key: string
  label: string
}

interface FeeModule {
  id: string
  group: string
  name: string
  short_name: string
  description: string
  fee_type: "percentage" | "fixed_usd" | "none"
  setting_field: string | null
  linked_setting_field: string | null
  linked_setting_field_alt?: string | null
  payer: "buyer" | "seller" | "organization" | "mixed"
  checkout: string
  refundable: boolean
  example_base_usd: number
  sort: number
  deprecated?: boolean
  is_primary_knob?: boolean
  admin_link?: string
  editable: boolean
  effective_value: number | null
  effective_unit: "percent" | "usd" | null
  rate_source: string | null
  example_fee_usd: number | null
}

interface Props {
  sales_platform_fee_percentage: number
  course_platform_fee_percentage: number
  event_platform_fee_percentage: number
  marketplace_printify_organization_fee_percentage: number
  marketplace_merchant_pool_fee_percentage: number
  gift_card_platform_fee_usd: number
  groups: FeeGroup[]
  modules: FeeModule[]
  editable_fields: string[]
}

type FormData = {
  sales_platform_fee_percentage: string
  course_platform_fee_percentage: string
  event_platform_fee_percentage: string
  marketplace_printify_organization_fee_percentage: string
  marketplace_merchant_pool_fee_percentage: string
  gift_card_platform_fee_usd: string
}

const breadcrumbs: BreadcrumbItem[] = [
  { title: "Dashboard", href: "/dashboard" },
  { title: "System", href: "#" },
  { title: "BIU fee (platform)", href: "/admin/biu-fee" },
]

const GROUP_ICONS: Record<string, typeof ShoppingBag> = {
  commerce: ShoppingBag,
  connection_hub: BookOpen,
  programs: Wrench,
  gift_cards: Gift,
}

const MODULE_ICONS: Record<string, typeof ShoppingBag> = {
  marketplace_printify_org: Store,
  marketplace_merchant_pool: ShoppingBag,
  marketplace_auctions: Ticket,
  connection_hub_courses: BookOpen,
  connection_hub_events: Globe,
  service_hub: Wrench,
  raffles: Ticket,
  merchant_hub_cash: Store,
  global_sales: Percent,
  gift_cards_legacy: Gift,
  gift_cards_supporter_tip: Gift,
}

const FIELD_LABELS: Record<keyof FormData, string> = {
  sales_platform_fee_percentage: "Global sales %",
  course_platform_fee_percentage: "Courses %",
  event_platform_fee_percentage: "Meetups / events %",
  marketplace_printify_organization_fee_percentage: "Printify / org %",
  marketplace_merchant_pool_fee_percentage: "Merchant / pool %",
  gift_card_platform_fee_usd: "Legacy fixed fee (USD)",
}

function payerBadge(payer: FeeModule["payer"]) {
  switch (payer) {
    case "buyer":
      return { label: "Buyer pays", className: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/25" }
    case "seller":
      return { label: "Seller deducted", className: "bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/25" }
    case "organization":
      return { label: "Org payout", className: "bg-violet-500/10 text-violet-700 dark:text-violet-300 border-violet-500/25" }
    default:
      return { label: "Varies by module", className: "bg-muted text-muted-foreground" }
  }
}

function checkoutLabel(checkout: string) {
  switch (checkout) {
    case "added":
      return "Added at checkout"
    case "deducted":
      return "Deducted from seller"
    case "post_sale":
      return "On fulfillment"
    case "optional_tip":
      return "Optional supporter tip"
    default:
      return checkout
  }
}

function formatRate(module: FeeModule): string {
  if (module.fee_type === "none") {
    return "No platform fee"
  }
  if (module.effective_value === null) {
    return "—"
  }
  if (module.effective_unit === "usd") {
    return `$${module.effective_value.toFixed(2)}`
  }
  return `${module.effective_value}%`
}

function rateSourceLabel(source: string | null, modules: FeeModule[]): string | null {
  if (!source || source === "direct") {
    return null
  }
  const linked = modules.find((m) => m.setting_field === source)
  return linked?.short_name ?? FIELD_LABELS[source as keyof FormData] ?? source
}

function livePreview(field: keyof FormData, value: string): string {
  const num = parseFloat(value) || 0
  if (field === "gift_card_platform_fee_usd") {
    const face = 25
    return `$${face} face + $${num.toFixed(2)} legacy fee → $${(face + num).toFixed(2)} BP (50/50 split)`
  }
  const base = field === "marketplace_printify_organization_fee_percentage" ? 60
    : field === "marketplace_merchant_pool_fee_percentage" ? 40
      : 100
  return `$${base.toFixed(2)} base → $${((base * num) / 100).toFixed(2)} platform fee`
}

function ModuleOverviewCard({ module, modules }: { module: FeeModule; modules: FeeModule[] }) {
  const Icon = MODULE_ICONS[module.id] ?? Coins
  const payer = payerBadge(module.payer)
  const source = rateSourceLabel(module.rate_source, modules)

  return (
    <Card
      className={cn(
        "group relative overflow-hidden transition-shadow hover:shadow-md",
        module.deprecated && "opacity-80",
      )}
    >
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between gap-2">
          <div className="flex items-center gap-2.5 min-w-0">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Icon className="h-4 w-4" />
            </div>
            <div className="min-w-0">
              <CardTitle className="text-sm font-semibold leading-tight truncate">{module.short_name}</CardTitle>
              {source && (
                <p className="text-xs text-muted-foreground mt-0.5 truncate">Uses {source}</p>
              )}
            </div>
          </div>
          <span className="text-lg font-bold tabular-nums text-foreground shrink-0">{formatRate(module)}</span>
        </div>
      </CardHeader>
      <CardContent className="space-y-2.5 pt-0">
        <div className="flex flex-wrap gap-1.5">
          <Badge variant="outline" className={cn("text-[10px] font-medium", payer.className)}>
            {payer.label}
          </Badge>
          <Badge variant="outline" className="text-[10px]">
            {checkoutLabel(module.checkout)}
          </Badge>
          {module.deprecated && (
            <Badge variant="outline" className="text-[10px] border-amber-500/30 text-amber-700 dark:text-amber-300">
              Legacy
            </Badge>
          )}
          {module.editable && (
            <Badge variant="secondary" className="text-[10px]">
              Editable
            </Badge>
          )}
        </div>
        {module.example_fee_usd !== null && module.fee_type !== "none" && (
          <p className="text-xs text-muted-foreground">
            Example: ${module.example_base_usd.toFixed(2)} → ${module.example_fee_usd.toFixed(2)} fee
          </p>
        )}
      </CardContent>
    </Card>
  )
}

function EditableFeeCard({
  module,
  data,
  setData,
  errors,
}: {
  module: FeeModule
  data: FormData
  setData: (key: keyof FormData, value: string) => void
  errors: Partial<Record<keyof FormData, string>>
}) {
  if (!module.setting_field || !module.editable) {
    return null
  }

  const field = module.setting_field as keyof FormData
  const isFixed = module.fee_type === "fixed_usd"

  return (
    <Card id={`fee-${module.id}`} className={cn(module.deprecated && "border-dashed")}>
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle className="text-base">{module.name}</CardTitle>
            <CardDescription className="max-w-2xl">{module.description}</CardDescription>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {module.deprecated && (
              <Badge variant="outline" className="border-amber-500/30 text-amber-700 dark:text-amber-300">
                Legacy
              </Badge>
            )}
            <Badge variant="outline" className={payerBadge(module.payer).className}>
              {payerBadge(module.payer).label}
            </Badge>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-[minmax(0,200px)_1fr] sm:items-end">
          <div className="space-y-2">
            <Label htmlFor={field}>{isFixed ? "Fixed fee (USD / BP)" : "Percent (%)"}</Label>
            <Input
              id={field}
              type="number"
              step={isFixed ? "0.01" : "0.01"}
              min={0}
              max={100}
              value={data[field]}
              onChange={(e) => setData(field, e.target.value)}
              className="font-mono"
            />
            {errors[field] && <p className="text-sm text-destructive">{errors[field]}</p>}
          </div>
          <div className="rounded-lg border bg-muted/40 px-4 py-3 text-sm">
            <p className="font-medium text-foreground">Live preview</p>
            <p className="mt-1 text-muted-foreground">{livePreview(field, data[field])}</p>
          </div>
        </div>
        {module.admin_link && (
          <Link
            href={module.admin_link}
            className="inline-flex items-center gap-1 text-sm text-primary hover:underline underline-offset-4"
          >
            Related admin page
            <ArrowUpRight className="h-3.5 w-3.5" />
          </Link>
        )}
      </CardContent>
    </Card>
  )
}

function LinkedModuleCard({ module, modules }: { module: FeeModule; modules: FeeModule[] }) {
  const source = rateSourceLabel(module.rate_source, modules)
  if (module.editable || module.fee_type === "none") {
    return null
  }

  return (
    <Card id={`fee-${module.id}`} className="border-muted bg-muted/20">
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle className="text-base">{module.name}</CardTitle>
            <CardDescription className="max-w-2xl">{module.description}</CardDescription>
          </div>
          <span className="text-xl font-bold tabular-nums">{formatRate(module)}</span>
        </div>
      </CardHeader>
      <CardContent className="space-y-3 pt-0">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Info className="h-4 w-4 text-muted-foreground shrink-0" />
          <span className="text-muted-foreground">
            Rate controlled by{" "}
            <span className="font-medium text-foreground">{source ?? "shared setting"}</span>
            {module.setting_field === null && module.linked_setting_field && (
              <>
                {" "}
                — edit it in the{" "}
                <a
                  href={`#fee-${modules.find((m) => m.setting_field === module.linked_setting_field)?.id ?? module.linked_setting_field}`}
                  className="text-primary hover:underline underline-offset-4"
                >
                  {source}
                </a>{" "}
                section above
              </>
            )}
          </span>
        </div>
        <div className="flex flex-wrap gap-1.5">
          <Badge variant="outline" className={payerBadge(module.payer).className}>
            {payerBadge(module.payer).label}
          </Badge>
          <Badge variant="outline">{checkoutLabel(module.checkout)}</Badge>
        </div>
        {module.admin_link && (
          <Link
            href={module.admin_link}
            className="inline-flex items-center gap-1 text-sm text-primary hover:underline underline-offset-4"
          >
            Module settings
            <ArrowUpRight className="h-3.5 w-3.5" />
          </Link>
        )}
      </CardContent>
    </Card>
  )
}

export default function AdminBiuFeeIndex(props: Props) {
  const { groups, modules } = props
  const { flash } = usePage().props as { flash?: { success?: string; error?: string } }
  const [activeTab, setActiveTab] = useState("overview")

  const { data, setData, put, processing, errors } = useForm<FormData>({
    sales_platform_fee_percentage: String(props.sales_platform_fee_percentage ?? 0),
    course_platform_fee_percentage: String(props.course_platform_fee_percentage ?? 0),
    event_platform_fee_percentage: String(props.event_platform_fee_percentage ?? 0),
    marketplace_printify_organization_fee_percentage: String(
      props.marketplace_printify_organization_fee_percentage ?? 0,
    ),
    marketplace_merchant_pool_fee_percentage: String(props.marketplace_merchant_pool_fee_percentage ?? 0),
    gift_card_platform_fee_usd: String(props.gift_card_platform_fee_usd ?? 0.5),
  })

  const liveModules = useMemo(
    () =>
      modules.map((module) => {
        if (!module.setting_field) {
          return module
        }
        const field = module.setting_field as keyof FormData
        const value = parseFloat(data[field]) || 0
        const exampleBase = module.example_base_usd
        let exampleFee: number | null = null
        if (module.fee_type === "percentage") {
          exampleFee = round2(exampleBase * value / 100)
        } else if (module.fee_type === "fixed_usd") {
          exampleFee = round2(value)
        }
        return {
          ...module,
          effective_value: value,
          example_fee_usd: exampleFee,
        }
      }).map((module) => {
        if (module.editable || !module.linked_setting_field) {
          return module
        }
        const linkedField = module.linked_setting_field as keyof FormData
        const linkedValue = parseFloat(data[linkedField]) || 0
        return {
          ...module,
          effective_value: linkedValue,
          example_fee_usd: module.fee_type === "percentage"
            ? round2(module.example_base_usd * linkedValue / 100)
            : module.example_fee_usd,
        }
      }),
    [modules, data],
  )

  const editableModules = liveModules.filter((m) => m.editable)
  const linkedModules = liveModules.filter((m) => !m.editable && m.fee_type !== "none")

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    put(route("admin.biu-fee.update"))
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="BIU platform fees — Admin" />

      <form onSubmit={handleSubmit} className="relative pb-24">
        <div className="w-full space-y-6 p-4 sm:p-6">
          {/* Header */}
          <div className="space-y-3">
            <div className="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
              <Coins className="h-3.5 w-3.5" />
              Admin · Platform fees
            </div>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
              <div className="space-y-2 max-w-3xl">
                <h1 className="text-3xl font-bold tracking-tight text-foreground">BIU platform fees</h1>
                <p className="text-muted-foreground">
                  Configure how Believe In Unity earns on each sales module. Percentages apply to the sale base
                  (subtotal, listing fee, or ticket total — not tax or shipping). Linked modules inherit rates
                  from the knobs you set here.
                </p>
              </div>
              <div className="flex flex-wrap gap-2 shrink-0">
                <Badge variant="secondary">{liveModules.length} modules</Badge>
                <Badge variant="secondary">{editableModules.length} editable rates</Badge>
              </div>
            </div>
          </div>

          {flash?.success && (
            <Alert className="border-emerald-500/30 bg-emerald-500/10">
              <CheckCircle2 className="h-4 w-4 text-emerald-600" />
              <AlertTitle>Saved</AlertTitle>
              <AlertDescription>{flash.success}</AlertDescription>
            </Alert>
          )}

          <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
            <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1 bg-muted/60 p-1">
              <TabsTrigger value="overview" className="text-xs sm:text-sm">Overview</TabsTrigger>
              <TabsTrigger value="configure" className="text-xs sm:text-sm">Configure rates</TabsTrigger>
              {groups.map((group) => (
                <TabsTrigger key={group.key} value={group.key} className="text-xs sm:text-sm">
                  {group.label}
                </TabsTrigger>
              ))}
            </TabsList>

            {/* Overview — all modules at a glance */}
            <TabsContent value="overview" className="space-y-6 mt-0">
              {groups.map((group) => {
                const GroupIcon = GROUP_ICONS[group.key] ?? Coins
                const groupModules = liveModules.filter((m) => m.group === group.key)
                if (groupModules.length === 0) {
                  return null
                }
                return (
                  <section key={group.key} className="space-y-3">
                    <div className="flex items-center gap-2">
                      <GroupIcon className="h-4 w-4 text-primary" />
                      <h2 className="text-lg font-semibold">{group.label}</h2>
                      <Badge variant="outline" className="text-xs">{groupModules.length}</Badge>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                      {groupModules.map((module) => (
                        <ModuleOverviewCard key={module.id} module={module} modules={liveModules} />
                      ))}
                    </div>
                  </section>
                )
              })}

              <Card className="border-primary/20 bg-primary/5">
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">Mixed cart example ($100 total)</CardTitle>
                  <CardDescription>Marketplace checkout with both line tiers.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-2 text-sm sm:grid-cols-3">
                  <div className="rounded-md bg-background/80 px-3 py-2">
                    <p className="text-muted-foreground">Printify/org @ {data.marketplace_printify_organization_fee_percentage}% on $60</p>
                    <p className="font-mono font-medium">${((60 * (parseFloat(data.marketplace_printify_organization_fee_percentage) || 0)) / 100).toFixed(2)}</p>
                  </div>
                  <div className="rounded-md bg-background/80 px-3 py-2">
                    <p className="text-muted-foreground">Merchant/pool @ {data.marketplace_merchant_pool_fee_percentage}% on $40</p>
                    <p className="font-mono font-medium">${((40 * (parseFloat(data.marketplace_merchant_pool_fee_percentage) || 0)) / 100).toFixed(2)}</p>
                  </div>
                  <div className="rounded-md bg-background/80 px-3 py-2">
                    <p className="text-muted-foreground">Combined platform fee</p>
                    <p className="font-mono font-medium text-primary">
                      $
                      {(
                        (60 * (parseFloat(data.marketplace_printify_organization_fee_percentage) || 0)) / 100
                        + (40 * (parseFloat(data.marketplace_merchant_pool_fee_percentage) || 0)) / 100
                      ).toFixed(2)}
                    </p>
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            {/* Configure — all editable knobs */}
            <TabsContent value="configure" className="space-y-6 mt-0">
              <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                Edit the rate knobs below. Modules like Service Hub, raffles, and Merchant Hub cash automatically
                use the linked rate — no duplicate settings to maintain.
              </div>

              <section className="space-y-4">
                <h2 className="text-lg font-semibold">Rate knobs</h2>
                {editableModules
                  .sort((a, b) => (a.is_primary_knob ? -1 : 0) - (b.is_primary_knob ? -1 : 0) || a.sort - b.sort)
                  .map((module) => (
                    <EditableFeeCard
                      key={module.id}
                      module={module}
                      data={data}
                      setData={setData}
                      errors={errors}
                    />
                  ))}
              </section>

              <Separator />

              <section className="space-y-4">
                <h2 className="text-lg font-semibold">Linked modules</h2>
                <p className="text-sm text-muted-foreground -mt-2">
                  These modules follow existing fee logic and inherit rates from the knobs above.
                </p>
                <div className="grid gap-3 lg:grid-cols-2">
                  {linkedModules.map((module) => (
                    <LinkedModuleCard key={module.id} module={module} modules={liveModules} />
                  ))}
                </div>
              </section>
            </TabsContent>

            {/* Per-group tabs */}
            {groups.map((group) => (
              <TabsContent key={group.key} value={group.key} className="space-y-4 mt-0">
                {liveModules
                  .filter((m) => m.group === group.key)
                  .map((module) =>
                    module.editable ? (
                      <EditableFeeCard
                        key={module.id}
                        module={module}
                        data={data}
                        setData={setData}
                        errors={errors}
                      />
                    ) : (
                      <LinkedModuleCard key={module.id} module={module} modules={liveModules} />
                    ),
                  )}
              </TabsContent>
            ))}
          </Tabs>

          <Card className="border-muted">
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Related settings</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-4 text-sm">
              <Link href="/settings/service-hub" className="inline-flex items-center gap-1 text-primary hover:underline underline-offset-4">
                Service Hub transaction fees
                <ArrowUpRight className="h-3.5 w-3.5" />
              </Link>
              <Link href="/admin/gift-card-revenue" className="inline-flex items-center gap-1 text-primary hover:underline underline-offset-4">
                Gift card revenue & provider commission
                <ArrowUpRight className="h-3.5 w-3.5" />
              </Link>
              <Link href="/admin/processing-fees" className="inline-flex items-center gap-1 text-primary hover:underline underline-offset-4">
                Payment provider fees (Stripe)
                <ArrowUpRight className="h-3.5 w-3.5" />
              </Link>
            </CardContent>
          </Card>
        </div>

        {/* Sticky save bar */}
        <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
          <div className="mx-auto flex max-w-screen-2xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <p className="hidden text-sm text-muted-foreground sm:block">
              {editableModules.length} editable rates · changes apply to new checkouts immediately
            </p>
            <Button type="submit" disabled={processing} className="ml-auto gap-2">
              <Save className="h-4 w-4" />
              {processing ? "Saving…" : "Save all rates"}
            </Button>
          </div>
        </div>
      </form>
    </AppLayout>
  )
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

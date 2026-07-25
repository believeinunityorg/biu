"use client"

import { Head, router } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import type { BreadcrumbItem } from "@/types"
import { Gift, Percent } from "lucide-react"
import { useState } from "react"

interface Statistics {
  gift_card_sales: number
  buyer_platform_fees: number
  platform_fee_biu_share: number
  platform_fee_org_share: number
  provider_commissions: number
  biu_revenue_share: number
  organization_revenue: number
  biu_total_earnings: number
  organization_total_earnings: number
  merchant_revenue: number
  purchase_count: number
  biu_share_percentage: number
  platform_fee_biu_share_percentage: number
}

interface RecentPurchase {
  id: number
  purchased_at: string | null
  brand_name: string | null
  amount: number
  platform_fee: number | null
  platform_fee_biu_share: number | null
  platform_fee_org_share: number | null
  provider_commission: number | null
  biu_revenue_share: number | null
  organization_revenue: number | null
  merchant_revenue: number
  organization_name: string | null
  buyer_name: string | null
  payment_method: string | null
}

interface Props {
  filters: { from: string | null; to: string | null }
  statistics: Statistics
  recentPurchases: RecentPurchase[]
}

const breadcrumbs: BreadcrumbItem[] = [
  { title: "Dashboard", href: "/dashboard" },
  { title: "System", href: "#" },
  { title: "Gift card revenue", href: "/admin/gift-card-revenue" },
]

function formatUsd(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return "—"
  }
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(value)
}

export default function AdminGiftCardRevenueIndex({ filters, statistics, recentPurchases }: Props) {
  const [from, setFrom] = useState(filters.from ?? "")
  const [to, setTo] = useState(filters.to ?? "")

  const applyFilters = (e: React.FormEvent) => {
    e.preventDefault()
    router.get(
      route("admin.gift-card-revenue.index"),
      { from: from || undefined, to: to || undefined },
      { preserveState: true },
    )
  }

  const clearFilters = () => {
    setFrom("")
    setTo("")
    router.get(route("admin.gift-card-revenue.index"))
  }

  const feePct = statistics.platform_fee_biu_share_percentage ?? 50
  const orgFeePct = 100 - feePct

  const metrics = [
    { label: "Gift card sales (face value)", value: statistics.gift_card_sales },
    { label: "Buyer platform fees", value: statistics.buyer_platform_fees },
    { label: `Platform fee → BIU (${feePct}%)`, value: statistics.platform_fee_biu_share },
    { label: `Platform fee → Org (${orgFeePct}%)`, value: statistics.platform_fee_org_share },
    { label: "Provider commissions", value: statistics.provider_commissions },
    { label: `BIU provider share (${statistics.biu_share_percentage}%)`, value: statistics.biu_revenue_share },
    { label: "Org provider share", value: statistics.organization_revenue },
    { label: "BIU total earnings", value: statistics.biu_total_earnings },
    { label: "Organization total earnings", value: statistics.organization_total_earnings },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Gift card revenue — Admin" />
      <div className="w-full space-y-6 p-4 sm:p-6">
        <div className="space-y-2">
          <div className="inline-flex items-center gap-2 rounded-full border border-fuchsia-500/25 bg-fuchsia-500/10 px-3 py-1 text-xs font-medium text-fuchsia-700 dark:text-fuchsia-300">
            <Gift className="h-3.5 w-3.5" />
            Admin · Gift cards
          </div>
          <h1 className="text-3xl font-bold text-foreground">Gift card revenue share</h1>
          <p className="max-w-3xl text-muted-foreground">
            Gift cards sell at face value plus a fixed buyer platform fee. That fee is split{" "}
            <span className="font-medium text-foreground">{feePct}% BIU / {orgFeePct}% organization</span>.
            Separately, BIU earns{" "}
            <span className="font-medium text-foreground">{statistics.biu_share_percentage}%</span> of provider (Phaze)
            commissions; the remainder goes to the beneficiary organization.
          </p>
        </div>

        <Card className="max-w-xl border-muted">
          <CardHeader>
            <CardTitle className="text-base flex items-center gap-2">
              <Percent className="h-4 w-4" />
              Revenue model
            </CardTitle>
            <CardDescription>{statistics.purchase_count} completed purchases in range.</CardDescription>
          </CardHeader>
          <CardContent className="text-sm text-muted-foreground space-y-1">
            <p>Buyer platform fee splits: BIU {feePct}% / organization {orgFeePct}%.</p>
            <p>Provider commission splits: BIU {statistics.biu_share_percentage}% / organization remainder / merchant $0.</p>
          </CardContent>
        </Card>

        <form onSubmit={applyFilters} className="flex flex-wrap items-end gap-4">
          <div className="space-y-1">
            <Label htmlFor="from">From</Label>
            <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-[160px]" />
          </div>
          <div className="space-y-1">
            <Label htmlFor="to">To</Label>
            <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-[160px]" />
          </div>
          <Button type="submit">Apply</Button>
          <Button type="button" variant="outline" onClick={clearFilters}>
            Clear
          </Button>
        </form>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3">
          {metrics.map((m) => (
            <Card key={m.label}>
              <CardHeader className="pb-2">
                <CardDescription className="text-xs">{m.label}</CardDescription>
                <CardTitle className="text-2xl">{formatUsd(m.value)}</CardTitle>
              </CardHeader>
            </Card>
          ))}
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Recent purchases</CardTitle>
            <CardDescription>Latest gift card sales with platform fee and commission splits.</CardDescription>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="pb-2 pr-4 font-medium">Date</th>
                  <th className="pb-2 pr-4 font-medium">Brand</th>
                  <th className="pb-2 pr-4 font-medium">Organization</th>
                  <th className="pb-2 pr-4 font-medium text-right">Sale</th>
                  <th className="pb-2 pr-4 font-medium text-right">Platform fee</th>
                  <th className="pb-2 pr-4 font-medium text-right">Fee → BIU</th>
                  <th className="pb-2 pr-4 font-medium text-right">Fee → Org</th>
                  <th className="pb-2 pr-4 font-medium text-right">Provider</th>
                  <th className="pb-2 pr-4 font-medium text-right">BIU prov.</th>
                  <th className="pb-2 pr-4 font-medium text-right">Org prov.</th>
                </tr>
              </thead>
              <tbody>
                {recentPurchases.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="py-8 text-center text-muted-foreground">
                      No purchases in this range.
                    </td>
                  </tr>
                ) : (
                  recentPurchases.map((row) => (
                    <tr key={row.id} className="border-b border-muted/50">
                      <td className="py-3 pr-4 whitespace-nowrap">
                        {row.purchased_at ? new Date(row.purchased_at).toLocaleDateString() : "—"}
                      </td>
                      <td className="py-3 pr-4">{row.brand_name ?? "—"}</td>
                      <td className="py-3 pr-4">{row.organization_name ?? "—"}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.amount)}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.platform_fee)}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.platform_fee_biu_share)}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.platform_fee_org_share)}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.provider_commission)}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.biu_revenue_share)}</td>
                      <td className="py-3 pr-4 text-right">{formatUsd(row.organization_revenue)}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}

"use client"

import { Head, Link, useForm } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import type { BreadcrumbItem } from "@/types"
import { Coins, Save } from "lucide-react"

interface Props {
  sales_platform_fee_percentage: number
  course_platform_fee_percentage: number
  event_platform_fee_percentage: number
  marketplace_printify_organization_fee_percentage: number
  marketplace_merchant_pool_fee_percentage: number
  gift_card_platform_fee_usd: number
}

const breadcrumbs: BreadcrumbItem[] = [
  { title: "Dashboard", href: "/dashboard" },
  { title: "System", href: "#" },
  { title: "BIU fee (platform)", href: "/admin/biu-fee" },
]

export default function AdminBiuFeeIndex({
  sales_platform_fee_percentage,
  course_platform_fee_percentage,
  event_platform_fee_percentage,
  marketplace_printify_organization_fee_percentage,
  marketplace_merchant_pool_fee_percentage,
  gift_card_platform_fee_usd,
}: Props) {
  const { data, setData, put, processing, errors } = useForm({
    sales_platform_fee_percentage: String(sales_platform_fee_percentage ?? 0),
    course_platform_fee_percentage: String(course_platform_fee_percentage ?? 0),
    event_platform_fee_percentage: String(event_platform_fee_percentage ?? 0),
    marketplace_printify_organization_fee_percentage: String(
      marketplace_printify_organization_fee_percentage ?? 0,
    ),
    marketplace_merchant_pool_fee_percentage: String(marketplace_merchant_pool_fee_percentage ?? 0),
    gift_card_platform_fee_usd: String(gift_card_platform_fee_usd ?? 0.5),
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    put(route("admin.biu-fee.update"))
  }

  const salesPct = parseFloat(data.sales_platform_fee_percentage) || 0
  const coursePct = parseFloat(data.course_platform_fee_percentage) || 0
  const eventPct = parseFloat(data.event_platform_fee_percentage) || 0
  const printifyOrgPct = parseFloat(data.marketplace_printify_organization_fee_percentage) || 0
  const merchantPoolPct = parseFloat(data.marketplace_merchant_pool_fee_percentage) || 0
  const giftCardFee = parseFloat(data.gift_card_platform_fee_usd) || 0

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="BIU fee — Admin" />
      <div className="w-full space-y-6 p-4 sm:p-6">
        <div className="w-full space-y-2">
          <div className="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
            <Coins className="h-3.5 w-3.5" />
            Admin · Platform fees
          </div>
          <h1 className="text-3xl font-bold text-foreground">BIU fee (platform)</h1>
          <p className="max-w-2xl text-muted-foreground">
            <span className="font-medium text-foreground">Marketplace checkout</span> charges supporters a platform fee on each line&apos;s subtotal: one
            rate for Printify and organization catalog goods, and another for merchant marketplace items and organization-adopted merchant pool listings.
            Mixed carts combine both.{" "}
            <span className="font-medium text-foreground">Connection Hub</span> courses and meetups use dedicated module rates (platform fees are never
            refunded when a host cancels).{" "}
            <span className="font-medium text-foreground">Other sales modules</span> (Service Hub, raffles, merchant hub cash) use the global sales rate
            below. <span className="font-medium text-foreground">Gift cards</span> charge a fixed platform fee on top of face value (Believe Points),
            split 50/50 between BIU and the beneficiary organization. BIU also earns a share of provider commissions (see Gift card revenue).
          </p>
        </div>

        <Card className="max-w-xl border-muted">
          <CardHeader>
            <CardTitle className="text-base">Quick reference</CardTitle>
            <CardDescription>Example on a $100 sale base before Stripe / transaction fees.</CardDescription>
          </CardHeader>
          <CardContent className="text-sm text-muted-foreground space-y-2">
            <p>
              Global sales @ {salesPct}% → ${((100 * salesPct) / 100).toFixed(2)} platform fee.
            </p>
            <p>
              Marketplace Printify/org @ {printifyOrgPct}% on $60 of lines → ${((60 * printifyOrgPct) / 100).toFixed(2)}; merchant/pool @ {merchantPoolPct}%
              on $40 → ${((40 * merchantPoolPct) / 100).toFixed(2)} (total ${((60 * printifyOrgPct) / 100 + (40 * merchantPoolPct) / 100).toFixed(2)}).
            </p>
            <p>
              Connection Hub course @ {coursePct}% → ${((100 * coursePct) / 100).toFixed(2)} platform fee on a $100 listing fee.
            </p>
            <p>
              Connection Hub meetup @ {eventPct}% → ${((100 * eventPct) / 100).toFixed(2)} platform fee on a $100 registration fee.
            </p>
            <p>
              Gift card $25 face + ${giftCardFee.toFixed(2)} platform fee → ${(25 + giftCardFee).toFixed(2)} total charged in Believe Points
              (fee split: BIU ${(giftCardFee / 2).toFixed(2)} / org ${(giftCardFee / 2).toFixed(2)}).
            </p>
          </CardContent>
        </Card>

        <form onSubmit={handleSubmit} className="w-full max-w-xl space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Connection Hub — Courses</CardTitle>
              <CardDescription>
                Learning, companion, and earning listings. Added on top of the listing fee at checkout; not refunded if the host cancels.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="course_platform_fee_percentage">Percent (%)</Label>
                <Input
                  id="course_platform_fee_percentage"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  value={data.course_platform_fee_percentage}
                  onChange={(e) => setData("course_platform_fee_percentage", e.target.value)}
                  className="font-mono max-w-xs"
                />
                {errors.course_platform_fee_percentage && (
                  <p className="text-sm text-red-600">{errors.course_platform_fee_percentage}</p>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Connection Hub — Meetups / events</CardTitle>
              <CardDescription>
                Events hub registrations. Added on top of the registration fee; withheld from BP refunds when the host cancels.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="event_platform_fee_percentage">Percent (%)</Label>
                <Input
                  id="event_platform_fee_percentage"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  value={data.event_platform_fee_percentage}
                  onChange={(e) => setData("event_platform_fee_percentage", e.target.value)}
                  className="font-mono max-w-xs"
                />
                {errors.event_platform_fee_percentage && (
                  <p className="text-sm text-red-600">{errors.event_platform_fee_percentage}</p>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Marketplace — Printify &amp; organization goods</CardTitle>
              <CardDescription>
                Applies to organization storefront catalog lines (Printify or manual) that are not merchant-pool listings.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="marketplace_printify_organization_fee_percentage">Percent (%)</Label>
                <Input
                  id="marketplace_printify_organization_fee_percentage"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  value={data.marketplace_printify_organization_fee_percentage}
                  onChange={(e) => setData("marketplace_printify_organization_fee_percentage", e.target.value)}
                  className="font-mono max-w-xs"
                />
                {errors.marketplace_printify_organization_fee_percentage && (
                  <p className="text-sm text-red-600">{errors.marketplace_printify_organization_fee_percentage}</p>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Marketplace — Merchant &amp; merchant pool</CardTitle>
              <CardDescription>
                Applies to direct merchant marketplace SKUs, organization pool adoptions, catalog rows linked to a merchant pool product, and{" "}
                <span className="font-medium text-foreground">Merchant Hub offer</span> cash checkouts.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="marketplace_merchant_pool_fee_percentage">Percent (%)</Label>
                <Input
                  id="marketplace_merchant_pool_fee_percentage"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  value={data.marketplace_merchant_pool_fee_percentage}
                  onChange={(e) => setData("marketplace_merchant_pool_fee_percentage", e.target.value)}
                  className="font-mono max-w-xs"
                />
                {errors.marketplace_merchant_pool_fee_percentage && (
                  <p className="text-sm text-red-600">{errors.marketplace_merchant_pool_fee_percentage}</p>
                )}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Gift cards — fixed platform fee</CardTitle>
              <CardDescription>
                Added on top of the gift card face value and charged in Believe Points at purchase. Split 50% BIU / 50% organization. The card face value sent to Phaze is unchanged.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="gift_card_platform_fee_usd">Fixed fee (USD / BP)</Label>
                <Input
                  id="gift_card_platform_fee_usd"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  value={data.gift_card_platform_fee_usd}
                  onChange={(e) => setData("gift_card_platform_fee_usd", e.target.value)}
                  className="font-mono max-w-xs"
                />
                {errors.gift_card_platform_fee_usd && (
                  <p className="text-sm text-red-600">{errors.gift_card_platform_fee_usd}</p>
                )}
                <p className="text-xs text-muted-foreground">
                  Default is $0.50. Example: $25 gift card → ${(25 + giftCardFee).toFixed(2)} Believe Points charged.
                </p>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Global sales platform fee</CardTitle>
              <CardDescription>
                Service Hub orders, raffle ticket face totals, merchant hub cash redemptions — not marketplace cart lines or Connection Hub listings.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="sales_platform_fee_percentage">Percent (%)</Label>
                <Input
                  id="sales_platform_fee_percentage"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  value={data.sales_platform_fee_percentage}
                  onChange={(e) => setData("sales_platform_fee_percentage", e.target.value)}
                  className="font-mono max-w-xs"
                />
                {errors.sales_platform_fee_percentage && (
                  <p className="text-sm text-red-600">{errors.sales_platform_fee_percentage}</p>
                )}
              </div>
              <Button type="submit" disabled={processing} className="gap-2">
                <Save className="h-4 w-4" />
                Save all
              </Button>
            </CardContent>
          </Card>
        </form>

        <p className="text-sm text-muted-foreground">
          Service Hub <span className="font-medium text-foreground">Stripe / Believe Points transaction</span> rates and monthly ad fee stay on{" "}
          <Link href="/settings/service-hub" className="text-primary underline-offset-4 hover:underline">
            Service hub settings
          </Link>
          .
        </p>
      </div>
    </AppLayout>
  )
}

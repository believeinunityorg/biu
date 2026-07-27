"use client"

import AppLayout from "@/layouts/app-layout"
import { Head, Link, router, useForm, usePage } from "@inertiajs/react"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import {
  AlertCircle,
  CheckCircle2,
  ExternalLink,
  Landmark,
  Loader2,
  Wallet,
} from "lucide-react"
import { useEffect, useState } from "react"

interface Readiness {
  preferred_payout_method: string | null
  preferred_payout_method_label: string | null
  stripe: {
    connected: boolean
    charges_enabled: boolean
    payouts_enabled: boolean
    account_type?: string | null
    is_legacy_express?: boolean
    ready: boolean
  }
  paypal: {
    connected: boolean
    payouts_enabled: boolean
    email: string | null
    connected_at: string | null
  }
  payout_ready: boolean
}

interface Props {
  organization: { name: string; email: string | null }
  readiness: Readiness
  availableMethods: Array<{ value: string; label: string }>
  stripeConfigured: boolean
  paypalConfigured: boolean
  modules: string[]
}

export default function PayoutSettings({
  organization,
  readiness,
  availableMethods,
  stripeConfigured,
  paypalConfigured,
  modules,
}: Props) {
  const { flash } = usePage().props as { flash?: { success?: string; error?: string } }
  const [submitting, setSubmitting] = useState(false)

  const form = useForm({
    preferred_payout_method: readiness.preferred_payout_method ?? "",
  })

  const savePreferred = (e: React.FormEvent) => {
    e.preventDefault()
    form.put(route("integrations.payout-settings.preferred-method"), {
      preserveScroll: true,
    })
  }

  const handleStripeStart = () => {
    if (!stripeConfigured) return
    setSubmitting(true)
    window.location.href = route("integrations.stripe-connect.start")
  }

  const moduleLabels = modules.map((m) =>
    m.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
  )

  useEffect(() => {
    const hash = window.location.hash.replace(/^#/, "")
    if (!hash) return
    window.setTimeout(() => {
      document.getElementById(hash)?.scrollIntoView({ behavior: "smooth", block: "start" })
    }, 100)
  }, [])

  return (
    <AppLayout>
      <Head title="Payout settings" />
      <div className="container max-w-2xl py-8">
        <h1 className="text-2xl font-bold mb-2 flex items-center gap-2">
          <Wallet className="h-7 w-7" aria-hidden />
          Payout settings
        </h1>
        <p className="text-muted-foreground mb-6">
          Choose how {organization.name} receives payouts for{" "}
          {moduleLabels.join(", ")}. Donation payouts are unchanged and continue through your existing donation payment setup.
        </p>

        {flash?.success ? (
          <div className="mb-4 rounded-md border border-green-300 bg-green-50 dark:bg-green-950/30 px-4 py-3 text-sm text-green-800 dark:text-green-200">
            {flash.success}
          </div>
        ) : null}
        {flash?.error ? (
          <div className="mb-4 rounded-md border border-red-300 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            {flash.error}
          </div>
        ) : null}

        <Card id="readiness-payout-settings" className="scroll-mt-24 mb-6">
          <CardContent className="pt-6 space-y-4">
            <form onSubmit={savePreferred} className="space-y-4">
              <div>
                <Label htmlFor="preferred_payout_method">Preferred payout method</Label>
                <select
                  id="preferred_payout_method"
                  value={form.data.preferred_payout_method}
                  onChange={(e) => form.setData("preferred_payout_method", e.target.value)}
                  className="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="">Select a method…</option>
                  {availableMethods.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
                {form.errors.preferred_payout_method ? (
                  <p className="text-sm text-red-600 mt-1">{form.errors.preferred_payout_method}</p>
                ) : null}
              </div>
              <Button type="submit" disabled={form.processing || !form.data.preferred_payout_method}>
                {form.processing ? "Saving…" : "Save preference"}
              </Button>
            </form>

            {readiness.payout_ready ? (
              <div className="flex items-start gap-2 text-green-700 dark:text-green-400 pt-2 border-t">
                <CheckCircle2 className="h-5 w-5 shrink-0 mt-0.5" />
                <p className="text-sm">
                  Payouts are ready via <strong>{readiness.preferred_payout_method_label}</strong>.
                </p>
              </div>
            ) : readiness.preferred_payout_method ? (
              <div className="flex items-start gap-2 text-amber-800 dark:text-amber-200 pt-2 border-t">
                <AlertCircle className="h-5 w-5 shrink-0 mt-0.5" />
                <p className="text-sm">
                  Complete setup for your selected method below before payouts can be sent.
                </p>
              </div>
            ) : null}
          </CardContent>
        </Card>

        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardContent className="pt-6 space-y-4">
              <div className="flex items-center justify-between">
                <h2 className="font-semibold flex items-center gap-2">
                  <Landmark className="h-5 w-5" />
                  Stripe Connect
                </h2>
                {readiness.stripe.ready ? (
                  <Badge variant="outline" className="text-green-700 border-green-400">Ready</Badge>
                ) : readiness.stripe.connected ? (
                  <Badge variant="outline" className="text-amber-700 border-amber-400">In progress</Badge>
                ) : (
                  <Badge variant="outline">Not connected</Badge>
                )}
              </div>
              <p className="text-sm text-muted-foreground">
                Connect a Standard Stripe account to receive bank payouts for selling modules.
              </p>
              {readiness.stripe.is_legacy_express ? (
                <p className="text-xs text-amber-700 dark:text-amber-300">
                  Legacy Express account detected. Open Details to disconnect and reconnect with Standard Connect.
                </p>
              ) : null}
              {!stripeConfigured ? (
                <p className="text-xs text-red-600">Stripe is not configured on this platform.</p>
              ) : null}
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  variant={readiness.preferred_payout_method === "stripe" ? "default" : "outline"}
                  disabled={submitting || !stripeConfigured}
                  onClick={handleStripeStart}
                >
                  {submitting ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                  {readiness.stripe.ready ? "Manage Stripe" : "Connect Stripe"}
                </Button>
                <Button variant="outline" asChild>
                  <Link href={route("integrations.stripe-connect")}>Details</Link>
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="pt-6 space-y-4">
              <div className="flex items-center justify-between">
                <h2 className="font-semibold flex items-center gap-2">
                  <Wallet className="h-5 w-5" />
                  PayPal Business
                </h2>
                {readiness.paypal.payouts_enabled ? (
                  <Badge variant="outline" className="text-green-700 border-green-400">Ready</Badge>
                ) : (
                  <Badge variant="outline">Not connected</Badge>
                )}
              </div>
              <p className="text-sm text-muted-foreground">
                Link your PayPal Business email to receive payouts — popular with churches and small nonprofits.
              </p>
              {readiness.paypal.email ? (
                <p className="text-xs font-mono break-all text-muted-foreground">{readiness.paypal.email}</p>
              ) : null}
              {!paypalConfigured ? (
                <p className="text-xs text-red-600">PayPal is not configured on this platform.</p>
              ) : null}
              <Button asChild variant={readiness.preferred_payout_method === "paypal" ? "default" : "outline"}>
                <Link href={route("integrations.paypal-payouts")}>
                  {readiness.paypal.payouts_enabled ? "Manage PayPal" : "Set up PayPal"}
                  <ExternalLink className="h-3.5 w-3.5 ml-1" />
                </Link>
              </Button>
            </CardContent>
          </Card>
        </div>

        <p className="text-xs text-muted-foreground mt-6">
          Bridge is planned as a future payout option and is not available yet.
        </p>
      </div>
    </AppLayout>
  )
}

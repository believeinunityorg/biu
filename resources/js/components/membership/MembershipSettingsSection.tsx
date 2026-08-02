"use client"

import { useForm } from "@inertiajs/react"
import { FormEventHandler, useEffect } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Label } from "@/components/frontend/ui/label"
import { Input } from "@/components/frontend/ui/input"
import { Button } from "@/components/frontend/ui/button"
import { Switch } from "@/components/frontend/ui/switch"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import InputError from "@/components/input-error"
import { Save, UsersRound } from "lucide-react"
import { Link } from "@inertiajs/react"

export type MembershipSettingsPayload = {
  memberships_enabled: boolean
  account_type: string
  account_id: number
  plan: {
    id?: number
    membership_name: string
    membership_type: string
    membership_fee: string | null
    billing_frequency: string | null
    join_method: string
    status?: string
  } | null
}

type MembershipForm = {
  memberships_enabled: boolean
  account_type: string
  account_id: number
  membership_name: string
  membership_type: string
  membership_fee: string
  billing_frequency: string
  join_method: string
}

interface MembershipSettingsSectionProps {
  membership: MembershipSettingsPayload | null
  membershipRoute?: string | null
  heading?: string
  description?: string
}

const MEMBERSHIP_TYPES = [
  { value: "free", label: "Free" },
  { value: "paid", label: "Paid" },
]

const JOIN_METHODS = [
  { value: "open_enrollment", label: "Open Enrollment" },
  { value: "request_to_join", label: "Request to Join" },
  { value: "invitation_only", label: "Invitation Only" },
]

const BILLING_FREQUENCIES = [
  { value: "none", label: "None" },
  { value: "monthly", label: "Monthly" },
  { value: "quarterly", label: "Quarterly" },
  { value: "annual", label: "Annual" },
]

function billingFrequencyFromApi(value: string | null | undefined): string {
  return value && value.length > 0 ? value : "none"
}

function billingFrequencyToApi(value: string): string {
  return value === "none" ? "" : value
}

export default function MembershipSettingsSection({
  membership,
  membershipRoute = null,
  heading = "Memberships",
  description = "Enable supporter memberships and configure how people can join.",
}: MembershipSettingsSectionProps) {
  if (!membership) {
    return null
  }

  const { data, setData, patch, transform, errors, processing, recentlySuccessful } = useForm<MembershipForm>({
    memberships_enabled: membership.memberships_enabled,
    account_type: membership.account_type,
    account_id: membership.account_id,
    membership_name: membership.plan?.membership_name ?? "",
    membership_type: membership.plan?.membership_type ?? "free",
    membership_fee: membership.plan?.membership_fee ?? "",
    billing_frequency: billingFrequencyFromApi(membership.plan?.billing_frequency),
    join_method: membership.plan?.join_method ?? "request_to_join",
  })

  transform((formData) => ({
    ...formData,
    billing_frequency: billingFrequencyToApi(formData.billing_frequency),
  }))

  useEffect(() => {
    if (data.membership_type === "free" && data.membership_fee !== "") {
      setData("membership_fee", "")
    }
  }, [data.membership_type, data.membership_fee, setData])

  const submit: FormEventHandler = (e) => {
    e.preventDefault()
    patch(route("membership.settings.update"), { preserveScroll: true })
  }

  return (
    <Card id="membership-settings" className="border-gray-200 dark:border-gray-800">
      <CardHeader>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <CardTitle className="flex items-center gap-2 text-gray-900 dark:text-white">
              <UsersRound className="h-5 w-5 text-primary" />
              {heading}
            </CardTitle>
            <CardDescription className="mt-1.5">{description}</CardDescription>
          </div>
          {membership.memberships_enabled && membershipRoute && (
            <Button variant="outline" size="sm" asChild className="shrink-0">
              <Link href={membershipRoute}>Open Membership tab</Link>
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-6">
          <div className="flex items-center justify-between gap-4 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <div>
              <Label htmlFor="memberships_enabled" className="text-gray-900 dark:text-white font-medium">
                Enable Memberships
              </Label>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Turn on membership configuration and the Membership navigation tab.
              </p>
            </div>
            <Switch
              id="memberships_enabled"
              checked={data.memberships_enabled}
              onCheckedChange={(checked) => setData("memberships_enabled", checked)}
            />
          </div>
          <InputError message={errors.memberships_enabled} />

          {data.memberships_enabled && (
            <div className="space-y-4 border-t border-gray-200 dark:border-gray-700 pt-6">
              <div>
                <Label htmlFor="membership_name" className="text-gray-900 dark:text-white font-medium">
                  Membership Name *
                </Label>
                <Input
                  id="membership_name"
                  value={data.membership_name}
                  onChange={(e) => setData("membership_name", e.target.value)}
                  className="mt-1"
                  placeholder="e.g. Supporter Circle"
                  required
                />
                <InputError message={errors.membership_name} className="mt-1" />
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <Label className="text-gray-900 dark:text-white font-medium">Membership Type *</Label>
                  <Select
                    value={data.membership_type}
                    onValueChange={(value) => setData("membership_type", value)}
                  >
                    <SelectTrigger className="mt-1">
                      <SelectValue placeholder="Select type" />
                    </SelectTrigger>
                    <SelectContent>
                      {MEMBERSHIP_TYPES.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <InputError message={errors.membership_type} className="mt-1" />
                </div>

                <div>
                  <Label className="text-gray-900 dark:text-white font-medium">Join Method *</Label>
                  <Select value={data.join_method} onValueChange={(value) => setData("join_method", value)}>
                    <SelectTrigger className="mt-1">
                      <SelectValue placeholder="Select join method" />
                    </SelectTrigger>
                    <SelectContent>
                      {JOIN_METHODS.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <InputError message={errors.join_method} className="mt-1" />
                  {data.join_method === "invitation_only" && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Manage sent invites from the Membership dashboard → Invitations tab after saving.
                    </p>
                  )}
                </div>
              </div>

              {data.membership_type === "paid" && (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="membership_fee" className="text-gray-900 dark:text-white font-medium">
                      Membership Fee *
                    </Label>
                    <Input
                      id="membership_fee"
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.membership_fee}
                      onChange={(e) => setData("membership_fee", e.target.value)}
                      className="mt-1"
                      placeholder="0.00"
                      required
                    />
                    <InputError message={errors.membership_fee} className="mt-1" />
                    <p className="mt-2 text-xs text-muted-foreground">
                      Supporters pay through Stripe Checkout using your organization&apos;s Stripe payout account. Finish setup under{" "}
                      <Link href={route("integrations.payout-settings")} className="text-primary underline">
                        Stripe payout settings
                      </Link>
                      .
                    </p>
                  </div>

                  <div>
                    <Label className="text-gray-900 dark:text-white font-medium">Billing Frequency</Label>
                    <Select
                      value={billingFrequencyFromApi(data.billing_frequency)}
                      onValueChange={(value) => setData("billing_frequency", value)}
                    >
                      <SelectTrigger className="mt-1">
                        <SelectValue placeholder="Optional" />
                      </SelectTrigger>
                      <SelectContent>
                        {BILLING_FREQUENCIES.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <InputError message={errors.billing_frequency} className="mt-1" />
                  </div>
                </div>
              )}

              {data.membership_type === "free" && (
                <div>
                  <Label className="text-gray-900 dark:text-white font-medium">Billing Frequency</Label>
                  <Select
                    value={billingFrequencyFromApi(data.billing_frequency)}
                    onValueChange={(value) => setData("billing_frequency", value)}
                  >
                    <SelectTrigger className="mt-1 max-w-xs">
                      <SelectValue placeholder="Optional" />
                    </SelectTrigger>
                    <SelectContent>
                      {BILLING_FREQUENCIES.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                          {option.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <InputError message={errors.billing_frequency} className="mt-1" />
                </div>
              )}
            </div>
          )}

          <div className="flex items-center justify-end gap-3">
            {recentlySuccessful && (
              <span className="text-sm text-green-600 dark:text-green-400">Membership settings saved.</span>
            )}
            <Button type="submit" disabled={processing}>
              {processing ? (
                "Saving..."
              ) : (
                <>
                  <Save className="mr-2 h-4 w-4" />
                  Save membership settings
                </>
              )}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  )
}

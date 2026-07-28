"use client"

import { useEffect, useState } from "react"
import { Head, router, useForm, usePage } from "@inertiajs/react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import { Checkbox } from "@/components/ui/checkbox"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Badge } from "@/components/ui/badge"
import {
  MessageSquare,
  Info,
  CheckCircle2,
  Loader2,
  KeyRound,
  Smartphone,
  Circle,
  ExternalLink,
  Shield,
  Database,
  FlaskConical,
  AlertCircle,
  Wifi,
} from "lucide-react"
import SettingsLayout from "@/layouts/settings/layout"
import { BridgeSection } from "@/pages/settings/bridge/components/BridgeSection"
import { BridgeField } from "@/pages/settings/bridge/components/BridgeField"
import { SecretInput } from "@/pages/settings/bridge/components/SecretInput"
import { showSuccessToast, showErrorToast } from "@/lib/toast"
import { cn } from "@/lib/utils"
import type { SharedData } from "@/types"

interface TwilioSettings {
  account_sid: string | null
  auth_token: string | null
  auth_token_set: boolean
  whatsapp_from: string | null
  sms_from: string | null
  sms_messaging_service_sid: string | null
  mode_environment: "sandbox" | "live"
  enabled: boolean
  has_database_credentials: boolean
  active_source: "database" | "none"
}

interface TwilioTestResult {
  ok: boolean
  channel: string
  title: string
  message: string
  details?: Record<string, string | number | boolean | null | undefined>
}

interface Props {
  settings: TwilioSettings
}

function StatusPill({
  label,
  ok,
  detail,
}: {
  label: string
  ok: boolean
  detail: string
}) {
  return (
    <div
      className={cn(
        "flex min-w-0 flex-1 flex-col gap-1 rounded-xl border px-3 py-2.5 sm:px-4",
        ok
          ? "border-emerald-200/80 bg-emerald-50/80 dark:border-emerald-900/50 dark:bg-emerald-950/25"
          : "border-border bg-muted/30",
      )}
    >
      <div className="flex items-center gap-2">
        {ok ? (
          <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
        ) : (
          <Circle className="h-4 w-4 shrink-0 text-muted-foreground" />
        )}
        <span className="truncate text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {label}
        </span>
      </div>
      <p
        className={cn(
          "text-sm font-medium leading-snug",
          ok ? "text-emerald-800 dark:text-emerald-200" : "text-foreground",
        )}
      >
        {detail}
      </p>
    </div>
  )
}

export default function TwilioSettingsPage({ settings }: Props) {
  const page = usePage<
    SharedData & {
      flash?: {
        success?: string
        error?: string
        twilio_test?: TwilioTestResult
      }
    }
  >()
  const { flash } = page.props

  const [testTo, setTestTo] = useState("+8801714438614")
  const [testingChannel, setTestingChannel] = useState<string | null>(null)
  const [lastTest, setLastTest] = useState<TwilioTestResult | null>(flash?.twilio_test ?? null)

  const { data, setData, post, processing, errors } = useForm({
    account_sid: settings.account_sid || "",
    auth_token: settings.auth_token || "",
    whatsapp_from: settings.whatsapp_from || "whatsapp:+14155238886",
    sms_from: settings.sms_from || "",
    sms_messaging_service_sid: settings.sms_messaging_service_sid || "",
    mode_environment: settings.mode_environment || "sandbox",
    enabled: settings.enabled ?? true,
  })

  useEffect(() => {
    if (flash?.success) showSuccessToast(flash.success)
    if (flash?.error) showErrorToast(flash.error)
    if (flash?.twilio_test) setLastTest(flash.twilio_test)
  }, [flash?.success, flash?.error, flash?.twilio_test])

  // After save, Inertia returns DB values — keep Auth Token visible in the form
  useEffect(() => {
    setData("account_sid", settings.account_sid || "")
    setData("auth_token", settings.auth_token || "")
    setData("whatsapp_from", settings.whatsapp_from || "whatsapp:+14155238886")
    setData("sms_from", settings.sms_from || "")
    setData("sms_messaging_service_sid", settings.sms_messaging_service_sid || "")
    setData("mode_environment", settings.mode_environment || "sandbox")
    setData("enabled", settings.enabled ?? true)
  }, [
    settings.account_sid,
    settings.auth_token,
    settings.whatsapp_from,
    settings.sms_from,
    settings.sms_messaging_service_sid,
    settings.mode_environment,
    settings.enabled,
  ])

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    post(route("twilio.update"), { preserveScroll: true })
  }

  const runTest = (channel: "connection" | "whatsapp" | "sms") => {
    setTestingChannel(channel)
    router.post(
      route("twilio.test"),
      { channel, to: testTo },
      {
        preserveScroll: true,
        onFinish: () => setTestingChannel(null),
      },
    )
  }

  const whatsappReady = Boolean(data.whatsapp_from?.trim())
  const smsReady = Boolean(data.sms_from?.trim() || data.sms_messaging_service_sid?.trim())
  const dbReady = settings.has_database_credentials

  return (
    <SettingsLayout
      activeTab="twilio"
      pageTitle="Twilio"
      pageSubtitle="WhatsApp campaigns and newsletter SMS — encrypted credentials managed by admins."
    >
      <Head title="Twilio Settings" />

      <form onSubmit={submit} className="w-full space-y-6">
        {/* Overview strip */}
        <div className="relative overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
          <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-purple-600 to-blue-600" />
          <div className="space-y-5 p-5 sm:p-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="flex items-start gap-3">
                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-sm">
                  <MessageSquare className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-widest text-purple-600 dark:text-purple-400">
                    Messaging admin
                  </p>
                  <h2 className="mt-1 text-lg font-semibold tracking-tight text-foreground">
                    Twilio integration
                  </h2>
                  <p className="mt-1 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                    Store Live Account SID and Auth Token here. WhatsApp Sandbox still uses Live keys
                    with the sandbox From number — recipients must join the sandbox first.
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Badge
                  variant="outline"
                  className={cn(
                    "border-transparent font-medium",
                    data.enabled
                      ? "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                      : "bg-muted text-muted-foreground",
                  )}
                >
                  {data.enabled ? "Messaging on" : "Messaging off"}
                </Badge>
                <Badge
                  variant="outline"
                  className={cn(
                    "border-transparent font-medium capitalize",
                    settings.active_source === "database"
                      ? "bg-purple-100 text-purple-800 dark:bg-purple-950/50 dark:text-purple-200"
                      : "bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100",
                  )}
                >
                  {settings.active_source === "database" ? "Source: Database" : "Not saved yet"}
                </Badge>
                <a
                  href="https://console.twilio.com/us1/account/keys-credentials/api-keys"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 text-xs font-medium text-purple-700 underline-offset-2 hover:underline dark:text-purple-300"
                >
                  Twilio Console <ExternalLink className="h-3 w-3" />
                </a>
              </div>
            </div>

            <div className="flex flex-col gap-2 sm:flex-row">
              <StatusPill
                label="Credentials"
                ok={dbReady}
                detail={dbReady ? "Saved in database" : "Save in admin to enable"}
              />
              <StatusPill
                label="WhatsApp"
                ok={whatsappReady && dbReady}
                detail={whatsappReady ? data.whatsapp_from : "From number missing"}
              />
              <StatusPill
                label="SMS"
                ok={smsReady}
                detail={smsReady ? "Sender configured" : "Optional — not set"}
              />
              <StatusPill
                label="Mode"
                ok
                detail={data.mode_environment === "sandbox" ? "Sandbox sender" : "Live business sender"}
              />
            </div>
          </div>
        </div>

        {/* Enable + mode — one bar so heights stay aligned */}
        <div className="flex flex-col gap-3 rounded-xl border border-border/60 bg-card p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-5 sm:py-4">
          <div className="flex min-w-0 flex-1 items-center justify-between gap-4 sm:justify-start">
            <div className="flex min-w-0 items-start gap-3">
              <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-500/15 to-blue-500/15">
                <Shield className="h-4 w-4 text-purple-600 dark:text-purple-400" />
              </div>
              <div className="min-w-0">
                <Label htmlFor="twilio_enabled" className="text-base font-semibold">
                  Enable Twilio messaging
                </Label>
                <p className="mt-0.5 text-sm text-muted-foreground">
                  When off, WhatsApp and SMS sends are disabled app-wide.
                </p>
              </div>
            </div>
            <Checkbox
              id="twilio_enabled"
              checked={data.enabled}
              onCheckedChange={(checked) => setData("enabled", checked === true)}
              className="h-5 w-5 shrink-0 rounded-md"
            />
          </div>

          <div
            className="flex h-11 w-full shrink-0 rounded-lg border border-border/60 bg-muted/40 p-1 sm:w-[240px]"
            role="group"
            aria-label="Sender mode"
          >
            {(["sandbox", "live"] as const).map((env) => (
              <button
                key={env}
                type="button"
                onClick={() => setData("mode_environment", env)}
                className={cn(
                  "flex-1 rounded-md text-sm font-medium transition-all",
                  data.mode_environment === env
                    ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm"
                    : "text-muted-foreground hover:text-foreground",
                )}
              >
                {env === "sandbox" ? "Sandbox" : "Live"}
              </button>
            ))}
          </div>
        </div>

        <Alert className="border-blue-200/80 bg-blue-50/50 dark:border-blue-900/40 dark:bg-blue-950/20">
          <Info className="h-4 w-4 text-blue-600 dark:text-blue-400" />
          <AlertDescription className="text-sm text-blue-900 dark:text-blue-100">
            Use <strong>Live</strong> credentials from Twilio Console — not Test credentials.
            Sandbox WhatsApp From is typically{" "}
            <code className="rounded bg-blue-100/80 px-1.5 py-0.5 text-xs dark:bg-blue-900/40">
              whatsapp:+14155238886
            </code>
            . Mode is an admin hint; set WhatsApp From to match.
          </AlertDescription>
        </Alert>

        {/* Account credentials */}
        <BridgeSection
          icon={KeyRound}
          title="API credentials"
          description="Encrypted Account SID and Auth Token. Same pattern as Stripe and Bridge keys."
        >
          <div className="grid gap-5 lg:grid-cols-2">
            <BridgeField
              id="account_sid"
              label="Account SID"
              value={data.account_sid}
              onChange={(v) => setData("account_sid", v)}
              placeholder="ACxxxxxxxx…"
              hint="Starts with AC — Live Account SID from Twilio Console."
              error={errors.account_sid}
            />
            <SecretInput
              id="auth_token"
              label="Auth Token"
              value={data.auth_token}
              onChange={(v) => setData("auth_token", v)}
              placeholder="Auth token"
              error={errors.auth_token}
            />
          </div>

          {dbReady && (
            <div className="flex items-center gap-2 rounded-lg border border-emerald-200/80 bg-emerald-50/60 px-3 py-2.5 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-100">
              <Database className="h-4 w-4 shrink-0 text-emerald-600" />
              Credentials are loaded from the database only. Click Save after any change before testing.
            </div>
          )}
          {!dbReady && (
            <div className="flex items-center gap-2 rounded-lg border border-amber-200/80 bg-amber-50/60 px-3 py-2.5 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-100">
              <Info className="h-4 w-4 shrink-0 text-amber-600" />
              WhatsApp/SMS will not work until you save Account SID and Auth Token here.
            </div>
          )}
        </BridgeSection>

        {/* WhatsApp */}
        <BridgeSection
          icon={MessageSquare}
          title="WhatsApp sender"
          description="Campaign and notification WhatsApp messages are sent via Twilio only (not Meta Cloud API)."
        >
          <div className="space-y-2 lg:col-span-2">
            <Label htmlFor="whatsapp_from_number">WhatsApp From</Label>
            <div className="flex overflow-hidden rounded-md border border-input bg-background shadow-xs focus-within:ring-2 focus-within:ring-ring">
              <span className="flex shrink-0 items-center border-r border-input bg-muted/50 px-3 font-mono text-sm text-muted-foreground select-none">
                whatsapp:
              </span>
              <Input
                id="whatsapp_from_number"
                value={data.whatsapp_from.replace(/^whatsapp:/i, "")}
                onChange={(e) => {
                  const number = e.target.value.replace(/^whatsapp:/i, "").replace(/\s+/g, "")
                  setData("whatsapp_from", `whatsapp:${number}`)
                }}
                placeholder="+14155238886"
                className="border-0 font-mono text-sm shadow-none focus-visible:ring-0"
                autoComplete="off"
              />
            </div>
            {errors.whatsapp_from && (
              <p className="text-sm text-destructive">{errors.whatsapp_from}</p>
            )}
            <p className="text-xs leading-relaxed text-muted-foreground">
              {data.mode_environment === "sandbox"
                ? "The whatsapp: prefix is fixed. Enter the sandbox number (e.g. +14155238886). Recipients must join the sandbox first."
                : "The whatsapp: prefix is fixed. Enter your approved business number in E.164 (e.g. +15551234567)."}
            </p>
          </div>
          <a
            href="https://console.twilio.com/us1/develop/sms/try-it-out/whatsapp-learn"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 text-sm font-medium text-purple-700 underline-offset-2 hover:underline dark:text-purple-300"
          >
            Open WhatsApp Sandbox settings <ExternalLink className="h-3.5 w-3.5" />
          </a>
        </BridgeSection>

        {/* SMS */}
        <BridgeSection
          icon={Smartphone}
          title="SMS (optional)"
          description="Used for newsletter and transactional SMS. Provide either an E.164 From number or a Messaging Service SID."
        >
          <div className="grid gap-5 lg:grid-cols-2">
            <BridgeField
              id="sms_from"
              label="SMS From (E.164)"
              value={data.sms_from}
              onChange={(v) => setData("sms_from", v)}
              placeholder="+15551234567"
              hint="Twilio phone number or short code in E.164 format."
              error={errors.sms_from}
            />
            <BridgeField
              id="sms_messaging_service_sid"
              label="Messaging Service SID"
              value={data.sms_messaging_service_sid}
              onChange={(v) => setData("sms_messaging_service_sid", v)}
              placeholder="MGxxxxxxxx…"
              hint="Optional alternative to SMS From (Messaging Service)."
              error={errors.sms_messaging_service_sid}
            />
          </div>
        </BridgeSection>

        {/* Sticky save */}
        <div className="sticky bottom-4 z-10 flex flex-col gap-3 rounded-xl border border-border/60 bg-card/95 p-4 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
          <p className="text-xs leading-relaxed text-muted-foreground">
            Saves encrypted credentials for{" "}
            <span className="font-medium capitalize text-foreground">{data.mode_environment}</span>{" "}
            messaging.
          </p>
          <Button
            type="submit"
            disabled={processing}
            className="w-full bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700 sm:w-auto"
          >
            {processing ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Saving…
              </>
            ) : (
              <>
                <CheckCircle2 className="mr-2 h-4 w-4" />
                Save Twilio settings
              </>
            )}
          </Button>
        </div>
      </form>

      {/* Testing system — outside settings form so it doesn't collide with save */}
      <div className="mt-8">
        <BridgeSection
          icon={FlaskConical}
          title="Test Twilio"
          description="Verify credentials and send a real WhatsApp or SMS using the saved database configuration only."
        >
          <div className="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
            <BridgeField
              id="test_to"
              label="Destination phone (E.164)"
              value={testTo}
              onChange={setTestTo}
              placeholder="+8801714438614"
              hint="WhatsApp: must have joined the sandbox. SMS: must be allowed on your Twilio account."
            />
            <div className="flex flex-col gap-2 sm:flex-row lg:pb-6">
              <Button
                type="button"
                variant="outline"
                disabled={testingChannel !== null}
                onClick={() => runTest("connection")}
                className="sm:min-w-[140px]"
              >
                {testingChannel === "connection" ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Wifi className="mr-2 h-4 w-4" />
                )}
                Test connection
              </Button>
              <Button
                type="button"
                variant="outline"
                disabled={testingChannel !== null}
                onClick={() => runTest("whatsapp")}
                className="border-purple-200 text-purple-800 hover:bg-purple-50 dark:border-purple-800 dark:text-purple-200 dark:hover:bg-purple-950/40 sm:min-w-[140px]"
              >
                {testingChannel === "whatsapp" ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <MessageSquare className="mr-2 h-4 w-4" />
                )}
                Send WhatsApp
              </Button>
              <Button
                type="button"
                variant="outline"
                disabled={testingChannel !== null}
                onClick={() => runTest("sms")}
                className="sm:min-w-[140px]"
              >
                {testingChannel === "sms" ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Smartphone className="mr-2 h-4 w-4" />
                )}
                Send SMS
              </Button>
            </div>
          </div>

          {lastTest && (
            <div
              className={cn(
                "rounded-xl border px-4 py-4",
                lastTest.ok
                  ? "border-emerald-200/80 bg-emerald-50/70 dark:border-emerald-900/40 dark:bg-emerald-950/20"
                  : "border-red-200/80 bg-red-50/70 dark:border-red-900/40 dark:bg-red-950/20",
              )}
            >
              <div className="flex items-start gap-3">
                {lastTest.ok ? (
                  <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                ) : (
                  <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                )}
                <div className="min-w-0 flex-1 space-y-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-semibold text-foreground">{lastTest.title}</p>
                    <Badge variant="outline" className="capitalize">
                      {lastTest.channel}
                    </Badge>
                  </div>
                  <p className="text-sm leading-relaxed text-foreground/90">{lastTest.message}</p>
                  {lastTest.details && Object.keys(lastTest.details).length > 0 && (
                    <dl className="grid gap-2 pt-1 sm:grid-cols-2">
                      {Object.entries(lastTest.details).map(([key, value]) => {
                        if (value === null || value === undefined || value === "") return null
                        return (
                          <div
                            key={key}
                            className="rounded-lg border border-border/50 bg-background/60 px-3 py-2"
                          >
                            <dt className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                              {key.replace(/_/g, " ")}
                            </dt>
                            <dd className="mt-0.5 break-all font-mono text-xs text-foreground">
                              {String(value)}
                            </dd>
                          </div>
                        )
                      })}
                    </dl>
                  )}
                </div>
              </div>
            </div>
          )}

          <Alert className="border-border/60 bg-muted/20">
            <Info className="h-4 w-4" />
            <AlertDescription className="text-sm text-muted-foreground">
              Save credentials first if you just edited them — tests and campaigns use the database only
              (not .env). Connection checks the Account API; WhatsApp/SMS deliver a real message.
            </AlertDescription>
          </Alert>
        </BridgeSection>
      </div>
    </SettingsLayout>
  )
}

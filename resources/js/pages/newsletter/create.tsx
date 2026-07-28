"use client"

import { Head, router, useForm, usePage } from "@inertiajs/react"
import { motion } from "framer-motion"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { TextArea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import {
    ArrowLeft,
    Eye,
    Code,
    Copy,
    Check,
    MessageSquare,
    Mail,
    CheckCircle2,
    Sparkles,
    Loader2,
    FileText,
    Coins,
    Users,
    Pencil,
    Plane,
    Bell,
    Search,
    ChevronRight,
    Lightbulb,
    Zap,
    ShieldCheck,
    UserPlus,
    Trash2,
    CalendarClock,
    Repeat,
    Layers2,
} from "lucide-react"
import { cn } from "@/lib/utils"
import { NEWSLETTER_AI_TONES, type NewsletterAiTone } from "@/lib/newsletter-ai-presets"
import type { SharedData } from "@/types"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import { getBrowserTimezone, convertUserTimezoneToUTC } from "@/lib/timezone-detection"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Checkbox } from "@/components/ui/checkbox"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"

const SMS_PLAIN_MAX_CHARS = 160

const QUICK_BRIEF_CHIPS: { label: string; text: string }[] = [
    { label: "Fundraiser", text: "Write a compelling fundraising appeal for our nonprofit, with impact story and a clear donate CTA." },
    { label: "Volunteer call", text: "Recruit volunteers for our upcoming event — include date, time, location, and how to sign up." },
    { label: "Weekly update", text: "A warm weekly update for supporters: highlights, thank-you, and one clear next step." },
]

interface Template {
    id: number
    name: string
    subject: string
    content: string
    html_content?: string
    template_type: string
}

interface PreviewData {
    organization_name: string
    organization_email: string
    organization_phone: string
    organization_address: string
    recipient_name: string
    recipient_email: string
    current_date: string
    current_year: string
    unsubscribe_link: string
    public_view_link: string
}

/** Must match NewsletterController::NEWSLETTER_TEMPLATE_MERGE_KEYS and SendNewsletterJob replacements */
const NEWSLETTER_MERGE_VARIABLES: { key: keyof PreviewData; label: string }[] = [
    { key: "organization_name", label: "Organization name" },
    { key: "organization_email", label: "Organization email" },
    { key: "organization_phone", label: "Organization phone" },
    { key: "organization_address", label: "Organization address" },
    { key: "recipient_name", label: "Recipient name" },
    { key: "recipient_email", label: "Recipient email" },
    { key: "current_date", label: "Current date" },
    { key: "current_year", label: "Current year" },
    { key: "unsubscribe_link", label: "Unsubscribe URL" },
    { key: "public_view_link", label: "View in browser URL" },
]

interface RecipientRow {
    id: number
    name: string
    email: string
}

/** Backend audience row (platform user with roles, or manual recipient from Recipients). */
type NewsletterAudienceRow =
    | {
          id: number
          kind: "user"
          name: string
          email: string
          email_verified_at?: string | null
          roles: { name: string }[]
      }
    | {
          id: number
          kind: "contact"
          name: string
          email: string
          /** e.g. "Manual recipient" — matches Recipients / manual adds + CSV import */
          badge: string
          /** active = included in Engagement; unsubscribed = excluded until re-selected */
          status?: string
      }

interface NewsletterAudienceCounts {
    followers: number
    donors: number
    volunteers: number
    newsletter_contacts: number
}

type NewsletterCreateAiResult =
    | {
          ok: true
          output_mode?: string
          subject: string
          content: string
          html_content: string
          tokens_used?: number
          ai_tokens_used?: number
          ai_tokens_included?: number
      }
    | { ok: false; message: string; code?: string }
    | null

interface NewsletterCreateProps {
    templates: Template[]
    previewData?: PreviewData
    openAiConfigured?: boolean
    newsletterCreateAiResult?: NewsletterCreateAiResult
    newsletterRecipientCount?: number
    newsletterRecipientPreview?: RecipientRow[]
    newsletterAudienceCounts?: NewsletterAudienceCounts
    newsletterAudiencePreview?: NewsletterAudienceRow[]
    newsletterAudienceLoadedSegment?: string | null
    /** Set after silent Inertia POST from inline manual-recipient form (no redirect). */
    newsletterRecipientInlineNotice?: { type: "success" | "error"; message: string } | null
}

function VariableItem({
    variable,
    description,
    sampleValue,
    onCopy,
}: {
    variable: string
    description: string
    sampleValue: string
    onCopy: () => void
}) {
    const [copied, setCopied] = useState(false)
    const handleCopy = (e: React.MouseEvent) => {
        e.preventDefault()
        e.stopPropagation()
        onCopy()
        setCopied(true)
        setTimeout(() => setCopied(false), 2000)
    }
    return (
        <div className="group flex w-full items-start justify-between gap-1.5 py-1.5 transition-colors hover:bg-white/5">
            <div className="min-w-0 flex-1">
                <button type="button" onClick={handleCopy} className="w-full text-left" title={`Copy ${variable}`}>
                    <code className="mb-0.5 block rounded bg-black/30 px-1.5 py-0.5 font-mono text-[11px] leading-snug break-all text-zinc-200">
                        {variable}
                    </code>
                    <div className="break-words text-[11px] leading-snug text-zinc-500">
                        <span>{description}</span>
                        {sampleValue && (
                            <span className="ml-2 break-all text-zinc-600">
                                → {sampleValue.length > 50 ? `${sampleValue.slice(0, 50)}…` : sampleValue}
                            </span>
                        )}
                    </div>
                </button>
            </div>
            <button type="button" onClick={handleCopy} className="shrink-0 p-1 text-zinc-500 hover:text-zinc-300">
                {copied ? <Check className="h-3 w-3 text-emerald-400" /> : <Copy className="h-3 w-3" />}
            </button>
        </div>
    )
}

type CommunicationKind = "newsletter" | "letter" | "message" | "push"
type AudienceTab = "all" | "donors" | "volunteers" | "custom"

function audienceTabToSegment(tab: AudienceTab): "followers" | "donors" | "volunteers" | "newsletter_contacts" {
    if (tab === "all") return "followers"
    if (tab === "custom") return "newsletter_contacts"
    return tab
}

function segmentToAudienceTab(segment: string): AudienceTab | null {
    switch (segment) {
        case "followers":
            return "all"
        case "donors":
            return "donors"
        case "volunteers":
            return "volunteers"
        case "newsletter_contacts":
            return "custom"
        default:
            return null
    }
}

/** Sliding window of page numbers (works with search-filtered lists). */
function audiencePaginationRange(current: number, total: number, maxButtons: number): number[] {
    const t = Math.max(1, total)
    const c = Math.min(Math.max(1, current), t)
    if (t <= maxButtons) {
        return Array.from({ length: t }, (_, i) => i + 1)
    }
    const half = Math.floor(maxButtons / 2)
    let start = Math.max(1, c - half)
    let end = Math.min(t, start + maxButtons - 1)
    if (end - start + 1 < maxButtons) {
        start = Math.max(1, end - maxButtons + 1)
    }
    return Array.from({ length: end - start + 1 }, (_, i) => start + i)
}

const ROLE_LABELS: Record<string, string> = {
    care_alliance: "Unity Impact Alliance",
}

function formatRoleLabel(role: string): string {
    const key = role.toLowerCase().replace(/\s+/g, "_")
    if (ROLE_LABELS[key]) {
        return ROLE_LABELS[key]
    }
    return role.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
}

function pickInitials(name: string): string {
    const parts = name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
    if (parts.length === 0) return "?"
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
    return (parts[0][0] + parts[1][0]).toUpperCase()
}

function AudienceMemberCard({ row }: { row: NewsletterAudienceRow }) {
    const displayName = row.name?.trim() || row.email || "Subscriber"
    const badgeLabel =
        row.kind === "contact"
            ? row.badge
            : row.roles?.length
              ? formatRoleLabel(row.roles[0].name)
              : row.email_verified_at
                ? "Verified"
                : "Unverified"

    return (
        <div className="flex w-full items-center gap-3 rounded-xl border border-white/10 bg-black/20 p-3 text-left text-xs">
            <div className="shrink-0">
                <Avatar className="size-11 border-2 border-white/10 shadow-sm">
                    <AvatarFallback className="bg-violet-950/80 text-sm font-semibold text-zinc-100">
                        {pickInitials(displayName)}
                    </AvatarFallback>
                </Avatar>
            </div>
            <div className="min-w-0 flex-1">
                <p className="truncate font-semibold leading-tight text-zinc-100">{displayName}</p>
                <p className="mt-0.5 truncate text-[11px] text-zinc-500">{row.email}</p>
                <div className="mt-1.5">
                    <Badge variant="secondary" className="text-[10px] font-semibold uppercase tracking-wide">
                        {badgeLabel}
                    </Badge>
                </div>
            </div>
        </div>
    )
}

type ContactAudienceRow = Extract<NewsletterAudienceRow, { kind: "contact" }>

function ManualRecipientAudienceRow({
    row,
    busy,
    onToggleIncluded,
    onEdit,
    onDelete,
}: {
    row: ContactAudienceRow
    busy: boolean
    onToggleIncluded: (nextIncluded: boolean) => void
    onEdit: () => void
    onDelete: () => void
}) {
    const displayName = row.name?.trim() || row.email || "Subscriber"
    const isIncluded = row.status === "active" || row.status === undefined

    return (
        <div className="flex w-full flex-col gap-2 rounded-xl border border-white/10 bg-black/20 p-3 text-left text-xs sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <Avatar className="size-11 shrink-0 border-2 border-white/10 shadow-sm">
                    <AvatarFallback className="bg-violet-950/80 text-sm font-semibold text-zinc-100">
                        {pickInitials(displayName)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold leading-tight text-zinc-100">{displayName}</p>
                    <p className="mt-0.5 truncate text-[11px] text-zinc-500">{row.email}</p>
                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                        <Badge variant="secondary" className="text-[10px] font-semibold uppercase tracking-wide">
                            {row.badge}
                        </Badge>
                        <label className="flex cursor-pointer items-center gap-2 text-[11px] text-zinc-400">
                            <Checkbox
                                checked={isIncluded}
                                disabled={busy}
                                onCheckedChange={(v) => onToggleIncluded(v === true)}
                                className="border-white/30"
                            />
                            <span>Include in Engagement</span>
                        </label>
                    </div>
                </div>
            </div>
            <div className="flex shrink-0 flex-wrap gap-1.5 sm:justify-end">
                <Button
                type="button"
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={onEdit}
                    className="h-8 border-white/15 bg-black/30 text-[11px] text-zinc-200"
                >
                    <Pencil className="mr-1 h-3.5 w-3.5" />
                    Edit
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={onDelete}
                    className="h-8 border-red-500/35 bg-red-950/20 text-[11px] text-red-200 hover:bg-red-950/40"
                >
                    <Trash2 className="mr-1 h-3.5 w-3.5" />
                    Delete
                </Button>
            </div>
        </div>
    )
}

export default function NewsletterCreate({
    templates,
    previewData,
    openAiConfigured = false,
    newsletterCreateAiResult = null,
    newsletterRecipientCount = 0,
    newsletterRecipientPreview = [],
    newsletterAudienceCounts: newsletterAudienceCountsProp,
    newsletterAudiencePreview: audiencePreviewRows = [],
    newsletterAudienceLoadedSegment = null,
    newsletterRecipientInlineNotice: newsletterRecipientInlineNoticeProp = null,
}: NewsletterCreateProps) {
    const newsletterAudienceCounts: NewsletterAudienceCounts = newsletterAudienceCountsProp ?? {
        followers: 0,
        donors: 0,
        volunteers: 0,
        newsletter_contacts: newsletterRecipientCount,
    }
    const page = usePage<
        SharedData & {
            errors?: Record<string, string | string[]>
            flash?: { success?: string; error?: string }
            newsletterRecipientInlineNotice?: { type: "success" | "error"; message: string } | null
        }
    >()
    const pageErrors = page.props.errors
    const flash = page.props.flash
    const newsletterRecipientInlineNotice =
        page.props.newsletterRecipientInlineNotice ?? newsletterRecipientInlineNoticeProp
    const firstSharedFieldError = (key: "email" | "name"): string | undefined => {
        const v = pageErrors?.[key]
        if (v === undefined || v === null) return undefined
        return Array.isArray(v) ? String(v[0]) : String(v)
    }
    const authUser = page.props.auth?.user as
        | { credits?: number; ai_tokens_used?: number; ai_tokens_included?: number }
        | undefined
    const credits = Number(authUser?.credits ?? 0)
    const aiTokensUsedAuth = Number(authUser?.ai_tokens_used ?? 0)
    const aiTokensIncludedAuth = Number(authUser?.ai_tokens_included ?? 0)
    const displayTokensUsed =
        newsletterCreateAiResult && newsletterCreateAiResult.ok
            ? Number(newsletterCreateAiResult.ai_tokens_used ?? aiTokensUsedAuth)
            : aiTokensUsedAuth
    const displayTokensIncluded =
        newsletterCreateAiResult && newsletterCreateAiResult.ok
            ? Number(newsletterCreateAiResult.ai_tokens_included ?? aiTokensIncludedAuth)
            : aiTokensIncludedAuth
    const hasAiTokensLeft = displayTokensIncluded === 0 || displayTokensUsed < displayTokensIncluded
    const aiTokensRemaining =
        displayTokensIncluded === 0 ? null : Math.max(0, displayTokensIncluded - displayTokensUsed)
    const aiTokensOverBy =
        displayTokensIncluded === 0 ? 0 : Math.max(0, displayTokensUsed - displayTokensIncluded)

    const [communicationKind, setCommunicationKind] = useState<CommunicationKind>("newsletter")
    const [aiBrief, setAiBrief] = useState("")
    const [aiTone, setAiTone] = useState<NewsletterAiTone>("warm")
    const [aiGenerating, setAiGenerating] = useState(false)
    const appliedAiSigRef = useRef<string | null>(null)
    const [selectedTemplate, setSelectedTemplate] = useState<Template | null>(null)
    const [scheduleType, setScheduleType] = useState<"immediate" | "scheduled" | "recurring">("immediate")
    const [showPreview, setShowPreview] = useState(false)
    const [composeTab, setComposeTab] = useState<"edit" | "preview">("edit")
    const [creditCheckoutLoading, setCreditCheckoutLoading] = useState(false)
    const [audienceTab, setAudienceTab] = useState<AudienceTab | null>(null)
    const [audienceListLoading, setAudienceListLoading] = useState(false)
    const [recipientQuery, setRecipientQuery] = useState("")
    const [recipientPage, setRecipientPage] = useState(1)
    const [manualRecipientEmail, setManualRecipientEmail] = useState("")
    const [manualRecipientName, setManualRecipientName] = useState("")
    const [manualRecipientSaving, setManualRecipientSaving] = useState(false)
    const [manualRowBusyId, setManualRowBusyId] = useState<number | null>(null)
    const [editManualContact, setEditManualContact] = useState<{
        id: number
        email: string
        name: string
    } | null>(null)
    const [deleteManualId, setDeleteManualId] = useState<number | null>(null)
    const [editManualSaving, setEditManualSaving] = useState(false)
    const perPage = 8
    const [wizardStep, setWizardStep] = useState<1 | 2 | 3>(1)
    const audienceTabInitedFromUrl = useRef(false)

    useEffect(() => {
        window.scrollTo({ top: 0, behavior: "smooth" })
    }, [wizardStep])

    useEffect(() => {
        if (audienceTabInitedFromUrl.current || audienceTab !== null) return
        if (!newsletterAudienceLoadedSegment) return
        const t = segmentToAudienceTab(newsletterAudienceLoadedSegment)
        if (t) {
            setAudienceTab(t)
            audienceTabInitedFromUrl.current = true
        }
    }, [audienceTab, newsletterAudienceLoadedSegment])

    const withManualRowBusy = (rowId: number) => ({
        preserveScroll: true,
        preserveState: true,
        onStart: () => setManualRowBusyId(rowId),
        onFinish: () => setManualRowBusyId(null),
    })

    const saveEditManualContact = () => {
        if (!editManualContact) return
        const id = editManualContact.id
        setEditManualSaving(true)
        router.patch(
            route("newsletter.recipients.manual.update", id),
            {
                email: editManualContact.email,
                name: editManualContact.name,
                return_to: "newsletter.create",
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setManualRowBusyId(id),
                onSuccess: () => setEditManualContact(null),
                onFinish: () => {
                    setManualRowBusyId(null)
                    setEditManualSaving(false)
                },
            }
        )
    }

    const confirmDeleteManualRecipient = () => {
        if (deleteManualId == null) return
        const id = deleteManualId
        router.delete(route("newsletter.recipients.manual.destroy", id), {
            data: { return_to: "newsletter.create" },
            preserveScroll: true,
            preserveState: true,
            onStart: () => setManualRowBusyId(id),
            onFinish: () => {
                setManualRowBusyId(null)
                setDeleteManualId(null)
            },
        })
    }

    const handleCreditTopUp = () => {
        setCreditCheckoutLoading(true)
        router.post(
            route("credits.checkout"),
            { amount: 1.0, return_route: "newsletter.create" },
            { preserveScroll: true, onError: () => setCreditCheckoutLoading(false), onFinish: () => setCreditCheckoutLoading(false) }
        )
    }

    const { data, setData, post, processing, errors } = useForm({
        newsletter_template_id: "",
        subject: "",
        content: "",
        html_content: "",
        send_via: "email" as "email" | "sms" | "both" | "push",
        schedule_type: "immediate" as "immediate" | "scheduled" | "recurring",
        send_date: "",
        target_type: "all" as "all" | "users" | "organizations" | "specific" | "roles",
        target_users: [] as number[],
        target_organizations: [] as number[],
        target_roles: [] as string[],
        target_criteria: {} as {
            organization_segment?: "followers" | "donors" | "volunteers" | "newsletter_contacts"
        },
        is_public: false,
    })

    const submitManualRecipient = () => {
        router.post(
            route("newsletter.recipients.store"),
            {
                email: manualRecipientEmail,
                name: manualRecipientName,
                return_to: "newsletter.create",
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setManualRecipientSaving(true),
                onFinish: () => setManualRecipientSaving(false),
                onSuccess: (page) => {
                    const p = page.props as {
                        errors?: Record<string, string | string[]>
                        newsletterRecipientInlineNotice?: { type: string } | null
                    }
                    if (p.errors?.email || p.errors?.name) return
                    if (p.newsletterRecipientInlineNotice?.type === "error") return
                    setManualRecipientEmail("")
                    setManualRecipientName("")
                },
            }
        )
    }

    const sampleData: PreviewData = {
        organization_name: previewData?.organization_name || "Your Organization",
        organization_email: previewData?.organization_email || "support@believeinunity.org",
        organization_phone: previewData?.organization_phone || "+1 (555) 000-0000",
        organization_address: previewData?.organization_address || "Your Organization Address",
        recipient_name: previewData?.recipient_name || "Recipient Name",
        recipient_email: previewData?.recipient_email || "recipient@example.com",
        current_date:
            previewData?.current_date ||
            new Date().toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" }),
        current_year: previewData?.current_year || new Date().getFullYear().toString(),
        unsubscribe_link: previewData?.unsubscribe_link || "https://example.com/unsubscribe?token=preview_token",
        public_view_link: previewData?.public_view_link || "https://example.com/newsletter/public/preview",
    }

    const replaceVariables = (text: string): string => {
        if (!text) return ""
        let result = text
        Object.entries(sampleData).forEach(([key, value]) => {
            result = result.replace(new RegExp(`\\{${key}\\}`, "g"), value)
        })
        return result
    }

    const previewSubject = replaceVariables(data.subject)
    const previewContent = replaceVariables(data.content)
    const previewHtmlContent = replaceVariables(data.html_content || "")

    const handleTemplateChange = (templateId: string) => {
        const template = templates.find((t) => t.id === parseInt(templateId, 10))
        if (template) {
            setSelectedTemplate(template)
            setData({
                ...data,
                newsletter_template_id: templateId,
                subject: template.subject,
                content: template.content,
                html_content: template.html_content || "",
            })
        }
    }

    const aiTemplateTypeForBackend = communicationKind === "letter" ? "announcement" : "newsletter"

    useEffect(() => {
        if (!newsletterCreateAiResult || !newsletterCreateAiResult.ok) return
        const sig = `${newsletterCreateAiResult.subject}|${newsletterCreateAiResult.content?.length ?? 0}|${newsletterCreateAiResult.html_content?.length ?? 0}`
        if (appliedAiSigRef.current === sig) return
        appliedAiSigRef.current = sig
        setData("subject", newsletterCreateAiResult.subject)
        setData("content", newsletterCreateAiResult.content)
        setData("html_content", newsletterCreateAiResult.html_content ?? "")
    }, [newsletterCreateAiResult])

    const applyCommunicationKind = (kind: CommunicationKind) => {
        setCommunicationKind(kind)
        if (kind === "message") {
            setData("send_via", "sms")
            setData("html_content", "")
        } else if (kind === "push") {
            setData("send_via", "push")
            setData("html_content", "")
        } else {
            setData("send_via", "email")
        }
    }

    /** Keep UI aligned if `send_via` is push but local kind was reset (e.g. after navigation). */
    useEffect(() => {
        if (data.send_via === "push" && communicationKind !== "push") {
            setCommunicationKind("push")
        }
    }, [data.send_via, communicationKind])

    const loadAudienceSegment = useCallback((tab: AudienceTab) => {
        const seg = audienceTabToSegment(tab)
        setAudienceListLoading(true)
        router.get(
            route("newsletter.create"),
            { audience_segment: seg },
            {
                preserveState: true,
                preserveScroll: true,
                only: [
                    "newsletterAudienceCounts",
                    "newsletterAudiencePreview",
                    "newsletterAudienceLoadedSegment",
                    "newsletterRecipientCount",
                    "newsletterRecipientPreview",
                    "newsletterCreateAiResult",
                    "openAiConfigured",
                    "previewData",
                    "templates",
                    "canUseNewsletterProTargeting",
                ],
                onFinish: () => setAudienceListLoading(false),
            }
        )
    }, [])

    useEffect(() => {
        if (!audienceTab) return
        const organization_segment = audienceTabToSegment(audienceTab)
        setData("target_type", "all")
        setData("target_roles", [])
        setData("target_criteria", { organization_segment })
    }, [audienceTab, setData])

    useEffect(() => {
        setRecipientPage(1)
    }, [audienceTab])

    const displayAudienceRows = useMemo((): NewsletterAudienceRow[] => {
        if (!audienceTab || !newsletterAudienceLoadedSegment) return []
        if (newsletterAudienceLoadedSegment !== audienceTabToSegment(audienceTab)) return []
        return audiencePreviewRows
    }, [audienceTab, newsletterAudienceLoadedSegment, audiencePreviewRows])

    const selectedAudienceCount = useMemo(() => {
        if (!audienceTab) return 0
        const c = newsletterAudienceCounts
        switch (audienceTab) {
            case "all":
                return c.followers
            case "donors":
                return c.donors
            case "volunteers":
                return c.volunteers
            case "custom":
                return c.newsletter_contacts
            default:
                return 0
        }
    }, [audienceTab, newsletterAudienceCounts])

    const filteredRecipients = useMemo(() => {
        const q = recipientQuery.trim().toLowerCase()
        const rows = displayAudienceRows
        if (!q) return rows
        return rows.filter(
            (r) =>
                r.email.toLowerCase().includes(q) ||
                (r.name && r.name.toLowerCase().includes(q))
        )
    }, [displayAudienceRows, recipientQuery])

    const pagedRecipients = useMemo(() => {
        const start = (recipientPage - 1) * perPage
        return filteredRecipients.slice(start, start + perPage)
    }, [filteredRecipients, recipientPage])

    const totalPages = Math.max(1, Math.ceil(filteredRecipients.length / perPage))

    useEffect(() => {
        setRecipientPage((p) => Math.min(Math.max(1, p), totalPages))
    }, [totalPages])

    const audiencePaginationPages = useMemo(
        () => audiencePaginationRange(recipientPage, totalPages, 5),
        [recipientPage, totalPages]
    )

    const audienceListRangeLabel = useMemo(() => {
        const n = filteredRecipients.length
        if (n === 0) return ""
        const start = (recipientPage - 1) * perPage + 1
        const end = Math.min(recipientPage * perPage, n)
        const q = recipientQuery.trim()
        return q
            ? `Showing ${start}–${end} of ${n} matching “${q}”`
            : `Showing ${start}–${end} of ${n}`
    }, [filteredRecipients.length, recipientPage, perPage, recipientQuery])

    const audienceStepReady =
        audienceTab != null &&
        !audienceListLoading &&
        newsletterAudienceLoadedSegment === audienceTabToSegment(audienceTab)

    const submitAiBrief = () => {
        if (!aiBrief.trim() || aiGenerating || !openAiConfigured || !hasAiTokensLeft) return
        setAiGenerating(true)
        const outputMode = data.send_via === "sms" || data.send_via === "push" ? "plain" : "html"
        router.post(
            route("newsletter.create.ai-generate"),
            {
                brief: aiBrief.trim(),
                template_type: aiTemplateTypeForBackend,
                tone: aiTone,
                output_mode: outputMode,
                send_via: data.send_via,
            },
            { preserveScroll: true, onFinish: () => setAiGenerating(false) }
        )
    }

    const runStore = () => {
        const st = scheduleType
        setData("schedule_type", st)

        const submitData: Record<string, unknown> = {
            ...data,
            schedule_type: st,
        }

        if (st === "immediate") {
            delete submitData.send_date
        } else {
            if (!data.send_date) {
                alert("Please select a date and time for scheduled or recurring Engagement.")
                return
            }
            const utcDate = convertUserTimezoneToUTC(data.send_date, getBrowserTimezone())
            submitData.send_date = utcDate.toISOString()
        }

        if (st === "recurring") {
            submitData.recurring_settings = { frequency: "weekly" }
        } else {
            delete submitData.recurring_settings
        }

        post(route("newsletter.store"), {
            data: submitData,
            onSuccess: () => {
                router.visit(route("newsletter.index"), { only: ["newsletters", "stats"] })
            },
        })
    }

    const hasBody =
        data.send_via === "sms"
            ? Boolean(data.content?.trim())
            : data.send_via === "both"
              ? Boolean(data.content?.trim() && data.html_content?.trim())
              : data.send_via === "push"
                ? Boolean(data.subject?.trim() && data.content?.trim())
                : Boolean(data.content?.trim() || data.html_content?.trim())

    return (
        <AppSidebarLayout>
            <Head title="Engagement · New" />

            <div className="w-full max-w-none px-5 py-6 sm:px-8 lg:px-10 xl:px-12">
                <div className="mx-auto w-full max-w-none">
                    <div className="mb-8 space-y-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => window.history.back()}
                                className="border-white/15 bg-white/5 text-zinc-100 hover:bg-white/10"
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                        </div>
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Engagement</p>
                        <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">
                            New Engagement
                                </h1>
                        <p className="text-sm text-zinc-400">
                            Recipients → schedule → Engagement (with templates &amp; history)
                        </p>

                        {openAiConfigured && (
                            <div
                                className={cn(
                                    "rounded-xl border px-4 py-3 text-sm",
                                    displayTokensIncluded > 0 && !hasAiTokensLeft
                                        ? "border-amber-500/40 bg-amber-950/30 text-amber-100/95"
                                        : "border-white/10 bg-white/[0.04] text-zinc-200"
                                )}
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                                    AI token balance
                                </p>
                                {displayTokensIncluded === 0 ? (
                                    <p className="mt-1 text-sm text-zinc-300">
                                        No fixed monthly cap on this account — usage is still tracked. You can run
                                        generate when the brief and model allow.
                                    </p>
                                ) : (
                                    <>
                                        <p className="mt-1 font-mono text-sm tabular-nums text-zinc-100">
                                            {displayTokensUsed.toLocaleString()} / {displayTokensIncluded.toLocaleString()}{" "}
                                            used
                                            {aiTokensRemaining !== null && (
                                                <>
                                                    {" "}
                                                    ·{" "}
                                                    <span
                                                        className={cn(
                                                            "font-semibold",
                                                            !hasAiTokensLeft ? "text-amber-300" : "text-emerald-300/90"
                                                        )}
                                                    >
                                                        {aiTokensRemaining.toLocaleString()} remaining
                                                    </span>
                                                </>
                                            )}
                                        </p>
                                        {!hasAiTokensLeft && (
                                            <p className="mt-2 text-sm leading-relaxed text-amber-100/90">
                                                You don&apos;t have enough AI tokens left to generate new content. Each
                                                run draws from this pool. Top up your plan or wait until your allowance
                                                resets, then try again.
                                            </p>
                                        )}
                                        {hasAiTokensLeft &&
                                            aiTokensRemaining !== null &&
                                            aiTokensRemaining > 0 &&
                                            aiTokensRemaining < 500 && (
                                                <p className="mt-2 text-xs text-zinc-500">
                                                    Tip: one generation often uses roughly a few hundred to several
                                                    thousand tokens — leave headroom if your brief is long.
                                                </p>
                                            )}
                                    </>
                                )}
                                {displayTokensIncluded > 0 && !hasAiTokensLeft && (
                                    <div className="mt-3">
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={handleCreditTopUp}
                                            disabled={creditCheckoutLoading}
                                            className="border border-amber-500/50 bg-amber-600/30 text-amber-50 hover:bg-amber-600/45"
                                        >
                                            {creditCheckoutLoading ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                "Top up"
                                            )}
                                        </Button>
                            </div>
                                )}
                        </div>
                        )}

                        <p className="text-sm leading-relaxed text-zinc-500">
                            <span className="font-medium text-zinc-400">Why this order?</span> Content first, then
                            audience, then schedule — with templates and history along the way.
                        </p>

                        <div className="flex flex-wrap gap-2 pt-1">
                            {(
                                [
                                    { step: 1 as const, label: "Content" },
                                    { step: 2 as const, label: "Recipients" },
                                    { step: 3 as const, label: "Schedule" },
                                ] as const
                            ).map(({ step, label }) => (
                                <button
                                    key={step}
                                    type="button"
                                    disabled={step > wizardStep}
                                    onClick={() => {
                                        if (step < wizardStep) {
                                            setWizardStep(step)
                                        }
                                    }}
                                    className={cn(
                                        "rounded-full border px-4 py-2 text-xs font-semibold transition-colors",
                                        wizardStep === step
                                            ? "border-violet-500 bg-violet-600/30 text-white"
                                            : step < wizardStep
                                              ? "cursor-pointer border-white/15 bg-white/5 text-zinc-300 hover:bg-white/10"
                                              : "cursor-not-allowed border-white/5 bg-black/20 text-zinc-600"
                                    )}
                                >
                                    {step}. {label}
                                </button>
                            ))}
                    </div>
                </div>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault()
                            if (wizardStep !== 3) {
                                return
                            }
                            runStore()
                        }}
                    >
                        <div className="space-y-5">
                            {wizardStep === 1 && (
                                <div className="space-y-1">
                                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-300/90">
                                        Step 1 of 3
                                    </p>
                                    <h2 className="text-lg font-bold text-white">Create communication</h2>
                                    <p className="text-xs text-zinc-500">What is this Engagement?</p>
                                </div>
                            )}
                            {wizardStep === 2 && (
                                <div className="space-y-1">
                                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-300/90">
                                        Step 2 of 3
                                    </p>
                                    <h2 className="text-lg font-bold text-white">Choose recipients</h2>
                                    <p className="text-xs text-zinc-500">Who should receive this?</p>
                                </div>
                            )}
                            {wizardStep === 3 && (
                                <div className="space-y-1">
                                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-violet-300/90">
                                        Step 3 of 3
                                    </p>
                                    <h2 className="text-lg font-bold text-white">Schedule Engagement</h2>
                                    <p className="text-xs text-zinc-500">When should it go out?</p>
                                </div>
                            )}

                            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(260px,360px)] lg:items-start lg:gap-8">
                            <div className="min-w-0 space-y-4">
                            {/* —— Step 1 —— */}
                            {wizardStep === 1 && (
                            <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-4">
                                            <div className="space-y-4">
                                    <div className="grid grid-cols-2 gap-2">
                                        {(
                                            [
                                                { kind: "newsletter" as const, label: "Email", Icon: Mail, color: "text-pink-400" },
                                                { kind: "letter" as const, label: "Letter", Icon: FileText, color: "text-amber-400" },
                                                { kind: "message" as const, label: "Message", Icon: MessageSquare, color: "text-sky-400" },
                                                { kind: "push" as const, label: "Push", Icon: Bell, color: "text-emerald-400" },
                                            ] as const
                                        ).map(({ kind, label, Icon, color }) => {
                                            const selected = communicationKind === kind
                                            return (
                                            <button
                                                key={kind}
                                                type="button"
                                                aria-pressed={selected}
                                                onClick={() => applyCommunicationKind(kind)}
                                                className={cn(
                                                    "flex min-h-[3.25rem] w-full items-center gap-3 rounded-xl border p-3 text-left text-sm transition-all duration-200",
                                                    selected
                                                        ? "border-violet-400/80 bg-gradient-to-br from-violet-600/35 to-fuchsia-600/25 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] ring-2 ring-violet-500/40"
                                                        : "border-white/10 bg-white/[0.03] hover:border-violet-400/35 hover:bg-white/[0.05]"
                                                )}
                                            >
                                                <span
                                                    className={cn(
                                                        "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border transition-colors",
                                                        selected
                                                            ? "border-violet-400/40 bg-violet-500/20"
                                                            : "border-white/10 bg-black/20"
                                                    )}
                                                >
                                                    <Icon className={cn("h-6 w-6", selected ? color : cn(color, "opacity-90"))} aria-hidden />
                                                </span>
                                                <span className="min-w-0 flex-1 font-semibold leading-tight text-white">
                                                    {label}
                                                </span>
                                                <span
                                                    className="flex min-w-[1.5rem] shrink-0 justify-end"
                                                    aria-hidden
                                                >
                                                    {selected && (
                                                        <CheckCircle2
                                                            className="h-6 w-6 text-violet-300 drop-shadow-[0_0_12px_rgba(167,139,250,0.45)]"
                                                            strokeWidth={2}
                                                        />
                                                    )}
                                                </span>
                                            </button>
                                            )
                                        })}
                                    </div>

                                    <div className="mt-4 flex flex-wrap gap-2">
                                        {QUICK_BRIEF_CHIPS.map((c) => (
                                            <Button
                                                key={c.label}
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                className="h-8 rounded-full border border-white/10 bg-white/5 text-xs text-zinc-200"
                                                onClick={() => setAiBrief(c.text)}
                                            >
                                                {c.label}
                                            </Button>
                                        ))}
                                    </div>

                                    {openAiConfigured && data.send_via !== "sms" && (
                                        <div className="mt-4 space-y-3 rounded-xl border border-violet-500/25 bg-violet-950/20 p-3">
                                            <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-zinc-300">
                                                <div className="flex min-w-0 flex-wrap gap-x-4 gap-y-2">
                                                    <span className="inline-flex items-center gap-1">
                                                        <Coins className="h-3.5 w-3.5 shrink-0 text-amber-400" />
                                                        Credits {credits.toLocaleString()}
                                                    </span>
                                                    <span className="inline-flex items-center gap-1 text-zinc-400">
                                                        <Sparkles className="h-3.5 w-3.5 shrink-0 text-violet-400" />
                                                        {displayTokensIncluded === 0
                                                            ? `AI tokens used: ${displayTokensUsed.toLocaleString()}`
                                                            : `${displayTokensUsed.toLocaleString()} / ${displayTokensIncluded.toLocaleString()} plan tokens`}
                                                        {aiTokensRemaining !== null && aiTokensRemaining > 0 && (
                                                            <span className="text-zinc-500">
                                                                {" "}
                                                                · {aiTokensRemaining.toLocaleString()} left
                                                            </span>
                                                        )}
                                                        {aiTokensOverBy > 0 && (
                                                            <span className="text-red-400"> (over by {aiTokensOverBy})</span>
                                                        )}
                                                    </span>
                                                </div>
                                                {!hasAiTokensLeft && displayTokensIncluded > 0 && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        onClick={handleCreditTopUp}
                                                        disabled={creditCheckoutLoading}
                                                        className="ml-auto shrink-0 self-end sm:self-auto"
                                                    >
                                                        {creditCheckoutLoading ? (
                                                            <Loader2 className="h-4 w-4 animate-spin" />
                                                        ) : (
                                                            "Top up"
                                                        )}
                                                    </Button>
                                                )}
                                            </div>
                                            <div className="grid grid-cols-2 gap-1.5 lg:grid-cols-4">
                                                {NEWSLETTER_AI_TONES.map(({ id, label, hint, Icon }) => (
                                                    <button
                                                        key={id}
                                                        type="button"
                                                        disabled={aiGenerating}
                                                        onClick={() => setAiTone(id)}
                                                        className={cn(
                                                            "rounded-lg border p-2 text-left text-[10px]",
                                                            aiTone === id
                                                                ? "border-violet-500 bg-violet-500/15"
                                                                : "border-white/10 bg-black/20"
                                                        )}
                                                    >
                                                        <span className="flex items-center gap-1 font-semibold text-zinc-200">
                                                            <Icon className="h-3 w-3" />
                                                            {label}
                                                        </span>
                                                        <span className="mt-1 line-clamp-3 text-zinc-500">{hint}</span>
                                                    </button>
                                                ))}
                                            </div>
                                            <Label className="text-zinc-400">Or describe your Engagement…</Label>
                                            <TextArea
                                                value={aiBrief}
                                                onChange={(e) => setAiBrief(e.target.value)}
                                                rows={3}
                                                className="border-white/10 bg-black/30 text-zinc-100"
                                                placeholder='e.g. "Thank-you to donors with impact stats and a donate button"'
                                            />
                                            <Button
                                                type="button"
                                                disabled={!aiBrief.trim() || aiGenerating || !hasAiTokensLeft}
                                                onClick={submitAiBrief}
                                                className="w-full bg-gradient-to-r from-violet-600 to-fuchsia-600"
                                            >
                                                {aiGenerating ? (
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                ) : (
                                                    <Sparkles className="mr-2 h-4 w-4" />
                                                )}
                                                Generate content
                                            </Button>
                                        </div>
                                    )}

                                    <div className="mt-4 space-y-2">
                                        <Label className="text-zinc-400">Saved template (optional)</Label>
                                                    <Select value={data.newsletter_template_id} onValueChange={handleTemplateChange}>
                                            <SelectTrigger className="border-white/10 bg-white/5 text-zinc-100">
                                                <SelectValue placeholder="Skip or pick a template" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                {templates.map((t) => (
                                                    <SelectItem key={t.id} value={String(t.id)}>
                                                        {t.name} ({t.template_type})
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                    <div className="mt-4 border-t border-white/10 pt-4">
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <h3 className="text-sm font-semibold text-white">Content</h3>
                                            <div className="flex gap-1 rounded-lg border border-white/10 bg-black/30 p-0.5">
                                                <button
                                                    type="button"
                                                    onClick={() => setComposeTab("edit")}
                                                    className={cn(
                                                        "rounded-md px-2 py-1 text-[10px] font-semibold",
                                                        composeTab === "edit" ? "bg-violet-600 text-white" : "text-zinc-500"
                                                    )}
                                                >
                                                    <Pencil className="mr-1 inline h-3 w-3" />
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setComposeTab("preview")}
                                                    className={cn(
                                                        "rounded-md px-2 py-1 text-[10px] font-semibold",
                                                        composeTab === "preview" ? "bg-violet-600 text-white" : "text-zinc-500"
                                                    )}
                                                >
                                                    <Eye className="mr-1 inline h-3 w-3" />
                                                    Preview
                                                </button>
                                            </div>
                                        </div>
                                        {composeTab === "edit" ? (
                                            <div className="space-y-3">
                                            <div>
                                                    <Label className="text-zinc-400">Subject</Label>
                                                <Input
                                                    value={data.subject}
                                                        onChange={(e) => setData("subject", e.target.value)}
                                                        className="mt-1 border-white/10 bg-white/5"
                                                />
                                                    {errors.subject && <p className="text-xs text-red-400">{errors.subject}</p>}
                                            </div>
                                            <div>
                                                    <Label className="text-zinc-400">Plain text</Label>
                                                <TextArea
                                                    value={data.content}
                                                        onChange={(e) => setData("content", e.target.value)}
                                                        rows={6}
                                                        className="mt-1 border-white/10 bg-white/5"
                                                    />
                                            </div>
                                                {data.send_via !== "sms" && data.send_via !== "push" && (
                                            <div>
                                                        <Label className="text-zinc-400">HTML</Label>
                                                <TextArea
                                                    value={data.html_content}
                                                            onChange={(e) => setData("html_content", e.target.value)}
                                                            rows={6}
                                                            className="mt-1 border-white/10 bg-white/5 font-mono text-xs"
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="overflow-hidden rounded-xl border border-white/10 bg-white text-zinc-900">
                                                <div className="border-b border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-semibold">
                                                    {previewSubject || data.subject || "Subject"}
                                                </div>
                                                <div className="max-h-48 overflow-y-auto p-3 text-sm">
                                                    {previewHtmlContent ? (
                                                        <div
                                                            className="prose prose-sm max-w-none"
                                                            dangerouslySetInnerHTML={{ __html: previewHtmlContent }}
                                                        />
                                                    ) : (
                                                        <div className="whitespace-pre-wrap">{previewContent || data.content}</div>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                            </div>

                                <div className="flex flex-wrap items-center justify-end gap-3">
                                                    <Button
                                                        type="button"
                                        onClick={() => setWizardStep(2)}
                                        disabled={!data.subject?.trim() || !hasBody}
                                        className="bg-gradient-to-r from-violet-600 to-fuchsia-600"
                                    >
                                        Next: Recipients
                                        <ChevronRight className="ml-2 h-4 w-4" />
                                                    </Button>
                                </div>
                            </motion.div>
                            )}

                            {/* —— Step 2 —— */}
                            {wizardStep === 2 && (
                            <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-4">
                                <div className="flex flex-col gap-3">
                                    <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                        {(
                                            [
                                                {
                                                    id: "all" as const,
                                                    label: "All followers",
                                                    hint: null as string | null,
                                                    count: newsletterAudienceCounts.followers,
                                                },
                                                {
                                                    id: "donors" as const,
                                                    label: "Donors",
                                                    hint: null as string | null,
                                                    count: newsletterAudienceCounts.donors,
                                                },
                                                {
                                                    id: "volunteers" as const,
                                                    label: "Volunteers",
                                                    hint: null as string | null,
                                                    count: newsletterAudienceCounts.volunteers,
                                                },
                                                {
                                                    id: "custom" as const,
                                                    label: "Manual recipients",
                                                    hint: "Add emails with the form below when selected",
                                                    count: newsletterAudienceCounts.newsletter_contacts,
                                                },
                                            ] as const
                                        ).map(({ id, label, hint, count }) => (
                                            <button
                                                key={id}
                                                        type="button"
                                                disabled={audienceListLoading}
                                                        onClick={() => {
                                                    setAudienceTab(id)
                                                    loadAudienceSegment(id)
                                                }}
                                                className={cn(
                                                    "rounded-lg border px-2 py-2 text-left text-xs transition-colors disabled:opacity-50",
                                                    audienceTab === id
                                                        ? "border-violet-500 bg-violet-600/25 text-white"
                                                        : "border-white/10 bg-black/20 text-zinc-300 hover:border-white/20"
                                                )}
                                            >
                                                <span className="font-semibold">{label}</span>
                                                <span className="ml-1 text-emerald-300/90">
                                                    ({count.toLocaleString()} active)
                                                </span>
                                                {hint ? (
                                                    <span
                                                        className={cn(
                                                            "mt-1 block text-[10px] leading-snug",
                                                            audienceTab === id
                                                                ? "text-violet-100/80"
                                                                : "text-zinc-500"
                                                        )}
                                                    >
                                                        {hint}
                                                    </span>
                                                ) : null}
                                            </button>
                                        ))}
                                    </div>

                                    {audienceTab === "custom" && (
                                        <div className="space-y-3 rounded-xl border border-violet-500/35 bg-gradient-to-br from-violet-950/50 to-fuchsia-950/30 px-3 py-3">
                                            {newsletterRecipientInlineNotice?.type === "success" ? (
                                                <p className="rounded-lg border border-emerald-500/40 bg-emerald-950/40 px-2.5 py-2 text-xs text-emerald-100">
                                                    {newsletterRecipientInlineNotice.message}
                                                </p>
                                            ) : null}
                                            {newsletterRecipientInlineNotice?.type === "error" ? (
                                                <p className="rounded-lg border border-red-500/40 bg-red-950/40 px-2.5 py-2 text-xs text-red-100">
                                                    {newsletterRecipientInlineNotice.message}
                                                </p>
                                            ) : null}
                                            {!newsletterRecipientInlineNotice && flash?.success ? (
                                                <p className="rounded-lg border border-emerald-500/40 bg-emerald-950/40 px-2.5 py-2 text-xs text-emerald-100">
                                                    {flash.success}
                                                </p>
                                            ) : null}
                                            {!newsletterRecipientInlineNotice && flash?.error ? (
                                                <p className="rounded-lg border border-red-500/40 bg-red-950/40 px-2.5 py-2 text-xs text-red-100">
                                                    {flash.error}
                                                </p>
                                            ) : null}
                                            <p className="text-xs leading-relaxed text-zinc-300">
                                                <span className="font-semibold text-white">Add a manual recipient</span> here — same
                                                list as Engagement → Recipients. After saving, the list below updates in place (no page reload).
                                            </p>
                                            {/* Must not nest <form> inside the wizard <form> — browsers close the outer form and can full-page POST the wrong action. */}
                                            <div className="grid w-full min-w-0 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end sm:gap-3">
                                                <div className="min-w-0">
                                                    <Label htmlFor="manual-recipient-email" className="text-[11px] text-zinc-400">
                                                        Email <span className="text-red-400">*</span>
                                                    </Label>
                                                    <Input
                                                        id="manual-recipient-email"
                                                        type="email"
                                                        autoComplete="email"
                                                        value={manualRecipientEmail}
                                                        onChange={(e) => setManualRecipientEmail(e.target.value)}
                                                        onKeyDown={(e) => {
                                                            if (e.key !== "Enter") return
                                                            e.preventDefault()
                                                            e.stopPropagation()
                                                            if (!manualRecipientSaving && manualRecipientEmail.trim()) {
                                                                submitManualRecipient()
                                                            }
                                                        }}
                                                        placeholder="supporter@example.com"
                                                        className="mt-1 w-full border-white/10 bg-black/40 text-sm text-zinc-100"
                                                    />
                                                    {firstSharedFieldError("email") ? (
                                                        <p className="mt-1 text-[11px] text-red-400">
                                                            {firstSharedFieldError("email")}
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <div className="min-w-0">
                                                    <Label htmlFor="manual-recipient-name" className="text-[11px] text-zinc-400">
                                                        Name (optional)
                                                    </Label>
                                                    <Input
                                                        id="manual-recipient-name"
                                                        type="text"
                                                        autoComplete="name"
                                                        value={manualRecipientName}
                                                        onChange={(e) => setManualRecipientName(e.target.value)}
                                                        placeholder="Jane Supporter"
                                                        className="mt-1 w-full border-white/10 bg-black/40 text-sm text-zinc-100"
                                                    />
                                                    {firstSharedFieldError("name") ? (
                                                        <p className="mt-1 text-[11px] text-red-400">
                                                            {firstSharedFieldError("name")}
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <Button
                                                    type="button"
                                                    disabled={manualRecipientSaving}
                                                    onClick={() => submitManualRecipient()}
                                                    className="h-10 w-full shrink-0 bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white sm:w-auto sm:min-w-[10.5rem]"
                                                >
                                                    {manualRecipientSaving ? (
                                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                    ) : (
                                                        <UserPlus className="mr-2 h-4 w-4" />
                                                    )}
                                                    Add recipient
                                                    </Button>
                                                </div>
                                            </div>
                                    )}

                                    <div className="relative">
                                        <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                                                    <Input
                                            value={recipientQuery}
                                            onChange={(e) => {
                                                setRecipientQuery(e.target.value)
                                                setRecipientPage(1)
                                            }}
                                            placeholder="Search contacts…"
                                            className="border-white/10 bg-black/30 pl-9 text-sm text-zinc-100"
                                        />
                                    </div>

                                    <div className="max-h-96 space-y-2 overflow-y-auto rounded-lg border border-white/10 bg-black/25 p-2">
                                        {audienceListLoading ? (
                                            <div className="flex flex-col items-center justify-center gap-2 py-10">
                                                <Loader2 className="h-8 w-8 animate-spin text-violet-400" />
                                                <p className="text-xs text-zinc-500">Loading audience from server…</p>
                                            </div>
                                        ) : !audienceTab ? (
                                            <p className="p-3 text-center text-xs text-zinc-500">
                                                Choose an audience type above. Counts load first; tap a card to load that list here
                                                (Inertia partial reload — no separate API calls).
                                            </p>
                                        ) : pagedRecipients.length === 0 ? (
                                            <div className="flex flex-col items-center gap-3 p-4 text-center">
                                                <p className="text-xs text-zinc-500">
                                                    {recipientQuery.trim()
                                                        ? "No contacts match your search."
                                                        : audienceTab === "custom"
                                                          ? "No manual recipients yet. Use the form above to add an email, or search if you already added some."
                                                          : audienceTab === "all"
                                                            ? "No followers yet. Supporters who follow your organization appear here."
                                                            : audienceTab === "donors"
                                                              ? "No donors yet. People who complete a donation to your organization appear here."
                                                              : "No volunteers yet. Accepted volunteer applications for your org appear here."}
                                                </p>
                                                </div>
                                        ) : (
                                            pagedRecipients.map((row) =>
                                                row.kind === "contact" && audienceTab === "custom" ? (
                                                    <ManualRecipientAudienceRow
                                                        key={`contact-${row.id}`}
                                                        row={row}
                                                        busy={manualRowBusyId === row.id}
                                                        onToggleIncluded={(nextIncluded) => {
                                                            if (nextIncluded) {
                                                                router.post(
                                                                    route("newsletter.recipients.manual.subscribe", row.id),
                                                                    { return_to: "newsletter.create" },
                                                                    withManualRowBusy(row.id)
                                                                )
                                                            } else {
                                                                router.post(
                                                                    route("newsletter.recipients.manual.unsubscribe", row.id),
                                                                    { return_to: "newsletter.create" },
                                                                    withManualRowBusy(row.id)
                                                                )
                                                            }
                                                        }}
                                                        onEdit={() =>
                                                            setEditManualContact({
                                                                id: row.id,
                                                                email: row.email,
                                                                name: row.name || "",
                                                            })
                                                        }
                                                        onDelete={() => setDeleteManualId(row.id)}
                                                    />
                                                ) : (
                                                    <AudienceMemberCard
                                                        key={`${row.kind}-${row.id}`}
                                                        row={row}
                                                    />
                                                )
                                            )
                                        )}
                                    </div>

                                    <div className="flex w-full flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-between">
                                        {audienceListRangeLabel ? (
                                            <p className="min-w-0 text-left text-[11px] text-zinc-500 sm:flex-1">
                                                {audienceListRangeLabel}
                                            </p>
                                        ) : (
                                            <span className="hidden sm:block sm:flex-1" aria-hidden />
                                        )}
                                        {totalPages > 1 ? (
                                            <div className="flex w-full shrink-0 flex-wrap items-center justify-end gap-0.5 sm:w-auto">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2"
                                                    disabled={recipientPage <= 1}
                                                    onClick={() => setRecipientPage((p) => Math.max(1, p - 1))}
                                                >
                                                    ‹
                                                </Button>
                                                {audiencePaginationPages[0] > 1 ? (
                                                    <>
                                                        <button
                                                            type="button"
                                                            onClick={() => setRecipientPage(1)}
                                                            className="h-7 w-7 rounded-md px-1.5 text-zinc-400 hover:bg-white/5"
                                                        >
                                                            1
                                                        </button>
                                                        {audiencePaginationPages[0] > 2 ? (
                                                            <span className="px-0.5 text-[11px] text-zinc-600">…</span>
                                                        ) : null}
                                                    </>
                                                ) : null}
                                                {audiencePaginationPages.map((p) => (
                                                    <button
                                                        key={p}
                                                        type="button"
                                                        onClick={() => setRecipientPage(p)}
                                                        className={cn(
                                                            "h-7 w-7 rounded-md px-1.5",
                                                            recipientPage === p
                                                                ? "bg-violet-600 text-white"
                                                                : "text-zinc-400 hover:bg-white/5"
                                                        )}
                                                    >
                                                        {p}
                                                    </button>
                                                ))}
                                                {(() => {
                                                    const lastInRange =
                                                        audiencePaginationPages[audiencePaginationPages.length - 1]
                                                    if (lastInRange == null || lastInRange >= totalPages) return null
                                                    return (
                                                        <>
                                                            {lastInRange < totalPages - 1 ? (
                                                                <span className="px-0.5 text-[11px] text-zinc-600">…</span>
                                                            ) : null}
                                                            <button
                                                                type="button"
                                                                onClick={() => setRecipientPage(totalPages)}
                                                                className="h-7 w-7 rounded-md px-1.5 text-zinc-400 hover:bg-white/5"
                                                            >
                                                                {totalPages}
                                                            </button>
                                                        </>
                                                    )
                                                })()}
                                            <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2"
                                                    disabled={recipientPage >= totalPages}
                                                    onClick={() => setRecipientPage((p) => Math.min(totalPages, p + 1))}
                                                >
                                                    ›
                                            </Button>
                                            </div>
                                        ) : null}
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
                                                <Button
                                                    type="button"
                                        variant="ghost"
                                        className="text-zinc-300"
                                        onClick={() => setWizardStep(1)}
                                    >
                                        <ArrowLeft className="mr-2 h-4 w-4" />
                                        Back
                                                </Button>
                                    <Button
                                        type="button"
                                        onClick={() => setWizardStep(3)}
                                        disabled={!audienceStepReady}
                                        className="bg-gradient-to-r from-violet-600 to-fuchsia-600"
                                    >
                                        Next: Schedule
                                        <ChevronRight className="ml-2 h-4 w-4" />
                                    </Button>
                                </div>
                                </motion.div>
                            )}

                            {/* —— Step 3 —— */}
                            {wizardStep === 3 && (
                            <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-4">
                                <div className="space-y-4">
                                    <div>
                                        <Label className="mb-2 block text-xs text-zinc-400">Channel</Label>
                                        {/* 2×2 grid: four columns in one row overflowed beside the merge-variables aside on lg; keep all channels visible */}
                                        <div className="grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2">
                                            {(
                                                [
                                                    {
                                                        id: "email" as const,
                                                        label: "Email",
                                                        desc: "HTML + plain text",
                                                        Icon: Mail,
                                                        color: "text-pink-400",
                                                    },
                                                    {
                                                        id: "sms" as const,
                                                        label: "SMS",
                                                        desc: "Text message",
                                                        Icon: MessageSquare,
                                                        color: "text-sky-400",
                                                    },
                                                    {
                                                        id: "push" as const,
                                                        label: "Push",
                                                        desc: "App push — mobile devices only",
                                                        Icon: Bell,
                                                        color: "text-lime-400",
                                                    },
                                                    {
                                                        id: "both" as const,
                                                        label: "Both",
                                                        desc: "Email and SMS",
                                                        Icon: Layers2,
                                                        color: "text-emerald-400",
                                                    },
                                                ] as const
                                            ).map(({ id, label, desc, Icon, color }) => {
                                                const selected = data.send_via === id
                                                return (
                                                    <button
                                                        key={id}
                                                        type="button"
                                                        aria-pressed={selected}
                                                        onClick={() => {
                                                            if (id === "push") {
                                                                applyCommunicationKind("push")
                                                                return
                                                            }
                                                            setData("send_via", id)
                                                            if (id === "sms") {
                                                                setData("html_content", "")
                                                                if (communicationKind === "push") {
                                                                    setCommunicationKind("message")
                                                                }
                                                            } else if (communicationKind === "push") {
                                                                setCommunicationKind("newsletter")
                                                            }
                                                        }}
                                                        className={cn(
                                                            "flex w-full items-start gap-3 rounded-xl border p-3 text-left text-sm transition-all duration-200",
                                                            selected
                                                                ? "border-violet-400/80 bg-gradient-to-br from-violet-600/35 to-fuchsia-600/25 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] ring-2 ring-violet-500/40"
                                                                : "border-white/10 bg-white/[0.03] hover:border-violet-400/35 hover:bg-white/[0.05]"
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border transition-colors",
                                                                selected
                                                                    ? "border-violet-400/40 bg-violet-500/20"
                                                                    : "border-white/10 bg-black/20"
                                                            )}
                                                        >
                                                            <Icon
                                                                className={cn(
                                                                    "h-5 w-5",
                                                                    selected ? color : cn(color, "opacity-90")
                                                                )}
                                                                aria-hidden
                                                            />
                                                        </span>
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block font-semibold leading-tight text-white">
                                                                {label}
                                                            </span>
                                                            <span className="mt-0.5 block text-[11px] leading-snug text-zinc-500">
                                                                {desc}
                                                            </span>
                                                        </span>
                                                        <span className="flex min-w-[1.25rem] shrink-0 justify-end" aria-hidden>
                                                            {selected ? (
                                                                <CheckCircle2 className="h-5 w-5 text-violet-300 drop-shadow-[0_0_12px_rgba(167,139,250,0.45)]" />
                                                            ) : null}
                                                        </span>
                                                    </button>
                                                )
                                            })}
                                                    </div>
                                                </div>

                                    <div className="space-y-2">
                                        <Label className="text-xs text-zinc-400">When</Label>
                                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                            {(
                                                [
                                                    {
                                                        v: "immediate" as const,
                                                        label: "Start now",
                                                        desc: "As soon as you confirm",
                                                        Icon: Zap,
                                                        color: "text-amber-300",
                                                    },
                                                    {
                                                        v: "scheduled" as const,
                                                        label: "Schedule for later",
                                                        desc: "Pick a date & time",
                                                        Icon: CalendarClock,
                                                        color: "text-sky-300",
                                                    },
                                                    {
                                                        v: "recurring" as const,
                                                        label: "Recurring",
                                                        desc: "Repeat on a cadence",
                                                        Icon: Repeat,
                                                        color: "text-fuchsia-300",
                                                    },
                                                ] as const
                                            ).map(({ v, label, desc, Icon, color }) => {
                                                const selected = scheduleType === v
                                                return (
                                                    <button
                                                        key={v}
                                                        type="button"
                                                        aria-pressed={selected}
                                                        onClick={() => {
                                                            setScheduleType(v)
                                                            setData("schedule_type", v)
                                                        }}
                                                        className={cn(
                                                            "flex w-full items-start gap-3 rounded-xl border p-3 text-left text-sm transition-all duration-200",
                                                            selected
                                                                ? "border-violet-400/80 bg-gradient-to-br from-violet-600/35 to-fuchsia-600/25 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] ring-2 ring-violet-500/40"
                                                                : "border-white/10 bg-white/[0.03] hover:border-violet-400/35 hover:bg-white/[0.05]"
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border transition-colors",
                                                                selected
                                                                    ? "border-violet-400/40 bg-violet-500/20"
                                                                    : "border-white/10 bg-black/20"
                                                            )}
                                                        >
                                                            <Icon
                                                                className={cn(
                                                                    "h-5 w-5",
                                                                    selected ? color : cn(color, "opacity-90")
                                                                )}
                                                                aria-hidden
                                                            />
                                                        </span>
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block font-semibold leading-tight text-white">
                                                                {label}
                                                            </span>
                                                            <span className="mt-0.5 block text-[11px] leading-snug text-zinc-500">
                                                                {desc}
                                                            </span>
                                                        </span>
                                                        <span className="flex min-w-[1.25rem] shrink-0 justify-end" aria-hidden>
                                                            {selected ? (
                                                                <CheckCircle2 className="h-5 w-5 text-violet-300 drop-shadow-[0_0_12px_rgba(167,139,250,0.45)]" />
                                                            ) : null}
                                                        </span>
                                                    </button>
                                                )
                                            })}
                                                    </div>
                                                </div>

                                    {(scheduleType === "scheduled" || scheduleType === "recurring") && (
                                        <div>
                                            <Label htmlFor="send_date" className="text-zinc-400">
                                                Date &amp; time
                                            </Label>
                                            <Input
                                                id="send_date"
                                                type="datetime-local"
                                                value={data.send_date}
                                                onChange={(e) => setData("send_date", e.target.value)}
                                                className="mt-1 border-white/10 bg-white/5"
                                                        />
                                                    </div>
                                    )}

                                    <div className="rounded-xl border border-white/10 bg-black/30 p-3 text-xs text-zinc-300">
                                        <p>
                                            <span className="text-zinc-500">Type:</span>{" "}
                                            <span className="text-zinc-100">{communicationKind}</span>
                                        </p>
                                        <p className="mt-1">
                                            <span className="text-zinc-500">Recipients:</span>{" "}
                                            <span className="text-zinc-100">
                                                ~{selectedAudienceCount.toLocaleString()} contacts
                                            </span>
                                        </p>
                                        <p className="mt-1">
                                            <span className="text-zinc-500">Go-live:</span>{" "}
                                            <span className="text-zinc-100">
                                                {scheduleType === "immediate"
                                                    ? "As soon as Engagement runs"
                                                    : data.send_date
                                                      ? new Date(data.send_date).toLocaleString()
                                                      : "Pick a date above"}
                                            </span>
                                                    </p>
                                                </div>

                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-stretch">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="border-white/15 bg-white/5 text-zinc-100 sm:w-auto"
                                            onClick={() => setWizardStep(2)}
                                        >
                                            <ArrowLeft className="mr-2 h-4 w-4" />
                                            Back
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                !data.subject?.trim() ||
                                                !hasBody ||
                                                ((scheduleType === "scheduled" || scheduleType === "recurring") &&
                                                    !data.send_date)
                                            }
                                            className="flex-1 bg-gradient-to-r from-violet-600 to-fuchsia-600 py-6 text-base font-semibold text-white shadow-lg sm:py-6"
                                        >
                                            <Plane className="mr-2 h-5 w-5" />
                                            {processing ? "Saving…" : "Start Engagement"}
                                        </Button>
                                            </div>
                                </div>
                                </motion.div>
                            )}
                            </div>

                            <aside className="min-w-0 lg:sticky lg:top-6 lg:self-start">
                                <Card className="gap-0 overflow-hidden rounded-xl border border-white/10 bg-zinc-950/50 py-0 text-zinc-100 shadow-lg dark:border-white/10 dark:bg-zinc-950/50">
                                    <CardHeader className="space-y-0 px-3 py-2.5 pb-2 sm:px-4">
                                        <CardTitle className="flex items-center gap-1.5 text-sm font-semibold text-white">
                                            <Code className="h-3.5 w-3.5 shrink-0 text-violet-400" />
                                            Merge variables
                                        </CardTitle>
                                        <CardDescription className="space-y-1 text-[11px] leading-snug text-zinc-500">
                                            <span className="block">Use in subject or body.</span>
                                            <span className="block text-zinc-600">
                                                Same tokens as in saved templates and the template editor.
                                            </span>
                                        </CardDescription>
                                            </CardHeader>
                                    <CardContent className="max-h-[min(520px,75vh)] overflow-y-auto px-3 pb-2 pt-0 text-[11px] sm:px-4">
                                        <div className="divide-y divide-white/10">
                                            {NEWSLETTER_MERGE_VARIABLES.map(({ key, label }) => {
                                                const token = `{${key}}`
                                                return (
                                                    <VariableItem
                                                        key={key}
                                                        variable={token}
                                                        description={label}
                                                        sampleValue={sampleData[key]}
                                                        onCopy={() => navigator.clipboard.writeText(token)}
                                                    />
                                                )
                                            })}
                                                    </div>
                                    </CardContent>
                                </Card>
                            </aside>
                                                </div>
                        </div>
                    </form>

                    {/* Footer strip — final step */}
                    {wizardStep === 3 && (
                    <div className="mt-10 border-t border-white/10 pt-8">
                        <div className="grid gap-4 sm:grid-cols-5">
                            {[
                                { Icon: Users, t: "User-first flow", d: "Matches natural planning." },
                                { Icon: ShieldCheck, t: "Fewer errors", d: "Audience before go-live." },
                                { Icon: Zap, t: "Clearer decisions", d: "One focus per column." },
                                { Icon: Sparkles, t: "Faster execution", d: "AI + templates together." },
                                { Icon: Mail, t: "One workspace", d: "No tab hopping." },
                            ].map(({ Icon, t, d }) => (
                                <div key={t} className="flex gap-2 text-xs">
                                    <Icon className="mt-0.5 h-4 w-4 shrink-0 text-violet-400" />
                                                    <div>
                                        <p className="font-semibold text-zinc-100">{t}</p>
                                        <p className="text-zinc-500">{d}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-4">
                            <p className="flex items-start gap-2 text-xs text-zinc-500">
                                <Lightbulb className="mt-0.5 h-4 w-4 shrink-0 text-amber-400" />
                                This flow stays simpler for non-technical teammates — fewer surprises at go-live.
                            </p>
                            <Button
                                type="button"
                                variant="secondary"
                                className="border border-violet-500/40 bg-violet-600/30 text-white hover:bg-violet-600/50"
                                onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}
                            >
                                Build smarter
                            </Button>
                        </div>
                                                    </div>
                                                )}

                    <Dialog
                        open={editManualContact != null}
                        onOpenChange={(open) => {
                            if (!open) setEditManualContact(null)
                        }}
                    >
                        <DialogContent className="border-white/10 bg-zinc-950 text-zinc-100 sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle>Edit manual recipient</DialogTitle>
                                <DialogDescription className="text-zinc-500">
                                    Changes apply to this Engagement audience list and Engagement → Recipients.
                                </DialogDescription>
                            </DialogHeader>
                            {editManualContact ? (
                                <div className="space-y-3 py-1">
                                    <div>
                                        <Label htmlFor="edit-manual-email" className="text-[11px] text-zinc-400">
                                            Email
                                        </Label>
                                        <Input
                                            id="edit-manual-email"
                                            type="email"
                                            value={editManualContact.email}
                                            onChange={(e) =>
                                                setEditManualContact((prev) =>
                                                    prev ? { ...prev, email: e.target.value } : prev
                                                )
                                            }
                                            className="mt-1 border-white/10 bg-black/40 text-sm"
                                        />
                            </div>
                                    <div>
                                        <Label htmlFor="edit-manual-name" className="text-[11px] text-zinc-400">
                                            Name (optional)
                                        </Label>
                                        <Input
                                            id="edit-manual-name"
                                            type="text"
                                            value={editManualContact.name}
                                            onChange={(e) =>
                                                setEditManualContact((prev) =>
                                                    prev ? { ...prev, name: e.target.value } : prev
                                                )
                                            }
                                            className="mt-1 border-white/10 bg-black/40 text-sm"
                                        />
                        </div>
                                    <DialogFooter className="gap-2 sm:justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="border-white/15"
                                            onClick={() => setEditManualContact(null)}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="button"
                                            disabled={editManualSaving}
                                            className="bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white"
                                            onClick={() => saveEditManualContact()}
                                        >
                                            {editManualSaving ? (
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                            ) : null}
                                            Save
                                        </Button>
                                    </DialogFooter>
                                </div>
                            ) : null}
                        </DialogContent>
                    </Dialog>

                    <AlertDialog
                        open={deleteManualId != null}
                        onOpenChange={(open) => {
                            if (!open) setDeleteManualId(null)
                        }}
                    >
                        <AlertDialogContent className="border-white/10 bg-zinc-950 text-zinc-100">
                            <AlertDialogHeader>
                                <AlertDialogTitle>Remove recipient?</AlertDialogTitle>
                                <AlertDialogDescription className="text-zinc-500">
                                    This removes the contact from manual recipients. You can add them again later if needed.
                                </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                                <AlertDialogCancel className="border-white/15 bg-transparent text-zinc-300">
                                    Cancel
                                </AlertDialogCancel>
                                <AlertDialogAction
                                    className="bg-red-600 text-white hover:bg-red-700"
                                    onClick={(e) => {
                                        e.preventDefault()
                                        confirmDeleteManualRecipient()
                                    }}
                                >
                                    Remove
                                </AlertDialogAction>
                            </AlertDialogFooter>
                        </AlertDialogContent>
                    </AlertDialog>

                    <Dialog open={showPreview} onOpenChange={setShowPreview}>
                        <DialogContent className="max-h-[min(85vh,720px)] w-[calc(100vw-2rem)] max-w-2xl overflow-y-auto">
                            <DialogHeader>
                                <DialogTitle>Preview</DialogTitle>
                                <DialogDescription>Variables replaced with sample org data.</DialogDescription>
                            </DialogHeader>
                            <div className="rounded-lg border bg-white p-4 text-zinc-900">
                                <p className="text-xs text-zinc-500">Subject</p>
                                <p className="font-semibold">{previewSubject || data.subject}</p>
                                <div className="mt-4 max-h-96 overflow-y-auto text-sm">
                                    {previewHtmlContent ? (
                                        <div
                                            className="prose prose-sm max-w-none"
                                            dangerouslySetInnerHTML={{ __html: previewHtmlContent }}
                                        />
                                    ) : (
                                        <div className="whitespace-pre-wrap">{previewContent || data.content}</div>
                                    )}
                                </div>
                            </div>
                        </DialogContent>
                    </Dialog>

                    {newsletterCreateAiResult && !newsletterCreateAiResult.ok && (
                        <p className="mt-4 text-sm text-red-400">{newsletterCreateAiResult.message}</p>
                    )}
                    {typeof pageErrors?.brief === "string" && (
                        <p className="mt-4 text-sm text-red-400">{pageErrors.brief}</p>
                    )}
                </div>
            </div>
        </AppSidebarLayout>
    )
}

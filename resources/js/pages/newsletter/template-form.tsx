"use client"

import { Head, router, useForm, usePage } from "@inertiajs/react"
import { motion } from "framer-motion"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { TextArea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog"
import { Switch } from "@/components/frontend/ui/switch"
import { useState } from "react"
import type { SharedData } from "@/types"

// Variable Item Component
function VariableItem({ variable, description, sampleValue, onCopy }: {
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
        <div className="group flex items-start justify-between gap-2 p-1.5 hover:bg-white dark:hover:bg-gray-700 rounded transition-colors">
            <div className="flex-1 min-w-0">
                <button
                    type="button"
                    onClick={handleCopy}
                    className="text-left w-full"
                    title={`Click to copy ${variable}`}
                >
                    <code className="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-xs font-mono block mb-1 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                        {variable}
                    </code>
                    <div className="text-xs text-gray-600 dark:text-gray-400">
                        <span>{description}</span>
                        <span className="ml-2 text-gray-500 dark:text-gray-500">
                            → {sampleValue}
                        </span>
                    </div>
                </button>
            </div>
            <button
                type="button"
                onClick={handleCopy}
                className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors shrink-0"
                title="Copy variable"
            >
                {copied ? (
                    <Check className="h-3 w-3 text-green-600 dark:text-green-400" />
                ) : (
                    <Copy className="h-3 w-3" />
                )}
            </button>
        </div>
    )
}
import {
    ArrowLeft,
    Save,
    Eye,
    Code,
    Copy,
    Check,
    Sparkles,
    Loader2,
    Send,
    Coins,
    AlertCircle,
    IndentIncrease,
    MessageSquare,
    Mail,
    CheckCircle2,
} from "lucide-react"
import { cn } from "@/lib/utils"
import {
    NEWSLETTER_AI_BRIEF_EXAMPLES,
    NEWSLETTER_AI_TONES,
    type NewsletterAiTone,
} from "@/lib/newsletter-ai-presets"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import { formatHtmlPretty } from "@/lib/format-html"

/** Matches backend NewsletterController::NEWSLETTER_SMS_PLAIN_MAX_CHARS */
const SMS_PLAIN_MAX_CHARS = 160

interface Template {
    id: number
    name: string
    subject: string
    content: string
    html_content?: string
    template_type: string
    settings: {
        frequency?: string
        timing?: string
    }
    is_active: boolean
    created_at: string
    updated_at: string
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

type TemplateAiResult =
    | {
          ok: true
          output_mode: "plain" | "html" | "both"
          subject: string
          content: string
          html_content: string
          suggested_name: string
          tokens_used: number
          ai_tokens_used: number
          ai_tokens_included: number
      }
    | {
          ok: false
          message: string
          code?: string
      }

interface NewsletterTemplateFormProps {
    template?: Template
    previewData?: PreviewData
    openAiConfigured?: boolean
    templateAiResult?: TemplateAiResult | null
}

function firstFieldError(err: unknown): string | undefined {
    if (typeof err === "string") return err
    if (Array.isArray(err) && err.length > 0) return String(err[0])
    return undefined
}

/** Segmented control track + tab states (violet → fuchsia), aligned with newsletter create page. */
const gradientTabTrack =
    "rounded-lg border border-violet-200/80 bg-gradient-to-r from-violet-100/50 via-fuchsia-100/40 to-indigo-100/45 p-1 shadow-sm dark:border-violet-800/55 dark:from-violet-950/55 dark:via-fuchsia-950/45 dark:to-indigo-950/45"
const gradientTabActive =
    "bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white shadow-md dark:from-violet-500 dark:to-fuchsia-600"
const gradientTabInactive =
    "text-violet-900/75 hover:bg-white/80 dark:text-violet-100/75 dark:hover:bg-violet-950/70"

export default function NewsletterTemplateForm({
    template,
    previewData,
    openAiConfigured = false,
    templateAiResult: templateAiResultProp = null,
}: NewsletterTemplateFormProps) {
    const [viewMode, setViewMode] = useState<'edit' | 'preview'>('edit')
    const isEditing = !!template

    const page = usePage<
        SharedData & {
            templateAiResult?: TemplateAiResult | null
            errors?: Record<string, string | string[]>
        }
    >()
    const templateAiResult = page.props.templateAiResult ?? templateAiResultProp
    const pageErrors = page.props.errors
    const authUser = page.props.auth.user
    const credits = Number(authUser.credits ?? 0)
    const aiTokensUsedAuth = Number(authUser.ai_tokens_used ?? 0)
    const aiTokensIncludedAuth = Number(authUser.ai_tokens_included ?? 0)

    const displayTokensUsed =
        templateAiResult && templateAiResult.ok ? templateAiResult.ai_tokens_used : aiTokensUsedAuth
    const displayTokensIncluded =
        templateAiResult && templateAiResult.ok ? templateAiResult.ai_tokens_included : aiTokensIncludedAuth

    const hasAiTokensLeft = displayTokensIncluded === 0 || displayTokensUsed < displayTokensIncluded
    const aiTokensRemaining =
        displayTokensIncluded === 0 ? null : Math.max(0, displayTokensIncluded - displayTokensUsed)
    const aiTokensOverBy =
        displayTokensIncluded === 0 ? 0 : Math.max(0, displayTokensUsed - displayTokensIncluded)

    const [aiModalOpen, setAiModalOpen] = useState(false)
    const [sendVia, setSendVia] = useState<"email" | "sms" | "both">("email")
    const [aiOutputMode, setAiOutputMode] = useState<"plain" | "html">("plain")
    const [aiBrief, setAiBrief] = useState('')
    const [aiTone, setAiTone] = useState<NewsletterAiTone>("professional")
    const [aiLoading, setAiLoading] = useState(false)
    const [formattingHtml, setFormattingHtml] = useState(false)
    const [prettifyError, setPrettifyError] = useState<string | null>(null)
    const [creditCheckoutLoading, setCreditCheckoutLoading] = useState(false)

    const handleCreditTopUp = () => {
        setCreditCheckoutLoading(true)
        router.post(
            route("credits.checkout"),
            { amount: 1.0, return_route: "newsletter.templates" },
            {
                preserveScroll: true,
                onError: () => setCreditCheckoutLoading(false),
                onFinish: () => setCreditCheckoutLoading(false),
            }
        )
    }

    const inferInitialBodyPreviewMode = (): "plain" | "html" => {
        const plain = (template?.content ?? "").trim()
        const html = (template?.html_content ?? "").trim()
        if (html && !plain) return "html"
        return "plain"
    }

    /** Which body to show in Preview (Edit still shows both fields). */
    const [bodyPreviewMode, setBodyPreviewMode] = useState<"plain" | "html">(inferInitialBodyPreviewMode)

    const { data, setData, post, put, processing, errors } = useForm({
        name: template?.name || '',
        subject: template?.subject || '',
        content: template?.content || '',
        html_content: template?.html_content || '',
        template_type: template?.template_type || 'newsletter',
        settings: template?.settings || {
            frequency: 'weekly',
            timing: 'morning'
        }
    })

    // Use real data from backend, fallback to demo data if not available
    const sampleData: PreviewData = previewData || {
        organization_name: 'Your Organization',
        organization_email: 'support@believeinunity.org',
        organization_phone: '+1 (555) 000-0000',
        organization_address: 'Your Organization Address',
        recipient_name: 'Recipient Name',
        recipient_email: 'recipient@example.com',
        current_date: new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
        current_year: new Date().getFullYear().toString(),
        unsubscribe_link: 'https://example.com/unsubscribe?token=preview_token',
        public_view_link: 'https://example.com/newsletter/public/preview',
    }

    // Function to replace variables with sample data
    const replaceVariables = (text: string): string => {
        if (!text) return ''

        let result = text
        Object.entries(sampleData).forEach(([key, value]) => {
            const regex = new RegExp(`\\{${key}\\}`, 'g')
            result = result.replace(regex, value)
        })

        return result
    }

    // Get preview of subject and content with variables replaced
    const previewSubject = replaceVariables(data.subject)
    const previewContent = replaceVariables(data.content)
    const previewHtmlContent = replaceVariables(data.html_content || '')

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        if (isEditing && template) {
            put(route('newsletter.templates.update', template.id))
        } else {
            post(route('newsletter.templates.store'))
        }
    }

    const submitAiBrief = () => {
        if (!aiBrief.trim() || aiLoading || !openAiConfigured || !hasAiTokensLeft) return
        setAiLoading(true)
        router.post(
            route("newsletter.templates.ai-generate"),
            {
                brief: aiBrief.trim(),
                template_type: data.template_type,
                tone: aiTone,
                output_mode: aiOutputMode,
                send_via: sendVia,
                ...(isEditing && template ? { template_id: template.id } : {}),
            },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => setAiLoading(false),
            },
        )
    }

    const handleAiKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault()
            submitAiBrief()
        }
    }

    const applyAiDraft = async () => {
        if (!templateAiResult || !templateAiResult.ok) return
        setData("subject", templateAiResult.subject)
        if (templateAiResult.suggested_name) {
            setData("name", templateAiResult.suggested_name)
        }
        if (templateAiResult.output_mode === "plain") {
            setData("content", templateAiResult.content)
            setData("html_content", "")
            setBodyPreviewMode("plain")
        } else {
            setFormattingHtml(true)
            setPrettifyError(null)
            const { formatted, error } = await formatHtmlPretty(templateAiResult.html_content)
            setFormattingHtml(false)
            setData("html_content", formatted)
            if (error) {
                setPrettifyError(error)
            }
            setData("content", templateAiResult.content)
            setBodyPreviewMode("html")
        }
        setViewMode("edit")
        setAiModalOpen(false)
    }

    const handlePrettifyHtml = async () => {
        if (!data.html_content.trim() || formattingHtml) return
        setFormattingHtml(true)
        setPrettifyError(null)
        const { formatted, error } = await formatHtmlPretty(data.html_content)
        setFormattingHtml(false)
        setData("html_content", formatted)
        if (error) {
            setPrettifyError(error)
        }
    }

    const briefValidationMessage = firstFieldError(pageErrors?.brief)

    const templateTypes = [
        { value: 'newsletter', label: 'Email' },
        { value: 'announcement', label: 'Announcement' },
        { value: 'event', label: 'Event' }
    ]

    return (
        <AppSidebarLayout>
            <Head
                title={
                    isEditing
                        ? `Engagement · Edit template · ${template.name}`
                        : "Engagement · Create template"
                }
            />

            <div className="space-y-6 sm:space-y-8 animate-in fade-in duration-500 m-10">
                {/* Header */}
                <div className="flex flex-col space-y-4 sm:space-y-0 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-2 animate-in slide-in-from-left duration-700">
                        <div className="flex items-center gap-4">
                            <Button variant="outline" size="sm" onClick={() => window.history.back()}>
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back
                            </Button>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">
                                    Engagement
                                </p>
                                <h1 className="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 dark:text-white">
                                    {isEditing ? "Edit template" : "Create template"}
                                </h1>
                                <p className="text-sm sm:text-base lg:text-lg text-gray-600 dark:text-gray-400">
                                    {isEditing ? 'Update your email template' : 'Create a reusable email template'}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                    <form onSubmit={handleSubmit}>
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            {/* Main Content */}
                            <div className="lg:col-span-2 space-y-6">
                                {/* Template Details */}
                                <motion.div
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ duration: 0.5, delay: 0.1 }}
                                >
                                    <Card className="shadow-lg">
                                        <CardHeader>
                                            <CardTitle>Template Details</CardTitle>
                                            <CardDescription>
                                                Basic information about your template
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <Label htmlFor="name">Template Name</Label>
                                                    <Input
                                                        id="name"
                                                        value={data.name}
                                                        onChange={(e) => setData('name', e.target.value)}
                                                        placeholder="e.g., Weekly update"
                                                        className="mt-1"
                                                    />
                                                    {errors.name && (
                                                        <p className="text-sm text-red-600 mt-1">{errors.name}</p>
                                                    )}
                                                </div>

                                                <div>
                                                    <Label htmlFor="template_type">Template Type</Label>
                                                    <Select value={data.template_type} onValueChange={(value) => setData('template_type', value)}>
                                                        <SelectTrigger className="mt-1">
                                                            <SelectValue placeholder="Select template type" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {templateTypes.map((type) => (
                                                                <SelectItem key={type.value} value={type.value}>
                                                                    {type.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {errors.template_type && (
                                                        <p className="text-sm text-red-600 mt-1">{errors.template_type}</p>
                                                    )}
                                                </div>
                                            </div>

                                            <div>
                                                <Label htmlFor="subject">Default Subject Line</Label>
                                                <Input
                                                    id="subject"
                                                    value={data.subject}
                                                    onChange={(e) => setData('subject', e.target.value)}
                                                    placeholder="e.g., Weekly Update from {organization_name}"
                                                    className="mt-1"
                                                />
                                                {errors.subject && (
                                                    <p className="text-sm text-red-600 mt-1">{errors.subject}</p>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                </motion.div>

                                {/* Content Editor */}
                                <motion.div
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ duration: 0.5, delay: 0.2 }}
                                >
                                    <Card className="shadow-lg">
                                        <CardHeader>
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div>
                                                    <CardTitle>Content</CardTitle>
                                                    <CardDescription>
                                                        {sendVia === "sms"
                                                            ? `SMS plain text only — max ${SMS_PLAIN_MAX_CHARS} characters (one standard segment).`
                                                            : "Create your email content"}
                                                    </CardDescription>
                                                </div>
                                                <div className="flex flex-col items-stretch gap-3 sm:items-end">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Button
                                                            type="button"
                                                            variant={viewMode === 'edit' ? 'default' : 'outline'}
                                                            size="sm"
                                                            onClick={() => setViewMode('edit')}
                                                        >
                                                            <Code className="h-4 w-4 mr-2" />
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant={viewMode === 'preview' ? 'default' : 'outline'}
                                                            size="sm"
                                                            onClick={() => setViewMode('preview')}
                                                        >
                                                            <Eye className="h-4 w-4 mr-2" />
                                                            Preview
                                                        </Button>
                                                    </div>
                                                    {viewMode === "preview" && (
                                                        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border/80 bg-muted/40 px-3 py-2">
                                                            <span
                                                                className={`text-xs font-medium sm:text-sm ${bodyPreviewMode === "plain" ? "text-foreground" : "text-muted-foreground"}`}
                                                            >
                                                                Plain text
                                                            </span>
                                                            <Switch
                                                                checked={bodyPreviewMode === "html"}
                                                                onCheckedChange={(checked) =>
                                                                    setBodyPreviewMode(checked ? "html" : "plain")
                                                                }
                                                                aria-label="Preview plain text or HTML body"
                                                            />
                                                            <span
                                                                className={`text-xs font-medium sm:text-sm ${bodyPreviewMode === "html" ? "text-foreground" : "text-muted-foreground"}`}
                                                            >
                                                                HTML
                                                            </span>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            {viewMode === 'edit' ? (
                                                <div className="space-y-4">
                                                    <div>
                                                        <Label htmlFor="content">Plain Text Content</Label>
                                                        <TextArea
                                                            id="content"
                                                            value={data.content}
                                                            onChange={(e) => setData('content', e.target.value)}
                                                            placeholder="Enter your email content in plain text..."
                                                            rows={10}
                                                            maxLength={
                                                                sendVia === "sms" ? SMS_PLAIN_MAX_CHARS : undefined
                                                            }
                                                            className="mt-1"
                                                        />
                                                        {sendVia === "sms" && (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {(data.content?.length ?? 0)} / {SMS_PLAIN_MAX_CHARS}{" "}
                                                                characters
                                                            </p>
                                                        )}
                                                        {errors.content && (
                                                            <p className="text-sm text-red-600 mt-1">{errors.content}</p>
                                                        )}
                                                    </div>

                                                    <div>
                                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                            <div>
                                                                <Label htmlFor="html_content">HTML source (optional)</Label>
                                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                                    Full HTML markup for this template. Scroll vertically and horizontally to see everything; nothing is hidden.
                                                                </p>
                                                            </div>
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                className="shrink-0 gap-1.5"
                                                                disabled={!data.html_content.trim() || formattingHtml}
                                                                onClick={handlePrettifyHtml}
                                                            >
                                                                {formattingHtml ? (
                                                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                                ) : (
                                                                    <IndentIncrease className="h-3.5 w-3.5" />
                                                                )}
                                                                Prettify HTML
                                                            </Button>
                                                        </div>
                                                        {prettifyError ? (
                                                            <p className="mt-2 text-xs text-red-600 dark:text-red-400" role="alert">
                                                                Could not prettify: {prettifyError}
                                                            </p>
                                                        ) : null}
                                                        <TextArea
                                                            id="html_content"
                                                            value={data.html_content}
                                                            onChange={(e) => {
                                                                setPrettifyError(null)
                                                                setData("html_content", e.target.value)
                                                            }}
                                                            placeholder="<!DOCTYPE html> … or a fragment: &lt;table&gt;…"
                                                            rows={18}
                                                            spellCheck={false}
                                                            className="mt-2 box-border min-h-[360px] max-h-[min(70vh,640px)] w-full resize-y overflow-auto whitespace-pre font-mono text-sm leading-normal [tab-size:2] rounded-md border bg-muted/30 dark:bg-muted/20"
                                                        />
                                                        {errors.html_content && (
                                                            <p className="text-sm text-red-600 mt-1">{errors.html_content}</p>
                                                        )}
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="space-y-4">
                                                    <div className="border rounded-lg p-4 bg-white dark:bg-gray-800">
                                                        <div className="mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                                                            <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Subject Preview:</p>
                                                            <h3 className="font-semibold text-lg">
                                                                {previewSubject || 'Template Preview'}
                                                            </h3>
                                                        </div>
                                                        {bodyPreviewMode === "plain" ? (
                                                            <>
                                                                <p className="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                    Plain text body
                                                                </p>
                                                                <div className="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">
                                                                    {previewContent || 'No plain text content yet — add some in Edit, or switch to HTML.'}
                                                                </div>
                                                                {previewHtmlContent ? (
                                                                    <p className="mt-3 text-xs text-muted-foreground">
                                                                        You also have HTML content. Toggle <strong>HTML</strong> above to preview it.
                                                                    </p>
                                                                ) : null}
                                                            </>
                                                        ) : (
                                                            <>
                                                                <p className="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                                    HTML body
                                                                </p>
                                                                {previewHtmlContent ? (
                                                                    <div className="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-700/80">
                                                                        <div
                                                                            className="text-sm prose prose-sm max-w-none dark:prose-invert"
                                                                            dangerouslySetInnerHTML={{ __html: previewHtmlContent }}
                                                                        />
                                                                    </div>
                                                                ) : (
                                                                    <p className="text-sm text-gray-600 dark:text-gray-400">
                                                                        No HTML content yet — add HTML in Edit, or switch to Plain text.
                                                                    </p>
                                                                )}
                                                                {previewContent.trim() ? (
                                                                    <p className="mt-3 text-xs text-muted-foreground">
                                                                        You also have plain text. Toggle <strong>Plain text</strong> above to preview it.
                                                                    </p>
                                                                ) : null}
                                                            </>
                                                        )}
                                                    </div>

                                                    {/* Variable Replacement Info */}
                                                    <div className="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                                                        <p className="text-xs text-blue-800 dark:text-blue-300 font-medium mb-1">
                                                            💡 Variables replaced with real data
                                                        </p>
                                                        <p className="text-xs text-blue-700 dark:text-blue-400">
                                                            Variables like {'{organization_name}'}, {'{recipient_name}'} are shown with your actual organization and recipient data in the preview above.
                                                        </p>
                                                    </div>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            </div>

                            {/* Sidebar */}
                            <div className="space-y-6">
                                {/* Template Settings */}
                                <motion.div
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ duration: 0.5, delay: 0.3 }}
                                >
                                    <Card className="shadow-lg">
                                        <CardHeader>
                                            <CardTitle>Template Settings</CardTitle>
                                            <CardDescription>
                                                Default frequency and how this template is intended to be sent (used for AI generation).
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <div className="space-y-2">
                                                <Label>Channel</Label>
                                                <div className={`flex gap-1 ${gradientTabTrack}`}>
                                                    {(
                                                        [
                                                            { id: "sms" as const, label: "SMS", icon: MessageSquare },
                                                            { id: "email" as const, label: "Email", icon: Mail },
                                                            { id: "both" as const, label: "Both", icon: CheckCircle2 },
                                                        ] as const
                                                    ).map(({ id, label, icon: Icon }) => (
                                                        <button
                                                            key={id}
                                                            type="button"
                                                            onClick={() => {
                                                                setSendVia(id)
                                                                if (id === "sms") {
                                                                    setData("html_content", "")
                                                                }
                                                            }}
                                                            className={`flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-2.5 text-xs font-medium transition-all sm:text-sm ${
                                                                sendVia === id ? gradientTabActive : gradientTabInactive
                                                            }`}
                                                        >
                                                            <Icon className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                                                            {label}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>

                                            <div>
                                                <Label htmlFor="frequency">Default Frequency</Label>
                                                <Select
                                                    value={data.settings.frequency}
                                                    onValueChange={(value) => setData('settings', { ...data.settings, frequency: value })}
                                                >
                                                    <SelectTrigger className="mt-1">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="daily">Daily</SelectItem>
                                                        <SelectItem value="weekly">Weekly</SelectItem>
                                                        <SelectItem value="bi-weekly">Bi-weekly</SelectItem>
                                                        <SelectItem value="monthly">Monthly</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>

                                            <div>
                                                <Label htmlFor="timing">Default Timing</Label>
                                                <Select
                                                    value={data.settings.timing}
                                                    onValueChange={(value) => setData('settings', { ...data.settings, timing: value })}
                                                >
                                                    <SelectTrigger className="mt-1">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="morning">Morning (9 AM)</SelectItem>
                                                        <SelectItem value="afternoon">Afternoon (2 PM)</SelectItem>
                                                        <SelectItem value="evening">Evening (6 PM)</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </motion.div>

                                {/* Actions */}
                                <motion.div
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ duration: 0.5, delay: 0.4 }}
                                >
                                    <Card className="shadow-lg">
                                        <CardHeader>
                                            <CardTitle>Actions</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="w-full border-violet-300/80 bg-gradient-to-r from-violet-50/90 to-fuchsia-50/50 text-violet-900 shadow-sm hover:from-violet-100/90 hover:to-fuchsia-100/50 dark:border-violet-800 dark:from-violet-950/50 dark:to-fuchsia-950/30 dark:text-violet-100 dark:hover:from-violet-900/60"
                                                onClick={() => setAiModalOpen(true)}
                                            >
                                                <Sparkles className="h-4 w-4 mr-2 text-violet-600 dark:text-violet-400" />
                                                AI Generate
                                            </Button>
                                            <Button
                                                type="submit"
                                                className="w-full"
                                                disabled={processing}
                                            >
                                                <Save className="h-4 w-4 mr-2" />
                                                {processing
                                                    ? (isEditing ? 'Updating...' : 'Creating...')
                                                    : (isEditing ? 'Update Template' : 'Create Template')
                                                }
                                            </Button>
                                        </CardContent>
                                    </Card>
                                </motion.div>

                                {/* Template Variables */}
                                <motion.div
                                    initial={{ opacity: 0, y: 20 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ duration: 0.5, delay: 0.5 }}
                                >
                                    <Card className="shadow-lg">
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <Code className="h-4 w-4" />
                                                Available Variables
                                            </CardTitle>
                                            <CardDescription>
                                                Click to copy, use in subject or content
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="space-y-3">
                                                <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">
                                                    <p className="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Organization</p>
                                                    <div className="space-y-1.5">
                                                        <VariableItem
                                                            variable="{organization_name}"
                                                            description="Organization name"
                                                            sampleValue={sampleData.organization_name}
                                                            onCopy={() => navigator.clipboard.writeText('{organization_name}')}
                                                        />
                                                        <VariableItem
                                                            variable="{organization_email}"
                                                            description="Organization email"
                                                            sampleValue={sampleData.organization_email}
                                                            onCopy={() => navigator.clipboard.writeText('{organization_email}')}
                                                        />
                                                        <VariableItem
                                                            variable="{organization_phone}"
                                                            description="Organization phone"
                                                            sampleValue={sampleData.organization_phone}
                                                            onCopy={() => navigator.clipboard.writeText('{organization_phone}')}
                                                        />
                                                        <VariableItem
                                                            variable="{organization_address}"
                                                            description="Organization address"
                                                            sampleValue={sampleData.organization_address}
                                                            onCopy={() => navigator.clipboard.writeText('{organization_address}')}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">
                                                    <p className="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Recipient</p>
                                                    <div className="space-y-1.5">
                                                        <VariableItem
                                                            variable="{recipient_name}"
                                                            description="Recipient name"
                                                            sampleValue={sampleData.recipient_name}
                                                            onCopy={() => navigator.clipboard.writeText('{recipient_name}')}
                                                        />
                                                        <VariableItem
                                                            variable="{recipient_email}"
                                                            description="Recipient email"
                                                            sampleValue={sampleData.recipient_email}
                                                            onCopy={() => navigator.clipboard.writeText('{recipient_email}')}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="p-2 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">
                                                    <p className="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">System</p>
                                                    <div className="space-y-1.5">
                                                        <VariableItem
                                                            variable="{current_date}"
                                                            description="Current date"
                                                            sampleValue={sampleData.current_date}
                                                            onCopy={() => navigator.clipboard.writeText('{current_date}')}
                                                        />
                                                        <VariableItem
                                                            variable="{current_year}"
                                                            description="Current year"
                                                            sampleValue={sampleData.current_year}
                                                            onCopy={() => navigator.clipboard.writeText('{current_year}')}
                                                        />
                                                        <VariableItem
                                                            variable="{unsubscribe_link}"
                                                            description="Unsubscribe link"
                                                            sampleValue={sampleData.unsubscribe_link}
                                                            onCopy={() => navigator.clipboard.writeText('{unsubscribe_link}')}
                                                        />
                                                        <VariableItem
                                                            variable="{public_view_link}"
                                                            description="Public view link"
                                                            sampleValue={sampleData.public_view_link}
                                                            onCopy={() => navigator.clipboard.writeText('{public_view_link}')}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="mt-3 p-2 bg-blue-50 dark:bg-blue-900/20 rounded border border-blue-200 dark:border-blue-800">
                                                    <p className="text-xs text-blue-800 dark:text-blue-300">
                                                        💡 <strong>Tip:</strong> Variables are automatically replaced in the Preview tab with your actual organization and recipient data
                                                    </p>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            </div>
                        </div>
                    </form>

                    <Dialog open={aiModalOpen} onOpenChange={setAiModalOpen}>
                            <DialogContent className="max-h-[min(92vh,800px)] gap-0 overflow-y-auto overflow-x-hidden border-violet-200/70 p-0 shadow-2xl shadow-violet-500/15 sm:max-w-2xl dark:border-violet-900/50">
                                <div className="pointer-events-none absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-violet-500 via-fuchsia-500 to-indigo-500" />
                                <motion.div
                                    key={aiModalOpen ? "open" : "closed"}
                                    initial={{ opacity: 0, y: 18, scale: 0.97 }}
                                    animate={{ opacity: 1, y: 0, scale: 1 }}
                                    transition={{ type: "spring", damping: 26, stiffness: 320, mass: 0.7 }}
                                    className="px-6 pb-6 pt-8"
                                >
                                    <DialogHeader className="space-y-2 text-left">
                                        <DialogTitle className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                                            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500/20 to-fuchsia-500/15 ring-1 ring-violet-500/20 dark:from-violet-950/80 dark:to-fuchsia-950/50">
                                                <Sparkles className="h-5 w-5 text-violet-600 dark:text-violet-400" />
                                            </span>
                                            AI template generator
                                        </DialogTitle>
                                        <DialogDescription className="text-left text-sm leading-relaxed text-muted-foreground">
                                            Pick a <strong className="text-foreground">tone</strong> — each one changes both copy and HTML
                                            styling (layout, colors, CTA). Uses your <strong className="text-foreground">Channel</strong>{" "}
                                            choice: SMS = short plain text; Email = rich HTML + plain twin when HTML is on; Both = SMS +
                                            email. Merge fields must match <strong className="text-foreground">Available Variables</strong>.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <motion.div
                                        initial={{ opacity: 0 }}
                                        animate={{ opacity: 1 }}
                                        transition={{ delay: 0.08, duration: 0.25 }}
                                        className="mt-4 flex flex-col gap-2 rounded-lg border border-border/80 bg-muted/40 px-3 py-2.5 text-xs"
                                    >
                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                            <span className="inline-flex items-center gap-1.5 font-medium text-foreground">
                                                <Coins className="h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                                                Wallet credits: {credits.toLocaleString()}
                                            </span>
                                            <span className="inline-flex flex-wrap items-center gap-x-1.5 text-muted-foreground">
                                                <Sparkles className="h-3.5 w-3.5 shrink-0 text-violet-600 dark:text-violet-400" />
                                                {displayTokensIncluded === 0 ? (
                                                    <>
                                                        AI tokens used: {displayTokensUsed.toLocaleString()}
                                                        <span className="text-muted-foreground/80"> (no plan cap)</span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <span className="font-medium text-foreground">Plan AI tokens:</span>
                                                        {displayTokensUsed.toLocaleString()} used of{" "}
                                                        {displayTokensIncluded.toLocaleString()} included
                                                        {aiTokensRemaining !== null && (
                                                            <>
                                                                {" "}
                                                                ·{" "}
                                                                <span
                                                                    className={
                                                                        aiTokensRemaining === 0
                                                                            ? "font-semibold text-destructive"
                                                                            : "font-medium text-foreground"
                                                                    }
                                                                >
                                                                    {aiTokensRemaining.toLocaleString()} remaining
                                                                </span>
                                                                {aiTokensOverBy > 0 && (
                                                                    <span className="text-destructive">
                                                                        {" "}
                                                                        (over allowance by{" "}
                                                                        {aiTokensOverBy.toLocaleString()})
                                                                    </span>
                                                                )}
                                                            </>
                                                        )}
                                                    </>
                                                )}
                                            </span>
                                        </div>
                                        <p className="text-[10px] leading-snug text-muted-foreground/90">
                                            Top up adds wallet credits and, if your plan has a token cap, increases included AI tokens by
                                            the same amount.
                                        </p>
                                    </motion.div>

                                    <div className="mt-5 space-y-4">
                                        {!openAiConfigured && (
                                            <p className="rounded-lg border border-dashed border-muted-foreground/30 bg-muted/30 px-3 py-3 text-sm text-muted-foreground">
                                                AI is disabled until{" "}
                                                <code className="rounded bg-background px-1.5 py-0.5 text-xs">OPENAI_API_KEY</code> is set
                                                on the server.
                                            </p>
                                        )}
                                        {openAiConfigured && !hasAiTokensLeft && displayTokensIncluded > 0 && (
                                            <div className="flex flex-col gap-3 rounded-lg border border-destructive/35 bg-destructive/5 p-3 text-sm text-destructive sm:flex-row sm:items-center sm:justify-between">
                                                <div className="flex min-w-0 items-start gap-2">
                                                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                                    <span>
                                                        AI token limit reached (same as AI Chat). Top up to add credits and extend your
                                                        included AI allowance, or wait for renewal.
                                                    </span>
                                                </div>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={creditCheckoutLoading || aiLoading}
                                                    onClick={handleCreditTopUp}
                                                    className="shrink-0 inline-flex items-center gap-2 bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                                                >
                                                    {creditCheckoutLoading ? (
                                                        <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                                                    ) : (
                                                        <>
                                                            <Coins className="h-4 w-4" aria-hidden />
                                                            Top up
                                                        </>
                                                    )}
                                                </Button>
                                            </div>
                                        )}
                                        {openAiConfigured && hasAiTokensLeft && (
                                            <>
                                                {sendVia === "email" ? (
                                                    <div className="flex flex-col gap-3 rounded-lg border border-border/80 bg-background/80 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                                        <div className="space-y-1">
                                                            <Label className="text-sm font-medium">Output format</Label>
                                                            <p className="text-xs text-muted-foreground">
                                                                {aiOutputMode === "plain"
                                                                    ? "Plain text only — HTML body will stay empty."
                                                                    : "HTML email plus a plain-text version (both stored)."}
                                                            </p>
                                                        </div>
                                                        <div className="flex items-center gap-3">
                                                            <span
                                                                className={`text-sm font-medium transition-colors ${aiOutputMode === "plain" ? "text-violet-700 dark:text-violet-300" : "text-muted-foreground"}`}
                                                            >
                                                                Plain text
                                                            </span>
                                                            <Switch
                                                                checked={aiOutputMode === "html"}
                                                                onCheckedChange={(checked) =>
                                                                    setAiOutputMode(checked ? "html" : "plain")
                                                                }
                                                                disabled={aiLoading}
                                                                aria-label="Toggle HTML output"
                                                            />
                                                            <span
                                                                className={`text-sm font-medium transition-colors ${aiOutputMode === "html" ? "text-violet-700 dark:text-violet-300" : "text-muted-foreground"}`}
                                                            >
                                                                HTML
                                                            </span>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <p className="rounded-lg border border-border/80 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                                                        {sendVia === "sms"
                                                            ? "Generating plain text only (SMS)."
                                                            : "Generating SMS-friendly plain text and full HTML for email."}
                                                    </p>
                                                )}
                                                <div className="space-y-3">
                                                    <div className="space-y-2">
                                                        <Label className="text-sm font-medium">Tone & visual style</Label>
                                                        <p className="text-xs text-muted-foreground leading-relaxed">
                                                            Each tone uses a different voice and HTML look (colors, header style, CTA). Choose
                                                            before you write the brief.
                                                        </p>
                                                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                                            {NEWSLETTER_AI_TONES.map(({ id, label, hint, Icon }) => {
                                                                const active = aiTone === id
                                                                return (
                                                                    <button
                                                                        key={id}
                                                                        type="button"
                                                                        disabled={aiLoading}
                                                                        onClick={() => setAiTone(id)}
                                                                        className={cn(
                                                                            "flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-all",
                                                                            active
                                                                                ? "border-violet-500 bg-gradient-to-br from-violet-500/10 to-fuchsia-500/5 shadow-sm ring-1 ring-violet-500/30 dark:border-violet-400/50 dark:from-violet-950/50 dark:to-fuchsia-950/20"
                                                                                : "border-border/80 bg-card/50 hover:border-violet-300/60 hover:bg-muted/40 dark:hover:border-violet-800/50"
                                                                        )}
                                                                    >
                                                                        <span
                                                                            className={cn(
                                                                                "mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
                                                                                active
                                                                                    ? "bg-violet-600 text-white dark:bg-violet-500"
                                                                                    : "bg-muted text-muted-foreground"
                                                                            )}
                                                                        >
                                                                            <Icon className="h-4 w-4" aria-hidden />
                                                                        </span>
                                                                        <span className="min-w-0">
                                                                            <span className="block text-sm font-semibold text-foreground">
                                                                                {label}
                                                                            </span>
                                                                            <span className="mt-0.5 block text-[11px] leading-snug text-muted-foreground">
                                                                                {hint}
                                                                            </span>
                                                                        </span>
                                                                    </button>
                                                                )
                                                            })}
                                                        </div>
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label htmlFor="ai_brief_modal" className="text-sm font-medium">
                                                            What should this email say?
                                                        </Label>
                                                        <TextArea
                                                            id="ai_brief_modal"
                                                            value={aiBrief}
                                                            onChange={(e) => setAiBrief(e.target.value)}
                                                            onKeyDown={handleAiKeyDown}
                                                            placeholder="Describe audience, goal, key facts, and the action you want (donate, RSVP, volunteer)…"
                                                            rows={4}
                                                            disabled={aiLoading}
                                                            className="min-h-[108px] resize-y border-border/80 bg-background/80 text-sm"
                                                        />
                                                        <p className="text-xs text-muted-foreground">
                                                            Enter to generate · Shift+Enter for a new line
                                                        </p>
                                                    </div>
                                                    <div className="rounded-xl border border-border/70 bg-muted/25 p-3">
                                                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                            Example prompts
                                                        </p>
                                                        <p className="mt-1 text-[11px] text-muted-foreground leading-relaxed">
                                                            Tap one to fill the box and matching tone — edit freely before generating.
                                                        </p>
                                                        <div className="mt-2.5 flex max-h-[168px] flex-wrap gap-1.5 overflow-y-auto pr-0.5">
                                                            {NEWSLETTER_AI_BRIEF_EXAMPLES.map((ex) => (
                                                                <Button
                                                                    key={ex.label}
                                                                    type="button"
                                                                    variant="secondary"
                                                                    size="sm"
                                                                    className="h-auto min-h-8 max-w-full whitespace-normal rounded-lg px-2.5 py-1.5 text-left text-[11px] font-normal leading-snug"
                                                                    disabled={aiLoading}
                                                                    onClick={() => {
                                                                        setAiBrief(ex.text)
                                                                        setAiTone(ex.tone)
                                                                    }}
                                                                >
                                                                    <span className="font-medium text-foreground">{ex.label}</span>
                                                                    <span className="mt-0.5 block text-[10px] text-muted-foreground">
                                                                        {NEWSLETTER_AI_TONES.find((t) => t.id === ex.tone)?.label} tone
                                                                    </span>
                                                                </Button>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                                {briefValidationMessage && (
                                                    <p className="text-sm text-red-600 dark:text-red-400" role="alert">
                                                        {briefValidationMessage}
                                                    </p>
                                                )}
                                                {templateAiResult && !templateAiResult.ok && (
                                                    <motion.p
                                                        initial={{ opacity: 0, x: -6 }}
                                                        animate={{ opacity: 1, x: 0 }}
                                                        className="text-sm text-red-600 dark:text-red-400"
                                                        role="alert"
                                                    >
                                                        {templateAiResult.message}
                                                    </motion.p>
                                                )}
                                                {templateAiResult?.ok && (
                                                    <motion.div
                                                        initial={{ opacity: 0, y: 10 }}
                                                        animate={{ opacity: 1, y: 0 }}
                                                        transition={{ type: "spring", damping: 22, stiffness: 300 }}
                                                        className="space-y-3 rounded-xl border border-violet-200/90 bg-gradient-to-br from-violet-50/90 to-fuchsia-50/40 p-4 dark:border-violet-900/60 dark:from-violet-950/40 dark:to-fuchsia-950/20"
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <p className="text-sm font-medium text-foreground">Draft ready</p>
                                                            <span className="rounded-full bg-violet-600/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-200">
                                                                {templateAiResult.output_mode === "plain"
                                                                    ? "Plain text only"
                                                                    : templateAiResult.output_mode === "both"
                                                                      ? "SMS + HTML"
                                                                      : "HTML + plain"}
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">
                                                            Template name:{" "}
                                                            <span className="font-medium text-foreground">
                                                                {templateAiResult.suggested_name}
                                                            </span>
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            Subject:{" "}
                                                            <span className="font-medium text-violet-800 dark:text-violet-200">
                                                                {templateAiResult.subject}
                                                            </span>
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            This run used{" "}
                                                            <strong className="text-foreground">
                                                                {templateAiResult.tokens_used.toLocaleString()}
                                                            </strong>{" "}
                                                            tokens · Total {displayTokensUsed.toLocaleString()}
                                                            {displayTokensIncluded > 0
                                                                ? ` / ${displayTokensIncluded.toLocaleString()}`
                                                                : ""}
                                                        </p>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            className="w-full sm:w-auto bg-violet-600 hover:bg-violet-700"
                                                            disabled={formattingHtml}
                                                            onClick={() => void applyAiDraft()}
                                                        >
                                                            {formattingHtml ? (
                                                                <>
                                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                                    Formatting HTML…
                                                                </>
                                                            ) : (
                                                                "Apply to template fields"
                                                            )}
                                                        </Button>
                                                    </motion.div>
                                                )}
                                            </>
                                        )}
                                    </div>

                                    <DialogFooter className="mt-6 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <Button type="button" variant="outline" onClick={() => setAiModalOpen(false)}>
                                            Close
                                        </Button>
                                        {openAiConfigured && hasAiTokensLeft && (
                                            <Button
                                                type="button"
                                                className="bg-violet-600 hover:bg-violet-700 dark:bg-violet-600 dark:hover:bg-violet-500"
                                                disabled={aiLoading || !aiBrief.trim()}
                                                onClick={submitAiBrief}
                                            >
                                                {aiLoading ? (
                                                    <>
                                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                        Generating…
                                                    </>
                                                ) : (
                                                    <>
                                                        <Send className="mr-2 h-4 w-4" />
                                                        {sendVia === "sms"
                                                            ? "Generate SMS plain draft"
                                                            : sendVia === "both"
                                                              ? "Generate SMS + HTML"
                                                              : aiOutputMode === "html"
                                                                ? "Generate HTML + plain draft"
                                                                : "Generate plain text draft"}
                                                    </>
                                                )}
                                            </Button>
                                        )}
                                    </DialogFooter>
                                </motion.div>
                            </DialogContent>
                        </Dialog>
            </div>
        </AppSidebarLayout>
    )
}

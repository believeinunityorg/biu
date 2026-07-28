"use client"

import { Head } from "@inertiajs/react"
import { motion } from "framer-motion"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { TextArea } from "@/components/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { ConfirmationModal } from "@/components/confirmation-modal"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { useState } from "react"
import { useForm, router } from "@inertiajs/react"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import {
    ArrowLeft,
    Save,
    Send,
    Trash2,
    Mail,
    FileText,
    Eye,
    AlertCircle,
    Code,
    Copy,
    Check,
    MessageSquare,
    CheckCircle2,
} from "lucide-react"

/** Matches backend NewsletterController::NEWSLETTER_SMS_PLAIN_MAX_CHARS */
const SMS_PLAIN_MAX_CHARS = 160

interface Newsletter {
    id: number
    subject: string
    content: string
    html_content: string
    send_via?: string
    status: string
    newsletter_template_id: number | null
    template?: {
        id: number
        name: string
        template_type: string
    } | null
}

interface Template {
    id: number
    name: string
    template_type: string
    is_active: boolean
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

interface NewsletterEditProps {
    newsletter: Newsletter
    templates: Template[]
    previewData?: PreviewData
}

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
        <div className="group flex items-start justify-between gap-2 p-1.5 hover:bg-white dark:hover:bg-gray-700 rounded transition-colors w-full">
            <div className="flex-1 min-w-0 grow">
                <button
                    type="button"
                    onClick={handleCopy}
                    className="text-left w-full"
                    title={`Click to copy ${variable}`}
                >
                    <code className="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-xs font-mono block mb-1 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors break-all">
                        {variable}
                    </code>
                    <div className="text-xs text-gray-600 dark:text-gray-400 wrap-break-word">
                        <span>{description}</span>
                        <span className="ml-2 text-gray-500 dark:text-gray-500 break-all">
                            → {sampleValue.length > 50 ? sampleValue.substring(0, 50) + '...' : sampleValue}
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

export default function NewsletterEdit({ newsletter, templates, previewData }: NewsletterEditProps) {
    const [confirmationModal, setConfirmationModal] = useState<{
        isOpen: boolean
        title: string
        description: string
        onConfirm: () => void
    }>({
        isOpen: false,
        title: '',
        description: '',
        onConfirm: () => {}
    })
    const [showPreview, setShowPreview] = useState(false)

    const initialSendVia: "email" | "sms" | "both" =
        newsletter.send_via === "sms" || newsletter.send_via === "both" || newsletter.send_via === "email"
            ? newsletter.send_via
            : "email"

    const { data, setData, put, processing, errors, reset } = useForm({
        subject: newsletter.subject,
        content: newsletter.content,
        html_content: newsletter.html_content || '',
        newsletter_template_id: newsletter.newsletter_template_id ?? '',
        send_via: initialSendVia,
    })

    const editHasBody =
        data.send_via === "sms"
            ? Boolean(data.content?.trim())
            : data.send_via === "both"
              ? Boolean(data.content?.trim() && data.html_content?.trim())
              : Boolean(data.content?.trim() || data.html_content?.trim())

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
        public_view_link: route('newsletter.show', newsletter.id) || 'https://example.com/newsletter/public/preview',
    }

    // Function to replace variables with real data
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
        put(route('newsletter.update', newsletter.id))
    }

    const handleDelete = () => {
        setConfirmationModal({
            isOpen: true,
            title: 'Delete Engagement',
            description: `Are you sure you want to delete "${newsletter.subject}"? This action cannot be undone.`,
            onConfirm: () => {
                router.delete(route('newsletter.destroy', newsletter.id))
            }
        })
    }

    const handleSend = () => {
        setConfirmationModal({
            isOpen: true,
            title: 'Start Engagement',
            description: `Start "${data.subject}" to all subscribers now?`,
            onConfirm: () => {
                router.post(route('newsletter.send', newsletter.id))
            }
        })
    }

    return (
        <AppSidebarLayout>
            <Head title={`Engagement · Edit · ${newsletter.subject}`} />

            <div className="w-full min-w-0 max-w-full space-y-6 px-4 py-6 sm:space-y-8 sm:px-6 lg:px-8 animate-in fade-in duration-500">
                {/* Header */}
                <div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                    <div className="min-w-0 flex-1 space-y-2 animate-in slide-in-from-left duration-700">
                        <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:gap-3">
                            <Button
                                variant="outline"
                                size="sm"
                                className="w-fit shrink-0"
                                onClick={() => router.get(route('newsletter.show', newsletter.id))}
                            >
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Back
                            </Button>
                            <div className="min-w-0 space-y-1">
                                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">
                                    Engagement
                                </p>
                                <h1 className="min-w-0 break-words text-2xl font-bold text-gray-900 sm:text-3xl lg:text-4xl dark:text-white">
                                    Edit Engagement
                                </h1>
                            </div>
                        </div>
                        <p className="text-sm text-gray-600 sm:text-base lg:text-lg dark:text-gray-400">
                            Update your email campaign content and settings
                        </p>
                    </div>
                    <div className="animate-in slide-in-from-right duration-700">
                        <div className="flex min-w-0 w-full flex-wrap gap-2 sm:w-auto sm:max-w-md sm:justify-end lg:max-w-none">
                            <Button
                                variant="outline"
                                onClick={handleDelete}
                                className="min-w-0 shrink-0 whitespace-nowrap text-red-600 hover:text-red-700 hover:border-red-300"
                            >
                                <Trash2 className="h-4 w-4 mr-2 shrink-0" />
                                <span className="hidden sm:inline">Delete</span>
                                <span className="sm:hidden">Del</span>
                            </Button>
                            <Button
                                onClick={handleSend}
                                className="min-w-0 shrink-0 whitespace-nowrap bg-green-600 hover:bg-green-700"
                            >
                                <Send className="h-4 w-4 mr-2 shrink-0" />
                                <span className="hidden sm:inline">Start now</span>
                                <span className="sm:hidden">Start</span>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Edit Form */}
                <form onSubmit={handleSubmit} className="min-w-0 space-y-6">
                    <div className="grid min-w-0 grid-cols-1 gap-6 lg:grid-cols-3">
                        {/* Main Content */}
                        <div className="min-w-0 space-y-6 lg:col-span-2">
                            <Card className="shadow-lg">
                                <CardHeader>
                                    <CardTitle>Channel</CardTitle>
                                    <CardDescription>
                                        SMS is plain text only. Both requires SMS plain text and HTML for email.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <div className="flex rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-gray-600 dark:bg-gray-800/80">
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
                                                    setData("send_via", id)
                                                    if (id === "sms") {
                                                        setData("html_content", "")
                                                    }
                                                }}
                                                className={`flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-2.5 text-xs font-medium transition-colors sm:text-sm ${
                                                    data.send_via === id
                                                        ? "bg-blue-600 text-white shadow-sm dark:bg-blue-600"
                                                        : "text-gray-600 hover:bg-white hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                                                }`}
                                            >
                                                <Icon className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                    {errors.send_via && (
                                        <p className="text-sm text-red-600 dark:text-red-400">{errors.send_via}</p>
                                    )}
                                </CardContent>
                            </Card>

                            <Card className="shadow-lg">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Mail className="h-5 w-5" />
                                        Message content
                                    </CardTitle>
                                    <CardDescription>
                                        {data.send_via === "sms" &&
                                            `Plain text only for SMS — max ${SMS_PLAIN_MAX_CHARS} characters.`}
                                        {data.send_via === "email" && "Plain and/or HTML for email."}
                                        {data.send_via === "both" && "Plain text for SMS and HTML for email (both required)."}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <Label htmlFor="subject">Subject Line</Label>
                                        <Input
                                            id="subject"
                                            type="text"
                                            value={data.subject}
                                            onChange={(e) => setData('subject', e.target.value)}
                                            placeholder="Enter subject…"
                                            className="mt-1"
                                        />
                                        {errors.subject && (
                                            <p className="text-red-600 dark:text-red-400 text-sm mt-1 flex items-center gap-1">
                                                <AlertCircle className="h-3 w-3" />
                                                {errors.subject}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="content">
                                            {data.send_via === "sms"
                                                ? "SMS message (plain text)"
                                                : data.send_via === "both"
                                                  ? "SMS message (plain text, required)"
                                                  : "Text content"}
                                        </Label>
                                        <TextArea
                                            id="content"
                                            value={data.content}
                                            onChange={(e) => setData('content', e.target.value)}
                                            placeholder="Plain text body..."
                                            maxLength={
                                                data.send_via === "sms" ? SMS_PLAIN_MAX_CHARS : undefined
                                            }
                                            className="mt-1 min-h-[200px]"
                                        />
                                        {data.send_via === "sms" && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {(data.content?.length ?? 0)} / {SMS_PLAIN_MAX_CHARS} characters
                                            </p>
                                        )}
                                        {errors.content && (
                                            <p className="text-red-600 dark:text-red-400 text-sm mt-1 flex items-center gap-1">
                                                <AlertCircle className="h-3 w-3" />
                                                {errors.content}
                                            </p>
                                        )}
                                    </div>

                                    {data.send_via !== "sms" && (
                                        <div>
                                            <Label htmlFor="html_content">
                                                {data.send_via === "both"
                                                    ? "HTML content for email (required)"
                                                    : "HTML content (optional)"}
                                            </Label>
                                            <TextArea
                                                id="html_content"
                                                value={data.html_content}
                                                onChange={(e) => setData('html_content', e.target.value)}
                                                placeholder="HTML version..."
                                                className="mt-1 min-h-[300px] font-mono text-sm"
                                            />
                                            {errors.html_content && (
                                                <p className="text-red-600 dark:text-red-400 text-sm mt-1 flex items-center gap-1">
                                                    <AlertCircle className="h-3 w-3" />
                                                    {errors.html_content}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        {/* Sidebar */}
                        <div className="min-w-0 space-y-6">
                            {/* Template Selection */}
                            <Card className="shadow-lg">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <FileText className="h-5 w-5" />
                                        Template
                                    </CardTitle>
                                    <CardDescription>
                                        Choose a template for this Engagement
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <div>
                                        <Label htmlFor="template">Template</Label>
                                        <Select
                                            value={
                                                data.newsletter_template_id === '' || data.newsletter_template_id === null
                                                    ? '__none__'
                                                    : String(data.newsletter_template_id)
                                            }
                                            onValueChange={(value) =>
                                                setData(
                                                    'newsletter_template_id',
                                                    value === '__none__' ? '' : parseInt(value, 10)
                                                )
                                            }
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue placeholder="No template (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">No template</SelectItem>
                                                {templates.map((template) => (
                                                    <SelectItem key={template.id} value={template.id.toString()}>
                                                        {template.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.newsletter_template_id && (
                                            <p className="text-red-500 text-sm mt-1">{errors.newsletter_template_id}</p>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Actions */}
                            <Card className="shadow-lg">
                                <CardHeader>
                                    <CardTitle>Actions</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Button
                                        type="submit"
                                        disabled={processing || !data.subject || !editHasBody}
                                        className="w-full"
                                    >
                                        <Save className="h-4 w-4 mr-2" />
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setShowPreview(true)}
                                        disabled={!data.subject && !editHasBody}
                                        className="w-full"
                                    >
                                        <Eye className="h-4 w-4 mr-2" />
                                        Preview
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => router.get(route('newsletter.show', newsletter.id))}
                                        className="w-full"
                                    >
                                        Cancel
                                    </Button>
                                </CardContent>
                            </Card>

                            {/* Available Variables */}
                            <Card className="shadow-lg w-full">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Code className="h-4 w-4" />
                                        Available Variables
                                    </CardTitle>
                                    <CardDescription>
                                        Click to copy, use in subject or content
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="w-full max-w-full overflow-x-hidden">
                                    <div className="space-y-3 w-full">
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
                                                💡 <strong>Tip:</strong> Variables are automatically replaced in the Preview with your actual organization and recipient data
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Engagement details */}
                            <Card className="shadow-lg">
                                <CardHeader>
                                    <CardTitle>Engagement details</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex justify-between">
                                        <span className="text-sm text-gray-600 dark:text-gray-400">Status:</span>
                                        <span className="text-sm font-medium capitalize">{newsletter.status}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-sm text-gray-600 dark:text-gray-400">Template:</span>
                                        <span className="text-sm font-medium">
                                            {newsletter.template?.name ?? 'None'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-sm text-gray-600 dark:text-gray-400">Created:</span>
                                        <span className="text-sm font-medium">
                                            {new Date(newsletter.created_at).toLocaleDateString()}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </form>

                {/* Confirmation Modal */}
                <ConfirmationModal
                    isOpen={confirmationModal.isOpen}
                    onChange={(open) => setConfirmationModal(prev => ({ ...prev, isOpen: open }))}
                    title={confirmationModal.title}
                    description={confirmationModal.description}
                    confirmLabel="Confirm"
                    cancelLabel="Cancel"
                    onConfirm={confirmationModal.onConfirm}
                    isLoading={processing}
                />

                {/* Preview Modal */}
                <Dialog open={showPreview} onOpenChange={setShowPreview}>
                    <DialogContent className="w-[calc(100vw-2rem)] max-w-xl max-h-[min(85vh,720px)] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Preview</DialogTitle>
                            <DialogDescription>
                                Preview how your message will look to recipients
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div className="border rounded-lg p-6 bg-white dark:bg-gray-800">
                                <div className="mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">Subject Preview:</p>
                                    <h3 className="font-semibold text-xl">
                                        {previewSubject || data.subject || 'Subject'}
                                    </h3>
                                </div>
                                {previewHtmlContent ? (
                                    <div
                                        className="prose prose-sm max-w-none dark:prose-invert"
                                        dangerouslySetInnerHTML={{ __html: previewHtmlContent }}
                                    />
                                ) : (
                                    <div className="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">
                                        {previewContent || data.content || 'Content will appear here…'}
                                    </div>
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
                    </DialogContent>
                </Dialog>
            </div>
        </AppSidebarLayout>
    )
}

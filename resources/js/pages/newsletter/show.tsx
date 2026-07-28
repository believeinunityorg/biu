"use client"

import { Head, router } from "@inertiajs/react"
import { motion } from "framer-motion"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Link } from "@inertiajs/react"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import { ConfirmationModal } from "@/components/confirmation-modal"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { useState } from "react"
import { getBrowserTimezone, formatDateInTimezone, convertUserTimezoneToUTC } from "@/lib/timezone-detection"
import {
    Mail,
    Users,
    Eye,
    MousePointer,
    Calendar,
    ArrowLeft,
    Send,
    Edit,
    BarChart3,
    Clock,
    CheckCircle,
    XCircle,
    Trash2,
    Save,
    Pause
} from "lucide-react"

function sendViaLabel(sendVia?: string): string {
    switch (sendVia) {
        case 'sms':
            return 'SMS'
        case 'both':
            return 'Email & SMS'
        case 'push':
            return 'Push'
        case 'email':
        default:
            return 'Email'
    }
}

interface Newsletter {
    id: number
    subject: string
    content: string
    html_content: string
    send_via?: 'email' | 'sms' | 'both' | 'push'
    status: 'draft' | 'paused' | 'scheduled' | 'sending' | 'sent' | 'failed'
    scheduled_at?: string
    scheduled_at_formatted?: string
    scheduled_at_iso?: string
    send_date?: string
    send_date_formatted?: string
    send_date_iso?: string
    sent_at?: string
    sent_at_formatted?: string
    created_at?: string
    created_at_formatted?: string
    schedule_type?: 'immediate' | 'scheduled' | 'recurring'
    total_recipients: number
    sent_count: number
    delivered_count: number
    opened_count: number
    clicked_count: number
    bounced_count: number
    unsubscribed_count: number
    template?: {
        id: number
        name: string
        template_type: string
    } | null
    organization?: {
        name: string
    }
    emails: Array<{
        id: number
        email: string
        status: string
        sent_at?: string
        sent_at_formatted?: string
        delivered_at?: string
        delivered_at_formatted?: string
        opened_at?: string
        opened_at_formatted?: string
        clicked_at?: string
        clicked_at_formatted?: string
        bounced_at?: string
        recipient?: {
            name?: string
            email: string
        }
    }>
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

interface NewsletterShowProps {
    newsletter: Newsletter
    previewData?: PreviewData
}

export default function NewsletterShow({ newsletter, previewData }: NewsletterShowProps) {
    const [isUpdatingSchedule, setIsUpdatingSchedule] = useState(false)
    const [isResumeModalOpen, setIsResumeModalOpen] = useState(false)
    const [isPauseModalOpen, setIsPauseModalOpen] = useState(false)
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false)
    const [isManualSendModalOpen, setIsManualSendModalOpen] = useState(false)
    const [showVariablePreview, setShowVariablePreview] = useState(false)
    const [newScheduleTime, setNewScheduleTime] = useState(() => {
        // Use ISO date from backend (already in user's timezone)
        const dateToUse = newsletter.send_date_iso || newsletter.scheduled_at_iso;
        if (dateToUse) {
            return dateToUse.slice(0, 16);
        }
        return '';
    })
    const [resumeScheduleTime, setResumeScheduleTime] = useState('')

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'draft': return 'bg-gray-100 text-gray-800'
            case 'paused': return 'bg-orange-100 text-orange-800'
            case 'scheduled': return 'bg-blue-100 text-blue-800'
            case 'sending': return 'bg-yellow-100 text-yellow-800'
            case 'sent': return 'bg-green-100 text-green-800'
            case 'failed': return 'bg-red-100 text-red-800'
            default: return 'bg-gray-100 text-gray-800'
        }
    }

    const getStatusIcon = (status: string) => {
        switch (status) {
            case 'draft': return <Edit className="h-4 w-4" />
            case 'paused': return <Pause className="h-4 w-4" />
            case 'scheduled': return <Clock className="h-4 w-4" />
            case 'sending': return <Send className="h-4 w-4" />
            case 'sent': return <CheckCircle className="h-4 w-4" />
            case 'failed': return <XCircle className="h-4 w-4" />
            default: return <Edit className="h-4 w-4" />
        }
    }

    // Dates are already formatted in backend with user's timezone - just use them directly

    const openRate = newsletter.total_recipients > 0 ?
        ((newsletter.opened_count / newsletter.total_recipients) * 100).toFixed(1) : '0.0'

    const clickRate = newsletter.total_recipients > 0 ?
        ((newsletter.clicked_count / newsletter.total_recipients) * 100).toFixed(1) : '0.0'

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
    const previewSubject = replaceVariables(newsletter.subject)
    const previewContent = replaceVariables(newsletter.content)
    const previewHtmlContent = replaceVariables(newsletter.html_content || '')

    return (
        <AppSidebarLayout>
            <Head title={`Engagement · ${newsletter.subject}`} />

            <div className="space-y-6 sm:space-y-8 animate-in fade-in duration-500 m-10">
                {/* Header */}
                <div className="flex flex-col space-y-4 sm:space-y-0 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-2 animate-in slide-in-from-left duration-700">
                        <div className="flex items-start gap-3">
                            <Link href={route('newsletter.index')} className="shrink-0 pt-1">
                                <Button variant="outline" size="sm" className="hover:bg-gray-50 dark:hover:bg-gray-800">
                                    <ArrowLeft className="h-4 w-4 mr-2" />
                                    <span className="hidden sm:inline">Back to Engagement</span>
                                    <span className="sm:hidden">Back</span>
                                </Button>
                            </Link>
                            <div className="min-w-0 space-y-1">
                                <p className="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500 dark:text-gray-400">
                                    Engagement
                                </p>
                                <h1 className="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 dark:text-white">
                                    {newsletter.subject}
                                </h1>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <Badge className={getStatusColor(newsletter.status)}>
                                {getStatusIcon(newsletter.status)}
                                <span className="ml-1 capitalize">{newsletter.status}</span>
                            </Badge>
                            <Badge variant="outline" className="border-violet-300 text-violet-800 dark:border-violet-700 dark:text-violet-200 font-normal">
                                Channel: {sendViaLabel(newsletter.send_via)}
                            </Badge>
                            {newsletter.sent_at_formatted && (
                                <span className="text-sm text-gray-600 dark:text-gray-400">
                                    Sent: {newsletter.sent_at_formatted}
                                </span>
                            )}
                            {(newsletter.send_date_formatted || newsletter.scheduled_at_formatted) && (
                                <span className="text-sm text-gray-600 dark:text-gray-400">
                                    Scheduled: {newsletter.send_date_formatted || newsletter.scheduled_at_formatted}
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="animate-in slide-in-from-right duration-700">
                        <div className="flex flex-col sm:flex-row gap-2">
                            {/* Draft Status Actions */}
                            {newsletter.status === 'draft' && (
                                <>
                                    <Link href={route('newsletter.edit', newsletter.id)}>
                                        <Button variant="outline">
                                            <Edit className="mr-2 h-4 w-4" />
                                            <span className="hidden sm:inline">Edit Engagement</span>
                                            <span className="sm:hidden">Edit</span>
                                        </Button>
                                    </Link>
                                    <Button
                                        onClick={() => setIsResumeModalOpen(true)}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <Send className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Start now</span>
                                        <span className="sm:hidden">Start</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsDeleteModalOpen(true)}
                                        variant="outline"
                                        className="text-red-600 hover:text-red-700 hover:border-red-300"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Delete</span>
                                    </Button>
                                </>
                            )}

                            {/* Paused Status Actions */}
                            {newsletter.status === 'paused' && (
                                <>
                                    <Link href={route('newsletter.edit', newsletter.id)}>
                                        <Button variant="outline">
                                            <Edit className="mr-2 h-4 w-4" />
                                            <span className="hidden sm:inline">Edit Engagement</span>
                                            <span className="sm:hidden">Edit</span>
                                        </Button>
                                    </Link>
                                    <Button
                                        onClick={() => setIsResumeModalOpen(true)}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <Send className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Resume</span>
                                        <span className="sm:hidden">Resume</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsDeleteModalOpen(true)}
                                        variant="outline"
                                        className="text-red-600 hover:text-red-700 hover:border-red-300"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Delete</span>
                                    </Button>
                                </>
                            )}

                            {/* Scheduled Status Actions */}
                            {newsletter.status === 'scheduled' && (
                                <>
                                    <Button
                                        onClick={() => setIsUpdatingSchedule(!isUpdatingSchedule)}
                                        variant="outline"
                                    >
                                        <Calendar className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Update Schedule</span>
                                        <span className="sm:hidden">Schedule</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsManualSendModalOpen(true)}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <Send className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Start now</span>
                                        <span className="sm:hidden">Start</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsPauseModalOpen(true)}
                                        variant="outline"
                                        className="text-orange-600 hover:text-orange-700 hover:border-orange-300"
                                    >
                                        <Pause className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Pause</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsDeleteModalOpen(true)}
                                        variant="outline"
                                        className="text-red-600 hover:text-red-700 hover:border-red-300"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Delete</span>
                                    </Button>
                                </>
                            )}

                            {/* Sending Status Actions */}
                            {newsletter.status === 'sending' && (
                                <>
                                    <Button
                                        onClick={() => setIsUpdatingSchedule(!isUpdatingSchedule)}
                                        variant="outline"
                                    >
                                        <Calendar className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Update Schedule</span>
                                        <span className="sm:hidden">Schedule</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsManualSendModalOpen(true)}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                            <Send className="mr-2 h-4 w-4" />
                                            <span className="hidden sm:inline">Start now</span>
                                            <span className="sm:hidden">Start</span>
                                        </Button>
                                    <Button
                                        onClick={() => setIsPauseModalOpen(true)}
                                        variant="outline"
                                        className="text-orange-600 hover:text-orange-700 hover:border-orange-300"
                                    >
                                        <Pause className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Pause</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsDeleteModalOpen(true)}
                                        variant="outline"
                                        className="text-red-600 hover:text-red-700 hover:border-red-300"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Delete</span>
                                    </Button>
                                </>
                            )}

                            {/* Sent Status Actions */}
                            {newsletter.status === 'sent' && (
                                <>
                                    <Button
                                        onClick={() => {
                                            console.log('Send Again button clicked for newsletter:', newsletter.id);
                                            setIsManualSendModalOpen(true);
                                        }}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <Send className="mr-2 h-4 w-4" />
                                            <span className="hidden sm:inline">Start again</span>
                                            <span className="sm:hidden">Again</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsDeleteModalOpen(true)}
                                        variant="outline"
                                        className="text-red-600 hover:text-red-700 hover:border-red-300"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Delete</span>
                                    </Button>
                                </>
                            )}

                            {/* Failed Status Actions */}
                            {newsletter.status === 'failed' && (
                                <>
                                    <Button
                                        onClick={() => setIsManualSendModalOpen(true)}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <Send className="mr-2 h-4 w-4" />
                                            <span className="hidden sm:inline">Retry</span>
                                        <span className="sm:hidden">Retry</span>
                                    </Button>
                                    <Button
                                        onClick={() => setIsDeleteModalOpen(true)}
                                        variant="outline"
                                        className="text-red-600 hover:text-red-700 hover:border-red-300"
                                    >
                                        <Trash2 className="mr-2 h-4 w-4" />
                                        <span className="hidden sm:inline">Delete</span>
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </div>

                {/* Schedule Update Form */}
                {newsletter.status === 'scheduled' && isUpdatingSchedule && (
                    <Card className="shadow-lg">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Calendar className="h-5 w-5" />
                                Update schedule
                            </CardTitle>
                            <CardDescription>
                                Change when this Engagement goes out
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                method="POST"
                                action={route('newsletter.update-schedule', newsletter.id)}
                                className="space-y-4"
                            >
                                <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''} />
                                <input type="hidden" name="_method" value="PUT" />

                                <div className="space-y-2">
                                    <Label htmlFor="scheduled_at" className="text-sm font-medium">
                                        New Schedule Time
                                    </Label>
                                    <Input
                                        id="scheduled_at"
                                        name="scheduled_at"
                                        type="datetime-local"
                                        value={newScheduleTime}
                                        onChange={(e) => setNewScheduleTime(e.target.value)}
                                        className="w-full"
                                        required
                                    />
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Current schedule: {(newsletter.send_date_formatted || newsletter.scheduled_at_formatted) || 'Not set'}
                                        {newsletter.schedule_type && newsletter.schedule_type !== 'immediate' && (
                                            <span className="ml-1">({newsletter.schedule_type})</span>
                                        )}
                                    </p>
                                </div>

                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        className="bg-blue-600 hover:bg-blue-700"
                                    >
                                        <Save className="mr-2 h-4 w-4" />
                                        Update Schedule
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setIsUpdatingSchedule(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {/* Statistics Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
                    <Card className="shadow-lg hover:shadow-xl transition-all duration-300">
                        <CardContent className="p-4 sm:p-6">
                            <div className="flex items-center justify-between mb-4">
                                <div className="p-3 bg-blue-100 dark:bg-blue-900/50 rounded-full">
                                    <Users className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                                </div>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Total Recipients</p>
                                <p className="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">{newsletter.total_recipients}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{newsletter.sent_count} sent</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="shadow-lg hover:shadow-xl transition-all duration-300">
                        <CardContent className="p-4 sm:p-6">
                            <div className="flex items-center justify-between mb-4">
                                <div className="p-3 bg-green-100 dark:bg-green-900/50 rounded-full">
                                    <CheckCircle className="h-6 w-6 text-green-600 dark:text-green-400" />
                                </div>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Delivered</p>
                                <p className="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">{newsletter.delivered_count}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">
                                    {newsletter.total_recipients > 0 ?
                                        ((newsletter.delivered_count / newsletter.total_recipients) * 100).toFixed(1) : '0.0'}% rate
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="shadow-lg hover:shadow-xl transition-all duration-300">
                        <CardContent className="p-4 sm:p-6">
                            <div className="flex items-center justify-between mb-4">
                                <div className="p-3 bg-purple-100 dark:bg-purple-900/50 rounded-full">
                                    <Eye className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                                </div>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Opens</p>
                                <p className="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">{newsletter.opened_count}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{openRate}% open rate</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="shadow-lg hover:shadow-xl transition-all duration-300">
                        <CardContent className="p-4 sm:p-6">
                            <div className="flex items-center justify-between mb-4">
                                <div className="p-3 bg-orange-100 dark:bg-orange-900/50 rounded-full">
                                    <MousePointer className="h-6 w-6 text-orange-600 dark:text-orange-400" />
                                </div>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Clicks</p>
                                <p className="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white">{newsletter.clicked_count}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400">{clickRate}% click rate</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Engagement details */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Message content */}
                    <Card className="shadow-lg">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Mail className="h-5 w-5" />
                                Message content
                            </CardTitle>
                            <CardDescription>
                                Preview of the message
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                <div>
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">Subject</h3>
                                    <p className="text-gray-700 dark:text-gray-300">{newsletter.subject}</p>
                                </div>

                                <div>
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">Template</h3>
                                    <Badge variant="outline">{newsletter.template?.name ?? 'None'}</Badge>
                                </div>

                                <div>
                                    <div className="flex items-center justify-between mb-2">
                                        <h3 className="font-semibold text-gray-900 dark:text-white">Content Preview</h3>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setShowVariablePreview(!showVariablePreview)}
                                            className="text-xs"
                                        >
                                            <Eye className="h-3 w-3 mr-1" />
                                            {showVariablePreview ? 'Show Original' : 'Show with Variables'}
                                        </Button>
                                    </div>
                                    <div className="border rounded-lg p-4 bg-gray-50 dark:bg-gray-800 max-h-96 overflow-y-auto">
                                        {showVariablePreview ? (
                                            // Show with variables replaced
                                            previewHtmlContent ? (
                                                <div
                                                    dangerouslySetInnerHTML={{ __html: previewHtmlContent }}
                                                    className="prose prose-sm max-w-none dark:prose-invert"
                                                />
                                            ) : (
                                                <pre className="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">
                                                    {previewContent}
                                                </pre>
                                            )
                                        ) : (
                                            // Show original
                                            newsletter.html_content ? (
                                                <div
                                                    dangerouslySetInnerHTML={{ __html: newsletter.html_content }}
                                                    className="prose prose-sm max-w-none dark:prose-invert"
                                                />
                                            ) : (
                                                <pre className="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">
                                                    {newsletter.content}
                                                </pre>
                                            )
                                        )}
                                    </div>
                                    {showVariablePreview && (
                                        <div className="mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded text-xs text-blue-800 dark:text-blue-300">
                                            💡 Variables like {'{organization_name}'}, {'{recipient_name}'} are replaced with real data above
                                        </div>
                                    )}
                                </div>

                                {showVariablePreview && (
                                    <div>
                                        <h3 className="font-semibold text-gray-900 dark:text-white mb-2">Subject Preview (with variables)</h3>
                                        <p className="text-gray-700 dark:text-gray-300 p-2 bg-gray-50 dark:bg-gray-800 rounded">
                                            {previewSubject}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Performance Metrics */}
                    <Card className="shadow-lg">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BarChart3 className="h-5 w-5" />
                                Performance Metrics
                            </CardTitle>
                            <CardDescription>
                                Detailed performance statistics
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-6">
                                {/* Rate Cards */}
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="text-center p-4 bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/20 dark:to-purple-800/20 rounded-lg border border-purple-200 dark:border-purple-800">
                                        <p className="text-3xl font-bold text-purple-700 dark:text-purple-300">{openRate}%</p>
                                        <p className="text-sm text-purple-600 dark:text-purple-400 mt-1">Open Rate</p>
                                        <p className="text-xs text-gray-500 mt-1">{newsletter.opened_count} of {newsletter.delivered_count} opened</p>
                                    </div>
                                    <div className="text-center p-4 bg-gradient-to-br from-orange-50 to-orange-100 dark:from-orange-900/20 dark:to-orange-800/20 rounded-lg border border-orange-200 dark:border-orange-800">
                                        <p className="text-3xl font-bold text-orange-700 dark:text-orange-300">{clickRate}%</p>
                                        <p className="text-sm text-orange-600 dark:text-orange-400 mt-1">Click Rate</p>
                                        <p className="text-xs text-gray-500 mt-1">{newsletter.clicked_count} clicks</p>
                                    </div>
                                </div>

                                {/* Progress Bars */}
                                <div className="space-y-3">
                                    <div>
                                        <div className="flex justify-between items-center mb-1">
                                            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Delivery Rate</span>
                                            <span className="text-sm font-semibold text-gray-900 dark:text-white">
                                                {newsletter.total_recipients > 0 ?
                                                    ((newsletter.delivered_count / newsletter.total_recipients) * 100).toFixed(1) : '0.0'}%
                                            </span>
                                        </div>
                                        <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                            <div
                                                className="bg-green-600 h-2 rounded-full transition-all"
                                                style={{
                                                    width: `${newsletter.total_recipients > 0 ?
                                                        ((newsletter.delivered_count / newsletter.total_recipients) * 100) : 0}%`
                                                }}
                                            />
                                        </div>
                                        <p className="text-xs text-gray-500 mt-1">{newsletter.delivered_count} of {newsletter.total_recipients} delivered</p>
                                    </div>

                                    <div>
                                        <div className="flex justify-between items-center mb-1">
                                            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Open Rate</span>
                                            <span className="text-sm font-semibold text-purple-600 dark:text-purple-400">{openRate}%</span>
                                        </div>
                                        <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                            <div
                                                className="bg-purple-600 h-2 rounded-full transition-all"
                                                style={{
                                                    width: `${openRate}%`
                                                }}
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <div className="flex justify-between items-center mb-1">
                                            <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Click Rate</span>
                                            <span className="text-sm font-semibold text-orange-600 dark:text-orange-400">{clickRate}%</span>
                                        </div>
                                        <div className="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                            <div
                                                className="bg-orange-600 h-2 rounded-full transition-all"
                                                style={{
                                                    width: `${clickRate}%`
                                                }}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* Additional Stats */}
                                <div className="space-y-2 pt-3 border-t border-gray-200 dark:border-gray-700">
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600 dark:text-gray-400">Bounced</span>
                                        <span className="font-semibold text-red-600 dark:text-red-400">{newsletter.bounced_count}</span>
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600 dark:text-gray-400">Unsubscribed</span>
                                        <span className="font-semibold text-orange-600 dark:text-orange-400">{newsletter.unsubscribed_count}</span>
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600 dark:text-gray-400">Pending</span>
                                        <span className="font-semibold text-gray-600 dark:text-gray-400">
                                            {newsletter.total_recipients - newsletter.sent_count}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Email List (if sent) */}
                {newsletter.status === 'sent' && newsletter.emails.length > 0 && (
                    <Card className="shadow-lg">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Users className="h-5 w-5" />
                                Email Delivery Status
                            </CardTitle>
                            <CardDescription>
                                Individual email delivery status for each recipient
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-2 max-h-96 overflow-y-auto">
                                {newsletter.emails.map((email) => (
                                    <div key={email.id} className="flex items-center justify-between p-3 border rounded-lg">
                                        <div className="flex-1">
                                            <div className="font-medium text-gray-900 dark:text-white">
                                                {email.recipient?.name || email.recipient?.email || email.email || 'Unknown Recipient'}
                                            </div>
                                            <div className="text-sm text-gray-600 dark:text-gray-400">
                                                {email.recipient?.email || email.email || 'No email address'}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={email.status === 'delivered' ? 'default' : 'secondary'}
                                                className={email.status === 'delivered' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}
                                            >
                                                {email.status}
                                            </Badge>
                                            {email.sent_at && (
                                                <span className="text-xs text-gray-500">
                                                    {email.sent_at_formatted || email.sent_at || 'N/A'}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Modals */}
                {/* Resume/Send Modal */}
                <Dialog open={isResumeModalOpen} onOpenChange={setIsResumeModalOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {newsletter.status === 'draft' ? 'Start Engagement' : 'Resume Engagement'}
                            </DialogTitle>
                            <DialogDescription>
                                {newsletter.status === 'draft'
                                    ? 'Start this Engagement now?'
                                    : 'Choose how to resume this Engagement:'}
                            </DialogDescription>
                        </DialogHeader>
                        {newsletter.status === 'paused' && (
                            <div className="space-y-4">
                                <div>
                                    <Label htmlFor="resume_schedule" className="text-sm font-medium">
                                        Schedule Time (optional)
                                    </Label>
                                    <Input
                                        id="resume_schedule"
                                        type="datetime-local"
                                        value={resumeScheduleTime}
                                        onChange={(e) => setResumeScheduleTime(e.target.value)}
                                        className="w-full mt-2"
                                    />
                                    <p className="text-xs text-gray-500 mt-1">
                                        Leave empty to start immediately, or set a future time to schedule
                                    </p>
                                </div>
                            </div>
                        )}
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setIsResumeModalOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={() => {
                                    // For draft newsletters, use send route. For paused, use resume route.
                                    const routeToUse = newsletter.status === 'draft'
                                        ? route('newsletter.send', newsletter.id)
                                        : route('newsletter.resume', newsletter.id);

                                    const form = document.createElement('form');
                                    form.method = 'POST';
                                    form.action = routeToUse;

                                    const token = document.createElement('input');
                                    token.type = 'hidden';
                                    token.name = '_token';
                                    token.value = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                                    form.appendChild(token);

                                    // Only add scheduled_at if it's a resume action and time is provided
                                    if (newsletter.status === 'paused' && resumeScheduleTime) {
                                        const scheduleInput = document.createElement('input');
                                        scheduleInput.type = 'hidden';
                                        scheduleInput.name = 'scheduled_at';
                                        scheduleInput.value = resumeScheduleTime;
                                        form.appendChild(scheduleInput);
                                    }

                                    document.body.appendChild(form);
                                    form.submit();
                                    setIsResumeModalOpen(false);
                                }}
                                className="bg-green-600 hover:bg-green-700"
                            >
                                {newsletter.status === 'draft'
                                    ? 'Start now'
                                    : (resumeScheduleTime ? 'Schedule' : 'Start now')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Pause Modal */}
                <ConfirmationModal
                    isOpen={isPauseModalOpen}
                    onChange={setIsPauseModalOpen}
                    title="Pause Engagement"
                    description="Are you sure you want to pause this Engagement? It will be moved back to draft status and the schedule will be cleared."
                    confirmLabel="Pause"
                    cancelLabel="Cancel"
                    onConfirm={() => {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = route('newsletter.pause', newsletter.id);

                        const token = document.createElement('input');
                        token.type = 'hidden';
                        token.name = '_token';
                        token.value = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                        form.appendChild(token);
                        document.body.appendChild(form);
                        form.submit();
                    }}
                />

                {/* Manual Send Modal */}
                <ConfirmationModal
                    isOpen={isManualSendModalOpen}
                    onChange={(open) => {
                        console.log('Manual send modal state changed:', open);
                        setIsManualSendModalOpen(open);
                    }}
                    title="Start Engagement"
                    description="Start this Engagement immediately? This will override any scheduled time."
                    confirmLabel="Start now"
                    cancelLabel="Cancel"
                    onConfirm={() => {
                        try {
                            console.log('Manual send confirmed for newsletter:', newsletter.id);
                            console.log('Route URL:', route('newsletter.manual-send', newsletter.id));

                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = route('newsletter.manual-send', newsletter.id);
                            form.style.display = 'none';

                            const token = document.createElement('input');
                            token.type = 'hidden';
                            token.name = '_token';
                            token.value = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                            form.appendChild(token);
                            document.body.appendChild(form);

                            console.log('Form created and submitted for newsletter:', newsletter.id);
                            console.log('Form action:', form.action);
                            console.log('CSRF token:', token.value);

                            form.submit();
                        } catch (error) {
                            console.error('Error submitting manual send form:', error);
                            alert('Could not start Engagement. Please try again.');
                        }
                    }}
                />

                {/* Delete Modal */}
                <ConfirmationModal
                    isOpen={isDeleteModalOpen}
                    onChange={setIsDeleteModalOpen}
                    title="Delete Engagement"
                    description={`Are you sure you want to delete "${newsletter.subject}"? This action cannot be undone.`}
                    confirmLabel="Delete"
                    cancelLabel="Cancel"
                    onConfirm={() => {
                        router.delete(route('newsletter.destroy', newsletter.id), {
                            onSuccess: () => {
                                router.visit(route('newsletter.index'))
                            },
                            onError: (errors) => {
                                console.error('Delete error:', errors)
                                alert('Failed to delete. Please try again.')
                            }
                        })
                    }}
                />
            </div>
        </AppSidebarLayout>
    )
}

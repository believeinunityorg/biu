"use client"

import { Head, Link } from "@inertiajs/react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Button } from "@/components/frontend/ui/button"
import { Badge } from "@/components/frontend/ui/badge"
import {
    Gift,
    ArrowLeft,
    Copy,
    CheckCircle,
    Calendar,
    DollarSign,
    Globe,
    FileText,
    Download,
    CreditCard,
    Clock,
    ListOrdered,
    AlertCircle,
} from "lucide-react"
import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import { useState } from "react"
import jsPDF from "jspdf"
import {
    formatGiftCardNumber,
    isPhazeFailedStatus,
    isPhazeSuccessStatus,
    labelPhazeField,
    labelPhazeStatus,
    resolveGiftCardCredentials,
} from "@/lib/gift-card-credentials"

interface FulfillmentAuditEntry {
    event?: string
    at?: string
    [key: string]: unknown
}

interface GiftCard {
    id: number
    voucher: string | null
    card_number: string | null
    pin?: string | null
    barcode?: string | null
    barcode_url?: string | null
    qr_code?: string | null
    qr_code_url?: string | null
    claim_url?: string | null
    redemption_instructions?: string | null
    how_to_use?: string | null
    amount: number
    brand: string | null
    brand_name: string | null
    country: string | null
    currency: string
    status: string
    purchased_at: string | null
    requested_at?: string | null
    scheduled_fulfillment_at?: string | null
    fulfilled_at?: string | null
    failure_reason?: string | null
    expires_at: string | null
    created_at: string
    payment_method?: string | null
    meta: any
    fulfillment_audit?: FulfillmentAuditEntry[]
    organization?: {
        id: number
        name: string
    } | null
}

interface ShowProps {
    giftCard: GiftCard
    phazePurchaseData?: any
    phazeDisbursementData?: any
    phazeFlatFields?: Record<string, string | number>
    showAllProviderFields?: boolean
    user?: {
        name: string
        email: string
    } | null
    sessionId?: string | null
}

export default function ShowPage({
    giftCard,
    phazePurchaseData,
    phazeDisbursementData,
    phazeFlatFields,
    showAllProviderFields = false,
    user,
    sessionId,
}: ShowProps) {
    const [copied, setCopied] = useState<string | null>(null)
    const credentials = resolveGiftCardCredentials(giftCard, phazePurchaseData)

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: giftCard.currency || 'USD',
        }).format(amount)
    }

    const copyToClipboard = (text: string, type: string) => {
        navigator.clipboard.writeText(text)
        setCopied(type)
        setTimeout(() => setCopied(null), 2000)
    }

    const formatCardNumber = (cardNumber: string | null) => formatGiftCardNumber(cardNumber)

    const getMetaValue = (key: string) => {
        return giftCard.meta?.[key] || null
    }

    const isProcessingStatus = ['pending_fulfillment', 'processing', 'capacity_reached'].includes(giftCard.status)
    const isFailedStatus = giftCard.status === 'failed'
    const fulfillmentAudit: FulfillmentAuditEntry[] = Array.isArray(giftCard.fulfillment_audit)
        ? giftCard.fulfillment_audit
        : Array.isArray(giftCard.meta?.fulfillment_audit)
            ? giftCard.meta.fulfillment_audit
            : []

    const formatAuditEvent = (event?: string) => {
        if (!event) return 'Update'
        return event
            .split('_')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ')
    }

    const formatDateTime = (value?: string | null) => {
        if (!value) return null
        return new Date(value).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    const generatePDFReceipt = () => {
        const pdf = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: 'a4',
        })

        // Colors
        const primaryColor = [59, 130, 246] // Blue
        const darkGray = [55, 65, 81]
        const lightGray = [156, 163, 175]
        const green = [34, 197, 94]

        let yPos = 20

        // Header
        pdf.setFillColor(...primaryColor)
        pdf.rect(0, 0, 210, 40, 'F')

        pdf.setTextColor(255, 255, 255)
        pdf.setFontSize(24)
        pdf.setFont('helvetica', 'bold')
        pdf.text('Gift Card Receipt', 105, 20, { align: 'center' })

        pdf.setFontSize(12)
        pdf.setFont('helvetica', 'normal')
        pdf.text('Payment Successful', 105, 30, { align: 'center' })

        yPos = 50

        // Receipt Details Section
        pdf.setTextColor(...darkGray)
        pdf.setFontSize(14)
        pdf.setFont('helvetica', 'bold')
        pdf.text('Receipt Details', 20, yPos)

        yPos += 10
        pdf.setDrawColor(...lightGray)
        pdf.line(20, yPos, 190, yPos)
        yPos += 8

        // Receipt Number
        pdf.setFontSize(10)
        pdf.setFont('helvetica', 'normal')
        pdf.setTextColor(...darkGray)
        pdf.text('Receipt Number:', 20, yPos)
        pdf.setFont('helvetica', 'bold')
        const receiptNumber = credentials.card_number
            ? `GC-${giftCard.id}-${credentials.card_number.substring(0, 8)}`
            : `GC-${giftCard.id}-${giftCard.created_at.substring(0, 10).replace(/-/g, '')}`
        pdf.text(receiptNumber, 80, yPos)
        yPos += 8

        // Purchase Date
        pdf.setFont('helvetica', 'normal')
        pdf.text('Purchase Date:', 20, yPos)
        pdf.setFont('helvetica', 'bold')
        const purchaseDate = giftCard.purchased_at
            ? new Date(giftCard.purchased_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
            : new Date(giftCard.created_at).toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
        pdf.text(purchaseDate, 80, yPos)
        yPos += 8

        // Status
        pdf.setFont('helvetica', 'normal')
        pdf.text('Status:', 20, yPos)
        pdf.setFont('helvetica', 'bold')
        // Set color based on status
        const status = giftCard.status.toLowerCase()
        if (status === 'active') {
            pdf.setTextColor(...green)
        } else if (status === 'failed' || status === 'expired') {
            pdf.setTextColor(220, 38, 38) // Red
        } else if (status === 'pending') {
            pdf.setTextColor(245, 158, 11) // Yellow/Orange
        } else {
            pdf.setTextColor(...darkGray) // Gray for used/inactive
        }
        pdf.text(giftCard.status.charAt(0).toUpperCase() + giftCard.status.slice(1), 80, yPos)
        yPos += 15

        // Gift Card Information Section
        pdf.setTextColor(...darkGray)
        pdf.setFontSize(14)
        pdf.setFont('helvetica', 'bold')
        pdf.text('Gift Card Information', 20, yPos)

        yPos += 10
        pdf.setDrawColor(...lightGray)
        pdf.line(20, yPos, 190, yPos)
        yPos += 8

        // Brand Name
        pdf.setFontSize(10)
        pdf.setFont('helvetica', 'normal')
        pdf.setTextColor(...darkGray)
        pdf.text('Brand:', 20, yPos)
        pdf.setFont('helvetica', 'bold')
        pdf.text(giftCard.brand_name || giftCard.brand || 'Gift Card', 80, yPos)
        yPos += 8

        // Amount
        pdf.setFont('helvetica', 'normal')
        pdf.text('Amount:', 20, yPos)
        pdf.setFont('helvetica', 'bold')
        pdf.setFontSize(14)
        pdf.setTextColor(...primaryColor)
        pdf.text(formatCurrency(giftCard.amount), 80, yPos)
        pdf.setFontSize(10)
        yPos += 8

        // Currency
        pdf.setFont('helvetica', 'normal')
        pdf.setTextColor(...darkGray)
        pdf.text('Currency:', 20, yPos)
        pdf.setFont('helvetica', 'bold')
        pdf.text(giftCard.currency || 'USD', 80, yPos)
        yPos += 8

        // Card Number - Prominently displayed
        if (credentials.card_number) {
            yPos += 10
            pdf.setFont('helvetica', 'normal')
            pdf.setTextColor(...darkGray)
            pdf.text('Card Number:', 20, yPos)
            yPos += 8

            // Card number in a highlighted box
            pdf.setFillColor(243, 244, 246) // Light gray background
            pdf.roundedRect(20, yPos - 3, 170, 12, 3, 3, 'F')

            pdf.setFont('helvetica', 'bold')
            pdf.setFontSize(16)
            pdf.setTextColor(...primaryColor)
            const formattedCardNumber = formatCardNumber(credentials.card_number) || credentials.card_number
            pdf.text(formattedCardNumber, 105, yPos + 6, { align: 'center' })

            pdf.setFontSize(10)
            yPos += 15
        }

        // PIN
        if (credentials.pin) {
            yPos += 5
            pdf.setFont('helvetica', 'normal')
            pdf.setTextColor(...darkGray)
            pdf.text('PIN:', 20, yPos)
            pdf.setFont('helvetica', 'bold')
            pdf.setFontSize(14)
            pdf.setTextColor(...primaryColor)
            pdf.text(credentials.pin, 80, yPos)
            pdf.setFontSize(10)
            yPos += 8
        }

        // Voucher Code
        if (credentials.voucher) {
            yPos += 5
            pdf.setFont('helvetica', 'normal')
            pdf.setTextColor(...darkGray)
            pdf.text('Voucher Code:', 20, yPos)
            pdf.setFont('helvetica', 'bold')
            pdf.setFontSize(12)
            pdf.setTextColor(...primaryColor)
            pdf.text(credentials.voucher, 80, yPos)
            pdf.setFontSize(10)
            yPos += 8
        }

        if (credentials.claim_url) {
            yPos += 5
            pdf.setFont('helvetica', 'normal')
            pdf.setTextColor(...darkGray)
            pdf.text('Redemption Link:', 20, yPos)
            yPos += 6
            pdf.setFont('helvetica', 'normal')
            pdf.setFontSize(9)
            pdf.setTextColor(...primaryColor)
            const claimLines = pdf.splitTextToSize(credentials.claim_url, 170)
            pdf.text(claimLines, 20, yPos)
            yPos += claimLines.length * 5 + 4
            pdf.setFontSize(10)
        }

        // Expiry Date - Prominently displayed
        if (giftCard.expires_at) {
            yPos += 8
            pdf.setFont('helvetica', 'normal')
            pdf.setTextColor(...darkGray)
            pdf.text('Expiry Date:', 20, yPos)
            pdf.setFont('helvetica', 'bold')
            pdf.setFontSize(14)
            const expiryDate = new Date(giftCard.expires_at)
            const isExpired = expiryDate < new Date()
            pdf.setTextColor(...(isExpired ? [220, 38, 38] : [34, 197, 94])) // Red if expired, green if valid
            pdf.text(expiryDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }), 80, yPos)
            if (isExpired) {
                yPos += 6
                pdf.setFontSize(10)
                pdf.setTextColor(220, 38, 38)
                pdf.text('(Expired)', 80, yPos)
                yPos -= 6
            }
            pdf.setFontSize(10)
            yPos += 8
        }

        yPos += 10

        // Customer Information Section
        if (user) {
            pdf.setTextColor(...darkGray)
            pdf.setFontSize(14)
            pdf.setFont('helvetica', 'bold')
            pdf.text('Customer Information', 20, yPos)

            yPos += 10
            pdf.setDrawColor(...lightGray)
            pdf.line(20, yPos, 190, yPos)
            yPos += 8

            pdf.setFontSize(10)
            pdf.setFont('helvetica', 'normal')
            pdf.text('Name:', 20, yPos)
            pdf.setFont('helvetica', 'bold')
            pdf.text(user.name, 80, yPos)
            yPos += 8

            pdf.setFont('helvetica', 'normal')
            pdf.text('Email:', 20, yPos)
            pdf.setFont('helvetica', 'bold')
            pdf.text(user.email, 80, yPos)
            yPos += 15
        }

        // Organization Information (if applicable)
        if (giftCard.organization) {
            pdf.setTextColor(...darkGray)
            pdf.setFontSize(14)
            pdf.setFont('helvetica', 'bold')
            pdf.text('Organization', 20, yPos)

            yPos += 10
            pdf.setDrawColor(...lightGray)
            pdf.line(20, yPos, 190, yPos)
            yPos += 8

            pdf.setFontSize(10)
            pdf.setFont('helvetica', 'normal')
            pdf.text('Organization Name:', 20, yPos)
            pdf.setFont('helvetica', 'bold')
            pdf.text(giftCard.organization.name, 80, yPos)
            yPos += 15
        }

        yPos += 10

        // Footer
        const pageHeight = pdf.internal.pageSize.height
        yPos = pageHeight - 30

        pdf.setDrawColor(...lightGray)
        pdf.line(20, yPos, 190, yPos)
        yPos += 10

        pdf.setFontSize(9)
        pdf.setFont('helvetica', 'italic')
        pdf.setTextColor(...lightGray)
        pdf.text('Thank you for your purchase!', 105, yPos, { align: 'center' })
        yPos += 6
        pdf.text('This is your official receipt. Please keep it for your records.', 105, yPos, { align: 'center' })
        yPos += 6
        pdf.text(`Generated on: ${new Date().toLocaleString('en-US')}`, 105, yPos, { align: 'center' })

        // Generate filename
        const date = giftCard.purchased_at
            ? new Date(giftCard.purchased_at).toISOString().split('T')[0]
            : new Date(giftCard.created_at).toISOString().split('T')[0]
        const filename = `Gift-Card-Receipt-${giftCard.id}-${date}.pdf`

        // Download PDF
        pdf.save(filename)
    }

    const getStatusBadge = (status: string) => {
        const statusConfig = {
            active: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Active' },
            completed: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Completed' },
            pending_fulfillment: { color: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200', label: 'Pending Fulfillment' },
            processing: { color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200', label: 'Processing' },
            capacity_reached: { color: 'bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-200', label: 'Capacity Reached' },
            pending: { color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200', label: 'Pending' },
            used: { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: 'Used' },
            expired: { color: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200', label: 'Expired' },
            failed: { color: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200', label: 'Failed' },
            inactive: { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: 'Inactive' },
        }

        const config = statusConfig[status as keyof typeof statusConfig] || statusConfig.pending

        return (
            <Badge className={config.color}>
                {config.label}
            </Badge>
        )
    }

    const getPaymentMethodBadge = (paymentMethod: string | null | undefined) => {
        if (!paymentMethod) return null

        const methodConfig = {
            stripe: { color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200', label: 'Card/Stripe' },
            believe_points: { color: 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-200', label: 'Believe Points' },
            bridge_wallet: { color: 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200', label: 'Believe Cash' },
        }

        const config = methodConfig[paymentMethod as keyof typeof methodConfig] || { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: paymentMethod }

        return (
            <Badge className={config.color} variant="outline">
                <CreditCard className="h-3 w-3 mr-1" />
                {config.label}
            </Badge>
        )
    }

    return (
        <ProfileLayout
            title={`${giftCard.brand_name || 'Gift Card'} Details`}
            description="View your gift card information"
        >
            <Head title={`${giftCard.brand_name || 'Gift Card'} Details`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Link href={route('gift-cards.my-cards')}>
                        <Button variant="ghost">
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back to My Cards
                        </Button>
                    </Link>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Main Details */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Gift Card Info */}
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="p-3 rounded-lg bg-primary/10">
                                            <Gift className="h-8 w-8 text-primary" />
                                        </div>
                                        <div>
                                            <CardTitle className="text-2xl">
                                                {giftCard.brand_name || giftCard.brand || 'Gift Card'}
                                            </CardTitle>
                                            <CardDescription>
                                                {giftCard.country && (
                                                    <div className="flex items-center gap-1 mt-1">
                                                        <Globe className="h-3 w-3" />
                                                        {giftCard.country}
                                                    </div>
                                                )}
                                            </CardDescription>
                                        </div>
                                    </div>
                                    {getStatusBadge(giftCard.status)}
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {isFailedStatus && (
                                    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-red-950 dark:border-red-800 dark:bg-red-950/30 dark:text-red-100">
                                        <div className="flex items-start gap-3">
                                            <AlertCircle className="mt-0.5 h-5 w-5 shrink-0" />
                                            <div className="space-y-1">
                                                <p className="font-semibold">Gift card issuance failed</p>
                                                <p className="text-sm">
                                                    {giftCard.failure_reason ||
                                                        (giftCard.payment_method === 'bridge_wallet'
                                                            ? 'We could not issue this gift card from the provider. Your Believe Cash charge remains recorded on this redemption — contact support or wait for an admin retry.'
                                                            : 'We could not issue this gift card from the provider. Your Believe Points remain recorded on this redemption — contact support or wait for an admin retry.')}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {isProcessingStatus && (
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                                        <div className="flex items-start gap-3">
                                            <Clock className="mt-0.5 h-5 w-5 shrink-0" />
                                            <div className="space-y-1">
                                                <p className="font-semibold">Your gift card is being prepared</p>
                                                <p className="text-sm">
                                                    Your payment was received. The gift card will be available within 72 hours.
                                                    We will email and notify you as soon as it is ready.
                                                </p>
                                                {giftCard.scheduled_fulfillment_at && (
                                                    <p className="text-sm">
                                                        Expected by: {formatDateTime(giftCard.scheduled_fulfillment_at)}
                                                    </p>
                                                )}
                                                {giftCard.failure_reason && (
                                                    <p className="text-sm text-amber-800 dark:text-amber-200">
                                                        Note: {giftCard.failure_reason}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Amount / purchase receipt */}
                                {(() => {
                                    const faceValue = Number(giftCard.meta?.gift_card_face_value ?? giftCard.amount) || Number(giftCard.amount) || 0
                                    const platformFee = Number(giftCard.meta?.platform_fee ?? giftCard.meta?.biu_fee ?? 0) || 0
                                    const supporterTip = Number(giftCard.meta?.supporter_tip ?? 0) || 0
                                    const totalCharged = Number(
                                        giftCard.meta?.gift_card_total_charged ?? faceValue + platformFee + supporterTip,
                                    ) || faceValue
                                    const hasExtraCharges = platformFee > 0 || supporterTip > 0

                                    return (
                                        <div className="space-y-3 p-6 rounded-lg bg-primary/5 border border-primary/20">
                                            <div className="flex items-center justify-between gap-4">
                                                <div>
                                                    <p className="text-sm text-muted-foreground mb-1">
                                                        {hasExtraCharges ? 'Total charged' : 'Gift Card Value'}
                                                    </p>
                                                    <p className="text-4xl font-bold text-primary">
                                                        {formatCurrency(totalCharged)}
                                                    </p>
                                                    {supporterTip > 0 && (
                                                        <p className="text-xs text-muted-foreground mt-1">
                                                            Includes organization tip
                                                        </p>
                                                    )}
                                                    {platformFee > 0 && supporterTip <= 0 && (
                                                        <p className="text-xs text-muted-foreground mt-1">
                                                            Includes platform fee
                                                        </p>
                                                    )}
                                                </div>
                                                <DollarSign className="h-12 w-12 text-primary opacity-50" />
                                            </div>
                                            {hasExtraCharges && (
                                                <div className="pt-3 border-t border-primary/20 space-y-1.5 text-sm">
                                                    <div className="flex justify-between">
                                                        <span className="text-muted-foreground">Gift card amount</span>
                                                        <span className="font-medium">{formatCurrency(faceValue)}</span>
                                                    </div>
                                                    {platformFee > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-muted-foreground">Platform fee</span>
                                                            <span className="font-medium">{formatCurrency(platformFee)}</span>
                                                        </div>
                                                    )}
                                                    {supporterTip > 0 && (
                                                        <div className="flex justify-between">
                                                            <span className="text-muted-foreground">Organization tip</span>
                                                            <span className="font-medium">{formatCurrency(supporterTip)}</span>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )
                                })()}

                                {/* Card Number — hide placeholders until Phaze issuance succeeds */}
                                {credentials.card_number && !isProcessingStatus && !isFailedStatus && (
                                    <div>
                                        <p className="text-sm font-medium mb-3">Card Number</p>
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1 p-4 rounded-lg bg-muted font-mono text-xl font-semibold text-center border-2 border-dashed">
                                                {formatCardNumber(credentials.card_number)}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="h-12 w-12"
                                                onClick={() => copyToClipboard(credentials.card_number!, 'card')}
                                            >
                                                {copied === 'card' ? (
                                                    <CheckCircle className="h-5 w-5 text-green-600" />
                                                ) : (
                                                    <Copy className="h-5 w-5" />
                                                )}
                                            </Button>
                                        </div>
                                        <p className="text-xs text-muted-foreground mt-2 text-center">
                                            {copied === 'card' ? '✓ Copied to clipboard!' : 'Click the copy button to copy this card number'}
                                        </p>
                                    </div>
                                )}

                                {/* PIN — required for online redemption on brands like Walmart */}
                                {credentials.pin && !isProcessingStatus && !isFailedStatus && (
                                    <div>
                                        <p className="text-sm font-medium mb-3">PIN</p>
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1 p-4 rounded-lg bg-muted font-mono text-xl font-semibold text-center border-2 border-dashed">
                                                {credentials.pin}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="h-12 w-12"
                                                onClick={() => copyToClipboard(credentials.pin!, 'pin')}
                                            >
                                                {copied === 'pin' ? (
                                                    <CheckCircle className="h-5 w-5 text-green-600" />
                                                ) : (
                                                    <Copy className="h-5 w-5" />
                                                )}
                                            </Button>
                                        </div>
                                        <p className="text-xs text-muted-foreground mt-2 text-center">
                                            {copied === 'pin' ? '✓ Copied to clipboard!' : 'Required for online checkout for some brands'}
                                        </p>
                                    </div>
                                )}

                                {/* Voucher Code */}
                                {credentials.voucher && !isProcessingStatus && !isFailedStatus && (
                                    <div>
                                        <p className="text-sm font-medium mb-3">Voucher Code</p>
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1 p-4 rounded-lg bg-muted font-mono text-xl font-semibold text-center border-2 border-dashed">
                                                {credentials.voucher}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="h-12 w-12"
                                                onClick={() => copyToClipboard(credentials.voucher!, 'voucher')}
                                            >
                                                {copied === 'voucher' ? (
                                                    <CheckCircle className="h-5 w-5 text-green-600" />
                                                ) : (
                                                    <Copy className="h-5 w-5" />
                                                )}
                                            </Button>
                                        </div>
                                        <p className="text-xs text-muted-foreground mt-2 text-center">
                                            {copied === 'voucher' ? '✓ Copied to clipboard!' : 'Click the copy button to copy this code'}
                                        </p>
                                    </div>
                                )}

                                {credentials.claim_url && !isProcessingStatus && !isFailedStatus && (
                                    <div>
                                        <p className="text-sm font-medium mb-2">Redemption Link</p>
                                        <a
                                            href={credentials.claim_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-sm text-primary underline break-all"
                                        >
                                            {credentials.claim_url}
                                        </a>
                                    </div>
                                )}

                                {(credentials.barcode_url || credentials.qr_code_url || credentials.barcode || credentials.qr_code) &&
                                    !isProcessingStatus &&
                                    !isFailedStatus && (
                                    <div className="space-y-3">
                                        <p className="text-sm font-medium">Barcode / QR Code</p>
                                        <div className="flex flex-wrap items-center gap-4">
                                            {credentials.barcode_url && (
                                                <img
                                                    src={credentials.barcode_url}
                                                    alt="Gift card barcode"
                                                    className="max-h-24 rounded border bg-white p-2"
                                                />
                                            )}
                                            {credentials.qr_code_url && (
                                                <img
                                                    src={credentials.qr_code_url}
                                                    alt="Gift card QR code"
                                                    className="max-h-32 rounded border bg-white p-2"
                                                />
                                            )}
                                        </div>
                                        {credentials.barcode && (
                                            <p className="font-mono text-sm break-all">{credentials.barcode}</p>
                                        )}
                                        {credentials.qr_code && !credentials.qr_code_url && (
                                            <p className="font-mono text-sm break-all">{credentials.qr_code}</p>
                                        )}
                                    </div>
                                )}

                                {(credentials.redemption_instructions || credentials.how_to_use) &&
                                    !isProcessingStatus &&
                                    !isFailedStatus && (
                                    <div className="rounded-lg border p-4 dark:border-gray-700">
                                        <p className="text-sm font-medium mb-2">Redemption Instructions</p>
                                        <div
                                            className="text-sm text-muted-foreground prose prose-sm max-w-none dark:prose-invert"
                                            dangerouslySetInnerHTML={{
                                                __html: credentials.redemption_instructions || credentials.how_to_use || '',
                                            }}
                                        />
                                    </div>
                                )}

                                {/* Details Grid */}
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                        <p className="text-xs text-muted-foreground mb-1">Currency</p>
                                        <p className="font-semibold dark:text-white">{giftCard.currency || 'USD'}</p>
                                    </div>
                                    {giftCard.purchased_at && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                <Calendar className="h-3 w-3" />
                                                Purchased Date
                                            </p>
                                            <p className="font-semibold dark:text-white">
                                                {new Date(giftCard.purchased_at).toLocaleDateString('en-US', {
                                                    year: 'numeric',
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit'
                                                })}
                                            </p>
                                        </div>
                                    )}
                                    {giftCard.payment_method && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-2 flex items-center gap-1">
                                                <CreditCard className="h-3 w-3" />
                                                Payment Method
                                            </p>
                                            {getPaymentMethodBadge(giftCard.payment_method)}
                                        </div>
                                    )}
                                    {giftCard.expires_at && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                <Calendar className="h-3 w-3" />
                                                Expiry Date
                                            </p>
                                            <p className={`font-semibold ${new Date(giftCard.expires_at) < new Date() ? 'text-red-600 dark:text-red-400' : 'dark:text-white'}`}>
                                                {new Date(giftCard.expires_at).toLocaleDateString('en-US', {
                                                    year: 'numeric',
                                                    month: 'short',
                                                    day: 'numeric'
                                                })}
                                            </p>
                                            {new Date(giftCard.expires_at) < new Date() && (
                                                <p className="text-xs text-red-600 dark:text-red-400 mt-1">Expired</p>
                                            )}
                                        </div>
                                    )}
                                    {giftCard.organization && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-1">Organization</p>
                                            <p className="font-semibold dark:text-white">{giftCard.organization.name}</p>
                                        </div>
                                    )}
                                    {giftCard.country && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                <Globe className="h-3 w-3" />
                                                Country
                                            </p>
                                            <p className="font-semibold dark:text-white">{giftCard.country}</p>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {(isProcessingStatus || fulfillmentAudit.length > 0 || giftCard.fulfilled_at) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 dark:text-white">
                                        <ListOrdered className="h-5 w-5" />
                                        Status & activity
                                    </CardTitle>
                                    <CardDescription>
                                        Track progress from purchase through fulfillment.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div className="rounded-lg border p-3 dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground">Current status</p>
                                            <div className="mt-1">{getStatusBadge(giftCard.status)}</div>
                                        </div>
                                        {giftCard.requested_at && (
                                            <div className="rounded-lg border p-3 dark:border-gray-700">
                                                <p className="text-xs text-muted-foreground">Requested</p>
                                                <p className="mt-1 text-sm font-medium">{formatDateTime(giftCard.requested_at)}</p>
                                            </div>
                                        )}
                                        {giftCard.scheduled_fulfillment_at && (
                                            <div className="rounded-lg border p-3 dark:border-gray-700">
                                                <p className="text-xs text-muted-foreground">Scheduled availability</p>
                                                <p className="mt-1 text-sm font-medium">{formatDateTime(giftCard.scheduled_fulfillment_at)}</p>
                                            </div>
                                        )}
                                        {giftCard.fulfilled_at && (
                                            <div className="rounded-lg border p-3 dark:border-gray-700">
                                                <p className="text-xs text-muted-foreground">Fulfilled</p>
                                                <p className="mt-1 text-sm font-medium">{formatDateTime(giftCard.fulfilled_at)}</p>
                                            </div>
                                        )}
                                    </div>

                                    {fulfillmentAudit.length > 0 && (
                                        <div className="space-y-3">
                                            <p className="text-sm font-medium">Activity log</p>
                                            <ol className="space-y-3 border-l border-border pl-4">
                                                {[...fulfillmentAudit].reverse().map((entry, index) => (
                                                    <li key={`${entry.event ?? 'event'}-${entry.at ?? index}`} className="relative">
                                                        <span className="absolute -left-[1.3rem] top-1.5 h-2.5 w-2.5 rounded-full bg-primary" />
                                                        <p className="text-sm font-medium dark:text-white">{formatAuditEvent(entry.event)}</p>
                                                        <p className="text-xs text-muted-foreground">{formatDateTime(entry.at) ?? '—'}</p>
                                                    </li>
                                                ))}
                                            </ol>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Brand Details from Meta */}
                        {(getMetaValue('productDescription') || getMetaValue('howToUse') || getMetaValue('termsAndConditions') || getMetaValue('expiryAndValidity')) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 dark:text-white">
                                        <FileText className="h-5 w-5" />
                                        Brand Information
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {getMetaValue('productDescription') && (
                                        <div>
                                            <h3 className="text-lg font-semibold mb-3 dark:text-white">About This Brand</h3>
                                            <div
                                                className="text-muted-foreground prose prose-sm max-w-none dark:prose-invert"
                                                dangerouslySetInnerHTML={{ __html: getMetaValue('productDescription') }}
                                            />
                                        </div>
                                    )}

                                    {getMetaValue('howToUse') && (
                                        <div>
                                            <h3 className="text-lg font-semibold mb-3 dark:text-white">How to Use</h3>
                                            <div
                                                className="text-muted-foreground prose prose-sm max-w-none dark:prose-invert"
                                                dangerouslySetInnerHTML={{ __html: getMetaValue('howToUse') }}
                                            />
                                        </div>
                                    )}

                                    {getMetaValue('expiryAndValidity') && (
                                        <div>
                                            <h3 className="text-lg font-semibold mb-3 dark:text-white">Expiry and Validity</h3>
                                            <div
                                                className="text-muted-foreground prose prose-sm max-w-none dark:prose-invert"
                                                dangerouslySetInnerHTML={{ __html: getMetaValue('expiryAndValidity') }}
                                            />
                                        </div>
                                    )}

                                    {getMetaValue('termsAndConditions') && (
                                        <div>
                                            <h3 className="text-lg font-semibold mb-3 dark:text-white">Terms and Conditions</h3>
                                            <div
                                                className="text-muted-foreground prose prose-sm max-w-none dark:prose-invert"
                                                dangerouslySetInnerHTML={{ __html: getMetaValue('termsAndConditions') }}
                                            />
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Phaze Purchase Details */}
                        {(phazePurchaseData || phazeDisbursementData) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 dark:text-white">
                                        <FileText className="h-5 w-5" />
                                        Phaze Purchase Details
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {phazePurchaseData && (
                                        <div>
                                            <h3 className="text-lg font-semibold mb-4 dark:text-white">Purchase Information</h3>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                {phazePurchaseData.id && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Transaction ID</p>
                                                        <p className="font-semibold dark:text-white font-mono text-sm">{phazePurchaseData.id}</p>
                                                    </div>
                                                )}
                                                {(phazePurchaseData.orderID || phazePurchaseData.orderId) && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Order ID</p>
                                                        <p className="font-semibold dark:text-white font-mono text-sm">{phazePurchaseData.orderID || phazePurchaseData.orderId}</p>
                                                    </div>
                                                )}
                                                {phazePurchaseData.status && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Status</p>
                                                        <Badge className={
                                                            isPhazeSuccessStatus(phazePurchaseData.status)
                                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200'
                                                                : isPhazeFailedStatus(phazePurchaseData.status)
                                                                ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
                                                                : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200'
                                                        }>
                                                            {labelPhazeStatus(phazePurchaseData.status)}
                                                        </Badge>
                                                    </div>
                                                )}
                                                {phazePurchaseData.productId && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Product ID</p>
                                                        <p className="font-semibold dark:text-white">{phazePurchaseData.productId}</p>
                                                    </div>
                                                )}
                                                {phazePurchaseData.denomination && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Denomination</p>
                                                        <p className="font-semibold dark:text-white">{formatCurrency(phazePurchaseData.denomination)}</p>
                                                    </div>
                                                )}
                                                {(phazePurchaseData.baseCurrency || phazePurchaseData.currency) && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Currency</p>
                                                        <p className="font-semibold dark:text-white">{phazePurchaseData.baseCurrency || phazePurchaseData.currency}</p>
                                                    </div>
                                                )}
                                                {phazePurchaseData.createdAt && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                            <Calendar className="h-3 w-3" />
                                                            Created At
                                                        </p>
                                                        <p className="font-semibold dark:text-white">
                                                            {new Date(phazePurchaseData.createdAt).toLocaleString('en-US', {
                                                                year: 'numeric',
                                                                month: 'short',
                                                                day: 'numeric',
                                                                hour: '2-digit',
                                                                minute: '2-digit'
                                                            })}
                                                        </p>
                                                    </div>
                                                )}
                                                {phazePurchaseData.updatedAt && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                            <Calendar className="h-3 w-3" />
                                                            Updated At
                                                        </p>
                                                        <p className="font-semibold dark:text-white">
                                                            {new Date(phazePurchaseData.updatedAt).toLocaleString('en-US', {
                                                                year: 'numeric',
                                                                month: 'short',
                                                                day: 'numeric',
                                                                hour: '2-digit',
                                                                minute: '2-digit'
                                                            })}
                                                        </p>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Additional Phaze purchase fields */}
                                            {(phazePurchaseData.externalUserId ||
                                                credentials.voucher ||
                                                credentials.card_number ||
                                                credentials.pin ||
                                                phazePurchaseData.error) && (
                                                <div className="mt-4 pt-4 border-t dark:border-gray-700">
                                                    <h4 className="text-sm font-semibold mb-3 dark:text-white">Additional Details</h4>
                                                    <div className="space-y-2 text-sm">
                                                        {phazePurchaseData.externalUserId && (
                                                            <div className="flex justify-between gap-4">
                                                                <span className="text-muted-foreground">External User ID:</span>
                                                                <span className="font-medium dark:text-white">{phazePurchaseData.externalUserId}</span>
                                                            </div>
                                                        )}
                                                        {credentials.voucher && (
                                                            <div className="flex justify-between gap-4">
                                                                <span className="text-muted-foreground">Voucher Code:</span>
                                                                <span className="font-medium dark:text-white font-mono">{credentials.voucher}</span>
                                                            </div>
                                                        )}
                                                        {credentials.card_number && (
                                                            <div className="flex justify-between gap-4">
                                                                <span className="text-muted-foreground">Card Number:</span>
                                                                <span className="font-medium dark:text-white font-mono">{formatCardNumber(credentials.card_number)}</span>
                                                            </div>
                                                        )}
                                                        {credentials.pin && (
                                                            <div className="flex justify-between gap-4">
                                                                <span className="text-muted-foreground">PIN:</span>
                                                                <span className="font-medium dark:text-white font-mono">{credentials.pin}</span>
                                                            </div>
                                                        )}
                                                        {phazePurchaseData.error && (
                                                            <div className="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                                                                <p className="text-xs font-semibold text-red-800 dark:text-red-200 mb-1">Error</p>
                                                                <p className="text-sm text-red-700 dark:text-red-300">{phazePurchaseData.error}</p>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}

                                            {showAllProviderFields && phazeFlatFields && Object.keys(phazeFlatFields).length > 0 && (
                                                <div className="mt-4 pt-4 border-t dark:border-gray-700">
                                                    <h4 className="text-sm font-semibold mb-3 dark:text-white">All Provider Fields</h4>
                                                    <div className="space-y-2 text-sm">
                                                        {Object.entries(phazeFlatFields).map(([key, value]) => (
                                                            <div key={key} className="flex justify-between gap-4">
                                                                <span className="text-muted-foreground shrink-0">{labelPhazeField(key)}</span>
                                                                <span className="font-medium dark:text-white font-mono text-right break-all">{String(value)}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {phazeDisbursementData && (
                                        <div className="pt-4 border-t dark:border-gray-700">
                                            <h3 className="text-lg font-semibold mb-4 dark:text-white">Disbursement Information</h3>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                {phazeDisbursementData.id && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Disbursement ID</p>
                                                        <p className="font-semibold dark:text-white font-mono text-sm">{phazeDisbursementData.id}</p>
                                                    </div>
                                                )}
                                                {phazeDisbursementData.status && (
                                                    <div className="p-4 rounded-lg border dark:border-gray-700">
                                                        <p className="text-xs text-muted-foreground mb-1">Status</p>
                                                        <Badge className={
                                                            phazeDisbursementData.status === 'completed' || phazeDisbursementData.status === 'success'
                                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200'
                                                                : phazeDisbursementData.status === 'failed'
                                                                ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
                                                                : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200'
                                                        }>
                                                            {phazeDisbursementData.status.charAt(0).toUpperCase() + phazeDisbursementData.status.slice(1)}
                                                        </Badge>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Sidebar Actions */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-lg">Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {credentials.voucher && (
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => copyToClipboard(credentials.voucher!, 'voucher')}
                                    >
                                        <Copy className="h-4 w-4 mr-2" />
                                        Copy Voucher Code
                                    </Button>
                                )}
                                {credentials.card_number && (
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => copyToClipboard(credentials.card_number!, 'card')}
                                    >
                                        <Copy className="h-4 w-4 mr-2" />
                                        Copy Card Number
                                    </Button>
                                )}
                                {credentials.pin && (
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => copyToClipboard(credentials.pin!, 'pin')}
                                    >
                                        <Copy className="h-4 w-4 mr-2" />
                                        Copy PIN
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    className="w-full justify-start"
                                    onClick={generatePDFReceipt}
                                >
                                    <Download className="h-4 w-4 mr-2" />
                                    Download PDF Receipt
                                </Button>
                            </CardContent>
                        </Card>

                        <Card className="bg-muted/50">
                            <CardHeader>
                                <CardTitle className="text-lg">Need Help?</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-muted-foreground">
                                    If you have questions about using this gift card, please contact our support team.
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </ProfileLayout>
    )
}


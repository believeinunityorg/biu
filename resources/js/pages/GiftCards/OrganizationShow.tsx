"use client"

import { Head, Link } from "@inertiajs/react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import {
    Gift,
    ArrowLeft,
    Copy,
    CheckCircle,
    Calendar,
    DollarSign,
    Globe,
    FileText,
    User,
    Building2,
    CreditCard
} from "lucide-react"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import { useState } from "react"

interface GiftCard {
    id: number
    voucher: string | null
    card_number: string | null
    amount: number
    platform_fee?: number | null
    platform_fee_biu_share?: number | null
    platform_fee_org_share?: number | null
    commission_percentage?: number | null
    total_commission?: number | null
    platform_commission?: number | null
    nonprofit_commission?: number | null
    brand: string | null
    brand_name: string | null
    country: string | null
    currency: string
    status: string
    purchased_at: string | null
    expires_at: string | null
    created_at: string
    payment_method?: string | null
    meta: any
    organization?: {
        id: number
        name: string
    } | null
}

interface OrganizationShowProps {
    giftCard: GiftCard
    phazePurchaseData?: any
    phazeDisbursementData?: any
    user?: {
        name: string
        email: string
    } | null
    organization?: {
        id: number
        name: string
    } | null
}

export default function OrganizationShowPage({ giftCard, phazePurchaseData, phazeDisbursementData, user, organization }: OrganizationShowProps) {
    const [copied, setCopied] = useState<string | null>(null)

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: giftCard.currency || 'USD',
        }).format(amount)
    }

    // Format commission with more decimal places for very small values
    const formatCommission = (amount: number | null | undefined) => {
        if (amount === null || amount === undefined) return 'N/A'

        // For very small amounts (< 0.01), show more decimal places
        if (amount > 0 && amount < 0.01) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: giftCard.currency || 'USD',
                minimumFractionDigits: 4,
                maximumFractionDigits: 8,
            }).format(amount)
        }

        // For normal amounts, use standard currency formatting
        return formatCurrency(amount)
    }

    const copyToClipboard = (text: string, type: string) => {
        navigator.clipboard.writeText(text)
        setCopied(type)
        setTimeout(() => setCopied(null), 2000)
    }

    const formatCardNumber = (cardNumber: string | null) => {
        if (!cardNumber) return null
        // Format as XXXX-XXXX-XXXX-XXXX
        return cardNumber.replace(/(\d{4})(?=\d)/g, '$1-')
    }

    const getMetaValue = (key: string) => {
        return giftCard.meta?.[key] || null
    }

    const getStatusBadge = (status: string) => {
        const statusConfig = {
            active: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Active' },
            pending: { color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200', label: 'Pending' },
            used: { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: 'Used' },
            expired: { color: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200', label: 'Expired' },
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
        <AppSidebarLayout>
            <Head title={`${giftCard.brand_name || 'Gift Card'} Details`} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl py-4 px-4 md:py-6 md:px-10">
                {/* Header */}
                <div className="flex items-center gap-4">
                    <Link href={route('gift-cards.created')}>
                        <Button variant="ghost">
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back to Purchased Cards
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
                                {/* Amount */}
                                <div className="flex items-center justify-between p-6 rounded-lg bg-primary/5 border border-primary/20">
                                    <div>
                                        <p className="text-sm text-muted-foreground mb-1">Gift Card Value</p>
                                        <p className="text-4xl font-bold text-primary">
                                            {formatCurrency(giftCard.amount)}
                                        </p>
                                    </div>
                                    <DollarSign className="h-12 w-12 text-primary opacity-50" />
                                </div>

                                {/* Card Number */}
                                {giftCard.card_number && (
                                    <div>
                                        <p className="text-sm font-medium mb-3">Card Number</p>
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1 p-4 rounded-lg bg-muted font-mono text-xl font-semibold text-center border-2 border-dashed">
                                                {formatCardNumber(giftCard.card_number)}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="h-12 w-12"
                                                onClick={() => copyToClipboard(giftCard.card_number!, 'card')}
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

                                {/* Voucher Code */}
                                {giftCard.voucher && (
                                    <div>
                                        <p className="text-sm font-medium mb-3">Voucher Code</p>
                                        <div className="flex items-center gap-2">
                                            <div className="flex-1 p-4 rounded-lg bg-muted font-mono text-xl font-semibold text-center border-2 border-dashed">
                                                {giftCard.voucher}
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="h-12 w-12"
                                                onClick={() => copyToClipboard(giftCard.voucher!, 'voucher')}
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

                                {/* Earnings Information */}
                                {(giftCard.platform_fee_org_share != null || giftCard.nonprofit_commission != null) && (
                                    <div className="p-6 rounded-lg bg-green-50 dark:bg-green-900/20 border-2 border-green-200 dark:border-green-800 space-y-4">
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <p className="text-sm text-green-800 dark:text-green-200 mb-1 font-medium">Your Organization Earnings</p>
                                                <p className="text-3xl font-bold text-green-700 dark:text-green-300">
                                                    {formatCommission(
                                                        (Number(giftCard.platform_fee_org_share) || 0) +
                                                        (Number(giftCard.nonprofit_commission) || 0)
                                                    )}
                                                </p>
                                            </div>
                                            <DollarSign className="h-12 w-12 text-green-600 dark:text-green-400 opacity-50" />
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                            {giftCard.platform_fee != null && (
                                                <div className="rounded-md bg-white/60 dark:bg-black/20 p-3">
                                                    <p className="text-green-800/80 dark:text-green-200/80">Platform fee (50% share)</p>
                                                    <p className="font-semibold text-green-800 dark:text-green-200">
                                                        {formatCommission(giftCard.platform_fee_org_share ?? 0)}
                                                        <span className="ml-1 text-xs font-normal opacity-80">
                                                            of {formatCommission(giftCard.platform_fee)}
                                                        </span>
                                                    </p>
                                                </div>
                                            )}
                                            {giftCard.nonprofit_commission != null && (
                                                <div className="rounded-md bg-white/60 dark:bg-black/20 p-3">
                                                    <p className="text-green-800/80 dark:text-green-200/80">Provider commission share</p>
                                                    <p className="font-semibold text-green-800 dark:text-green-200">
                                                        {formatCommission(giftCard.nonprofit_commission)}
                                                    </p>
                                                    {giftCard.total_commission && giftCard.commission_percentage && (
                                                        <p className="text-xs text-green-600 dark:text-green-400 mt-1">
                                                            From {giftCard.commission_percentage}% on {formatCurrency(giftCard.amount)}
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
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
                                    {user && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                <User className="h-3 w-3" />
                                                Purchased By
                                            </p>
                                            <p className="font-semibold dark:text-white">{user.name}</p>
                                            <p className="text-xs text-muted-foreground">{user.email}</p>
                                        </div>
                                    )}
                                    {organization && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-1 flex items-center gap-1">
                                                <Building2 className="h-3 w-3" />
                                                Organization
                                            </p>
                                            <p className="font-semibold dark:text-white">{organization.name}</p>
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
                                    {giftCard.payment_method && (
                                        <div className="p-4 rounded-lg border dark:border-gray-700">
                                            <p className="text-xs text-muted-foreground mb-2 flex items-center gap-1">
                                                <CreditCard className="h-3 w-3" />
                                                Payment Method
                                            </p>
                                            {getPaymentMethodBadge(giftCard.payment_method)}
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

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
                                                            phazePurchaseData.status === 'completed' || phazePurchaseData.status === 'success'
                                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200'
                                                                : phazePurchaseData.status === 'failed'
                                                                ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
                                                                : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200'
                                                        }>
                                                            {phazePurchaseData.status.charAt(0).toUpperCase() + phazePurchaseData.status.slice(1)}
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
                                            {(phazePurchaseData.externalUserId || phazePurchaseData.voucher || phazePurchaseData.cardNumber || phazePurchaseData.card_number || phazePurchaseData.error) && (
                                                <div className="mt-4 pt-4 border-t dark:border-gray-700">
                                                    <h4 className="text-sm font-semibold mb-3 dark:text-white">Additional Details</h4>
                                                    <div className="space-y-2 text-sm">
                                                        {phazePurchaseData.externalUserId && (
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">External User ID:</span>
                                                                <span className="font-medium dark:text-white">{phazePurchaseData.externalUserId}</span>
                                                            </div>
                                                        )}
                                                        {phazePurchaseData.voucher && (
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">Voucher Code:</span>
                                                                <span className="font-medium dark:text-white font-mono">{phazePurchaseData.voucher}</span>
                                                            </div>
                                                        )}
                                                        {(phazePurchaseData.cardNumber || phazePurchaseData.card_number) && (
                                                            <div className="flex justify-between">
                                                                <span className="text-muted-foreground">Card Number:</span>
                                                                <span className="font-medium dark:text-white font-mono">{formatCardNumber(phazePurchaseData.cardNumber || phazePurchaseData.card_number)}</span>
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
                                {giftCard.voucher && (
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => copyToClipboard(giftCard.voucher!, 'voucher')}
                                    >
                                        <Copy className="h-4 w-4 mr-2" />
                                        Copy Voucher Code
                                    </Button>
                                )}
                                {giftCard.card_number && (
                                    <Button
                                        variant="outline"
                                        className="w-full justify-start"
                                        onClick={() => copyToClipboard(giftCard.card_number!, 'card')}
                                    >
                                        <Copy className="h-4 w-4 mr-2" />
                                        Copy Card Number
                                    </Button>
                                )}
                            </CardContent>
                        </Card>

                        <Card className="bg-muted/50">
                            <CardHeader>
                                <CardTitle className="text-lg">Need Help?</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-muted-foreground">
                                    If you have questions about this gift card, please contact our support team.
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppSidebarLayout>
    )
}


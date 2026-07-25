"use client"

import { Head, usePage, router, useForm, Link } from "@inertiajs/react"
import { useEffect } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
    Gift,
    Calendar,
    DollarSign,
    Eye,
    User,
    Building2,
    AlertCircle,
    CheckCircle,
    FileText,
    ChevronLeft,
    ChevronRight,
    CreditCard,
    RefreshCw,
    Play,
} from "lucide-react"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import { format } from "date-fns"
import toast from "react-hot-toast"
import InputError from "@/components/input-error"
interface GiftCard {
    id: number
    voucher: string | null
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
    currency: string
    status: string
    created_at: string
    purchased_at: string | null
    scheduled_fulfillment_at?: string | null
    failure_reason?: string | null
    payment_method?: string | null
    user?: {
        id: number
        name: string
        email: string
    }
    organization?: {
        id: number
        name: string
    }
}

interface EarningsSummary {
    platform_fee: number
    platform_fee_biu_share: number
    platform_fee_org_share: number
    platform_commission: number
    nonprofit_commission: number
    biu_total: number
    organization_total: number
}

interface CreatedCardsProps {
    giftCards: {
        data: GiftCard[]
        current_page: number
        last_page: number
        per_page: number
        total: number
        from?: number
        to?: number
        links?: Array<{
            url: string | null
            label: string
            active: boolean
        }>
        prev_page_url?: string | null
        next_page_url?: string | null
    }
    earningsSummary?: EarningsSummary
    organization: {
        id: number
        name: string
        gift_card_terms_approved?: boolean
        gift_card_terms_approved_at?: string
    } | null
    isAdmin?: boolean
}

export default function CreatedCardsPage({ giftCards, earningsSummary, organization, isAdmin = false }: CreatedCardsProps) {
    const page = usePage<{
        flash?: { success?: string }
        errors?: Record<string, string>
    }>()
    const flash = page.props.flash || {}
    const actionError = page.props.errors?.retry || page.props.errors?.force_fulfill || null
    const { auth } = page.props as any

    // Form for gift card terms approval
    const { data, setData, patch, processing, errors } = useForm({
        gift_card_terms_approved: organization?.gift_card_terms_approved || false,
    })

    const canAdminRetry = (card: GiftCard) =>
        isAdmin &&
        card.payment_method === 'believe_points' &&
        (card.status === 'failed' || card.status === 'capacity_reached')

    const canAdminForceFulfill = (card: GiftCard) =>
        isAdmin &&
        card.payment_method === 'believe_points' &&
        (card.status === 'pending_fulfillment' || card.status === 'processing')

    const retryFulfillment = (id: number) => {
        router.post(route('admin.gift-card-redemptions.retry', id), {}, {
            preserveScroll: true,
            onSuccess: (visit) => {
                const errs = (visit.props as { errors?: Record<string, string> }).errors
                if (errs?.retry) {
                    toast.error(errs.retry)
                    return
                }
                toast.success('Retry finished — check the card status.')
            },
            onError: (errs) => toast.error(errs.retry || 'Retry failed. Check Phaze credentials/balance.'),
        })
    }

    const forceFulfill = (id: number) => {
        if (!window.confirm('Fulfill this gift card now via Phaze?')) {
            return
        }
        router.post(route('admin.gift-card-redemptions.force-fulfill', id), {}, {
            preserveScroll: true,
            onSuccess: (visit) => {
                const errs = (visit.props as { errors?: Record<string, string> }).errors
                if (errs?.force_fulfill) {
                    toast.error(errs.force_fulfill)
                    return
                }
                toast.success('Fulfillment finished — check the card status.')
            },
            onError: (errs) => toast.error(errs.force_fulfill || 'Fulfillment failed. Check Phaze credentials/balance.'),
        })
    }

    const handleTermsSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        patch(route('profile.gift-card-terms.update'), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Gift card terms updated successfully!')
                // Refresh the page to get updated organization data
                router.reload({ only: ['organization'] })
            },
            onError: (errors) => {
                console.error('Errors:', errors)
                toast.error('Failed to update gift card terms')
            }
        })
    }

    const formatCurrency = (amount: number, currency: string = 'USD') => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
        }).format(amount)
    }

    // Format commission with more decimal places for very small values
    const formatCommission = (amount: number | null | undefined, currency: string = 'USD') => {
        if (amount === null || amount === undefined) return 'N/A'

        // For very small amounts (< 0.01), show more decimal places
        if (amount > 0 && amount < 0.01) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency,
                minimumFractionDigits: 4,
                maximumFractionDigits: 8,
            }).format(amount)
        }

        // For normal amounts, use standard currency formatting
        return formatCurrency(amount, currency)
    }

    const getStatusBadge = (status: string) => {
        const statusConfig = {
            active: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Active' },
            completed: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Completed' },
            pending_fulfillment: { color: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200', label: 'Pending Fulfillment' },
            processing: { color: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200', label: 'Processing' },
            capacity_reached: { color: 'bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-200', label: 'Capacity Reached' },
            inactive: { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: 'Inactive' },
            pending: { color: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200', label: 'Pending' },
            used: { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: 'Used' },
            expired: { color: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200', label: 'Expired' },
            failed: { color: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200', label: 'Failed' },
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

    // Removed delete and toggle functions since all cards here are purchased

    return (
        <AppSidebarLayout>
            <Head title="Purchased Gift Cards" />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl py-4 px-4 md:py-6 md:px-10">
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Main Content */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Header */}
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h1 className="text-3xl font-bold flex items-center gap-2">
                                    <Gift className="h-8 w-8" />
                                    Purchased Gift Cards
                                </h1>
                                <p className="text-muted-foreground mt-1">
                                    {isAdmin
                                        ? 'All gift cards purchased for all organizations'
                                        : organization
                                            ? `Gift cards purchased for ${organization.name}`
                                            : 'All gift cards purchased for organizations'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-xl"
                                    onClick={() => router.visit(route('gift-cards.index'))}
                                >
                                    Browse gift cards
                                </Button>
                                <Button
                                    type="button"
                                    className="rounded-xl bg-gradient-to-r from-violet-600 to-blue-600 font-semibold text-white hover:from-violet-700 hover:to-blue-700"
                                    onClick={() => router.visit('/gift-bp')}
                                >
                                    <Gift className="mr-2 h-4 w-4" />
                                    Gift BP
                                </Button>
                            </div>
                        </div>

                        {isAdmin && (
                            <Alert className="border-blue-200 bg-blue-50 text-blue-950 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-100">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>
                                    Failed Believe Points cards can be retried here, or from{' '}
                                    <Link
                                        href={route('admin.gift-card-redemptions.index', { status: 'failed' })}
                                        className="font-medium underline underline-offset-2"
                                    >
                                        System → Gift card redemptions
                                    </Link>
                                    . Phaze must have a real prefunded balance before retry succeeds.
                                </AlertDescription>
                            </Alert>
                        )}

                        {flash.success && (
                            <Alert className="border-green-200 bg-green-50 text-green-950 dark:border-green-800 dark:bg-green-950/30 dark:text-green-100">
                                <CheckCircle className="h-4 w-4" />
                                <AlertDescription>{flash.success}</AlertDescription>
                            </Alert>
                        )}

                        {actionError && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>{actionError}</AlertDescription>
                            </Alert>
                        )}

                        {/* Stats */}
                        <div className={`grid grid-cols-1 sm:grid-cols-2 ${isAdmin ? 'lg:grid-cols-4' : 'lg:grid-cols-3'} gap-4`}>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Purchased</CardTitle>
                            <Gift className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{giftCards.total}</div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Gift cards purchased
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Buyer Platform Fees</CardTitle>
                            <DollarSign className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {formatCommission(earningsSummary?.platform_fee ?? 0, giftCards.data[0]?.currency || 'USD')}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Split 50% BIU / 50% organization
                            </p>
                        </CardContent>
                    </Card>

                    {isAdmin ? (
                        <Card className="border-l-4 border-l-blue-500">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">BIU Total Earnings</CardTitle>
                                <DollarSign className="h-4 w-4 text-blue-500" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                    {formatCommission(earningsSummary?.biu_total ?? 0, giftCards.data[0]?.currency || 'USD')}
                                </div>
                                <p className="text-xs text-muted-foreground mt-1">
                                    Fee share {formatCommission(earningsSummary?.platform_fee_biu_share ?? 0)} + provider {formatCommission(earningsSummary?.platform_commission ?? 0)}
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="border-l-4 border-l-green-500">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                <CardTitle className="text-sm font-medium">Your Total Earnings</CardTitle>
                                <DollarSign className="h-4 w-4 text-green-500" />
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-green-600 dark:text-green-400">
                                    {formatCommission(earningsSummary?.organization_total ?? 0, giftCards.data[0]?.currency || 'USD')}
                                </div>
                                <p className="text-xs text-muted-foreground mt-1">
                                    Fee share {formatCommission(earningsSummary?.platform_fee_org_share ?? 0)} + provider {formatCommission(earningsSummary?.nonprofit_commission ?? 0)}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active Cards</CardTitle>
                            <Building2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {giftCards.data.filter(c => c.status === 'active' || c.status === 'completed').length}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Currently active
                            </p>
                        </CardContent>
                    </Card>
                </div>

                        {/* Gift Cards List */}
                        <Card>
                    <CardHeader>
                        <CardTitle>Purchased Gift Cards ({giftCards.total})</CardTitle>
                        <CardDescription>
                            {isAdmin
                                ? 'Complete list of all gift cards purchased for all organizations'
                                : 'Complete list of all gift cards purchased for your organization'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {giftCards.data.length === 0 ? (
                            <div className="text-center py-12 text-muted-foreground">
                                <Gift className="h-12 w-12 mx-auto mb-4 opacity-50" />
                                <p>No purchased gift cards yet</p>
                                <p className="text-sm mt-2">
                                    Gift cards purchased for your organization will appear here
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {giftCards.data.map((card) => (
                                    <div
                                        key={card.id}
                                        className="flex flex-col sm:flex-row sm:items-center gap-4 p-4 border rounded-xl hover:shadow-md transition-all duration-300 bg-card"
                                    >
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-3 mb-2">
                                                <div className="flex-shrink-0">
                                                    <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                                        <Gift className="h-5 w-5 text-primary" />
                                                    </div>
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <p className="font-semibold text-base truncate">
                                                        {card.brand_name || card.brand || 'Gift Card'}
                                                    </p>
                                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                        {card.organization && (
                                                            <>
                                                                <Building2 className="h-3 w-3" />
                                                                <span>{card.organization.name}</span>
                                                            </>
                                                        )}
                                                        {card.user && (
                                                            <>
                                                                <User className="h-3 w-3 ml-2" />
                                                                <span>{card.user.name}</span>
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            {card.voucher && (
                                                <p className="text-xs text-muted-foreground font-mono pl-13">
                                                    Voucher: {card.voucher}
                                                </p>
                                            )}
                                            {card.status === 'pending_fulfillment' && card.failure_reason && (
                                                <p className="text-xs text-amber-700 dark:text-amber-300 mt-1 pl-13">
                                                    Issuance delayed — {card.failure_reason}
                                                </p>
                                            )}
                                            {card.status === 'failed' && (
                                                <p className="text-xs text-red-700 dark:text-red-300 mt-1 pl-13">
                                                    Failed — {card.failure_reason || 'Gift card could not be issued from the provider. An admin can retry fulfillment.'}
                                                </p>
                                            )}
                                            {card.status === 'capacity_reached' && (
                                                <p className="text-xs text-orange-700 dark:text-orange-300 mt-1 pl-13">
                                                    Capacity reached — {card.failure_reason || 'Gift card reserve capacity was reached. Please try again later.'}
                                                </p>
                                            )}
                                        </div>

                                        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 flex-shrink-0">
                                            <div className="text-right">
                                                <p className="text-2xl font-bold text-primary">
                                                    {formatCurrency(card.amount, card.currency)}
                                                </p>
                                                {card.platform_fee != null && Number(card.platform_fee) > 0 && (
                                                    <p className="text-xs text-muted-foreground mt-1">
                                                        Platform fee: {formatCommission(card.platform_fee, card.currency)}
                                                        {card.platform_fee_org_share != null && (
                                                            <> (org {formatCommission(card.platform_fee_org_share, card.currency)} / BIU {formatCommission(card.platform_fee_biu_share ?? 0, card.currency)})</>
                                                        )}
                                                    </p>
                                                )}
                                                {isAdmin && card.platform_commission !== null && card.platform_commission !== undefined && (
                                                    <p className="text-sm font-semibold text-blue-600 dark:text-blue-400 mt-1">
                                                        BIU provider: {formatCommission(card.platform_commission, card.currency)}
                                                    </p>
                                                )}
                                                {!isAdmin && (
                                                    <p className="text-sm font-semibold text-green-600 dark:text-green-400 mt-1">
                                                        Your earnings: {formatCommission(
                                                            (Number(card.platform_fee_org_share) || 0) + (Number(card.nonprofit_commission) || 0),
                                                            card.currency
                                                        )}
                                                    </p>
                                                )}
                                                {isAdmin && card.nonprofit_commission !== null && card.nonprofit_commission !== undefined && (
                                                    <p className="text-sm font-semibold text-green-600 dark:text-green-400 mt-1">
                                                        Org provider: {formatCommission(card.nonprofit_commission, card.currency)}
                                                    </p>
                                                )}
                                                <p className="text-xs text-muted-foreground flex items-center gap-1 mt-1">
                                                    <Calendar className="h-3 w-3" />
                                                    {format(new Date(card.created_at), 'MMM dd, yyyy')}
                                                </p>
                                            </div>
                                            <div className="flex flex-col gap-2">
                                                {getStatusBadge(card.status)}
                                                {getPaymentMethodBadge(card.payment_method)}
                                                <div className="flex flex-col gap-2 mt-2 min-w-[9.5rem]">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => router.visit(route('gift-cards.show.id', card.id))}
                                                    >
                                                        <Eye className="h-3 w-3 mr-1" />
                                                        View Details
                                                    </Button>
                                                    {canAdminRetry(card) && (
                                                        <Button
                                                            size="sm"
                                                            variant="default"
                                                            onClick={() => retryFulfillment(card.id)}
                                                        >
                                                            <RefreshCw className="h-3 w-3 mr-1" />
                                                            Retry Phaze
                                                        </Button>
                                                    )}
                                                    {canAdminForceFulfill(card) && (
                                                        <Button
                                                            size="sm"
                                                            variant="default"
                                                            onClick={() => forceFulfill(card.id)}
                                                        >
                                                            <Play className="h-3 w-3 mr-1" />
                                                            Fulfill now
                                                        </Button>
                                                    )}
                                                </div>
                                                {card.purchased_at && (
                                                    <div className="text-xs text-muted-foreground mt-1">
                                                        Purchased on {format(new Date(card.purchased_at), 'MMM dd, yyyy')}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Pagination */}
                        {giftCards.last_page > 1 && (
                            <div className="mt-6 pt-6 border-t flex flex-col sm:flex-row justify-between items-center gap-4">
                                <div className="text-sm text-muted-foreground">
                                    Showing <span className="font-semibold">{giftCards.from || 0}</span> to{' '}
                                    <span className="font-semibold">{giftCards.to || 0}</span> of{' '}
                                    <span className="font-semibold">{giftCards.total}</span> gift cards
                                </div>

                                <div className="flex items-center gap-2">
                                    {/* Previous Button */}
                                    {giftCards.prev_page_url && (
                                        <Link href={giftCards.prev_page_url}>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="flex items-center gap-1"
                                            >
                                                <ChevronLeft className="h-4 w-4" />
                                                Previous
                                            </Button>
                                        </Link>
                                    )}

                                    {/* Page Numbers */}
                                    {giftCards.links && giftCards.links.length > 0 && (
                                        <div className="flex items-center gap-1">
                                            {giftCards.links
                                                .filter((link) => {
                                                    // Only show numeric page numbers (not "Previous", "Next", etc.)
                                                    const label = link.label?.trim() || '';
                                                    return link.url && !isNaN(Number(label)) && label !== '';
                                                })
                                                .map((link, index) => {
                                                    const pageNum = Number(link.label);
                                                    return (
                                                        <div key={`page-${pageNum}-${index}`}>
                                                            {link.url ? (
                                                                <Link href={link.url}>
                                                                    <Button
                                                                        variant={link.active ? "default" : "outline"}
                                                                        size="sm"
                                                                        className={`min-w-10 ${
                                                                            link.active
                                                                                ? "bg-primary text-primary-foreground"
                                                                                : ""
                                                                        }`}
                                                                    >
                                                                        {link.label}
                                                                    </Button>
                                                                </Link>
                                                            ) : (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled
                                                                    className="min-w-10"
                                                                >
                                                                    {link.label}
                                                                </Button>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                        </div>
                                    )}

                                    {/* Next Button */}
                                    {giftCards.next_page_url && (
                                        <Link href={giftCards.next_page_url}>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="flex items-center gap-1"
                                            >
                                                Next
                                                <ChevronRight className="h-4 w-4" />
                                            </Button>
                                        </Link>
                                    )}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
                    </div>

                    {/* Sidebar - Gift Card Terms (Organization Only) */}
                    {!isAdmin && auth?.user?.role === "organization" && (
                        <div className="lg:col-span-1 space-y-6 lg:sticky lg:top-4 lg:self-start lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">
                            <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-800 shadow-sm">
                                <CardHeader className="pb-4">
                                    <CardTitle className="text-gray-900 dark:text-white flex items-center gap-2">
                                        <Gift className="h-5 w-5 text-pink-500" />
                                        Gift Card Program Terms
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    <Alert className="bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800">
                                        <AlertCircle className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                                        <AlertDescription className="text-blue-800 dark:text-blue-200 text-sm">
                                            <strong>Important:</strong> Please review and approve the gift card program terms to enable gift card purchases for your organization.
                                        </AlertDescription>
                                    </Alert>

                                    <div className="bg-gray-50 dark:bg-gray-900/50 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                                        <h4 className="font-semibold text-gray-900 dark:text-white mb-3">Summary</h4>
                                        <p className="text-sm text-gray-700 dark:text-gray-300 leading-relaxed mb-4">
                                            Believe is not soliciting donations through gift card sales. Gift cards are sold at face value plus a fixed buyer platform fee. That platform fee is split evenly (50% Believe / 50% your organization). Separately, the gift card provider pays a commission; Believe retains 10% of that provider commission and distributes the remainder to participating nonprofits as earned fundraising revenue. Purchases are not tax-deductible, and all funds are reported as program-related income.
                                        </p>
                                    </div>

                                    <form onSubmit={handleTermsSubmit}>
                                        <div className="flex items-start space-x-3">
                                            <input
                                                type="checkbox"
                                                id="gift_card_terms_approved"
                                                checked={data.gift_card_terms_approved || false}
                                                onChange={(e) => setData("gift_card_terms_approved", e.target.checked)}
                                                className="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                            />
                                            <Label htmlFor="gift_card_terms_approved" className="text-gray-900 dark:text-white text-sm font-medium cursor-pointer">
                                                I understand and approve the gift card program terms as described above
                                            </Label>
                                        </div>
                                        <InputError message={errors.gift_card_terms_approved} className="mt-1" />

                                        {organization?.gift_card_terms_approved && organization?.gift_card_terms_approved_at && (
                                            <div className="text-sm text-green-600 dark:text-green-400 flex items-center gap-2 mt-3">
                                                <CheckCircle className="h-4 w-4" />
                                                Approved on {new Date(organization.gift_card_terms_approved_at).toLocaleDateString()}
                                            </div>
                                        )}

                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="w-full mt-4 bg-blue-600 hover:bg-blue-700 text-white"
                                        >
                                            {processing ? (
                                                <>
                                                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                                    Saving...
                                                </>
                                            ) : (
                                                <>
                                                    <FileText className="h-4 w-4 mr-2" />
                                                    Save Terms Approval
                                                </>
                                            )}
                                        </Button>
                                    </form>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </div>
            </div>

        </AppSidebarLayout>
    )
}


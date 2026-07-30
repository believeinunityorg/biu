"use client"

import { Head, Link } from "@inertiajs/react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Button } from "@/components/frontend/ui/button"
import { Badge } from "@/components/frontend/ui/badge"
import {
    Gift,
    ArrowRight,
    Calendar,
    DollarSign,
    Eye,
    Copy,
    CheckCircle,
    Download,
    CreditCard
} from "lucide-react"
import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import { useState } from "react"

interface GiftCard {
    id: number
    voucher: string | null
    card_number?: string | null
    pin?: string | null
    amount: number
    brand: string | null
    brand_name: string | null
    currency: string
    status: string
    purchased_at: string | null
    created_at: string
    payment_method?: string | null
    stripe_session_id?: string | null
    stripe_payment_intent_id?: string | null
}

interface MyCardsProps {
    giftCards: {
        data: GiftCard[]
        current_page: number
        last_page: number
        per_page: number
        total: number
    }
    user?: {
        name: string
        email: string
    }
}

export default function MyCardsPage({ giftCards, user }: MyCardsProps) {
    const [copiedId, setCopiedId] = useState<number | null>(null)

    const formatCurrency = (amount: number, currency: string = 'USD') => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
        }).format(amount)
    }

    const getStatusBadge = (status: string) => {
        const statusConfig = {
            active: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Active' },
            completed: { color: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200', label: 'Completed' },
            pending_fulfillment: { color: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200', label: 'Processing (within 72h)' },
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
        }

        const config = methodConfig[paymentMethod as keyof typeof methodConfig] || { color: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300', label: paymentMethod }

        return (
            <Badge className={config.color} variant="outline">
                <CreditCard className="h-3 w-3 mr-1" />
                {config.label}
            </Badge>
        )
    }

    const copyToClipboard = (text: string, id: number) => {
        navigator.clipboard.writeText(text)
        setCopiedId(id)
        setTimeout(() => setCopiedId(null), 2000)
    }

    const downloadPDFReceipt = (cardId: number) => {
        // Use server-side PDF generation using the updated blade template
        window.open(route('gift-cards.download-pdf', cardId), '_blank')
    }

    return (
        <ProfileLayout title="My Gift Cards" description="View and manage all your gift cards">
            <Head title="My Gift Cards" />

            <div className="space-y-6">
                {/* Primary actions */}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <Link
                        href={route('gift-cards.index')}
                        className="group flex items-center gap-4 rounded-2xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.06] to-blue-600/[0.06] p-4 shadow-sm transition hover:border-purple-500/40 hover:shadow-md dark:from-purple-500/10 dark:to-blue-500/10"
                    >
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/25">
                            <Gift className="h-6 w-6" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold text-slate-900 dark:text-white">Browse Gift Cards</p>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Explore and purchase from hundreds of top brands.
                            </p>
                        </div>
                        <ArrowRight className="h-5 w-5 shrink-0 text-purple-600 transition group-hover:translate-x-0.5" />
                    </Link>

                    <a
                        href="#my-cards-list"
                        className="group flex items-center gap-4 rounded-2xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.06] to-blue-600/[0.06] p-4 shadow-sm transition hover:border-purple-500/40 hover:shadow-md dark:from-purple-500/10 dark:to-blue-500/10"
                    >
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/25">
                            <CreditCard className="h-6 w-6" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="font-semibold text-slate-900 dark:text-white">My Cards</p>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                View card numbers, PINs, and receipts for cards you own.
                            </p>
                        </div>
                        <ArrowRight className="h-5 w-5 shrink-0 text-purple-600 transition group-hover:translate-x-0.5" />
                    </a>

                    <Link
                        href="/gift-bp"
                        className="group relative flex items-center gap-4 overflow-hidden rounded-2xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.06] to-blue-600/[0.06] p-4 shadow-sm transition hover:border-purple-500/40 hover:shadow-md dark:from-purple-500/10 dark:to-blue-500/10"
                    >
                        <Badge className="absolute right-3 top-3 border-0 bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-600 hover:to-blue-600">
                            New
                        </Badge>
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/25">
                            <Gift className="h-6 w-6" />
                        </div>
                        <div className="min-w-0 flex-1 pr-8">
                            <p className="font-semibold text-slate-900 dark:text-white">Gift BP</p>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Send Believe Points to anyone. They can choose any gift card they want.
                            </p>
                        </div>
                        <ArrowRight className="h-5 w-5 shrink-0 text-purple-600 transition group-hover:translate-x-0.5" />
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Cards</CardTitle>
                            <Gift className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{giftCards.total}</div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Gift cards owned
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Value</CardTitle>
                            <DollarSign className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {formatCurrency(
                                    giftCards.data.length > 0
                                        ? giftCards.data.reduce((sum, card) => {
                                              const amount = typeof card.amount === 'number' ? card.amount : parseFloat(card.amount) || 0;
                                              return sum + amount;
                                          }, 0)
                                        : 0,
                                    giftCards.data[0]?.currency || 'USD'
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Combined value
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Active Cards</CardTitle>
                            <CheckCircle className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {giftCards.data.filter(c => c.status === 'active' || c.status === 'completed').length}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Ready to use
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Gift Cards List */}
                <Card id="my-cards-list" className="scroll-mt-24">
                    <CardHeader>
                        <CardTitle>All Gift Cards ({giftCards.total})</CardTitle>
                        <CardDescription>
                            Your complete gift card collection
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {giftCards.data.length === 0 ? (
                            <div className="text-center py-12 text-muted-foreground">
                                <Gift className="h-12 w-12 mx-auto mb-4 opacity-50" />
                                <p>No gift cards yet</p>
                                <p className="text-sm mt-2">
                                    Start by purchasing your first gift card
                                </p>
                                <Link href={route('gift-cards.index')} className="mt-4 inline-block">
                                    <Button>Browse Gift Cards</Button>
                                </Link>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                {giftCards.data.map((card) => (
                                    <Card key={card.id} className="hover:shadow-lg transition-all duration-300">
                                        <CardHeader>
                                            <div className="flex items-start justify-between mb-2">
                                                <div className="flex-1">
                                                    <CardTitle className="text-lg mb-1">
                                                        {card.brand_name || card.brand || 'Gift Card'}
                                                    </CardTitle>
                                                    <CardDescription>
                                                        {formatCurrency(card.amount, card.currency)}
                                                    </CardDescription>
                                                </div>
                                                {getStatusBadge(card.status)}
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {!['pending_fulfillment', 'processing', 'capacity_reached', 'failed'].includes(card.status) && (
                                                <>
                                                    {card.card_number && (
                                                        <div>
                                                            <p className="text-xs text-muted-foreground mb-2">Card Number</p>
                                                            <div className="flex items-center gap-2">
                                                                <div className="flex-1 p-2 rounded bg-muted font-mono text-sm text-center">
                                                                    {card.card_number}
                                                                </div>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                    onClick={() => copyToClipboard(card.card_number!, card.id)}
                                                                >
                                                                    {copiedId === card.id ? (
                                                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                                                    ) : (
                                                                        <Copy className="h-4 w-4" />
                                                                    )}
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    )}

                                                    {card.pin && (
                                                        <div>
                                                            <p className="text-xs text-muted-foreground mb-2">PIN</p>
                                                            <div className="flex items-center gap-2">
                                                                <div className="flex-1 p-2 rounded bg-muted font-mono text-sm text-center">
                                                                    {card.pin}
                                                                </div>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                    onClick={() => copyToClipboard(card.pin!, card.id + 100000)}
                                                                >
                                                                    {copiedId === card.id + 100000 ? (
                                                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                                                    ) : (
                                                                        <Copy className="h-4 w-4" />
                                                                    )}
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    )}

                                                    {card.voucher && (
                                                        <div>
                                                            <p className="text-xs text-muted-foreground mb-2">Voucher Code</p>
                                                            <div className="flex items-center gap-2">
                                                                <div className="flex-1 p-2 rounded bg-muted font-mono text-sm text-center">
                                                                    {card.voucher}
                                                                </div>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8"
                                                                    onClick={() => copyToClipboard(card.voucher!, card.id)}
                                                                >
                                                                    {copiedId === card.id ? (
                                                                        <CheckCircle className="h-4 w-4 text-green-600" />
                                                                    ) : (
                                                                        <Copy className="h-4 w-4" />
                                                                    )}
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    )}
                                                </>
                                            )}

                                            <div className="space-y-2">
                                                {card.purchased_at && (
                                                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                        <Calendar className="h-3 w-3" />
                                                        <span>
                                                            {new Date(card.purchased_at).toLocaleDateString()}
                                                        </span>
                                                    </div>
                                                )}
                                                {getPaymentMethodBadge(card.payment_method)}
                                            </div>

                                            <div className="flex gap-2">
                                                <Link href={route('gift-cards.show.id', card.id)} className="flex-1">
                                                    <Button variant="outline" className="w-full">
                                                        <Eye className="h-4 w-4 mr-2" />
                                                        View Details
                                                    </Button>
                                                </Link>
                                                {card.purchased_at && (
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        onClick={() => downloadPDFReceipt(card.id)}
                                                        title="Download PDF Receipt"
                                                    >
                                                        <Download className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}

                        {/* Pagination Info */}
                        {giftCards.total > giftCards.per_page && (
                            <div className="mt-6 text-center text-sm text-muted-foreground">
                                Showing {((giftCards.current_page - 1) * giftCards.per_page) + 1} to{' '}
                                {Math.min(giftCards.current_page * giftCards.per_page, giftCards.total)} of{' '}
                                {giftCards.total} gift cards
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </ProfileLayout>
    )
}


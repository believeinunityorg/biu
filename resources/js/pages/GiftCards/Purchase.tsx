"use client"

import { Head, router, useForm, usePage } from "@inertiajs/react"
import { useState, useEffect } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Badge } from "@/components/frontend/ui/badge"
import {
    Gift,
    ArrowLeft,
    CreditCard,
    DollarSign,
    Loader2,
    CheckCircle,
    Coins
} from "lucide-react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import BrpParticipationHint from "@/components/brp/BrpParticipationHint"

interface Brand {
    brandName: string
    countryName: string
    currency: string
    denominations: number[]
    valueRestrictions: {
        maxVal: number
        minVal: number
    }
    productId: number
    productImage: string
    productDescription: string
    discount: number
}

interface PurchaseProps {
    brand: Brand
    stripeKey: string
    user: {
        id: number
        name: string
        email: string
    }
}

export default function PurchasePage({ brand, stripeKey, user }: PurchaseProps) {
    const [selectedAmount, setSelectedAmount] = useState<number | null>(null)
    const [customAmount, setCustomAmount] = useState("")
    const [isProcessing, setIsProcessing] = useState(false)
    const [csrfToken, setCsrfToken] = useState<string>("")
    const [paymentMethod, setPaymentMethod] = useState<'stripe' | 'believe_points'>('stripe')
    const page = usePage()
    const auth = (page.props as any).auth
    const availableBalance = parseFloat(auth?.user?.believe_points) || 0
    const giftedBalance = parseFloat(auth?.user?.gifted_believe_points) || 0
    const purchasedBalance =
        parseFloat(auth?.user?.purchased_believe_points) ||
        Math.max(0, availableBalance - giftedBalance)
    const brandNameLower = (brand.brandName || '').toLowerCase()
    const isClosedLoop =
        !/\bvisa\b/.test(brandNameLower) &&
        !/\bmastercard\b/.test(brandNameLower) &&
        !/\bmaster card\b/.test(brandNameLower)
    // Closed-loop gift cards: full Available. Visa/MC: purchased only.
    const currentBalance = isClosedLoop ? availableBalance : purchasedBalance
    const csrf_token = (page.props as any).csrf_token

    // Get CSRF token on component mount
    useEffect(() => {
        const getCsrfToken = () => {
            // Method 1: From Inertia props (most reliable)
            if (csrf_token) {
                setCsrfToken(csrf_token)
                return csrf_token
            }

            // Method 2: From meta tag
            const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            if (metaToken) {
                setCsrfToken(metaToken)
                return metaToken
            }

            // Method 3: From cookie (XSRF-TOKEN)
            const cookieToken = document.cookie
                .split('; ')
                .find(row => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1]

            if (cookieToken) {
                const decodedToken = decodeURIComponent(cookieToken)
                setCsrfToken(decodedToken)
                return decodedToken
            }

            console.error('CSRF token not found!')
            return ''
        }

        const token = getCsrfToken()
        if (token) {
            setCsrfToken(token)
        }
    }, [csrf_token])

    const { data, setData, post, processing, errors } = useForm({
        brand: brand.brandName,
        brand_name: brand.brandName,
        amount: 0,
        country: brand.countryName,
        currency: brand.currency || 'USD',
        payment_method: paymentMethod,
    })

    // Update form data when payment method changes
    useEffect(() => {
        setData('payment_method', paymentMethod)
    }, [paymentMethod])

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: brand.currency || 'USD',
        }).format(amount)
    }

    const handleAmountSelect = (amount: number) => {
        setSelectedAmount(amount)
        setCustomAmount("")
        setData('amount', amount)
    }

    const handleCustomAmount = (value: string) => {
        setCustomAmount(value)
        setSelectedAmount(null)
        const numValue = parseFloat(value)
        if (!isNaN(numValue) && numValue >= brand.valueRestrictions.minVal && numValue <= brand.valueRestrictions.maxVal) {
            setData('amount', numValue)
        } else {
            setData('amount', 0)
        }
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()

        if (data.amount < brand.valueRestrictions.minVal || data.amount > brand.valueRestrictions.maxVal) {
            return
        }

        setIsProcessing(true)

        // Get fresh CSRF token
        const getCsrfToken = () => {
            if (csrfToken) return csrfToken

            // Fallback methods
            const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            if (metaToken) return metaToken

            const cookieToken = document.cookie
                .split('; ')
                .find(row => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1]

            if (cookieToken) return decodeURIComponent(cookieToken)

            return ''
        }

        const token = getCsrfToken()

        if (!token) {
            setIsProcessing(false)
            alert('CSRF token not found. Please refresh the page and try again.')
            return
        }

        try {
            const requestData = {
                ...data,
                payment_method: paymentMethod,
            }

            const response = await fetch(route('gift-cards.purchase.store'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'include', // Important: Include cookies for CSRF
                body: JSON.stringify(requestData),
            })

            const result = await response.json()

            if (result.success) {
                if (result.url) {
                    // Stripe payment - redirect to Stripe
                    window.location.href = result.url
                } else if (result.redirect) {
                    // Believe Points payment - redirect to success page
                    window.location.href = result.redirect
                } else {
                    // Fallback redirect
                    router.visit(route('gift-cards.success'))
                }
            } else {
                setIsProcessing(false)
                alert(result.message || 'Failed to process payment')
            }
        } catch (error) {
            setIsProcessing(false)
            console.error('Error:', error)
            alert('An error occurred. Please try again.')
        }
    }

    const isValidAmount = data.amount >= brand.valueRestrictions.minVal &&
                          data.amount <= brand.valueRestrictions.maxVal &&
                          data.amount > 0

    return (
        <FrontendLayout>
            <Head title={`Purchase ${brand.brandName} Gift Card`} />

            <div className="min-h-screen bg-gradient-to-br from-background via-background to-primary/5">
                <div className="container mx-auto px-4 py-8 max-w-7xl">
                    {/* Header */}
                    <div className="mb-8">
                        <Button
                            variant="ghost"
                            onClick={() => router.visit(route('gift-cards.index'))}
                            className="mb-4"
                        >
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back to Gift Cards
                        </Button>
                        <div className="flex items-center gap-3">
                            <div className="p-3 rounded-xl bg-primary/10">
                                <Gift className="h-8 w-8 text-primary" />
                            </div>
                            <div>
                                <h1 className="text-3xl font-bold">{brand.brandName} Gift Card</h1>
                                <p className="text-muted-foreground">{brand.countryName}</p>
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    {/* Main Purchase Form */}
                    <div className="lg:col-span-2">
                        <Card className="shadow-xl border-2">
                            <CardHeader className="bg-gradient-to-r from-primary/10 to-primary/5">
                                <CardTitle className="text-2xl">Select Gift Card Amount</CardTitle>
                                <CardDescription>Choose your desired gift card value</CardDescription>
                            </CardHeader>
                            <CardContent className="pt-6">
                                <form onSubmit={handleSubmit} className="space-y-6">
                                    {/* Amount Selection */}
                                    <div>
                                        <Label className="text-base mb-4 block">Select Amount</Label>

                                        {/* Predefined Amounts */}
                                        {brand.denominations && brand.denominations.length > 0 && (
                                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
                                                {brand.denominations.map((amount) => (
                                                    <button
                                                        key={amount}
                                                        type="button"
                                                        onClick={() => handleAmountSelect(amount)}
                                                        className={`p-4 rounded-lg border-2 transition-all ${
                                                            selectedAmount === amount
                                                                ? 'border-primary bg-primary/10'
                                                                : 'border-input hover:border-primary/50'
                                                        }`}
                                                    >
                                                        <div className="font-semibold">{formatCurrency(amount)}</div>
                                                    </button>
                                                ))}
                                            </div>
                                        )}

                                        {/* Custom Amount */}
                                        <div>
                                            <Label htmlFor="custom-amount" className="mb-2 block">
                                                Or enter custom amount
                                            </Label>
                                            <Input
                                                id="custom-amount"
                                                type="number"
                                                placeholder={`${formatCurrency(brand.valueRestrictions.minVal)} - ${formatCurrency(brand.valueRestrictions.maxVal)}`}
                                                value={customAmount}
                                                onChange={(e) => handleCustomAmount(e.target.value)}
                                                min={brand.valueRestrictions.minVal}
                                                max={brand.valueRestrictions.maxVal}
                                                step="0.01"
                                            />
                                            <p className="text-xs text-muted-foreground mt-2">
                                                Minimum: {formatCurrency(brand.valueRestrictions.minVal)} |
                                                Maximum: {formatCurrency(brand.valueRestrictions.maxVal)}
                                            </p>
                                        </div>

                                        {errors.amount && (
                                            <p className="text-sm text-destructive mt-2">{errors.amount}</p>
                                        )}
                                    </div>

                                    {/* Selected Amount Display */}
                                    {isValidAmount && (
                                        <div className="p-6 rounded-xl bg-gradient-to-r from-primary/10 via-primary/5 to-primary/10 border-2 border-primary/20 shadow-lg">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm text-muted-foreground mb-1">Total Amount</p>
                                                    <p className="text-4xl font-bold text-primary">
                                                        {formatCurrency(data.amount)}
                                                    </p>
                                                </div>
                                                <div className="p-4 rounded-full bg-primary/20">
                                                    <DollarSign className="h-10 w-10 text-primary" />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Payment Method Selection */}
                                    {isValidAmount && (
                                        <div className="space-y-4 p-4 border rounded-lg bg-muted/50">
                                            <Label className="text-base font-semibold">Payment Method</Label>

                                            <div className="grid grid-cols-1 gap-3">
                                                {/* Stripe Payment */}
                                                <label className={`flex items-center gap-3 p-4 border-2 rounded-lg cursor-pointer transition-all ${
                                                    paymentMethod === 'stripe'
                                                        ? 'border-primary bg-primary/10'
                                                        : 'border-input hover:border-primary/50'
                                                }`}>
                                                    <input
                                                        type="radio"
                                                        name="payment_method"
                                                        value="stripe"
                                                        checked={paymentMethod === 'stripe'}
                                                        onChange={(e) => setPaymentMethod(e.target.value as 'stripe' | 'believe_points')}
                                                        className="w-4 h-4 text-primary"
                                                    />
                                                    <CreditCard className="h-5 w-5" />
                                                    <div className="flex-1">
                                                        <div className="font-semibold">Pay with Card (Stripe)</div>
                                                        <div className="text-sm text-muted-foreground">Secure payment via Stripe</div>
                                                    </div>
                                                </label>

                                                {/* Believe Points Payment */}
                                                <label className={`flex items-center gap-3 p-4 border-2 rounded-lg cursor-pointer transition-all ${
                                                    paymentMethod === 'believe_points'
                                                        ? 'border-primary bg-primary/10'
                                                        : 'border-input hover:border-primary/50'
                                                } ${currentBalance < data.amount ? 'opacity-50 cursor-not-allowed' : ''}`}>
                                                    <input
                                                        type="radio"
                                                        name="payment_method"
                                                        value="believe_points"
                                                        checked={paymentMethod === 'believe_points'}
                                                        onChange={(e) => setPaymentMethod(e.target.value as 'stripe' | 'believe_points')}
                                                        disabled={currentBalance < data.amount}
                                                        className="w-4 h-4 text-primary"
                                                    />
                                                    <Coins className="h-5 w-5 text-yellow-600" />
                                                    <div className="flex-1">
                                                        <div className="font-semibold flex items-center gap-2">
                                                            Pay with Believe Points
                                                            {currentBalance < data.amount && (
                                                                <Badge variant="destructive" className="text-xs">
                                                                    Insufficient
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <div className="text-sm text-muted-foreground">
                                                            Your balance: {currentBalance.toFixed(2)} points
                                                            {currentBalance >= data.amount && (
                                                                <span className="text-green-600 ml-2">
                                                                    (You'll have {(currentBalance - data.amount).toFixed(2)} points remaining)
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>

                                            {paymentMethod === 'believe_points' && currentBalance < data.amount && (
                                                <p className="text-sm text-destructive flex items-center gap-1">
                                                    <CheckCircle className="h-4 w-4" />
                                                    You need {data.amount.toFixed(2)} points but only have {currentBalance.toFixed(2)} points.
                                                </p>
                                            )}
                                        </div>
                                    )}

                                    <BrpParticipationHint module="gift_card_purchase" variant="alert" />

                                    {/* Submit Button */}
                                    <Button
                                        type="submit"
                                        className="w-full bg-gradient-to-r from-primary to-primary/90 hover:from-primary/90 hover:to-primary shadow-lg"
                                        size="lg"
                                        disabled={!isValidAmount || processing || isProcessing || (paymentMethod === 'believe_points' && currentBalance < data.amount)}
                                    >
                                        {processing || isProcessing ? (
                                            <>
                                                <Loader2 className="h-5 w-5 mr-2 animate-spin" />
                                                Processing...
                                            </>
                                        ) : paymentMethod === 'believe_points' ? (
                                            <>
                                                <Coins className="h-5 w-5 mr-2" />
                                                Pay with Believe Points
                                            </>
                                        ) : (
                                            <>
                                                <CreditCard className="h-5 w-5 mr-2" />
                                                Proceed to Secure Payment
                                            </>
                                        )}
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Sidebar Info */}
                    <div className="space-y-6">
                        {/* Brand Info */}
                        {brand.productImage && (
                            <Card className="shadow-lg overflow-hidden">
                                <div className="w-full h-64 overflow-hidden bg-muted">
                                    <img
                                        src={brand.productImage}
                                        alt={brand.brandName}
                                        className="w-full h-full object-cover hover:scale-105 transition-transform duration-300"
                                    />
                                </div>
                                {brand.productDescription && (
                                    <CardContent className="pt-6">
                                        <div
                                            className="text-sm text-muted-foreground prose prose-sm max-w-none dark:prose-invert"
                                            dangerouslySetInnerHTML={{ __html: brand.productDescription }}
                                        />
                                    </CardContent>
                                )}
                            </Card>
                        )}

                        {/* Info Card */}
                        <Card className="bg-gradient-to-br from-primary/10 to-primary/5 border-2 border-primary/20 shadow-lg">
                            <CardHeader>
                                <CardTitle className="text-lg flex items-center gap-2">
                                    <div className="p-2 rounded-lg bg-primary/20">
                                        <CheckCircle className="h-5 w-5 text-primary" />
                                    </div>
                                    Secure Payment
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-start gap-3 text-sm">
                                    <CheckCircle className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <span className="text-muted-foreground">Instant delivery after payment</span>
                                </div>
                                <div className="flex items-start gap-3 text-sm">
                                    <CheckCircle className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <span className="text-muted-foreground">Secure Stripe payment processing</span>
                                </div>
                                <div className="flex items-start gap-3 text-sm">
                                    <CheckCircle className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <span className="text-muted-foreground">Full refund if not satisfied</span>
                                </div>
                                <div className="flex items-start gap-3 text-sm">
                                    <CheckCircle className="h-4 w-4 text-green-600 mt-0.5 flex-shrink-0" />
                                    <span className="text-muted-foreground">24/7 customer support</span>
                                </div>
                            </CardContent>
                        </Card>

                        {brand.discount > 0 && (
                            <Card className="bg-gradient-to-r from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 border-2 border-green-300 dark:border-green-700 shadow-lg">
                                <CardContent className="pt-6">
                                    <div className="flex flex-col items-center gap-2 text-center">
                                        <Badge className="bg-green-600 text-white text-lg px-4 py-1">
                                            {brand.discount}% OFF
                                        </Badge>
                                        <span className="text-sm font-semibold text-green-800 dark:text-green-200">
                                            Special discount available!
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
                </div>
            </div>
        </FrontendLayout>
    )
}


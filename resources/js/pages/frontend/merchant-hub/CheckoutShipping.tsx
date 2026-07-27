import React, { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import axios from 'axios'
import { PageHead } from '@/components/frontend/PageHead'
import FrontendLayout from '@/layouts/frontend/frontend-layout'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/frontend/ui/card'
import { Button } from '@/components/frontend/ui/button'
import { Input } from '@/components/frontend/ui/input'
import { Label } from '@/components/frontend/ui/label'
import { ArrowLeft, MapPin, Package } from 'lucide-react'

interface OfferSummary {
  id: string
  title: string
  image: string
  merchantName: string
  amount: number
  platformFee: number
  pointsRequired: number
  userPoints: number
  currency: string
  pickupAvailable?: boolean
  pickupAddress?: string | null
}

interface Props {
  offer: OfferSummary
  defaultPaymentMethod?: 'points' | 'cash' | 'believe_points'
}

const COUNTRY_OPTIONS = [
  { value: 'US', label: 'United States' },
  { value: 'CA', label: 'Canada' },
  { value: 'GB', label: 'United Kingdom' },
  { value: 'AU', label: 'Australia' },
  { value: 'DE', label: 'Germany' },
  { value: 'FR', label: 'France' },
  { value: 'IN', label: 'India' },
  { value: 'MX', label: 'Mexico' },
  { value: 'BR', label: 'Brazil' },
  { value: 'ES', label: 'Spain' },
  { value: 'IT', label: 'Italy' },
  { value: 'NL', label: 'Netherlands' },
  { value: 'JP', label: 'Japan' },
  { value: 'SG', label: 'Singapore' },
  { value: 'OTHER', label: 'Other' },
]

interface ShippingMethod {
  id: string
  name: string
  cost: number
  provider?: string
  estimated_days?: string
  total_amount: number
  stripe_processing_fee_addon?: number
  charged_total?: number
  pickup_address?: string
}

export default function CheckoutShipping({ offer, defaultPaymentMethod = 'cash' }: Props) {
  const { errors, auth } = usePage().props as { errors?: Record<string, string>, auth?: { user?: { believe_points?: number | string } } }
  const [submitting, setSubmitting] = useState(false)
  const [quoting, setQuoting] = useState(false)
  const [paymentMethod, setPaymentMethod] = useState<'points' | 'cash' | 'believe_points'>(defaultPaymentMethod)
  const [shipmentId, setShipmentId] = useState('')
  const [shippingMethods, setShippingMethods] = useState<ShippingMethod[]>([])
  const [selectedRateId, setSelectedRateId] = useState('')
  const [subtotalAmount, setSubtotalAmount] = useState<number>(offer.amount)
  const [platformFeeAmount, setPlatformFeeAmount] = useState<number>(offer.platformFee ?? 0)
  const [taxAmount, setTaxAmount] = useState<number>(0)
  const [quoteError, setQuoteError] = useState('')
  const [form, setForm] = useState({
    shipping_name: '',
    shipping_line1: '',
    shipping_line2: '',
    shipping_city: '',
    shipping_state: '',
    shipping_postal_code: '',
    shipping_country: 'US',
  })

  const offerImageSrc = (src: string) => {
    if (!src || src === '/placeholder.jpg') return src || '/placeholder.jpg'
    if (src.startsWith('http') || src.startsWith('//') || src.startsWith('/storage')) return src
    return '/storage/' + src.replace(/^\//, '')
  }

  const selectedMethod = shippingMethods.find(m => m.id === selectedRateId) || null
  const shippingCost = selectedMethod?.cost ?? 0
  const basketTotal =
    selectedMethod?.total_amount ?? subtotalAmount + platformFeeAmount + taxAmount + shippingCost
  const stripeProcessingFeeAddon =
    paymentMethod === 'cash' ? Number(selectedMethod?.stripe_processing_fee_addon ?? 0) : 0
  const chargedTotal =
    paymentMethod === 'cash'
      ? Number(selectedMethod?.charged_total ?? basketTotal + stripeProcessingFeeAddon)
      : basketTotal
  const hasEnoughPoints = offer.userPoints >= offer.pointsRequired
  const believePointsBalance = Number(
    auth?.user?.purchased_believe_points ??
      Math.max(
        0,
        Number(auth?.user?.believe_points ?? 0) - Number(auth?.user?.gifted_believe_points ?? 0)
      )
  )
  const hasEnoughBelievePoints = believePointsBalance >= basketTotal

  const quoteRates = async () => {
    setQuoting(true)
    setQuoteError('')
    try {
      const response = await axios.post('/merchant-hub/checkout/rates', {
        offer_id: offer.id,
        payment_method: paymentMethod,
        ...form,
      })
      const methods: ShippingMethod[] = response.data.shipping_methods || []
      setShippingMethods(methods)
      setSelectedRateId(methods[0]?.id ?? '')
      setShipmentId(response.data.shipment_id ?? '')
      setSubtotalAmount(Number(response.data.subtotal_amount ?? offer.amount))
      setPlatformFeeAmount(Number(response.data.platform_fee_amount ?? offer.platformFee ?? 0))
      setTaxAmount(Number(response.data.tax_amount ?? 0))
    } catch (error: any) {
      const message = error?.response?.data?.error || 'Failed to fetch shipping options.'
      setQuoteError(message)
      setShippingMethods([])
      setSelectedRateId('')
      setShipmentId('')
    } finally {
      setQuoting(false)
    }
  }

  const isPickupRate = selectedRateId === 'pickup'

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedRateId || (!shipmentId && !isPickupRate)) {
      setQuoteError('Please get shipping options and select a shipping method.')
      return
    }
    if (paymentMethod === 'points' && !hasEnoughPoints) {
      setQuoteError(`You need ${offer.pointsRequired} points but only have ${offer.userPoints}.`)
      return
    }
    if (paymentMethod === 'believe_points' && !hasEnoughBelievePoints) {
      setQuoteError(`You need ${basketTotal.toFixed(2)} BP but only have ${believePointsBalance.toFixed(2)} BP.`)
      return
    }
    setSubmitting(true)
    const country = form.shipping_country === 'OTHER' ? 'US' : form.shipping_country
    router.post('/merchant-hub/checkout', {
      offer_id: offer.id,
      payment_method: paymentMethod,
      ...form,
      shipping_country: country,
      shippo_shipment_id: isPickupRate ? '' : shipmentId,
      shippo_rate_object_id: selectedRateId,
    }, {
      preserveScroll: true,
      onFinish: () => setSubmitting(false),
    })
  }

  return (
    <FrontendLayout>
      <PageHead title="Shipping address" description="Enter your shipping address to complete your purchase." />
      <div className="min-h-screen bg-gradient-to-br from-background via-background to-muted/20">
        <div className="border-b bg-background/95 backdrop-blur sticky top-0 z-40">
          <div className="container mx-auto px-4 py-4">
            <Link href={`/merchant-hub/offers/${offer.id}`}>
              <Button variant="ghost" size="sm" className="gap-2">
                <ArrowLeft className="h-4 w-4" />
                Back to offer
              </Button>
            </Link>
          </div>
        </div>

        <div className="container mx-auto px-4 py-8 max-w-2xl">
          <h1 className="text-2xl font-bold text-foreground mb-2">Shipping address</h1>
          <p className="text-muted-foreground mb-6">Enter where you’d like your order sent before paying.</p>

          {/* Offer summary */}
          <Card className="mb-6 overflow-hidden">
            <CardHeader className="py-3">
              <CardTitle className="text-base flex items-center gap-2">
                <Package className="h-4 w-4" />
                Order summary
              </CardTitle>
            </CardHeader>
            <CardContent className="pt-0">
              <div className="flex gap-4">
                <img
                  src={offerImageSrc(offer.image)}
                  alt={offer.title}
                  className="w-20 h-20 object-cover rounded-lg border"
                />
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-foreground truncate">{offer.title}</p>
                  <p className="text-sm text-muted-foreground">{offer.merchantName}</p>
                  <p className="text-lg font-bold text-foreground mt-1">
                    {offer.currency} {Number(offer.amount).toFixed(2)}
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Shipping form */}
          <Card>
            <CardHeader className="py-3">
              <CardTitle className="text-base flex items-center gap-2">
                <MapPin className="h-4 w-4" />
                Delivery address
              </CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="space-y-2">
                  <Label>Payment method</Label>
                  <div className="grid grid-cols-3 gap-3">
                    <button
                      type="button"
                      onClick={() => setPaymentMethod('points')}
                      className={`h-10 rounded-md border text-sm font-medium ${
                        paymentMethod === 'points'
                          ? 'border-blue-600 text-blue-600'
                          : 'border-input text-foreground'
                      }`}
                    >
                      Use points ({offer.pointsRequired})
                    </button>
                    <button
                      type="button"
                      onClick={() => setPaymentMethod('believe_points')}
                      className={`h-10 rounded-md border text-sm font-medium ${
                        paymentMethod === 'believe_points'
                          ? 'border-indigo-600 text-indigo-600'
                          : 'border-input text-foreground'
                      }`}
                    >
                      Pay with BP
                    </button>
                    <button
                      type="button"
                      onClick={() => setPaymentMethod('cash')}
                      className={`h-10 rounded-md border text-sm font-medium ${
                        paymentMethod === 'cash'
                          ? 'border-green-600 text-green-600'
                          : 'border-input text-foreground'
                      }`}
                    >
                      Pay with cash
                    </button>
                  </div>
                  {paymentMethod === 'points' && (
                    <p className={`text-xs ${hasEnoughPoints ? 'text-emerald-600' : 'text-amber-600'}`}>
                      You have {offer.userPoints} points (need {offer.pointsRequired}).
                    </p>
                  )}
                  {paymentMethod === 'believe_points' && (
                    <p className={`text-xs ${hasEnoughBelievePoints ? 'text-emerald-600' : 'text-amber-600'}`}>
                      You have {believePointsBalance.toFixed(2)} BP (need {basketTotal.toFixed(2)} BP).
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="shipping_name">Full name</Label>
                  <Input
                    id="shipping_name"
                    value={form.shipping_name}
                    onChange={e => setForm(f => ({ ...f, shipping_name: e.target.value }))}
                    placeholder="John Doe"
                    required
                    maxLength={255}
                    className={errors?.shipping_name ? 'border-red-500' : ''}
                  />
                  {errors?.shipping_name && (
                    <p className="text-sm text-red-600 dark:text-red-400">{errors.shipping_name}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="shipping_line1">Address line 1</Label>
                  <Input
                    id="shipping_line1"
                    value={form.shipping_line1}
                    onChange={e => setForm(f => ({ ...f, shipping_line1: e.target.value }))}
                    placeholder="Street address, P.O. box"
                    required
                    maxLength={255}
                    className={errors?.shipping_line1 ? 'border-red-500' : ''}
                  />
                  {errors?.shipping_line1 && (
                    <p className="text-sm text-red-600 dark:text-red-400">{errors.shipping_line1}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="shipping_line2">Address line 2 (optional)</Label>
                  <Input
                    id="shipping_line2"
                    value={form.shipping_line2}
                    onChange={e => setForm(f => ({ ...f, shipping_line2: e.target.value }))}
                    placeholder="Apartment, suite, unit, building, floor, etc."
                    maxLength={255}
                  />
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="shipping_city">City</Label>
                    <Input
                      id="shipping_city"
                      value={form.shipping_city}
                      onChange={e => setForm(f => ({ ...f, shipping_city: e.target.value }))}
                      placeholder="City"
                      required
                      maxLength={100}
                      className={errors?.shipping_city ? 'border-red-500' : ''}
                    />
                    {errors?.shipping_city && (
                      <p className="text-sm text-red-600 dark:text-red-400">{errors.shipping_city}</p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="shipping_state">State / Province (optional)</Label>
                    <Input
                      id="shipping_state"
                      value={form.shipping_state}
                      onChange={e => setForm(f => ({ ...f, shipping_state: e.target.value }))}
                      placeholder="State or Province"
                      maxLength={100}
                    />
                  </div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="shipping_postal_code">Postal code</Label>
                    <Input
                      id="shipping_postal_code"
                      value={form.shipping_postal_code}
                      onChange={e => setForm(f => ({ ...f, shipping_postal_code: e.target.value }))}
                      placeholder="ZIP / Postal code"
                      required
                      maxLength={20}
                      className={errors?.shipping_postal_code ? 'border-red-500' : ''}
                    />
                    {errors?.shipping_postal_code && (
                      <p className="text-sm text-red-600 dark:text-red-400">{errors.shipping_postal_code}</p>
                    )}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="shipping_country">Country</Label>
                    <select
                      id="shipping_country"
                      value={form.shipping_country}
                      onChange={e => setForm(f => ({ ...f, shipping_country: e.target.value }))}
                      className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                      {COUNTRY_OPTIONS.map(opt => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                    {errors?.shipping_country && (
                      <p className="text-sm text-red-600 dark:text-red-400">{errors.shipping_country}</p>
                    )}
                  </div>
                </div>
                {errors?.error && (
                  <div className="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <p className="text-sm text-red-800 dark:text-red-200">{errors.error}</p>
                  </div>
                )}

                {quoteError && (
                  <div className="p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                    <p className="text-sm text-red-800 dark:text-red-200">{quoteError}</p>
                  </div>
                )}

                <Button
                  type="button"
                  onClick={quoteRates}
                  disabled={quoting}
                  variant="outline"
                  className="w-full h-11"
                >
                  {quoting ? 'Loading shipping options...' : 'Get shipping options'}
                </Button>

                {shippingMethods.length > 0 && (
                  <div className="space-y-3">
                    <p className="text-sm font-medium">Choose shipping</p>
                    {shippingMethods.map(method => (
                      <label
                        key={method.id}
                        className={`flex items-center justify-between rounded-md border p-3 cursor-pointer ${
                          selectedRateId === method.id ? 'border-blue-600' : 'border-input'
                        }`}
                      >
                        <div className="flex items-center gap-2">
                          <input
                            type="radio"
                            checked={selectedRateId === method.id}
                            onChange={() => {
                              setSelectedRateId(method.id)
                            }}
                          />
                          <div>
                            <p className="text-sm font-medium">{method.name}</p>
                            <p className="text-xs text-muted-foreground">
                              {method.id === 'pickup'
                                ? 'No shipping charge'
                                : method.estimated_days && method.estimated_days !== '—'
                                  ? `${method.estimated_days} business days`
                                  : 'Estimated time unavailable'}
                            </p>
                          </div>
                        </div>
                        <p className="text-sm font-semibold">{offer.currency} {method.cost.toFixed(2)}</p>
                      </label>
                    ))}
                    {selectedMethod?.id === 'pickup' && selectedMethod.pickup_address && (
                      <div className="rounded-md border border-emerald-500/40 bg-emerald-500/5 p-3 text-sm">
                        <p className="font-medium text-foreground mb-1">Pickup location</p>
                        <p className="text-muted-foreground whitespace-pre-line">{selectedMethod.pickup_address}</p>
                      </div>
                    )}
                  </div>
                )}

                <div className="rounded-lg border p-3 space-y-1.5 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Subtotal</span>
                    <span>{offer.currency} {subtotalAmount.toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Platform fee</span>
                    <span>{offer.currency} {platformFeeAmount.toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Shipping</span>
                    <span>{offer.currency} {shippingCost.toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">State sales tax</span>
                    <span>{offer.currency} {taxAmount.toFixed(2)}</span>
                  </div>
                  <div className="flex justify-between pt-1 border-t font-semibold">
                    <span>{paymentMethod === 'cash' ? 'Order total' : 'Total'}</span>
                    <span>{offer.currency} {basketTotal.toFixed(2)}</span>
                  </div>
                  {paymentMethod === 'cash' && stripeProcessingFeeAddon > 0 && (
                    <>
                      <div className="flex justify-between text-muted-foreground">
                        <span className="text-xs sm:text-sm">Est. payment provider fee</span>
                        <span>{offer.currency} {stripeProcessingFeeAddon.toFixed(2)}</span>
                      </div>
                      <div className="flex justify-between font-semibold">
                        <span>Total charged (card)</span>
                        <span>{offer.currency} {chargedTotal.toFixed(2)}</span>
                      </div>
                    </>
                  )}
                </div>

                <Button
                  type="submit"
                  disabled={
                    submitting ||
                    !selectedRateId ||
                    (!shipmentId && !isPickupRate) ||
                    (paymentMethod === 'points' && !hasEnoughPoints) ||
                    (paymentMethod === 'believe_points' && !hasEnoughBelievePoints)
                  }
                  className="w-full h-12 text-base"
                  size="lg"
                >
                  {submitting
                    ? 'Processing...'
                    : paymentMethod === 'points'
                      ? `Confirm with points + shipping — ${offer.currency} ${basketTotal.toFixed(2)}`
                      : paymentMethod === 'believe_points'
                        ? `Confirm with BP + shipping — ${offer.currency} ${basketTotal.toFixed(2)}`
                        : `Continue to payment — ${offer.currency} ${chargedTotal.toFixed(2)}`}
                </Button>
              </form>
            </CardContent>
          </Card>
        </div>
      </div>
    </FrontendLayout>
  )
}

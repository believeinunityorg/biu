"use client"

import { useState, useEffect } from "react"
import { motion } from 'framer-motion'
import { ArrowLeft, CreditCard, Shield, Snowflake, Unlock, Eye, EyeOff, Copy, Check, Lock, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { getCsrfToken } from './utils'
import { showSuccessToast, showErrorToast } from '@/lib/toast'

interface VirtualCardProps {
    cardNumber?: string
    cardholderName?: string
    expiryDate?: string
    cvv?: string
    onBack: () => void
    onCardCreated?: () => void
    onOpenCardsEndorsementVerification?: () => void
}

export function VirtualCard({
    cardNumber: propCardNumber,
    cardholderName: propCardholderName,
    expiryDate: propExpiryDate,
    cvv: propCvv,
    onBack,
    onCardCreated,
    onOpenCardsEndorsementVerification,
}: VirtualCardProps) {
    const [isFlipped, setIsFlipped] = useState(false)
    const [isFrozen, setIsFrozen] = useState(false)
    const [showSecureDetails, setShowSecureDetails] = useState(false)
    const [copiedField, setCopiedField] = useState<string | null>(null)
    const [hasCardAccount, setHasCardAccount] = useState<boolean | null>(null)
    const [isLoadingCardAccount, setIsLoadingCardAccount] = useState(true)
    const [isCreatingCardAccount, setIsCreatingCardAccount] = useState(false)
    const [needsCardsVerification, setNeedsCardsVerification] = useState(false)
    const [issueCardError, setIssueCardError] = useState<string | null>(null)
    const [cardData, setCardData] = useState<{
        cardNumber?: string
        cardholderName?: string
        expiryDate?: string
        cvv?: string
    } | null>(null)

    // Use card data from API or props
    const cardNumber = cardData?.cardNumber || propCardNumber || '4532 1598 7634 2109'
    const cardholderName = cardData?.cardholderName || propCardholderName || 'JOHN DOE'
    const expiryDate = cardData?.expiryDate || propExpiryDate || '09/27'
    const cvv = cardData?.cvv || propCvv || '456'

    // Check card account on mount
    useEffect(() => {
        checkCardAccount()
    }, [])

    const checkCardAccount = async () => {
        setIsLoadingCardAccount(true)
        try {
            const timestamp = Date.now()
            const response = await fetch(`/wallet/bridge/card-account?t=${timestamp}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0',
                },
                credentials: 'include',
                cache: 'no-store',
            })

            const data = await response.json()
            if (data.success && data.has_card_account && data.data) {
                setHasCardAccount(true)
                // Extract card details from Bridge API response
                const cardAccount = data.data
                
                // Format expiry date from month/year
                let formattedExpiry = undefined
                if (cardAccount.expiry_month && cardAccount.expiry_year) {
                    const month = String(cardAccount.expiry_month).padStart(2, '0')
                    const year = String(cardAccount.expiry_year).slice(-2)
                    formattedExpiry = `${month}/${year}`
                } else if (cardAccount.expiry_date) {
                    formattedExpiry = cardAccount.expiry_date
                } else if (cardAccount.expiration_date) {
                    formattedExpiry = cardAccount.expiration_date
                }
                
                // Get card number (may be masked with last 4 digits)
                const cardNum = cardAccount.card_number || cardAccount.number || cardAccount.pan || cardAccount.last_four
                
                // Get cardholder name - handle both string and object formats
                let cardholder = cardAccount.cardholder_name || cardAccount.name || cardAccount.cardholder
                
                // If cardholder is an object with first_name and last_name, combine them
                if (cardholder && typeof cardholder === 'object') {
                    const firstName = cardholder.first_name || ''
                    const lastName = cardholder.last_name || ''
                    cardholder = `${firstName} ${lastName}`.trim() || undefined
                }
                
                // Ensure cardholder is a string or undefined
                if (cardholder && typeof cardholder !== 'string') {
                    cardholder = String(cardholder)
                }
                
                setCardData({
                    cardNumber: cardNum,
                    cardholderName: cardholder,
                    expiryDate: formattedExpiry,
                    cvv: cardAccount.cvv || cardAccount.security_code || cardAccount.cvc || undefined,
                })
            } else {
                setHasCardAccount(false)
            }
        } catch (error) {
            console.error('Failed to check card account:', error)
            setHasCardAccount(false)
        } finally {
            setIsLoadingCardAccount(false)
        }
    }

    const handleCreateCardAccount = async () => {
        setIsCreatingCardAccount(true)
        setIssueCardError(null)
        try {
            const response = await fetch('/wallet/bridge/card-account', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({}),
            })

            const data = await response.json()
            if (data.success) {
                setNeedsCardsVerification(false)
                showSuccessToast('Card issued successfully!')
                await checkCardAccount()
                if (onCardCreated) {
                    onCardCreated()
                }
            } else if (data.error_code === 'cards_endorsement_kyc_required') {
                setNeedsCardsVerification(true)
                setIssueCardError(data.message || 'Complete cards verification to issue your card.')
                showErrorToast(data.message || 'Complete cards verification to issue your card.')
            } else if (data.error_code === 'cards_endorsement_phone_required') {
                setNeedsCardsVerification(false)
                setIssueCardError(
                    data.message ||
                        'Add a valid US phone number in E.164 format (e.g. +12025550100) to your profile, then try again.',
                )
                showErrorToast(
                    data.message ||
                        'Add a valid US phone number in E.164 format (e.g. +12025550100) to your profile, then try again.',
                )
            } else if (data.error_code === 'cards_endorsement_kyc_link_failed') {
                setIssueCardError(data.message || 'Failed to get cards verification link.')
                showErrorToast(data.message || 'Failed to get cards verification link.')
            } else if (data.error_code === 'stripe_cardholder_missing') {
                setIssueCardError(
                    data.message ||
                        'Install the Bridge Cards Stripe App on your Stripe Sandbox, then Enable Bridge Cards in Settings.'
                )
                showErrorToast(data.message || 'Stripe cardholder not ready. Check Believe Cash + Stripe setup.')
            } else if (data.error_code === 'cards_endorsement_required') {
                setIssueCardError(data.message || 'Complete cards verification to issue your card.')
                showErrorToast(data.message || 'Complete cards verification to issue your card.')
            } else if (data.error_code === 'cards_endorsement_not_approved') {
                setIssueCardError(data.message || 'Cards verification is pending approval.')
                showErrorToast(data.message || 'Cards verification is pending approval.')
            } else if (data.error_code === 'missing_date_of_birth' && data.requires_kyc) {
                setIssueCardError(data.message || 'Complete identity verification before issuing a card.')
                showErrorToast(data.message || 'Complete identity verification before issuing a card.')
            } else if (data.requires_kyc || data.error_code === 'missing_required_fields') {
                setIssueCardError(data.message || 'Complete identity verification before issuing a card.')
                showErrorToast(data.message || 'Complete identity verification before issuing a card.')
            } else {
                setIssueCardError(data.message || 'Failed to create card account')
                showErrorToast(data.message || 'Failed to create card account')
            }
        } catch (error) {
            console.error('Failed to create card account:', error)
            setIssueCardError('Failed to create card account. Please try again.')
            showErrorToast('Failed to create card account. Please try again.')
        } finally {
            setIsCreatingCardAccount(false)
        }
    }

    const handleOpenCardsEndorsementVerification = () => {
        if (!onOpenCardsEndorsementVerification) {
            return
        }

        onOpenCardsEndorsementVerification()
    }

    const handleDoubleClick = () => {
        setIsFlipped(!isFlipped)
    }

    // Format card number with spaces
    const formattedCardNumber = cardNumber.replace(/\s/g, '').replace(/(.{4})/g, '$1 ').trim()
    
    // Mask card number (show only last 4 digits)
    const maskedCardNumber = cardNumber.replace(/\s/g, '').replace(/(.{4})/g, '$1 ').trim().replace(/\d{4}\s\d{4}\s\d{4}/, '•••• •••• ••••')
    const last4Digits = cardNumber.replace(/\s/g, '').slice(-4)
    
    const handleCopy = (field: 'card' | 'cvv', value: string) => {
        navigator.clipboard.writeText(value.replace(/\s/g, ''))
        setCopiedField(field)
        setTimeout(() => setCopiedField(null), 2000)
    }

    // Show loading state
    if (isLoadingCardAccount) {
        return (
            <motion.div
                initial={{ opacity: 0, x: 20 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -20 }}
                transition={{ duration: 0.3 }}
                className="p-4 space-y-4"
            >
                <div className="space-y-4">
                    <div className="flex items-center gap-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onBack}
                            className="h-8 w-8 p-0"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                        <div className="flex items-center gap-2">
                            <CreditCard className="h-5 w-5 text-primary" />
                            <h3 className="text-lg font-semibold">Cards</h3>
                        </div>
                    </div>
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                    </div>
                </div>
            </motion.div>
        )
    }

    // No card on file — offer issue card
    if (hasCardAccount === false) {
        return (
            <motion.div
                initial={{ opacity: 0, x: 20 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -20 }}
                transition={{ duration: 0.3 }}
                className="p-4 space-y-4"
            >
                <div className="space-y-4">
                    <div className="flex items-center gap-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onBack}
                            className="h-8 w-8 p-0"
                        >
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                        <div className="flex items-center gap-2">
                            <CreditCard className="h-5 w-5 text-primary" />
                            <h3 className="text-lg font-semibold">Cards</h3>
                        </div>
                    </div>

                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border py-12 space-y-4 px-4">
                        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                            <CreditCard className="h-8 w-8 text-muted-foreground" />
                        </div>
                        <div className="text-center space-y-1">
                            <p className="text-sm font-medium">No card available</p>
                            <p className="text-xs text-muted-foreground max-w-xs">
                                Issue a virtual card linked to your wallet balance.
                            </p>
                            {issueCardError && (
                                <p className="text-xs text-destructive max-w-xs pt-1">{issueCardError}</p>
                            )}
                        </div>
                        {needsCardsVerification && onOpenCardsEndorsementVerification ? (
                            <Button
                                onClick={handleOpenCardsEndorsementVerification}
                                className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                            >
                                <Shield className="h-4 w-4 mr-2" />
                                Complete Cards Verification
                            </Button>
                        ) : (
                            <Button
                                onClick={handleCreateCardAccount}
                                disabled={isCreatingCardAccount}
                                className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                            >
                                {isCreatingCardAccount ? (
                                    <>
                                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                        Issuing…
                                    </>
                                ) : (
                                    <>
                                        <CreditCard className="h-4 w-4 mr-2" />
                                        Issue Card
                                    </>
                                )}
                            </Button>
                        )}
                    </div>
                </div>
            </motion.div>
        )
    }

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: -20 }}
            transition={{ duration: 0.3 }}
            className="p-4 space-y-4 select-none"
        >
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onBack}
                        className="h-8 w-8 p-0"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                    <div className="flex items-center gap-2">
                        <CreditCard className="h-5 w-5 text-primary" />
                        <h3 className="text-lg font-semibold">Cards</h3>
                    </div>
                </div>

                {/* Card Display */}
                <div className="flex flex-col items-center">
                    <div
                        className="relative w-full max-w-[500px] aspect-[1.586/1] cursor-pointer select-none"
                        style={{ perspective: "1000px" }}
                        onDoubleClick={handleDoubleClick}
                    >
                        <div
                            className="relative w-full h-full transition-transform duration-700"
                            style={{
                                transformStyle: "preserve-3d",
                                transform: isFlipped ? "rotateY(180deg)" : "rotateY(0deg)",
                            }}
                        >
                            {/* Card Front */}
                            <div
                                className="absolute inset-0 rounded-2xl overflow-hidden select-none"
                                style={{ backfaceVisibility: "hidden" }}
                            >
                                {/* Frozen Overlay */}
                                {isFrozen && (
                                    <div className="absolute inset-0 bg-white/80 backdrop-blur-sm z-50 flex items-center justify-center rounded-2xl">
                                        <div className="text-center space-y-2">
                                            <Snowflake className="h-12 w-12 text-blue-600 mx-auto animate-pulse" />
                                            <p className="text-lg font-bold text-gray-800">Card Frozen</p>
                                            <p className="text-sm text-gray-600">This card is temporarily disabled</p>
                                        </div>
                                    </div>
                                )}
                                <div className="absolute inset-0 bg-gradient-to-br from-blue-50 via-cyan-50 to-blue-100 rounded-2xl">
                                    <div className="absolute inset-0 opacity-[0.08]">
                                        <svg className="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500">
                                            <defs>
                                                <linearGradient id="visaWaveGradient1" x1="0%" y1="0%" x2="100%" y2="100%">
                                                    <stop offset="0%" stopColor="#8B5CF6" stopOpacity="1" />
                                                    <stop offset="100%" stopColor="#EC4899" stopOpacity="0.8" />
                                                </linearGradient>
                                                <linearGradient id="visaWaveGradient2" x1="0%" y1="100%" x2="100%" y2="0%">
                                                    <stop offset="0%" stopColor="#7C3AED" stopOpacity="0.8" />
                                                    <stop offset="100%" stopColor="#DB2777" stopOpacity="0.6" />
                                                </linearGradient>
                                            </defs>
                                            <path d="M0,120 Q200,60 400,120 T800,120 L800,0 L0,0 Z" fill="url(#visaWaveGradient1)" />
                                            <path d="M0,380 Q200,320 400,380 T800,380 L800,500 L0,500 Z" fill="url(#visaWaveGradient2)" />
                                        </svg>
                                    </div>
                                    <div className="absolute inset-0 opacity-[0.03]">
                                        <svg className="w-full h-full" xmlns="http://www.w3.org/2000/svg">
                                            <defs>
                                                <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse">
                                                    <path d="M 40 0 L 0 0 0 40" fill="none" stroke="black" strokeWidth="1" />
                                                </pattern>
                                            </defs>
                                            <rect width="100%" height="100%" fill="url(#grid)" />
                                        </svg>
                                    </div>
                                    <div className="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-500/10 to-transparent rounded-full blur-2xl" />
                                    <div className="absolute bottom-0 left-0 w-40 h-40 bg-gradient-to-tr from-indigo-500/10 to-transparent rounded-full blur-2xl" />
                                    <div className="absolute inset-0 bg-gradient-to-br from-white/20 via-transparent to-transparent" />
                                </div>

                                <div className="relative h-full p-5 sm:p-6 flex flex-col justify-between">
                                    <div className="flex items-start justify-between -mt-2">
                                        {/* Believe In Unity Branding */}
                                        <div className="flex items-center gap-1.5">
                                            <div className="w-10 h-8 sm:w-12 sm:h-9 flex items-center justify-center">
                                                <img
                                                    src="/favicon-96x96.png"
                                                    alt="Believe In Unity Logo"
                                                    className="h-full w-full object-contain"
                                                />
                                            </div>
                                            <div className="text-slate-800">
                                                <p 
                                                    className="text-[9px] sm:text-[10px] font-semibold uppercase tracking-wide"
                                                    style={{
                                                        textShadow:
                                                            "0 2px 4px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.15), 0 -1px 0 rgba(255,255,255,0.9), 1px 1px 0 rgba(255,255,255,0.7)",
                                                        fontWeight: "600",
                                                        letterSpacing: "0.1em",
                                                    }}
                                                >
                                                    Believe In
                                                </p>
                                                <p 
                                                    className="text-[10px] sm:text-xs font-bold uppercase tracking-wide"
                                                    style={{
                                                        textShadow:
                                                            "0 2px 4px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.15), 0 -1px 0 rgba(255,255,255,0.9), 1px 1px 0 rgba(255,255,255,0.7)",
                                                        fontWeight: "600",
                                                        letterSpacing: "0.1em",
                                                    }}
                                                >
                                                    Unity
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-1 opacity-40 pr-1">
                                            <div className="w-4 h-4 sm:w-5 sm:h-5 border-2 border-slate-800 rounded-full" />
                                            <div className="w-4 h-4 sm:w-5 sm:h-5 border-2 border-slate-800 rounded-full -ml-2" />
                                            <div className="w-4 h-4 sm:w-5 sm:h-5 border-2 border-slate-800 rounded-full -ml-2" />
                                        </div>
                                    </div>

                                    <div className="space-y-6">
                                        <div
                                            className="font-mono text-lg sm:text-xl tracking-[0.15em] text-slate-800 whitespace-nowrap"
                                            style={{
                                                textShadow:
                                                    "0 3px 5px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.2), 0 -1px 0 rgba(255,255,255,0.9), 1px 1px 0 rgba(255,255,255,0.8)",
                                                fontWeight: "600",
                                                letterSpacing: "0.2em",
                                            }}
                                        >
                                            {formattedCardNumber}
                                        </div>

                                        <div className="flex items-end justify-between">
                                            <div className="space-y-1">
                                                <div className="text-[10px] sm:text-xs text-slate-500 uppercase tracking-wider">Card Holder</div>
                                                <div
                                                    className="text-sm sm:text-base font-medium tracking-wide text-slate-800 uppercase"
                                                    style={{
                                                        textShadow:
                                                            "0 2px 4px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.15), 0 -1px 0 rgba(255,255,255,0.9), 1px 1px 0 rgba(255,255,255,0.7)",
                                                        fontWeight: "600",
                                                        letterSpacing: "0.1em",
                                                    }}
                                                >
                                                    {cardholderName}
                                                </div>
                                            </div>

                                            <div className="space-y-1 text-right">
                                                <div className="text-[10px] sm:text-xs text-slate-500 uppercase tracking-wider">Expires</div>
                                                <div
                                                    className="text-sm sm:text-base font-medium tracking-wide text-slate-800"
                                                    style={{
                                                        textShadow:
                                                            "0 2px 4px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.15), 0 -1px 0 rgba(255,255,255,0.9), 1px 1px 0 rgba(255,255,255,0.7)",
                                                        fontWeight: "600",
                                                        letterSpacing: "0.1em",
                                                    }}
                                                >
                                                    {expiryDate}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-end justify-between">
                                        <div className="text-xs sm:text-sm font-medium tracking-wider text-slate-600">SIGNATURE</div>
                                        <div className="flex items-center pr-2">
                                            <svg className="h-8 sm:h-10 w-auto" viewBox="0 0 750 471" xmlns="http://www.w3.org/2000/svg">
                                                <path
                                                    fill="#1434CB"
                                                    d="M278.2 334.2h-52.8L268 131.8h52.8l-42.6 202.4zm189.3-198.9c-10.5-4.2-27-8.7-47.6-8.7-52.5 0-89.5 28-89.8 68.1-.4 29.6 26.4 46.1 46.6 56 20.6 10.1 27.5 16.6 27.5 25.6-.1 13.8-16.5 20.1-31.8 20.1-21.3 0-32.6-3.1-50.1-10.8l-6.8-3.3-7.5 46.4c12.4 5.7 35.3 10.7 59.2 11 55.8 0 92-27.6 92.3-70.3.2-23.5-14-41.4-44.8-56.1-18.7-9.5-30.1-15.9-30.1-25.5.1-8.6 9.6-17.7 30.4-17.7 17.4-.3 30 3.7 39.8 7.9l4.8 2.4 7.3-44.9zm110.7-3.5h-40.8c-12.6 0-22.1 3.6-27.6 16.9L436.3 334.2h55.7s9.1-25.3 11.2-30.8h68.3c1.6 7.3 6.5 30.8 6.5 30.8h49.2l-42.9-202.4h-6.1zm-65.7 131.5c4.4-11.7 21.2-57.3 21.2-57.3-.3.5 4.4-11.9 7.1-19.7l3.6 17.9s10.3 49.6 12.4 59.1h-44.3zm-256.5-131.5l-51.9 137.9-5.5-28.2c-9.6-32.7-39.6-68.1-73.2-85.8l47.3 178h56.2l83.6-202h-56.5z"
                                                />
                                                <path
                                                    fill="#FAA61A"
                                                    d="M131.9 131.8H45.2L44.5 136c66.5 17 110.5 58 128.8 107.2l-18.6-94.7c-3.2-13-12.5-16.4-22.8-16.7z"
                                                />
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Card Back */}
                            <div
                                className="absolute inset-0 rounded-2xl overflow-hidden select-none"
                                style={{
                                    backfaceVisibility: "hidden",
                                    transform: "rotateY(180deg)",
                                }}
                            >
                                {/* Frozen Overlay */}
                                {isFrozen && (
                                    <div className="absolute inset-0 bg-white/80 backdrop-blur-sm z-50 flex items-center justify-center rounded-2xl">
                                        <div className="text-center space-y-2">
                                            <Snowflake className="h-12 w-12 text-blue-600 mx-auto animate-pulse" />
                                            <p className="text-lg font-bold text-gray-800">Card Frozen</p>
                                            <p className="text-sm text-gray-600">This card is temporarily disabled</p>
                                        </div>
                                    </div>
                                )}
                                <div className="absolute inset-0 bg-gradient-to-br from-blue-50 via-cyan-50 to-blue-100 rounded-2xl">
                                    <div className="absolute inset-0 opacity-[0.08]">
                                        <svg className="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500">
                                            <defs>
                                                <linearGradient id="visaBackWaveGradient1" x1="0%" y1="0%" x2="100%" y2="100%">
                                                    <stop offset="0%" stopColor="#8B5CF6" stopOpacity="1" />
                                                    <stop offset="100%" stopColor="#EC4899" stopOpacity="0.8" />
                                                </linearGradient>
                                                <linearGradient id="visaBackWaveGradient2" x1="0%" y1="100%" x2="100%" y2="0%">
                                                    <stop offset="0%" stopColor="#7C3AED" stopOpacity="0.8" />
                                                    <stop offset="100%" stopColor="#DB2777" stopOpacity="0.6" />
                                                </linearGradient>
                                            </defs>
                                            <path d="M0,120 Q200,60 400,120 T800,120 L800,0 L0,0 Z" fill="url(#visaBackWaveGradient1)" />
                                            <path d="M0,380 Q200,320 400,380 T800,380 L800,500 L0,500 Z" fill="url(#visaBackWaveGradient2)" />
                                        </svg>
                                    </div>
                                </div>

                                <div className="relative h-full flex flex-col">
                                    <div className="flex-1 px-4 sm:px-6 pt-5 sm:pt-6 pb-4 sm:pb-5 flex flex-col justify-between">
                                        <div className="space-y-2.5 sm:space-y-3">
                                            <div className="bg-white/95 h-10 sm:h-11 flex items-center px-3 sm:px-4 relative">
                                                <div className="absolute inset-0 bg-gradient-to-r from-white/50 via-transparent to-white/30" />
                                                <div
                                                    className="relative text-gray-800 italic text-xs sm:text-sm font-medium"
                                                    style={{ fontFamily: "cursive" }}
                                                >
                                                    {cardholderName}
                                                </div>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <div className="flex-1 bg-white/95 h-8 sm:h-9" />
                                                <div className="bg-white/95 px-2.5 sm:px-3 py-1.5 sm:py-2 min-w-[55px] sm:min-w-[65px]">
                                                    <div className="text-[8px] sm:text-[9px] text-gray-600 mb-0.5 uppercase tracking-wider font-medium">
                                                        CVV
                                                    </div>
                                                    <div className="font-mono text-sm sm:text-base font-bold text-gray-900 tracking-wider">{cvv}</div>
                                                </div>
                                            </div>

                                            <div className="text-gray-500 text-[8px] sm:text-[9px] leading-relaxed">
                                                Authorized signature required. Not valid unless signed.
                                            </div>
                                        </div>

                                        <div className="space-y-1.5 sm:space-y-2">
                                            <div className="text-gray-600 text-[9px] sm:text-[10px] leading-relaxed">
                                                For customer service call 1-800-BELIEVE
                                            </div>

                                            <div className="flex items-center justify-between">
                                                <svg className="h-6 sm:h-7 w-auto" viewBox="0 0 750 471" xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        fill="#1434CB"
                                                        d="M278.2 334.2h-52.8L268 131.8h52.8l-42.6 202.4zm189.3-198.9c-10.5-4.2-27-8.7-47.6-8.7-52.5 0-89.5 28-89.8 68.1-.4 29.6 26.4 46.1 46.6 56 20.6 10.1 27.5 16.6 27.5 25.6-.1 13.8-16.5 20.1-31.8 20.1-21.3 0-32.6-3.1-50.1-10.8l-6.8-3.3-7.5 46.4c12.4 5.7 35.3 10.7 59.2 11 55.8 0 92-27.6 92.3-70.3.2-23.5-14-41.4-44.8-56.1-18.7-9.5-30.1-15.9-30.1-25.5.1-8.6 9.6-17.7 30.4-17.7 17.4-.3 30 3.7 39.8 7.9l4.8 2.4 7.3-44.9zm110.7-3.5h-40.8c-12.6 0-22.1 3.6-27.6 16.9L436.3 334.2h55.7s9.1-25.3 11.2-30.8h68.3c1.6 7.3 6.5 30.8 6.5 30.8h49.2l-42.9-202.4h-6.1zm-65.7 131.5c4.4-11.7 21.2-57.3 21.2-57.3-.3.5 4.4-11.9 7.1-19.7l3.6 17.9s10.3 49.6 12.4 59.1h-44.3zm-256.5-131.5l-51.9 137.9-5.5-28.2c-9.6-32.7-39.6-68.1-73.2-85.8l47.3 178h56.2l83.6-202h-56.5z"
                                                    />
                                                    <path
                                                        fill="#FAA61A"
                                                        d="M131.9 131.8H45.2L44.5 136c66.5 17 110.5 58 128.8 107.2l-18.6-94.7c-3.2-13-12.5-16.4-22.8-16.7z"
                                                    />
                                                </svg>
                                                <div className="text-gray-400 text-[8px] sm:text-[9px] italic">Hologram</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 text-center">
                        <p className="text-sm text-muted-foreground mb-4">Double-click to flip card</p>
                    </div>
                </div>

                {/* Secure Card Details */}
                <div className="space-y-3 pt-2">
                    <div className="p-4 bg-muted rounded-lg border border-border space-y-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Lock className="h-4 w-4 text-primary" />
                                <span className="text-sm font-semibold">Secure Card Details</span>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setShowSecureDetails(!showSecureDetails)}
                                className="h-8"
                            >
                                {showSecureDetails ? (
                                    <>
                                        <EyeOff className="h-4 w-4 mr-1" />
                                        Hide
                                    </>
                                ) : (
                                    <>
                                        <Eye className="h-4 w-4 mr-1" />
                                        Show
                                    </>
                                )}
                            </Button>
                        </div>

                        {showSecureDetails && (
                            <div className="space-y-3 pt-2 border-t border-border">
                                {/* Card Number */}
                                <div className="space-y-2">
                                    <label className="text-xs text-muted-foreground">Card Number</label>
                                    <div className="flex items-center gap-2">
                                        <div className="flex-1 p-3 bg-background rounded-lg border border-border font-mono text-sm">
                                            {showSecureDetails ? formattedCardNumber : `${maskedCardNumber} ${last4Digits}`}
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handleCopy('card', cardNumber)}
                                            className="h-9 w-9 p-0"
                                        >
                                            {copiedField === 'card' ? (
                                                <Check className="h-4 w-4 text-green-600" />
                                            ) : (
                                                <Copy className="h-4 w-4" />
                                            )}
                                        </Button>
                                    </div>
                                </div>

                                {/* CVV */}
                                <div className="space-y-2">
                                    <label className="text-xs text-muted-foreground">CVV</label>
                                    <div className="flex items-center gap-2">
                                        <div className="flex-1 p-3 bg-background rounded-lg border border-border font-mono text-sm">
                                            {showSecureDetails ? cvv : '•••'}
                                        </div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => handleCopy('cvv', cvv)}
                                            className="h-9 w-9 p-0"
                                        >
                                            {copiedField === 'cvv' ? (
                                                <Check className="h-4 w-4 text-green-600" />
                                            ) : (
                                                <Copy className="h-4 w-4" />
                                            )}
                                        </Button>
                                    </div>
                                </div>

                                <div className="p-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                                    <p className="text-xs text-blue-800 dark:text-blue-200">
                                        <Lock className="h-3 w-3 inline mr-1" />
                                        Your card details are encrypted and secure. Never share your CVV with anyone.
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* Card Controls */}
                <div className="space-y-3 pt-2">
                    <div className="p-3 bg-muted rounded-lg border border-border">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-muted-foreground">Card Status</span>
                            </div>
                            <span className={`text-xs font-semibold ${isFrozen ? 'text-blue-600' : 'text-green-600'}`}>
                                {isFrozen ? 'Frozen' : 'Active'}
                            </span>
                        </div>
                    </div>
                    
                    <Button
                        onClick={() => setIsFrozen(!isFrozen)}
                        variant={isFrozen ? "default" : "outline"}
                        className="w-full"
                    >
                        {isFrozen ? (
                            <>
                                <Unlock className="h-4 w-4 mr-2" />
                                Unfreeze Card
                            </>
                        ) : (
                            <>
                                <Snowflake className="h-4 w-4 mr-2" />
                                Freeze Card
                            </>
                        )}
                    </Button>
                </div>
            </div>
        </motion.div>
    )
}

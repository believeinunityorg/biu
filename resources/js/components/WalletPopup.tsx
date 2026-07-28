"use client"

import React, { useState, useEffect, useRef, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { X, Wallet, Copy, Check, RefreshCw, ChevronDown, Activity, ArrowUpRight, ArrowDownLeft, ArrowRightLeft, ArrowLeft, QrCode, CheckCircle2, Search, Building2, User, Plus, AlertCircle, Shield, FileCheck, Clock, ExternalLink, Upload, FileImage, Loader2, CreditCard } from 'lucide-react'
import { motion, AnimatePresence } from 'framer-motion'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { showSuccessToast, showErrorToast } from '@/lib/toast'
import { TermsOfService } from './TermsOfService'
import { ImageUploadDropzone } from './ImageUploadDropzone'
import { DocumentUploadDropzone } from './DocumentUploadDropzone'
import { SubscriptionRequiredModal } from './SubscriptionRequiredModal'
import { usePage, router } from '@inertiajs/react'
import {
    applyWalletBridgeStatusPayload,
    isBridgeKycPending,
    isBridgeKybPending,
    isWalletBridgeAccountVerified,
    formatBridgeVerificationStatusLabel,
    type WalletBridgeStatusPayload,
} from '@/lib/bridge-verification'
import { pickWalletBalance } from '@/lib/wallet-balance-fetch'
import { useWalletBridgeRealtime, type WalletBridgeUpdatePayload } from '@/hooks/use-wallet-bridge-realtime'
import { patchActivitiesFromBridgeUpdate, prependPendingTransferActivity } from '@/lib/patch-wallet-activities'
import {
    clearRecentWalletActivityCache,
    clearAllWalletActivityCache,
    patchRecentWalletActivityCache,
    setRecentWalletActivityCache,
} from '@/lib/wallet-activity-cache'
import type { ActionView } from '@/components/wallet/types'
import {
    SuccessMessage,
    BalanceDisplay,
    SwapView,
    ReceiveMoney,
    AddMoney,
    SendMoney,
    WalletScreen,
    ActivityList,
    ActivityScreen,
    TransactionDetails,
    ConnectWallet,
    CreateWallet,
    ExternalAccounts,
    AddBankAccount,
    TransferFromExternal,
    WithdrawToExternal,
    VirtualCard,
    ServicesMenu,
    KYCForm,
    KycVerificationStatusPanel,
    KYBForm,
    SplashScreen,
    BalanceSkeleton,
    ActivitySkeleton,
    QRCodeSkeleton,
    DepositInstructionsSkeleton,
    WaitingScreen,
    BridgeVerificationModal,
    BridgePersonaVerificationPanel,
    getCsrfToken as getWalletCsrfToken,
    resolveBridgeVerificationWidgetUrl,
    isBridgePersonaVerificationCompleteMessage,
    walletFetch,
    isCsrfMismatch,
    formatAddress as formatWalletAddress
} from './wallet'

interface WalletPopupProps {
    isOpen: boolean
    onClose: () => void
    organizationName?: string
    /** Deep-link into a wallet sub-view when the popup opens (e.g. virtual_card). */
    initialView?: ActionView
}

// Use the getCsrfToken from wallet utils
const getCsrfToken = getWalletCsrfToken

interface SharedData {
    auth: {
        user: {
            id: number
            name: string
            email: string
            role?: string
        } | null
    }
}

export function WalletPopup({ isOpen, onClose, organizationName, initialView = 'main' }: WalletPopupProps) {
    const { auth } = usePage<SharedData>().props
    const [walletBalance, setWalletBalance] = useState<number | null>(null)
    const [walletAddress, setWalletAddress] = useState<string | null>(null)
    const [isLoading, setIsLoading] = useState(false)
    const [isInitialLoading, setIsInitialLoading] = useState(true) // Track initial load for splash screen
    const [isConnectingWallet, setIsConnectingWallet] = useState(false) // Track wallet connection loading
    const [hasSubscription, setHasSubscription] = useState<boolean | null>(null)
    const [showSubscriptionModal, setShowSubscriptionModal] = useState(false)
    const [copied, setCopied] = useState(false)
    const [activeTab, setActiveTab] = useState<'account' | 'activity'>('account')
    const [actionView, setActionView] = useState<ActionView>(initialView || 'main')
    const [externalAccounts, setExternalAccounts] = useState<Array<{
        id: string;
        account_number: string;
        routing_number: string;
        account_type: string;
        account_holder_name: string;
        bank_name?: string;
        status: string;
    }>>([])
    const [isLoadingExternalAccounts, setIsLoadingExternalAccounts] = useState(false)
    const [isRemovingBankAccount, setIsRemovingBankAccount] = useState(false)
    const [transferAmount, setTransferAmount] = useState('')
    const [withdrawAmount, setWithdrawAmount] = useState('')
    const [withdrawPaymentRail, setWithdrawPaymentRail] = useState<'ach' | 'wire'>('ach')
    const [bankWithdrawalAvailable, setBankWithdrawalAvailable] = useState(true)
    const [selectedExternalAccount, setSelectedExternalAccount] = useState<string>('')
    const [sendAmount, setSendAmount] = useState('')
    const [addMoneyAmount, setAddMoneyAmount] = useState('')
    const [recipientSearch, setRecipientSearch] = useState('')
    const [selectedRecipient, setSelectedRecipient] = useState<{ id: string; type: string; name: string; email?: string; display_name: string; address?: string } | null>(null)
    const [searchResults, setSearchResults] = useState<Array<{ id: string; type: string; name: string; email?: string; display_name: string; address?: string }>>([])
    const [isLoadingSearch, setIsLoadingSearch] = useState(false)
    const [showDropdown, setShowDropdown] = useState(false)
    const searchInputRef = useRef<HTMLInputElement>(null)
    const dropdownRef = useRef<HTMLDivElement>(null)
    const [swapAmount, setSwapAmount] = useState('')
    const [swapFrom, setSwapFrom] = useState('USD')
    const [swapTo, setSwapTo] = useState('USD')
    const [showSuccess, setShowSuccess] = useState(false)
    const [successMessage, setSuccessMessage] = useState('')
    const [successType, setSuccessType] = useState<'send' | 'receive' | 'swap' | 'addMoney' | null>(null)
    const [selectedActivity, setSelectedActivity] = useState<{
        id: string | number
        type: string
        amount: number
        date: string
        status: string
        donor_name: string
        donor_email?: string
        frequency: string
        message?: string
        transaction_id?: string
        is_outgoing?: boolean
        recipient_type?: string
    } | null>(null)
    const [activities, setActivities] = useState<Array<{
        id: string | number;
        type: string;
        amount: number;
        date: string;
        status: string;
        donor_name: string;
        donor_email?: string;
        frequency: string;
        message?: string;
        transaction_id?: string;
        is_outgoing?: boolean;
        recipient_type?: string;
    }>>([])
    const [isLoadingActivities, setIsLoadingActivities] = useState(false)
    const [currentPage, setCurrentPage] = useState(1)
    const activitiesLoadedWhileOpenRef = useRef(false)
    const [hasMoreActivities, setHasMoreActivities] = useState(false)
    const [isLoadingMore, setIsLoadingMore] = useState(false)
    const [bridgeInitialized, setBridgeInitialized] = useState(false)
    const [hasWallet, setHasWallet] = useState(false)
    const [isSandbox, setIsSandbox] = useState(false)
    const [bridgeEndorsements, setBridgeEndorsements] = useState<{
        us_ach_available?: boolean
        sepa_available?: boolean
        region_blocks_us_ach?: boolean
        base_customer_reason?: string | null
        custodial_wallet_ok?: boolean
    } | null>(null)
    const [hasCardWallet, setHasCardWallet] = useState<boolean | null>(null)
    const [isCheckingCardWallet, setIsCheckingCardWallet] = useState(false)
    const [hasBankAccounts, setHasBankAccounts] = useState<boolean | null>(null)
    const [showAddBankFormOnEntry, setShowAddBankFormOnEntry] = useState(false)
    const [depositInstructions, setDepositInstructions] = useState<{
        bank_name?: string;
        bank_address?: string;
        bank_routing_number?: string;
        bank_account_number?: string;
        bank_beneficiary_name?: string;
        bank_beneficiary_address?: string;
        iban?: string;
        bic?: string;
        payment_rail?: string;
        payment_rails?: string[];
        currency?: string;
    } | null>(null)
    const [isLoadingDepositInstructions, setIsLoadingDepositInstructions] = useState(false)
    const [isCreatingDepositAccount, setIsCreatingDepositAccount] = useState(false)
    const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<'ach' | 'wire'>('ach')
    const [qrCodeUrl, setQrCodeUrl] = useState<string | null>(null)
    const [receiveDepositInstructions, setReceiveDepositInstructions] = useState<{
        bank_name?: string;
        bank_address?: string;
        bank_routing_number?: string;
        bank_account_number?: string;
        bank_beneficiary_name?: string;
        bank_beneficiary_address?: string;
        payment_rail?: string;
        payment_rails?: string[];
        currency?: string;
    } | null>(null)
    const [isLoadingReceiveData, setIsLoadingReceiveData] = useState(false)
    // Bridge KYC/KYB Link statuses: not_started, incomplete, under_review, awaiting_questionnaire, awaiting_ubo, approved, rejected, paused, offboarded
    const [kycStatus, setKycStatus] = useState<'not_started' | 'incomplete' | 'under_review' | 'awaiting_questionnaire' | 'awaiting_ubo' | 'approved' | 'rejected' | 'paused' | 'offboarded'>('not_started')
    const [kybStatus, setKybStatus] = useState<'not_started' | 'incomplete' | 'under_review' | 'awaiting_questionnaire' | 'awaiting_ubo' | 'approved' | 'rejected' | 'paused' | 'offboarded'>('not_started')
    const [kycLinkUrl, setKycLinkUrl] = useState<string | null>(null)
    const [kybLinkUrl, setKybLinkUrl] = useState<string | null>(null)
    const [kycWidgetUrl, setKycWidgetUrl] = useState<string | null>(null)
    const [kybWidgetUrl, setKybWidgetUrl] = useState<string | null>(null)
    const [tosLinkUrl, setTosLinkUrl] = useState<string | null>(null)
    const [tosStatus, setTosStatus] = useState<'pending' | 'accepted' | 'approved' | 'rejected'>('pending')
    const [requiresVerification, setRequiresVerification] = useState(false)
    const [verificationType, setVerificationType] = useState<'kyc' | 'kyb' | null>(null)
    const [showVerificationIframe, setShowVerificationIframe] = useState(false)
    const [isLoadingVerificationWidget, setIsLoadingVerificationWidget] = useState(false)
    const [verificationModalWidgetUrl, setVerificationModalWidgetUrl] = useState<string | null>(null)

    const applyBackendKycStatus = (backendStatus: string) => {
        setKycStatus(backendStatus as typeof kycStatus)

        if (backendStatus === 'approved') {
            setRequiresVerification(false)
        } else if (backendStatus !== 'not_started') {
            setRequiresVerification(true)
            setVerificationType('kyc')
        }
    }

    const refreshKycStatusAfterSubmission = async () => {
        try {
            const statusResponse = await fetch(`/wallet/bridge/status?t=${Date.now()}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
            })

            if (!statusResponse.ok) {
                return
            }

            const statusData = await statusResponse.json()
            if (statusData.success && statusData.kyc_status) {
                applyBackendKycStatus(statusData.kyc_status)
            }

            if (statusData.requires_verification !== undefined) {
                setRequiresVerification(statusData.requires_verification)
            }
            if (statusData.verification_type) {
                setVerificationType(statusData.verification_type)
            }
        } catch (error) {
            console.error('Failed to refresh KYC status:', error)
        }
    }
    const [tosIframeUrl, setTosIframeUrl] = useState<string | null>(null)
    const [signedAgreementId, setSignedAgreementId] = useState<string | null>(null)
    const processedTosAgreementIdsRef = useRef<Set<string>>(new Set())
    const lastBridgeStatusCheckRef = useRef(0)
    const bridgeStatusCheckMinIntervalMs = 5000

    // Custom KYC form state
    const [kycFormData, setKycFormData] = useState({
        first_name: '',
        last_name: '',
        email: '',
        birth_date: '',
        street_line_1: '',
        city: '',
        subdivision: '',
        postal_code: '',
        country: 'USA',
        ssn: '',
        id_type: '',
        id_number: '',
        id_front_image: '' as string, // Store as base64 string
        id_back_image: '' as string, // Store as base64 string
    })

    // Business KYB form state - business info comes from organization, only need control person
    const [kybFormData, setKybFormData] = useState({
        business_name: '',
        email: '',
        street_line_1: '',
        city: '',
        subdivision: '',
        postal_code: '',
        country: 'USA',
        ein: '',
        business_description: '',
        business_industry: '',
        primary_website: '',
        // Bridge "business_type" (must be one of: cooperative, corporation, llc, other, partnership, sole_prop, trust)
        business_type: '' as '' | 'cooperative' | 'corporation' | 'llc' | 'other' | 'partnership' | 'sole_prop' | 'trust',
        // Standard KYB Requirements
        entity_type: '', // LLC, Corp, Partnership, etc.
        dao_status: false, // Is this a DAO?
        // Principal Operating Address (separate from registered address)
        physical_address: {
            street_line_1: '',
            street_line_2: '',
            city: '',
            subdivision: '',
            postal_code: '',
            country: 'USA',
        },
        // Enhanced KYB Requirements
        source_of_funds: '',
        annual_revenue: '',
        transaction_volume: '', // Expected Monthly Transaction Volume (USD)
        account_purpose: '',
        high_risk_activities: '',
        high_risk_geographies: '',
        // Control Person (Beneficial Owner) - required by Bridge
        control_person: {
            first_name: '',
            last_name: '',
            email: '',
            birth_date: '',
            ssn: '',
            title: '',
            ownership_percentage: '',
            street_line_1: '',
            city: '',
            state: '',
            postal_code: '',
            country: 'USA',
            id_type: '',
            id_number: '',
            id_front_image: '',
            id_back_image: '',
        },
        // Business Documents
        business_formation_document: '', // Base64 PDF
        business_ownership_document: '', // Base64 PDF
        proof_of_address_document: '', // Base64 PDF (conditional)
        proof_of_nature_of_business: '', // Base64 PDF (for Bridge verification)
        determination_letter_501c3: '', // Base64 PDF (stored only for us, not sent to Bridge)
    })

    // KYB Step tracking: 'control_person' | 'business_documents' | 'kyc_verification'
    const [kybStep, setKybStep] = useState<'control_person' | 'business_documents' | 'kyc_verification'>('control_person')
    const [controlPersonSubmitted, setControlPersonSubmitted] = useState(false)
    const [controlPersonKycLink, setControlPersonKycLink] = useState<string | null>(null)
    const [controlPersonKycIframeUrl, setControlPersonKycIframeUrl] = useState<string | null>(null)
    const [kybSubmissionStatus, setKybSubmissionStatus] = useState<string | null>(null)
    const [businessDocumentsSubmitted, setBusinessDocumentsSubmitted] = useState(false)
    const [documentStatuses, setDocumentStatuses] = useState<Record<string, 'pending' | 'approved' | 'rejected'>>({})
    const [documentRejectionReasons, setDocumentRejectionReasons] = useState<Record<string, string>>({})

    // Validation errors for Control Person fields
    const [controlPersonErrors, setControlPersonErrors] = useState<Record<string, string>>({})
    const [businessDocumentErrors, setBusinessDocumentErrors] = useState<Record<string, string>>({})

    // Admin requested fields for re-fill
    const [requestedFields, setRequestedFields] = useState<string[]>([])
    const [refillMessage, setRefillMessage] = useState<string | null>(null)

    // Helper function to check if a field should be shown
    const shouldShowField = (fieldPath: string): boolean => {
        // If no requested fields, show all fields (normal flow)
        if (!requestedFields || requestedFields.length === 0) {
            return true
        }
        // If requested fields exist, only show those fields
        const shouldShow = requestedFields.includes(fieldPath)
        return shouldShow
    }

    // Pre-fill KYC form with user data when popup opens (only if fields are empty)
    useEffect(() => {
        if (isOpen && auth?.user) {
            const userName = auth.user.name || ''
            const userEmail = auth.user.email || ''

            // Only populate if fields are currently empty
            if (!kycFormData.first_name && !kycFormData.email && userName && userEmail) {
                // Split name into first_name and last_name
                const nameParts = userName.trim().split(/\s+/)
                const firstName = nameParts[0] || ''
                const lastName = nameParts.slice(1).join(' ') || ''

                setKycFormData(prev => ({
                    ...prev,
                    first_name: firstName,
                    last_name: lastName,
                    email: userEmail,
                }))
            }
        }
    }, [isOpen, auth?.user])

    // Remove legacy sessionStorage flags from older builds — backend kyc_status is the source of truth.
    useEffect(() => {
        if (!isOpen || !auth?.user?.id) {
            return
        }

        sessionStorage.removeItem(`bridge_kyc_submitted_${auth.user.id}`)
        sessionStorage.removeItem(`bridge_kyc_customer_${auth.user.id}`)
    }, [isOpen, auth?.user?.id])

    // Auto-switch to correct KYB step when business-related fields are requested
    useEffect(() => {
        if (!requestedFields || requestedFields.length === 0) {
            return
        }

        // Step 1 (control_person) fields
        const controlPersonFields = [
            'control_person.first_name', 'control_person.last_name', 'control_person.email',
            'control_person.birth_date', 'control_person.ssn', 'control_person.title',
            'control_person.ownership_percentage', 'control_person.street_line_1',
            'control_person.city', 'control_person.state', 'control_person.postal_code',
            'control_person.country', 'control_person.id_type', 'control_person.id_number',
            'control_person.id_front_image', 'control_person.id_back_image'
        ]

        // Step 2 (business_documents) fields
        const businessDocumentFields = [
            'business_formation_document', 'business_ownership_document',
            'proof_of_address_document', 'proof_of_nature_of_business',
            'determination_letter_501c3', 'entity_type', 'business_description',
            'business_industry', 'primary_website', 'source_of_funds',
            'annual_revenue', 'transaction_volume', 'account_purpose',
            'high_risk_activities', 'high_risk_geographies', 'dao_status'
        ]

        // Physical address fields (part of Step 2)
        const physicalAddressFields = [
            'physical_address.street_line_1', 'physical_address.street_line_2',
            'physical_address.city', 'physical_address.subdivision',
            'physical_address.postal_code', 'physical_address.country'
        ]

        // Business info fields that can appear in Step 1 (read-only) or Step 2
        const businessInfoFields = ['business_name', 'ein', 'email', 'street_line_1']

        // Check which step the requested fields belong to
        const hasControlPersonFields = requestedFields.some((field: string) =>
            controlPersonFields.includes(field) || field.startsWith('control_person.')
        )

        const hasBusinessDocumentFields = requestedFields.some((field: string) =>
            businessDocumentFields.includes(field) ||
            physicalAddressFields.includes(field) ||
            field.startsWith('physical_address.')
        )

        // Only check business info fields if they're not part of control person step
        const hasOnlyBusinessInfoFields = requestedFields.some((field: string) =>
            businessInfoFields.includes(field)
        ) && !hasControlPersonFields && !hasBusinessDocumentFields

        // Auto-switch to KYB if business fields are detected
        const shouldBeKyb = hasControlPersonFields || hasBusinessDocumentFields || hasOnlyBusinessInfoFields
        if (shouldBeKyb && verificationType !== 'kyb') {
            setVerificationType('kyb')
        }

        // Auto-switch to the correct step within KYB
        // Check the condition directly instead of relying on state (which updates asynchronously)
        if (shouldBeKyb || verificationType === 'kyb') {
            if (hasControlPersonFields) {
                setKybStep('control_person')
            } else if (hasBusinessDocumentFields || hasOnlyBusinessInfoFields) {
                setKybStep('business_documents')
            }
            // Note: Step 3 (kyc_verification) is typically triggered after documents are approved
        }
    }, [requestedFields, verificationType])

    // Check Bridge status and fetch balance - moved outside useEffect so it can be called from other functions
    const checkBridgeAndFetchBalance = async (options?: { force?: boolean }) => {
        const now = Date.now()
        if (!options?.force && now - lastBridgeStatusCheckRef.current < bridgeStatusCheckMinIntervalMs) {
            return
        }
        lastBridgeStatusCheckRef.current = now

        if (isInitialLoading) {
            setIsInitialLoading(true)
        } else {
            setIsLoading(true)
        }
        try {
            // First, check if Bridge wallet is initialized (without fetching balance)
            const statusResponse = await fetch(`/wallet/bridge/status?t=${Date.now()}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
            })

            const statusData = await statusResponse.json()

            // Pre-fill form with organization data if available
            // Only repopulate if there are no requested fields from backend (to avoid repopulating after refill submission)
            // Check statusData.requested_fields first (from backend), then fall back to state
            const hasRequestedFields = (statusData.requested_fields && Array.isArray(statusData.requested_fields) && statusData.requested_fields.length > 0) ||
                (requestedFields && requestedFields.length > 0)

            if (statusData.organization_data && !hasRequestedFields) {
                setKybFormData(prev => ({
                    ...prev,
                    business_name: statusData.organization_data.business_name || prev.business_name || '',
                    email: statusData.organization_data.email || prev.email || '',
                    ein: statusData.organization_data.ein || prev.ein || '',
                    street_line_1: statusData.organization_data.street_line_1 || prev.street_line_1 || '',
                    city: statusData.organization_data.city || prev.city || '',
                    subdivision: statusData.organization_data.state || prev.subdivision || '',
                    postal_code: statusData.organization_data.postal_code || prev.postal_code || '',
                    country: statusData.organization_data.country || prev.country || 'USA',
                }))
            }

            if (statusData.success && statusData.initialized) {
                const bridgeStatus = applyWalletBridgeStatusPayload(
                    statusData as WalletBridgeStatusPayload,
                )

                // Bridge is initialized (customer exists)
                setBridgeInitialized(bridgeStatus.bridgeInitialized)

                // Update verification status if provided
                setRequiresVerification(bridgeStatus.requiresVerification)
                setTosStatus(bridgeStatus.tosStatus)

                if (statusData.kyc_status) {
                    applyBackendKycStatus(statusData.kyc_status)
                }
                if (statusData.kyb_status) {
                    setKybStatus(statusData.kyb_status)
                    if (statusData.kyb_status === 'approved') {
                        setTosStatus('accepted')
                    }
                }
                if (statusData.tos_accepted === true) {
                    setTosStatus('accepted')
                }
                if (isWalletBridgeAccountVerified(statusData as WalletBridgeStatusPayload)) {
                    setRequiresVerification(false)
                }
                if (statusData.tos_status || statusData.tos_accepted) {
                    const isBackendAccepted =
                        statusData.tos_accepted === true ||
                        statusData.tos_status === 'accepted' ||
                        statusData.tos_status === 'approved' ||
                        statusData.kyb_status === 'approved'

                    // If TOS is accepted, hide the iframe immediately
                    if (isBackendAccepted) {
                        setTosIframeUrl(null)
                        // If TOS is accepted but we don't have signedAgreementId, try to get it
                        if (!signedAgreementId) {
                            // The signed_agreement_id should be in bridge_metadata, but if not, backend will handle it
                            // Just ensure we know TOS is accepted
                        }
                    }
                }
                if (statusData.tos_link) {
                    setTosLinkUrl(statusData.tos_link)
                }
                if (statusData.kyc_link) {
                    setKycLinkUrl(statusData.kyc_link)
                }
                if (statusData.kyb_link) {
                    setKybLinkUrl(statusData.kyb_link)
                }
                if (statusData.kyc_widget_url) {
                    setKycWidgetUrl(statusData.kyc_widget_url)
                }
                if (statusData.kyb_widget_url) {
                    setKybWidgetUrl(statusData.kyb_widget_url)
                }
                if (statusData.verification_type) {
                    setVerificationType(statusData.verification_type)
                }
                if (statusData.control_person_kyc_link) {
                    setControlPersonKycLink(statusData.control_person_kyc_link)
                }
                if (statusData.control_person_kyc_iframe_url) {
                    setControlPersonKycIframeUrl(statusData.control_person_kyc_iframe_url)
                }
                if (statusData.kyb_submission_status) {
                    setKybSubmissionStatus(statusData.kyb_submission_status)
                }
                // Check if business documents have been submitted
                if (statusData.business_documents_submitted !== undefined) {
                    setBusinessDocumentsSubmitted(statusData.business_documents_submitted)
                }
                // Load document statuses and rejection reasons
                if (statusData.document_statuses) {
                    const statuses: Record<string, 'pending' | 'approved' | 'rejected'> = {}
                    const rejectionReasons: Record<string, string> = {}

                    // Extract statuses and rejection reasons
                    Object.keys(statusData.document_statuses).forEach(key => {
                        if (key.endsWith('_rejection_reason')) {
                            // This is a rejection reason
                            const docType = key.replace('_rejection_reason', '')
                            rejectionReasons[docType] = statusData.document_statuses[key]
                        } else if (['pending', 'approved', 'rejected'].includes(statusData.document_statuses[key])) {
                            // This is a status - only include direct status keys (not metadata like _rejected_at, _approved_by, etc.)
                            // Direct status keys are: business_formation, business_ownership, proof_of_address, proof_of_nature_of_business, id_front, id_back
                            const directStatusKeys = ['business_formation', 'business_ownership', 'proof_of_address', 'proof_of_nature_of_business', 'determination_letter_501c3', 'id_front', 'id_back']
                            if (directStatusKeys.includes(key)) {
                                statuses[key] = statusData.document_statuses[key]
                            }
                        }
                    })

                    setDocumentStatuses(statuses)
                    setDocumentRejectionReasons(rejectionReasons)
                    
                }

                // Load requested fields and refill message from admin                }

                // Load requested fields and refill message from admin
                if (statusData.requested_fields && Array.isArray(statusData.requested_fields)) {
                    setRequestedFields(statusData.requested_fields)

                    // Step 1 (control_person) fields
                    const controlPersonFields = [
                        'control_person.first_name', 'control_person.last_name', 'control_person.email',
                        'control_person.birth_date', 'control_person.ssn', 'control_person.title',
                        'control_person.ownership_percentage', 'control_person.street_line_1',
                        'control_person.city', 'control_person.state', 'control_person.postal_code',
                        'control_person.country', 'control_person.id_type', 'control_person.id_number',
                        'control_person.id_front_image', 'control_person.id_back_image'
                    ]

                    // Step 2 (business_documents) fields
                    const businessDocumentFields = [
                        'business_formation_document', 'business_ownership_document',
                        'proof_of_address_document', 'proof_of_nature_of_business',
                        'determination_letter_501c3', 'entity_type', 'business_description',
                        'business_industry', 'primary_website', 'source_of_funds',
                        'annual_revenue', 'transaction_volume', 'account_purpose',
                        'high_risk_activities', 'high_risk_geographies', 'dao_status'
                    ]

                    const physicalAddressFields = [
                        'physical_address.street_line_1', 'physical_address.street_line_2',
                        'physical_address.city', 'physical_address.subdivision',
                        'physical_address.postal_code', 'physical_address.country'
                    ]

                    const businessInfoFields = ['business_name', 'ein', 'email', 'street_line_1']

                    const hasControlPersonFields = statusData.requested_fields.some((field: string) =>
                        controlPersonFields.includes(field) || field.startsWith('control_person.')
                    )

                    const hasBusinessDocumentFields = statusData.requested_fields.some((field: string) =>
                        businessDocumentFields.includes(field) ||
                        physicalAddressFields.includes(field) ||
                        field.startsWith('physical_address.')
                    )

                    const hasOnlyBusinessInfoFields = statusData.requested_fields.some((field: string) =>
                        businessInfoFields.includes(field)
                    ) && !hasControlPersonFields && !hasBusinessDocumentFields

                    // Auto-switch to KYB if business fields are detected
                    if ((hasControlPersonFields || hasBusinessDocumentFields || hasOnlyBusinessInfoFields) && statusData.verification_type !== 'kyb') {
                        setVerificationType('kyb')
                    }

                    // Auto-switch to the correct step within KYB
                    if (statusData.verification_type === 'kyb' || (hasControlPersonFields || hasBusinessDocumentFields || hasOnlyBusinessInfoFields)) {
                        if (hasControlPersonFields) {
                            setKybStep('control_person')
                        } else if (hasBusinessDocumentFields || hasOnlyBusinessInfoFields) {
                            setKybStep('business_documents')
                        }
                    }
                } else {
                    setRequestedFields([])
                }
                if (statusData.refill_message) {
                    setRefillMessage(statusData.refill_message)
                } else {
                    setRefillMessage(null)
                }

                // Load control person data for pre-filling form (especially for ID-only rejections)
                if (statusData.control_person_data) {
                    setKybFormData(prev => ({
                        ...prev,
                        control_person: {
                            ...prev.control_person,
                            // Only update fields that exist in the response, preserve others
                            id_type: statusData.control_person_data.id_type || prev.control_person.id_type || '',
                            id_number: statusData.control_person_data.id_number || prev.control_person.id_number || '',
                            first_name: statusData.control_person_data.first_name || prev.control_person.first_name || '',
                            last_name: statusData.control_person_data.last_name || prev.control_person.last_name || '',
                            email: statusData.control_person_data.email || prev.control_person.email || '',
                            birth_date: statusData.control_person_data.birth_date || prev.control_person.birth_date || '',
                            ssn: statusData.control_person_data.ssn || prev.control_person.ssn || '',
                            title: statusData.control_person_data.title || prev.control_person.title || '',
                            ownership_percentage: statusData.control_person_data.ownership_percentage?.toString() || prev.control_person.ownership_percentage || '',
                            street_line_1: statusData.control_person_data.street_line_1 || prev.control_person.street_line_1 || '',
                            city: statusData.control_person_data.city || prev.control_person.city || '',
                            state: statusData.control_person_data.state || prev.control_person.state || '',
                            postal_code: statusData.control_person_data.postal_code || prev.control_person.postal_code || '',
                            country: statusData.control_person_data.country || prev.control_person.country || '',
                        }
                    }))
                }

                // Track if wallet exists
                if (statusData.has_wallet !== undefined) {
                    setHasWallet(statusData.has_wallet)
                }

                if (statusData.endorsements && typeof statusData.endorsements === 'object') {
                    setBridgeEndorsements(statusData.endorsements)
                }

                // Track if we're in sandbox mode
                if (statusData.is_sandbox !== undefined) {
                    setIsSandbox(statusData.is_sandbox)
                }

                if (statusData.bank_withdrawal_available !== undefined) {
                    setBankWithdrawalAvailable(statusData.bank_withdrawal_available)
                }

                // Set wallet address if available
                if (statusData.wallet_address) {
                    setWalletAddress(statusData.wallet_address)
                }

                // Balance from Bridge API via /wallet/balance
                const balanceResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (balanceResponse.ok) {
                    const balanceData = await balanceResponse.json()
                    if (balanceData.success) {
                        // Bridge or ledger balance from API (never mix with local_balance fallback)
                        setWalletBalance(pickWalletBalance(balanceData))
                        setHasSubscription(balanceData.has_subscription ?? null)

                        // If no subscription, show subscription modal instead (for regular users only)
                        const isRegularUser = auth?.user?.role === 'user' || !auth?.user?.role
                        if (isRegularUser && balanceData.has_subscription === false) {
                            setShowSubscriptionModal(true)
                            setIsLoading(false)
                            setIsInitialLoading(false)
                            return
                        }
                    }
                }
                setIsLoading(false)
                setIsInitialLoading(false)
                return
            } else {
                // Wallet not initialized
                setBridgeInitialized(false)

                const fallbackResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (fallbackResponse.ok) {
                    const fallbackData = await fallbackResponse.json()
                    if (fallbackData.success) {
                        setWalletBalance(pickWalletBalance(fallbackData))
                        setHasSubscription(fallbackData.has_subscription ?? null)

                        // If no subscription, show subscription modal instead (for regular users only)
                        const isRegularUser = auth?.user?.role === 'user' || !auth?.user?.role
                        if (isRegularUser && fallbackData.has_subscription === false) {
                            setShowSubscriptionModal(true)
                            setIsLoading(false)
                            setIsInitialLoading(false)
                            return
                        }
                    }
                }
            }
        } catch (error) {
            console.error('Failed to check Bridge status:', error)
            setBridgeInitialized(false)
            setIsInitialLoading(false)
            // Try to get regular balance as fallback
            try {
                const fallbackResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })
                if (fallbackResponse.ok) {
                    const fallbackData = await fallbackResponse.json()
                    if (fallbackData.success) {
                        setWalletBalance(pickWalletBalance(fallbackData))
                        setHasSubscription(fallbackData.has_subscription ?? null)

                        // If no subscription, show subscription modal instead (for regular users only)
                        const isRegularUser = auth?.user?.role === 'user' || !auth?.user?.role
                        if (isRegularUser && fallbackData.has_subscription === false) {
                            setShowSubscriptionModal(true)
                            setIsLoading(false)
                            setIsInitialLoading(false)
                            return
                        }
                    }
                }
            } catch (fallbackError) {
                console.error('Failed to fetch fallback balance:', fallbackError)
                setWalletBalance(0)
            }
        } finally {
            setIsLoading(false)
            setIsInitialLoading(false)
        }
    }

    const bridgeBalanceRefreshRef = useRef<() => void>(() => {})
    bridgeBalanceRefreshRef.current = () => {
        void checkBridgeAndFetchBalance({ force: true })
    }

    const handleBridgeRealtimeUpdate = useCallback((payload: WalletBridgeUpdatePayload) => {
        if (payload.refresh_activity !== false) {
            setActivities((prev) => {
                const next = patchActivitiesFromBridgeUpdate(prev, payload)
                patchRecentWalletActivityCache(() => next)

                return next
            })
        }
        if (payload.refresh_balance !== false) {
            bridgeBalanceRefreshRef.current()
        }
    }, [])

    useWalletBridgeRealtime({
        userId: auth?.user?.id ?? null,
        enabled: Boolean(auth?.user?.id),
        onUpdate: handleBridgeRealtimeUpdate,
    })

    useEffect(() => {
        if (!isOpen || !isBridgeKycPending(kycStatus)) {
            return
        }

        const interval = window.setInterval(() => {
            void checkBridgeAndFetchBalance()
        }, 30000)

        return () => window.clearInterval(interval)
    }, [isOpen, kycStatus])

    // On open: only restore an already-connected wallet from local DB — do not auto-link Bridge.
    // New connections start on the Connect Wallet screen until the user clicks Connect.
    useEffect(() => {
        if (!isOpen) {
            setIsInitialLoading(true)
            setBridgeInitialized(false)
            setShowVerificationIframe(false)
            setIsLoadingVerificationWidget(false)
            setVerificationModalWidgetUrl(null)
            return
        }
        checkBridgeAndFetchBalance({ force: true })
    }, [isOpen])


    // Listen for iframe messages from Bridge verification widget
    useEffect(() => {
        const handleMessage = (event: MessageEvent) => {
            // Verify origin for security - allow Bridge domains and our own origin
            const allowedOrigins = ['bridge.withpersona.com', 'withpersona.com', 'bridge.xyz', 'sandbox.bridge.xyz', window.location.origin]
            const isAllowedOrigin = allowedOrigins.some(origin => event.origin.includes(origin))

            if (!isAllowedOrigin) {
                return
            }

            // Follow-up iframe messages after TOS redirect (no re-submit)
            if (event.data && typeof event.data === 'object') {
                const action = event.data.action
                if (action === 'close') {
                    setTosIframeUrl(null)
                    setTimeout(() => checkBridgeAndFetchBalance({ force: true }), 500)
                    return
                }
                if (action === 'checkStatus') {
                    setTimeout(() => checkBridgeAndFetchBalance(), 300)
                    return
                }
            }

            // Handle TOS acceptance from iframe callback
            if (event.data && typeof event.data === 'object' && event.data.signedAgreementId) {
                const agreementId = String(event.data.signedAgreementId).trim()
                if (!agreementId) {
                    return
                }

                if (processedTosAgreementIdsRef.current.has(agreementId)) {
                    return
                }
                processedTosAgreementIdsRef.current.add(agreementId)

                setSignedAgreementId(agreementId)

                const submitTosAcceptance = async () => {
                    try {
                        if (tosStatus === 'accepted' || tosStatus === 'approved') {
                            setTosIframeUrl(null)
                            return
                        }

                        const response = await walletFetch('/wallet/tos-callback', {
                            method: 'POST',
                            body: { signed_agreement_id: agreementId },
                        })

                        const data = await response.json().catch(() => ({} as Record<string, unknown>))
                        const message = typeof data.message === 'string' ? data.message : null

                        if (isCsrfMismatch(response.status, message)) {
                            processedTosAgreementIdsRef.current.delete(agreementId)
                            showErrorToast('Session expired. Please refresh the page and try again.')
                            return
                        }

                        if (!response.ok || !data.success) {
                            processedTosAgreementIdsRef.current.delete(agreementId)
                            showErrorToast(message || 'Failed to accept Terms of Service')
                            return
                        }

                            setTosIframeUrl(null)

                        const backendTosStatus =
                            typeof data.tos_status === 'string' ? data.tos_status : 'accepted'
                        setTosStatus(backendTosStatus === 'approved' ? 'approved' : 'accepted')

                                showSuccessToast('Terms of Service accepted successfully')

                            setTimeout(() => {
                            checkBridgeAndFetchBalance({ force: true })
                            }, 1000)
                    } catch (error) {
                        processedTosAgreementIdsRef.current.delete(agreementId)
                        console.error('Error submitting TOS acceptance:', error)
                        showErrorToast('Failed to accept Terms of Service')
                    }
                }

                submitTosAcceptance()
                return
            }

            // Handle verification status updates from iframe
            if (event.data && typeof event.data === 'object') {
                if (
                    isBridgePersonaVerificationCompleteMessage(event.data) ||
                    event.data.type === 'persona:inquiry:complete'
                ) {
                    void refreshKycStatusAfterSubmission()
                    void checkBridgeAndFetchBalance({ force: true })
                } else if (event.data.type === 'persona:inquiry:status') {
                    void refreshKycStatusAfterSubmission()
                }
            }
        }

        window.addEventListener('message', handleMessage)
        return () => {
            window.removeEventListener('message', handleMessage)
        }
    }, [])

    // Apply deep-link view whenever the popup opens (Bridge Cards, Add Money, etc.).
    useEffect(() => {
        if (!isOpen) {
            return
        }
        setActionView(initialView || 'main')
    }, [isOpen, initialView])

    // Fetch wallet activity once per popup open (Reverb patches update in place)
    useEffect(() => {
        if (!isOpen) {
            activitiesLoadedWhileOpenRef.current = false
            clearRecentWalletActivityCache()
            clearAllWalletActivityCache()
            return
        }

        if (activitiesLoadedWhileOpenRef.current) {
            return
        }

        const fetchActivities = async () => {
            setIsLoadingActivities(true)

            try {
                const response = await fetch(`/wallet/activity?t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (response.ok) {
                    const data = await response.json()
                    if (data.success) {
                        const nextActivities = data.activities || []
                        setActivities(nextActivities)
                        setRecentWalletActivityCache(nextActivities, data.has_more || false)
                        setHasMoreActivities(data.has_more || false)
                        setCurrentPage(1)
                        activitiesLoadedWhileOpenRef.current = true
                    }
                }
            } catch (error) {
                console.error('Failed to fetch wallet activity:', error)
            } finally {
                setIsLoadingActivities(false)
                setIsLoadingMore(false)
            }
        }

        setCurrentPage(1)
        setHasMoreActivities(false)
        void fetchActivities()
    }, [isOpen])

    // Handle "See More" button click
    const handleSeeMore = () => {
        setActionView('activity')
    }

    // Handle activity click to show details
    const handleActivityClick = (activity: {
        id: string | number
        type: string
        amount: number
        date: string
        status: string
        donor_name: string
        donor_email?: string
        frequency: string
        message?: string
        transaction_id?: string
        is_outgoing?: boolean
        recipient_type?: string
    }) => {
        setSelectedActivity(activity)
        setActionView('transaction_details')
    }

    const handleCopyAddress = () => {
        if (walletAddress) {
            navigator.clipboard.writeText(walletAddress)
            setCopied(true)
            showSuccessToast('Wallet address copied to clipboard')
            setTimeout(() => setCopied(false), 2000)
        }
    }

    // Fetch external accounts
    const fetchExternalAccounts = async () => {
        setIsLoadingExternalAccounts(true)
        try {
            const timestamp = Date.now()
            const response = await fetch(`/wallet/bridge/external-accounts?t=${timestamp}`, {
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
            
            if (data.success && data.data) {
                // Backend already returns mapped accounts in the correct format
                // Handle nested structure: { success: true, data: { count: X, data: [...] } }
                let accounts = []
                if (Array.isArray(data.data)) {
                    accounts = data.data
                } else if (data.data && Array.isArray(data.data.data)) {
                    accounts = data.data.data.map((account: any) => ({
                        id: account.id || '',
                        account_number: account.account?.last_4 || account.last_4 || '0000',
                        routing_number: account.account?.routing_number || '',
                        account_type: account.account?.checking_or_savings || 'checking',
                        account_holder_name: account.account_name || account.account_owner_name || '',
                        status: account.active ? 'verified' : 'pending',
                    }))
                }
                
                setExternalAccounts(accounts)
                setHasBankAccounts(accounts.length > 0)
            } else {
                setExternalAccounts([])
                setHasBankAccounts(false)
            }
        } catch {
            setExternalAccounts([])
            setHasBankAccounts(false)
        } finally {
            setIsLoadingExternalAccounts(false)
        }
    }

    const goToAddBankAccount = () => {
        setShowAddBankFormOnEntry(true)
        setActionView('external_accounts')
        if (hasBankAccounts === null) {
            fetchExternalAccounts()
        }
    }

    const applyDepositInstructions = (instructions: NonNullable<typeof depositInstructions>) => {
        setDepositInstructions(instructions)

        if (instructions.payment_rails && Array.isArray(instructions.payment_rails)) {
            if (instructions.payment_rails.includes('ach_push')) {
                setSelectedPaymentMethod('ach')
            } else if (instructions.payment_rails.includes('wire')) {
                setSelectedPaymentMethod('wire')
            }
        } else if (instructions.payment_rail) {
            if (instructions.payment_rail === 'ach_push') {
                setSelectedPaymentMethod('ach')
            } else if (instructions.payment_rail === 'wire') {
                setSelectedPaymentMethod('wire')
            }
        }
    }

    const fetchDepositInstructions = async () => {
        setIsLoadingDepositInstructions(true)
        try {
            const timestamp = Date.now()
            const response = await fetch(`/wallet/bridge/deposit-instructions?t=${timestamp}`, {
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

            if (response.ok) {
                const data = await response.json()
                if (data.success && data.data?.deposit_instructions) {
                    applyDepositInstructions(data.data.deposit_instructions)
                    return true
                }
            }

            // No local/Bridge instructions — sync or create deposit account automatically
            const provisionResponse = await fetch('/wallet/bridge/virtual-account', {
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

            const provisionData = await provisionResponse.json()
            if (provisionData.success) {
                const instructions = provisionData.data?.source_deposit_instructions
                if (instructions) {
                    applyDepositInstructions(instructions)
                    return true
                }

                const retryResponse = await fetch(`/wallet/bridge/deposit-instructions?t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (retryResponse.ok) {
                    const retryData = await retryResponse.json()
                    if (retryData.success && retryData.data?.deposit_instructions) {
                        applyDepositInstructions(retryData.data.deposit_instructions)
                        return true
                    }
                }
            }

            setDepositInstructions(null)
            return false
        } catch (error) {
            console.error('Failed to fetch deposit instructions:', error)
            setDepositInstructions(null)
            return false
        } finally {
            setIsLoadingDepositInstructions(false)
        }
    }

    const handleCreateBridgeDepositAccount = async () => {
        setIsCreatingDepositAccount(true)
        try {
            const response = await fetch('/wallet/bridge/virtual-account', {
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
                showSuccessToast('Deposit bank account created successfully!')
                const instructions = data.data?.source_deposit_instructions
                if (instructions) {
                    applyDepositInstructions(instructions)
                } else {
                    await fetchDepositInstructions()
                }
            } else {
                showErrorToast(data.message || 'Failed to create deposit bank account')
            }
        } catch (error) {
            console.error('Failed to create Bridge deposit account:', error)
            showErrorToast('Failed to create deposit bank account. Please try again.')
        } finally {
            setIsCreatingDepositAccount(false)
        }
    }

    // Link external account
    const handleLinkExternalAccount = async (accountData: {
        routing_number: string;
        account_number: string;
        account_type: 'checking' | 'savings';
        account_holder_name: string;
        bank_name: string;
        first_name: string;
        last_name: string;
        street_line_1: string;
        city: string;
        state: string;
        postal_code: string;
        country: string;
    }) => {
        setIsLoading(true)
        try {
            const response = await fetch('/wallet/bridge/external-account', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    account_data: accountData,
                }),
            })

            const data = await response.json()
            if (data.success) {
                showSuccessToast('Bank account linked successfully!')
                setShowAddBankFormOnEntry(false)
                await fetchExternalAccounts()
                setActionView('external_accounts')
            } else {
                showErrorToast(data.message || 'Failed to link bank account')
            }
        } catch (error) {
            console.error('Failed to link external account:', error)
            showErrorToast('Failed to link bank account. Please try again.')
        } finally {
            setIsLoading(false)
        }
    }

    const handleRemoveExternalAccount = async (accountId: string) => {
        setIsRemovingBankAccount(true)
        try {
            const response = await fetch(`/wallet/bridge/external-account/${encodeURIComponent(accountId)}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
            })

            const data = await response.json()
            if (data.success) {
                showSuccessToast('Bank account removed')
                if (selectedExternalAccount === accountId) {
                    setSelectedExternalAccount('')
                }
                await fetchExternalAccounts()
            } else {
                showErrorToast(data.message || 'Failed to remove bank account')
            }
        } catch (error) {
            console.error('Failed to remove external account:', error)
            showErrorToast('Failed to remove bank account. Please try again.')
        } finally {
            setIsRemovingBankAccount(false)
        }
    }

    // Transfer from external account
    const handleTransferFromExternal = async () => {
        if (!selectedExternalAccount || !transferAmount || parseFloat(transferAmount) <= 0) {
            showErrorToast('Please select an account and enter a valid amount')
            return
        }

        if (!hasWallet) {
            showErrorToast('Wallet is required for transfers')
            return
        }

        setIsLoading(true)
        try {
            // Get wallet ID from status or use bridge_wallet_id
            let walletId = null
            if (bridgeInitialized) {
                // Try to get from status response or use a placeholder
                const statusResponse = await fetch('/wallet/bridge/status', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (statusResponse.ok) {
                    const statusData = await statusResponse.json()
                    if (statusData.success && statusData.wallet_id) {
                        walletId = statusData.wallet_id
                    }
                }
            }

            if (!walletId) {
                showErrorToast('Wallet not found. Please create a wallet first.')
                return
            }

            const response = await fetch('/wallet/bridge/transfer-from-external', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    external_account_id: selectedExternalAccount,
                    wallet_id: walletId,
                    amount: parseFloat(transferAmount),
                    currency: 'USD',
                }),
            })

            const data = await response.json()
            if (data.success) {
                showSuccessToast('Transfer initiated successfully!')
                setTransferAmount('')
                setSelectedExternalAccount('')
                setActionView('main')
                await checkBridgeAndFetchBalance()
            } else {
                showErrorToast(data.message || 'Failed to initiate transfer')
            }
        } catch (error) {
            console.error('Failed to transfer from external account:', error)
            showErrorToast('Failed to initiate transfer. Please try again.')
        } finally {
            setIsLoading(false)
        }
    }

    // Withdraw to external account
    const handleWithdrawToExternal = async () => {
        if (!selectedExternalAccount || !withdrawAmount || parseFloat(withdrawAmount) <= 0) {
            showErrorToast('Please select an account and enter a valid amount')
            return
        }

        if (!hasWallet) {
            showErrorToast('Wallet is required for withdrawals')
            return
        }

        if (!bankWithdrawalAvailable) {
            showErrorToast(
                isSandbox
                    ? 'Bank withdrawals are not available in sandbox mode.'
                    : 'Bridge wallet required before withdrawing to a bank account.'
            )
            return
        }

        setIsLoading(true)
        try {
            const response = await fetch('/wallet/bridge/transfer-to-external', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    external_account_id: selectedExternalAccount,
                    amount: parseFloat(withdrawAmount),
                    currency: 'USD',
                    payment_rail: withdrawPaymentRail,
                }),
            })

            const data = await response.json()
            if (data.success) {
                showSuccessToast('Withdrawal initiated successfully!')
                setWithdrawAmount('')
                setSelectedExternalAccount('')
                setActionView('main')
                await checkBridgeAndFetchBalance()
            } else {
                showErrorToast(data.message || 'Failed to initiate withdrawal')
            }
        } catch (error) {
            console.error('Failed to withdraw to external account:', error)
            showErrorToast('Failed to initiate withdrawal. Please try again.')
        } finally {
            setIsLoading(false)
        }
    }

    // Handle wallet creation after approval
    const handleCreateWallet = async () => {
        setIsLoading(true)
        try {
            const response = await fetch('/wallet/bridge/create-wallet', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    chain: 'solana', // Default chain
                }),
            })

            const data = await response.json()

            if (data.success) {
                // Check if it's sandbox mode (virtual account created)
                if (data.data?.is_sandbox) {
                    // Check if it was already existing or newly created
                    const message = data.message || 'Virtual account'
                    if (message.includes('already exists')) {
                        showSuccessToast('Virtual account retrieved successfully!')
                    } else {
                        showSuccessToast('Virtual account created successfully!')
                    }
                    // In sandbox, we have a virtual account which acts like a wallet
                    // Set hasWallet to true if we have an address (virtual account created)
                    if (data.data?.address) {
                        setHasWallet(true) // Treat virtual account as wallet for display purposes
                        setWalletAddress(data.data.address)
                    } else {
                        setHasWallet(false)
                    }
                } else {
                    // Check if it was already existing or newly created
                    const message = data.message || 'Wallet'
                    if (message.includes('already exists')) {
                        showSuccessToast('Wallet retrieved successfully!')
                    } else {
                        showSuccessToast('Wallet and virtual account created successfully!')
                    }
                    setHasWallet(true)

                    // Set wallet address if provided
                    if (data.data?.address) {
                        setWalletAddress(data.data.address)
                    }
                }

                // Refresh status to get updated info (this will also update hasWallet from backend)
                await checkBridgeAndFetchBalance()

                // After wallet creation, also create Card Account and Liquidation Address
                // Note: Virtual Account is already created by the backend create-wallet endpoint
                // These calls are non-blocking and won't affect wallet creation success
                try {
                    // Get customer ID from status check
                    const statusResponse = await fetch('/wallet/bridge/status', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'include',
                        cache: 'no-store',
                    })

                    if (statusResponse.ok) {
                        const statusData = await statusResponse.json()
                        const customerId = statusData.data?.customer_id

                        if (customerId) {
                            // Create Card Account (if not exists) - Non-blocking
                            fetch('/wallet/bridge/card-account', {
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
                                .then(res => res.json())
                                .then(cardData => {
                                    if (cardData.success) {
                                    }
                                })
                                .catch(cardError => {
                                })

                            // Create Liquidation Address (if not exists) - Non-blocking
                            fetch('/wallet/bridge/liquidation-address', {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': getCsrfToken(),
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                credentials: 'include',
                                cache: 'no-store',
                                body: JSON.stringify({
                                    chain: 'ethereum',
                                    currency: 'usdc',
                                }),
                            })
                                .then(res => res.json())
                                .then(liquidationData => {
                                    if (liquidationData.success) {
                                    }
                                })
                                .catch(liquidationError => {
                                })
                        }
                    }
                } catch (additionalError) {
                    // Non-critical errors, wallet creation was successful
                }

                // Ensure we're showing the wallet screen by switching to main view
                setActionView('main')
            } else {
                showErrorToast(data.message || 'Failed to create wallet/virtual account')
            }
        } catch (error) {
            console.error('Failed to create wallet:', error)
            showErrorToast('Failed to create wallet. Please try again.')
        } finally {
            setIsLoading(false)
        }
    }

    const handleConnectWallet = async () => {
        setIsConnectingWallet(true) // Show loading animation screen
        setIsLoading(true)
        try {
            const response = await walletFetch('/wallet/bridge/initialize', {
                method: 'POST',
                body: {},
            })

            const data = await response.json().catch(() => ({} as Record<string, unknown>))

            if (isCsrfMismatch(response.status, typeof data.message === 'string' ? data.message : null)) {
                showErrorToast('Session expired. Please refresh the page and try again.')
                setIsLoading(false)
                return
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    (typeof data.message === 'string' && data.message) || 'Failed to connect wallet',
                )
            }

            // Successfully connected
            setBridgeInitialized(true)
            showSuccessToast('Wallet connected successfully!')

            // Check status first, then fetch balance only if wallet exists
            const statusResponse = await walletFetch(`/wallet/bridge/status?t=${Date.now()}`, {
                method: 'GET',
            })

            const statusData = await statusResponse.json().catch(() => ({} as Record<string, unknown>))

            if (isCsrfMismatch(statusResponse.status, typeof statusData.message === 'string' ? statusData.message : null)) {
                showErrorToast('Session expired. Please refresh the page and try again.')
                setIsLoading(false)
                return
            }

            if (statusData.success && statusData.initialized) {
                const bridgeStatus = applyWalletBridgeStatusPayload(
                    statusData as WalletBridgeStatusPayload,
                )

                setRequiresVerification(bridgeStatus.requiresVerification)
                setTosStatus(bridgeStatus.tosStatus)

                if (statusData.kyc_status) {
                    applyBackendKycStatus(statusData.kyc_status)
                }
                if (statusData.kyb_status) {
                    setKybStatus(statusData.kyb_status)
                    if (statusData.kyb_status === 'approved') {
                        setTosStatus('accepted')
                }
                }
                if (statusData.tos_accepted === true) {
                    setTosStatus('accepted')
                }
                if (statusData.tos_link) {
                    setTosLinkUrl(statusData.tos_link)
                }
                if (statusData.kyc_link) {
                    setKycLinkUrl(statusData.kyc_link)
                }
                if (statusData.kyb_link) {
                    setKybLinkUrl(statusData.kyb_link)
                }
                if (statusData.kyc_widget_url) {
                    setKycWidgetUrl(statusData.kyc_widget_url)
                }
                if (statusData.kyb_widget_url) {
                    setKybWidgetUrl(statusData.kyb_widget_url)
                }
                if (statusData.verification_type) {
                    setVerificationType(statusData.verification_type)
                }

                if (isWalletBridgeAccountVerified(statusData as WalletBridgeStatusPayload)) {
                    setRequiresVerification(false)
                }

                if (statusData.control_person_kyc_link) {
                    setControlPersonKycLink(statusData.control_person_kyc_link)
                }
                if (statusData.control_person_kyc_iframe_url) {
                    setControlPersonKycIframeUrl(statusData.control_person_kyc_iframe_url)
                }

                // Balance from Bridge API via /wallet/balance
                const balanceResponse = await walletFetch(`/wallet/balance?t=${Date.now()}`, {
                    method: 'GET',
                })

                if (balanceResponse.ok) {
                    const balanceData = await balanceResponse.json()
                    if (balanceData.success) {
                        // Bridge or ledger balance from API (never mix with local_balance fallback)
                        setWalletBalance(pickWalletBalance(balanceData))
                        setHasSubscription(balanceData.has_subscription ?? null)
                    }
                }
            }

            // Handle verification links if provided in the response
            if (data.data) {
                if (data.data.tos_link) {
                    setTosLinkUrl(data.data.tos_link)
                }
                if (data.data.kyc_link) {
                    setKycLinkUrl(data.data.kyc_link)
                }
                if (data.data.kyb_link) {
                    setKybLinkUrl(data.data.kyb_link)
                }
                if (data.data.kyc_widget_url) {
                    setKycWidgetUrl(data.data.kyc_widget_url)
                }
                if (data.data.kyb_widget_url) {
                    setKybWidgetUrl(data.data.kyb_widget_url)
                }
                const initBridgeStatus = applyWalletBridgeStatusPayload({
                    ...(data.data as WalletBridgeStatusPayload),
                    initialized: true,
                })
                setTosStatus(initBridgeStatus.tosStatus)
                if (data.data.kyc_status) {
                    applyBackendKycStatus(data.data.kyc_status)
                }
                if (data.data.kyb_status) {
                    setKybStatus(data.data.kyb_status)
                    if (data.data.kyb_status === 'approved') {
                        setTosStatus('accepted')
                    }
                }
                setRequiresVerification(initBridgeStatus.requiresVerification)
                if (isWalletBridgeAccountVerified({
                    ...(data.data as WalletBridgeStatusPayload),
                    initialized: true,
                })) {
                    setRequiresVerification(false)
                }
            }
        } catch (error) {
            console.error('Connect wallet error:', error)
            const errorMessage = error instanceof Error ? error.message : 'Failed to connect wallet. Please try again.'
            showErrorToast(errorMessage)
        } finally {
            setIsLoading(false)
            // Delay hiding the connecting screen slightly to show completion
            setTimeout(() => {
                setIsConnectingWallet(false)
            }, 500)
        }
    }

    // Convert image file to base64
    const convertImageToBase64 = (file: File): Promise<string> => {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => {
                const result = reader.result as string
                resolve(result) // Already includes data:image/jpeg;base64, prefix
            }
            reader.onerror = reject
            reader.readAsDataURL(file)
        })
    }

    // Convert PDF file to base64
    const convertPdfToBase64 = (file: File): Promise<string> => {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => {
                const result = reader.result as string
                resolve(result) // Already includes data:application/pdf;base64, prefix
            }
            reader.onerror = reject
            reader.readAsDataURL(file)
        })
    }

    // Handle TOS acceptance - show custom modal
    const handleAcceptTos = async () => {
        try {
            setIsLoading(true)

            // Get CSRF token with validation - try multiple times if needed
            let csrfToken = getCsrfToken()

            // If token is missing, try to get it from a fresh request
            if (!csrfToken) {
                try {
                    // Make a simple GET request to refresh the session and get CSRF token
                    await fetch('/wallet/bridge/status', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'include',
                        cache: 'no-store',
                    })
                    // Try to get token again after the request
                    csrfToken = getCsrfToken()
                } catch (e) {
                    console.warn('Failed to refresh CSRF token:', e)
                }
            }

            if (!csrfToken) {
                showErrorToast('CSRF token not found. Please refresh the page and try again.')
                setIsLoading(false)
                return
            }

            // Always get a fresh TOS link from Bridge (refresh=1 ensures new link is fetched and saved to database)
            const response = await walletFetch('/wallet/bridge/tos-link?refresh=1', {
                method: 'GET',
            })

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: 'Request failed' }))
                if (isCsrfMismatch(response.status, typeof errorData.message === 'string' ? errorData.message : null)) {
                    showErrorToast('Session expired. Please refresh the page and try again.')
                    setIsLoading(false)
                    return
                }
                showErrorToast(errorData.message || 'Failed to load Terms of Service')
                setIsLoading(false)
                return
            }

            const data = await response.json()

            if (data.success) {
                // Check if TOS is already accepted
                if (data.data?.already_accepted) {
                    setTosStatus('accepted')
                    setTosIframeUrl(null) // Hide iframe immediately
                    showSuccessToast('Terms of Service already accepted')
                    // Still set the URL if available for reference
                    if (data.data.tos_url) {
                        setTosIframeUrl(data.data.tos_url)
                    }
                } else if (data.data?.tos_url) {
                    // Store TOS URL — user opens the iframe by clicking the Terms of Service button
                    setTosIframeUrl(data.data.tos_url)
                } else {
                    showErrorToast(data.message || 'Failed to get TOS link')
                }
            } else {
                showErrorToast(data.message || 'Failed to get TOS link')
            }
        } catch (error) {
            console.error('Failed to get TOS link:', error)
            showErrorToast('Failed to get TOS link')
        } finally {
            setIsLoading(false)
        }
    }

    // Handle TOS acceptance confirmation - now handled by TermsOfService component with iframe
    const handleConfirmTosAcceptance = async () => {
        // The TermsOfService component will show the iframe
        // We just need to ensure we have a TOS URL
        if (!tosIframeUrl) {
            await handleAcceptTos()
        }
    }

    // Helper function to refresh CSRF token
    const refreshCsrfToken = async (): Promise<string> => {
        try {
            // Make a simple GET request to refresh the session and get CSRF token
            await fetch('/wallet/bridge/status', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
            })
            // Try to get token again after the request
            return getCsrfToken()
        } catch (e) {
            console.warn('Failed to refresh CSRF token:', e)
            return getCsrfToken()
        }
    }

    // Handle Control Person submission (Step 1 of KYB)
    const handleSubmitControlPerson = async () => {
        try {
            setIsLoading(true)

            if (tosStatus !== 'accepted' && tosStatus !== 'approved' && !signedAgreementId) {
                showErrorToast('Please accept Terms of Service first')
                setIsLoading(false)
                return
            }

            // Validate control person fields
            const errors: Record<string, string> = {}

            if (!kybFormData.control_person.first_name?.trim()) {
                errors.first_name = 'First name is required'
            }
            if (!kybFormData.control_person.last_name?.trim()) {
                errors.last_name = 'Last name is required'
            }
            if (!kybFormData.control_person.email?.trim()) {
                errors.email = 'Email is required'
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(kybFormData.control_person.email)) {
                errors.email = 'Please enter a valid email address'
            }
            if (!kybFormData.control_person.birth_date) {
                errors.birth_date = 'Date of birth is required'
            }
            if (!kybFormData.control_person.ssn?.trim()) {
                errors.ssn = 'SSN is required'
            } else if (!/^\d{9}$/.test(kybFormData.control_person.ssn.replace(/\D/g, ''))) {
                errors.ssn = 'SSN must be 9 digits'
            }
            if (!kybFormData.control_person.title?.trim()) {
                errors.title = 'Title is required'
            }
            if (!kybFormData.control_person.ownership_percentage?.trim()) {
                errors.ownership_percentage = 'Ownership percentage is required'
            } else {
                const ownership = parseFloat(kybFormData.control_person.ownership_percentage)
                if (isNaN(ownership) || ownership < 0 || ownership > 100) {
                    errors.ownership_percentage = 'Ownership percentage must be between 0 and 100'
                }
            }
            if (!kybFormData.control_person.street_line_1?.trim()) {
                errors.street_line_1 = 'Street address is required'
            }
            if (!kybFormData.control_person.city?.trim()) {
                errors.city = 'City is required'
            }
            if (!kybFormData.control_person.state?.trim()) {
                errors.state = 'State is required'
            }
            if (!kybFormData.control_person.postal_code?.trim()) {
                errors.postal_code = 'Postal code is required'
            }
            if (!kybFormData.control_person.id_type?.trim()) {
                errors.id_type = 'ID type is required'
            }
            if (!kybFormData.control_person.id_number?.trim()) {
                errors.id_number = 'ID number is required'
            }
            // Only require ID images if they are rejected OR if field is in requestedFields
            if (documentStatuses.id_front === 'rejected' || shouldShowField('control_person.id_front_image')) {
                if (!kybFormData.control_person.id_front_image) {
                    errors.id_front_image = kybFormData.control_person.id_type === 'drivers_license'
                        ? 'ID front image is required'
                        : 'Passport image is required'
                }
            }
            // Only require back image for Driver's License if rejected OR if field is in requestedFields
            if (kybFormData.control_person.id_type === 'drivers_license') {
                if (documentStatuses.id_back === 'rejected' || shouldShowField('control_person.id_back_image')) {
                    if (!kybFormData.control_person.id_back_image) {
                        errors.id_back_image = 'ID back image is required for Driver\'s License'
                    }
                }
            }

            setControlPersonErrors(errors)

            if (Object.keys(errors).length > 0) {
                showErrorToast('Please fix the validation errors below')
                setIsLoading(false)
                return
            }

            // Get fresh CSRF token before submission
            let csrfToken = getCsrfToken()
            if (!csrfToken) {
                // Try to refresh token
                csrfToken = await refreshCsrfToken()
            }

            if (!csrfToken) {
                showErrorToast('CSRF token not found. Refreshing page...')
                setIsLoading(false)
                setTimeout(() => {
                    window.location.reload()
                }, 1500)
                return
            }

            // Prepare request body
            const requestBody = {
                    signed_agreement_id: signedAgreementId,
                    step: 'control_person', // Indicate this is step 1
                    business_name: kybFormData.business_name,
                    email: kybFormData.email,
                    ein: kybFormData.ein,
                    business_address: {
                        street_line_1: kybFormData.street_line_1,
                        city: kybFormData.city,
                        subdivision: kybFormData.subdivision,
                        postal_code: kybFormData.postal_code,
                        country: kybFormData.country,
                    },
                    control_person: {
                        first_name: kybFormData.control_person.first_name,
                        last_name: kybFormData.control_person.last_name,
                        email: kybFormData.control_person.email,
                        birth_date: kybFormData.control_person.birth_date,
                        ssn: kybFormData.control_person.ssn,
                        title: kybFormData.control_person.title,
                        ownership_percentage: parseFloat(kybFormData.control_person.ownership_percentage),
                        street_line_1: kybFormData.control_person.street_line_1,
                        city: kybFormData.control_person.city,
                        state: kybFormData.control_person.state,
                        postal_code: kybFormData.control_person.postal_code,
                        country: kybFormData.control_person.country,
                        id_type: kybFormData.control_person.id_type,
                        id_number: kybFormData.control_person.id_number,
                        id_front_image: kybFormData.control_person.id_front_image,
                        // Only send id_back_image if ID type is drivers_license (not for passport)
                        ...(kybFormData.control_person.id_type === 'drivers_license' && kybFormData.control_person.id_back_image
                            ? { id_back_image: kybFormData.control_person.id_back_image }
                            : {}),
                    },
                }

            // Retry logic for CSRF token mismatch
            let response: Response | null = null
            let retryCount = 0
            const maxRetries = 3

            while (retryCount < maxRetries) {
                try {
                    // Refresh token before each attempt
                    if (retryCount > 0) {
                        csrfToken = await refreshCsrfToken()
                        if (!csrfToken) {
                            throw new Error('Failed to get CSRF token after retry')
                        }
                        // Small delay before retry
                        await new Promise(resolve => setTimeout(resolve, 500))
                    }

                    response = await fetch('/wallet/bridge/create-customer-kyc', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'include',
                        cache: 'no-store',
                        body: JSON.stringify(requestBody),
                    })

                    // If successful or non-CSRF error, break retry loop
                    if (response.ok || (response.status !== 419 && response.status !== 403)) {
                        break
                    }

                    // If CSRF mismatch, retry
                    if (response.status === 419 || response.status === 403) {
                        retryCount++
                        if (retryCount < maxRetries) {
                            console.warn(`CSRF token mismatch (attempt ${retryCount + 1}/${maxRetries}), retrying...`)
                            continue
                        } else {
                            showErrorToast('Session expired. Please refresh the page and try again.')
                            setIsLoading(false)
                            return
                        }
                    }
                } catch (error) {
                    if (retryCount < maxRetries - 1) {
                        retryCount++
                        console.warn(`Request failed (attempt ${retryCount}/${maxRetries}), retrying...`, error)
                        continue
                    } else {
                        throw error
                    }
                }
            }

            if (!response) {
                throw new Error('Failed to get response after retries')
            }

            const data = await response.json()

            if (data.success) {
                // Check if this was an ID-only submission (only ID fields were rejected/requested)
                const idRelatedFields = [
                    'control_person.id_type',
                    'control_person.id_number',
                    'control_person.id_front_image',
                    'control_person.id_back_image'
                ]
                const hasRejectedIdDocs = documentStatuses.id_front === 'rejected' || documentStatuses.id_back === 'rejected'
                const hasOnlyIdFieldsRequested = requestedFields.length > 0 && 
                    requestedFields.every((field: string) => idRelatedFields.includes(field))
                const isOnlyIdRejection = hasRejectedIdDocs && 
                    (!requestedFields || requestedFields.length === 0 || hasOnlyIdFieldsRequested)

                if (isOnlyIdRejection) {
                    // For ID-only submissions, stay on control_person step and show waiting screen
                    showSuccessToast('ID documents have been submitted and are awaiting admin review.')
                    setControlPersonSubmitted(true)
                    // Update document statuses to 'pending' to trigger waiting screen
                    setDocumentStatuses(prev => ({
                        ...prev,
                        id_front: documentStatuses.id_front === 'rejected' ? 'pending' : prev.id_front,
                        id_back: documentStatuses.id_back === 'rejected' ? 'pending' : prev.id_back,
                    }))
                    // Stay on control_person step
                    setKybStep('control_person')
                } else {
                    // For full control person submission, move to next step
                    showSuccessToast('Control Person information submitted. Please upload business documents next.')
                    setControlPersonSubmitted(true)
                    setKybStep('business_documents')
                }
                
                setControlPersonErrors({})

                // Store requested fields before clearing
                const fieldsToClear = [...requestedFields]

                // Clear requested fields after successful submission
                setRequestedFields([])
                setRefillMessage(null)

                // Clear form data for requested fields to prevent them from showing
                if (fieldsToClear.length > 0) {
                    setKybFormData(prev => {
                        const updated = { ...prev }
                        const updatedControlPerson = { ...prev.control_person }

                        fieldsToClear.forEach(field => {
                            if (field.startsWith('control_person.')) {
                                const fieldName = field.replace('control_person.', '') as keyof typeof updatedControlPerson
                                if (fieldName in updatedControlPerson) {
                                    if (fieldName === 'id_front_image' || fieldName === 'id_back_image') {
                                        (updatedControlPerson as any)[fieldName] = ''
                                    } else {
                                        (updatedControlPerson as any)[fieldName] = ''
                                    }
                                }
                            } else if (field === 'business_name' || field === 'email' || field === 'ein' || field === 'street_line_1' || field === 'city' || field === 'subdivision' || field === 'postal_code' || field === 'country') {
                                (updated as any)[field] = ''
                            }
                        })

                        updated.control_person = updatedControlPerson
                        return updated
                    })
                }

                // If backend returns KYC link for control person, store it
                if (data.data?.control_person_kyc_link) {
                    setControlPersonKycLink(data.data.control_person_kyc_link)
                }
                if (data.data?.control_person_kyc_iframe_url) {
                    setControlPersonKycIframeUrl(data.data.control_person_kyc_iframe_url)
                }
            } else {
                showErrorToast(data.message || 'Failed to submit Control Person information')
            }
        } catch (error) {
            console.error('Failed to submit Control Person:', error)
            showErrorToast('Failed to submit Control Person information')
        } finally {
            setIsLoading(false)
        }
    }

    // Handle Business Documents submission (Step 2 of KYB)
    const handleSubmitBusinessDocuments = async () => {
        try {
            setIsLoading(true)

            const errors: Record<string, string> = {}

            // Check if we're in re-upload mode (some documents are rejected)
            const hasRejectedDocuments = documentStatuses.business_formation === 'rejected' ||
                documentStatuses.business_ownership === 'rejected' ||
                documentStatuses.proof_of_address === 'rejected'

            // Only require documents that are explicitly rejected (for re-upload)
            // If a document is approved or pending, don't require it
            if (documentStatuses.business_formation === 'rejected') {
                // Re-upload required for rejected document
                if (!kybFormData.business_formation_document) {
                    errors.business_formation_document = 'Business formation document is required (e.g., Articles of Incorporation)'
                }
            } else if (!hasRejectedDocuments && !documentStatuses.business_formation) {
                // Only require if no documents are rejected AND this document hasn't been uploaded yet (initial submission)
                if (!kybFormData.business_formation_document) {
                    errors.business_formation_document = 'Business formation document is required (e.g., Articles of Incorporation)'
                }
            }

            if (documentStatuses.business_ownership === 'rejected') {
                // Re-upload required for rejected document
                if (!kybFormData.business_ownership_document) {
                    errors.business_ownership_document = 'Business ownership document is required (e.g., Cap table, Operating Agreement)'
                }
            } else if (!hasRejectedDocuments && !documentStatuses.business_ownership) {
                // Only require if no documents are rejected AND this document hasn't been uploaded yet (initial submission)
                if (!kybFormData.business_ownership_document) {
                    errors.business_ownership_document = 'Business ownership document is required (e.g., Cap table, Operating Agreement)'
                }
            }

            // Only validate other fields if this is the initial submission (not re-upload mode)
            if (!hasRejectedDocuments && !businessDocumentsSubmitted) {
                if (!kybFormData.business_description?.trim()) {
                    errors.business_description = 'Business description is required'
                }
                if (!kybFormData.entity_type) {
                    errors.entity_type = 'Entity type is required'
                }
            }

            setBusinessDocumentErrors(errors)

            if (Object.keys(errors).length > 0) {
                showErrorToast('Please complete all required fields')
                setIsLoading(false)
                return
            }

            const response = await fetch('/wallet/bridge/create-customer-kyc', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    step: 'business_documents', // Indicate this is step 2
                    business_formation_document: kybFormData.business_formation_document,
                    business_ownership_document: kybFormData.business_ownership_document,
                    proof_of_nature_of_business: kybFormData.proof_of_nature_of_business || null,
                    determination_letter_501c3: kybFormData.determination_letter_501c3 || null,
                    business_description: kybFormData.business_description,
                    business_industry: kybFormData.business_industry,
                    primary_website: kybFormData.primary_website,
                    // Standard KYB Requirements
                    entity_type: kybFormData.entity_type,
                    business_type: kybFormData.entity_type || null, // Use entity_type as business_type for Bridge
                    dao_status: kybFormData.dao_status,
                    physical_address: kybFormData.physical_address.street_line_1 ? kybFormData.physical_address : null,
                    // Enhanced KYB Requirements
                    source_of_funds: kybFormData.source_of_funds || null,
                    annual_revenue: kybFormData.annual_revenue || null,
                    transaction_volume: kybFormData.transaction_volume || null,
                    account_purpose: kybFormData.account_purpose || null,
                    high_risk_activities: kybFormData.high_risk_activities || null,
                    high_risk_geographies: kybFormData.high_risk_geographies || null,
                    proof_of_address_document: kybFormData.proof_of_address_document || null,
                }),
            })

            const data = await response.json()

            if (data.success) {
                showSuccessToast('Business documents submitted successfully. Please wait for admin approval before proceeding to KYC verification.')
                // Don't automatically move to step 3 - wait for admin approval
                // Stay on business_documents step until approved
                setKybStep('business_documents')
                setBusinessDocumentErrors({})
                setBusinessDocumentsSubmitted(true) // Mark documents as submitted

                // Store requested fields before clearing
                const fieldsToClear = [...requestedFields]

                // Clear requested fields after successful submission
                setRequestedFields([])
                setRefillMessage(null)

                // Store which documents were just submitted before clearing form data
                const submittedFormation = !!kybFormData.business_formation_document
                const submittedOwnership = !!kybFormData.business_ownership_document
                const submittedProofOfAddress = !!kybFormData.proof_of_address_document

                // Immediately update document statuses to 'pending' for re-uploaded documents
                // This ensures the waiting screen shows right away instead of the form
                setDocumentStatuses(prev => {
                    const updated = { ...prev }
                    // If a document was rejected and we just submitted it, change status to 'pending'
                    if (prev.business_formation === 'rejected' && submittedFormation) {
                        updated.business_formation = 'pending'
                    }
                    if (prev.business_ownership === 'rejected' && submittedOwnership) {
                        updated.business_ownership = 'pending'
                    }
                    if (prev.proof_of_address === 'rejected' && submittedProofOfAddress) {
                        updated.proof_of_address = 'pending'
                    }
                    // Also set to pending if it was the initial submission (status was undefined/null)
                    if (!prev.business_formation && submittedFormation) {
                        updated.business_formation = 'pending'
                    }
                    if (!prev.business_ownership && submittedOwnership) {
                        updated.business_ownership = 'pending'
                    }
                    return updated
                })

                // Clear the form data for re-uploaded documents to prevent showing the form again
                setKybFormData(prev => {
                    const updated = {
                        ...prev,
                        business_formation_document: '',
                        business_ownership_document: '',
                        proof_of_address_document: '',
                        proof_of_nature_of_business: '',
                        determination_letter_501c3: '',
                    }

                    // Clear form data for requested fields to prevent them from showing
                    if (fieldsToClear.length > 0) {
                        fieldsToClear.forEach(field => {
                            if (field === 'entity_type') updated.entity_type = ''
                            else if (field === 'business_description') updated.business_description = ''
                            else if (field === 'business_industry') updated.business_industry = ''
                            else if (field === 'primary_website') updated.primary_website = ''
                            else if (field === 'dao_status') updated.dao_status = false
                            else if (field === 'source_of_funds') updated.source_of_funds = ''
                            else if (field === 'annual_revenue') updated.annual_revenue = ''
                            else if (field === 'transaction_volume') updated.transaction_volume = ''
                            else if (field === 'account_purpose') updated.account_purpose = ''
                            else if (field === 'high_risk_activities') updated.high_risk_activities = ''
                            else if (field === 'high_risk_geographies') updated.high_risk_geographies = ''
                            else if (field.startsWith('physical_address.')) {
                                const fieldName = field.replace('physical_address.', '')
                                updated.physical_address = {
                                    ...updated.physical_address,
                                    [fieldName]: ''
                                }
                            }
                        })
                    }

                    return updated
                })

                // Refresh status to get updated submission status and document statuses from backend
                await checkBridgeAndFetchBalance()
            } else {
                showErrorToast(data.message || 'Failed to submit business documents')
            }
        } catch (error) {
            console.error('Failed to submit business documents:', error)
            showErrorToast('Failed to submit business documents')
        } finally {
            setIsLoading(false)
        }
    }

    // Handle Control Person KYC verification (Step 3 of KYB)
    const handleControlPersonKyc = async (silent = false) => {
        try {
            if (!silent) {
                setIsLoading(true)
            }

            // If we already have an iframe URL, don't fetch again
            if (controlPersonKycIframeUrl) {
                if (!silent) {
                    setIsLoading(false)
                }
                return
            }

            // If we already have a KYC link but no iframe, try to convert it to iframe URL
            if (controlPersonKycLink && !controlPersonKycIframeUrl) {
                // Convert link to iframe URL if possible
                const iframeUrl = controlPersonKycLink.replace('/verify', '/widget')
                const separator = iframeUrl.includes('?') ? '&' : '?'
                const fullIframeUrl = `${iframeUrl}${separator}iframe-origin=${encodeURIComponent(window.location.origin)}`
                setControlPersonKycIframeUrl(fullIframeUrl)
                if (!silent) {
                    setIsLoading(false)
                }
                return
            }

            // Request KYC link for control person
            const response = await fetch('/wallet/bridge/control-person-kyc-link', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    control_person_email: kybFormData.control_person.email,
                }),
            })

            const data = await response.json()

            if (data.success) {
                if (data.data?.kyc_link) {
                    setControlPersonKycLink(data.data.kyc_link)
                }
                if (data.data?.iframe_kyc_link || data.data?.kyc_iframe_url) {
                    setControlPersonKycIframeUrl(data.data.iframe_kyc_link || data.data.kyc_iframe_url)
                } else if (data.data?.kyc_link) {
                    // Convert link to iframe URL if iframe URL not provided
                    const iframeUrl = data.data.kyc_link.replace('/verify', '/widget')
                    const separator = iframeUrl.includes('?') ? '&' : '?'
                    const fullIframeUrl = `${iframeUrl}${separator}iframe-origin=${encodeURIComponent(window.location.origin)}`
                    setControlPersonKycIframeUrl(fullIframeUrl)
                }
                // Only show toast if not silent (user-initiated)
                if (!silent) {
                    if (data.data?.iframe_kyc_link || data.data?.kyc_iframe_url || data.data?.kyc_link) {
                        showSuccessToast('KYC verification iframe loaded')
                    } else {
                        showErrorToast('KYC verification link not available')
                    }
                }
            } else {
                if (!silent) {
                    showErrorToast(data.message || 'Failed to get KYC verification link')
                }
            }
        } catch (error) {
            console.error('Failed to get Control Person KYC link:', error)
            if (!silent) {
                showErrorToast('Failed to get KYC verification link')
            }
        } finally {
            if (!silent) {
                setIsLoading(false)
            }
        }
    }

    // Handle custom KYC form submission (for individual KYC, not KYB)
    const handleSubmitCustomKyc = async () => {
        try {
            setIsLoading(true)

            if (tosStatus !== 'accepted' && tosStatus !== 'approved' && !signedAgreementId) {
                showErrorToast('Please accept Terms of Service first')
                setIsLoading(false)
                return
            }

            if (verificationType === 'kyb') {
                // KYB is now handled by multi-step flow
                // This should not be called for KYB anymore
                showErrorToast('Please use the KYB multi-step flow')
                setIsLoading(false)
                return
            } else {
                // Individual KYC submission
                // Validate based on ID type
                if (!kycFormData.id_front_image) {
                    const idTypeLabel = kycFormData.id_type === 'passport' ? 'passport' : 'ID front'
                    showErrorToast(`Please upload ${idTypeLabel} image`)
                    setIsLoading(false)
                    return
                }

                // For driver's license and state ID, require back image
                if (kycFormData.id_type !== 'passport' && !kycFormData.id_back_image) {
                    showErrorToast('Please upload ID back image')
                    setIsLoading(false)
                    return
                }

                // id_front_image and id_back_image are already base64 strings
                const idFrontBase64 = typeof kycFormData.id_front_image === 'string'
                    ? kycFormData.id_front_image
                    : await convertImageToBase64(kycFormData.id_front_image as File)
                const idBackBase64 = kycFormData.id_type !== 'passport' && kycFormData.id_back_image
                    ? (typeof kycFormData.id_back_image === 'string'
                        ? kycFormData.id_back_image
                        : await convertImageToBase64(kycFormData.id_back_image as File))
                    : null

                const response = await fetch('/wallet/bridge/create-customer-kyc', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                    body: JSON.stringify({
                        signed_agreement_id: signedAgreementId,
                        first_name: kycFormData.first_name,
                        last_name: kycFormData.last_name,
                        email: kycFormData.email,
                        birth_date: kycFormData.birth_date,
                        residential_address: {
                            street_line_1: kycFormData.street_line_1,
                            city: kycFormData.city,
                            subdivision: kycFormData.subdivision,
                            postal_code: kycFormData.postal_code,
                            country: kycFormData.country,
                        },
                        ssn: kycFormData.ssn,
                        id_type: kycFormData.id_type,
                        id_number: kycFormData.id_number,
                        id_front_image: idFrontBase64,
                        // Only send id_back_image if ID type is not passport (drivers_license or state_id)
                        ...(idBackBase64 ? { id_back_image: idBackBase64 } : {}),
                    }),
                })

                const data = await response.json()

                if (data.success) {
                    // Check if KYC was instantly approved (like KYB)
                    const kycStatusFromResponse = data.data?.kyc_status
                    const isInstantlyApproved = kycStatusFromResponse === 'approved'

                    if (isInstantlyApproved) {
                        showSuccessToast('KYC verification approved instantly! You can now access your wallet.')
                        setKycStatus('approved')
                        setRequiresVerification(false)

                        setTimeout(() => {
                            checkBridgeAndFetchBalance()
                        }, 500)
                    } else {
                    showSuccessToast('KYC data submitted successfully. Verification is pending.')
                    setTimeout(() => {
                            void refreshKycStatusAfterSubmission()
                        }, 500)
                    }
                } else {
                    showErrorToast(data.message || 'Failed to submit KYC data')
                }
            }
        } catch (error) {
            console.error('Failed to submit KYC data:', error)
            showErrorToast('Failed to submit KYC data')
        } finally {
            setIsLoading(false)
        }
    }

    const handleCloseVerificationModal = () => {
        setShowVerificationIframe(false)
        setIsLoadingVerificationWidget(false)
        setVerificationModalWidgetUrl(null)
        void refreshKycStatusAfterSubmission()
        void checkBridgeAndFetchBalance()
    }

    const handleBridgeVerificationComplete = () => {
        setShowVerificationIframe(false)
        setIsLoadingVerificationWidget(false)
        setVerificationModalWidgetUrl(null)

        if (verificationType === 'kyc') {
            showSuccessToast('Verification submitted. We will update your status when review is complete.')
        } else {
            showSuccessToast('Business verification submitted. We will update your status when review is complete.')
        }

        void refreshKycStatusAfterSubmission()
        void checkBridgeAndFetchBalance()
    }

    const openBridgeVerificationWithUrl = (linkUrl: string) => {
        const embedUrl = resolveBridgeVerificationWidgetUrl(null, linkUrl)
        setShowVerificationIframe(true)
        setIsLoadingVerificationWidget(false)
        setVerificationModalWidgetUrl(embedUrl ?? linkUrl)
    }

    const openBridgeVerificationWidget = async (options?: { endorsement?: 'base' | 'cards' }) => {
        const linkType = verificationType || 'kyc'
        const endorsement = options?.endorsement ?? 'base'

        setShowVerificationIframe(true)

        setIsLoadingVerificationWidget(true)
        setVerificationModalWidgetUrl(null)

        try {
            const endpoint = linkType === 'kyb' ? '/wallet/bridge/kyb-link' : '/wallet/bridge/kyc-link'

            // Empty redirect_url tells the API this is an iframe embed — no hosted redirect on "Next".
            const response = await fetch(endpoint, {
                method: 'POST',
                            headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': getCsrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'include',
                            cache: 'no-store',
                body: JSON.stringify({
                    redirect_url: '',
                    endorsement,
                }),
            })

            const data = await response.json().catch(() => ({}))

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to start verification')
            }

            const linkUrl = data.data?.link_url as string | undefined
            const widgetUrl = data.data?.widget_url as string | undefined
            const embedUrl = resolveBridgeVerificationWidgetUrl(widgetUrl, linkUrl)

            if (linkType === 'kyb') {
                if (linkUrl) {
                    setKybLinkUrl(linkUrl)
                }
                if (widgetUrl) {
                    setKybWidgetUrl(widgetUrl)
                }
            } else {
                if (linkUrl) {
                    setKycLinkUrl(linkUrl)
                }
                if (widgetUrl) {
                    setKycWidgetUrl(widgetUrl)
                }
            }

            if (embedUrl) {
                setVerificationModalWidgetUrl(embedUrl)
                return
            }

            throw new Error('Could not load the verification widget. Try opening in a new tab.')
        } catch (error) {
            console.error('Failed to open Bridge verification:', error)
            showErrorToast(error instanceof Error ? error.message : 'Failed to create verification link')
            setShowVerificationIframe(false)
        } finally {
            setIsLoadingVerificationWidget(false)
        }
    }

    const openCardsEndorsementVerification = () => openBridgeVerificationWidget({ endorsement: 'cards' })

    const handleRefresh = async () => {
        setIsLoading(true)
        try {
            const balanceResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
            })

            if (balanceResponse.ok) {
                const balanceData = await balanceResponse.json()
                if (balanceData.success) {
                    setWalletBalance(pickWalletBalance(balanceData))
                    setHasSubscription(balanceData.has_subscription ?? null)
                    showSuccessToast('Balance refreshed')
                }
            }
        } catch (error) {
            console.error('Failed to refresh balance:', error)
            showErrorToast('Failed to refresh balance')
        } finally {
            setIsLoading(false)
        }
    }

    // Use formatAddress from wallet utils
    const formatAddress = formatWalletAddress

    // Debounced search function
    useEffect(() => {
        if (!recipientSearch || recipientSearch.length < 2) {
            setSearchResults([])
            setShowDropdown(false)
            return
        }

        const timeoutId = setTimeout(async () => {
            setIsLoadingSearch(true)
            setShowDropdown(true)
            try {
                const response = await fetch(`/wallet/search-recipients?search=${encodeURIComponent(recipientSearch)}&limit=10`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (response.ok) {
                    const data = await response.json()
                    if (data.success) {
                        setSearchResults(data.results || [])
                        setShowDropdown(true)
                    }
                }
            } catch (error) {
                console.error('Search error:', error)
                setSearchResults([])
            } finally {
                setIsLoadingSearch(false)
            }
        }, 300)

        return () => clearTimeout(timeoutId)
    }, [recipientSearch])

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (
                dropdownRef.current &&
                !dropdownRef.current.contains(event.target as Node) &&
                searchInputRef.current &&
                !searchInputRef.current.contains(event.target as Node)
            ) {
                setShowDropdown(false)
            }
        }

        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    // Reset recipient search when switching away from send view
    useEffect(() => {
        if (actionView !== 'send') {
            setRecipientSearch('')
            setSelectedRecipient(null)
            setSearchResults([])
            setShowDropdown(false)
        }
    }, [actionView])

    // Reset add money amount and payment method when switching away from addMoney view
    useEffect(() => {
        if (actionView !== 'addMoney') {
            setAddMoneyAmount('')
            setSelectedPaymentMethod('ach') // Reset to default
        }
    }, [actionView])

    // Fetch deposit instructions and QR code when receive view is shown
    useEffect(() => {
        if (actionView === 'receive') {
            const fetchReceiveData = async () => {
                setIsLoadingReceiveData(true)
                try {
                    // Fetch deposit instructions
                    const timestamp = Date.now()
                    const instructionsResponse = await fetch(`/wallet/bridge/deposit-instructions?t=${timestamp}`, {
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

                    if (instructionsResponse.ok) {
                        const data = await instructionsResponse.json()
                        if (data.success && data.data?.deposit_instructions) {
                            const instructions = data.data.deposit_instructions
                            setReceiveDepositInstructions(instructions)

                            // Fetch QR code as blob and convert to data URL
                            try {
                                const qrTimestamp = Date.now()
                                const qrResponse = await fetch(`/wallet/bridge/deposit-qr-code?t=${qrTimestamp}`, {
                                    method: 'GET',
                                    headers: {
                                        'Accept': 'image/svg+xml, image/png, image/*',
                                        'X-CSRF-TOKEN': getCsrfToken(),
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    credentials: 'include',
                                    cache: 'no-store',
                                })

                                if (qrResponse.ok) {
                                    const blob = await qrResponse.blob()
                                    const reader = new FileReader()
                                    reader.onloadend = () => {
                                        setQrCodeUrl(reader.result as string)
                                    }
                                    reader.readAsDataURL(blob)
                                } else {
                                    console.error('Failed to fetch QR code:', qrResponse.status, qrResponse.statusText)
                                    setQrCodeUrl(null)
                                }
                            } catch (qrError) {
                                console.error('Error fetching QR code:', qrError)
                                setQrCodeUrl(null)
                            }
                        }
                    }
                } catch (error) {
                    console.error('Failed to fetch receive data:', error)
                } finally {
                    setIsLoadingReceiveData(false)
                }
            }

            fetchReceiveData()
        } else if (actionView !== 'receive') {
            // Clear receive data when leaving receive view
            setReceiveDepositInstructions(null)
            setQrCodeUrl(null)
        }
    }, [actionView])

    // Fetch deposit instructions when addMoney view is shown - always fetch fresh (no cache)
    useEffect(() => {
        if (actionView === 'external_accounts') {
            fetchExternalAccounts()
        } else {
            setShowAddBankFormOnEntry(false)
        }
    }, [actionView])

    // Prefetch bank accounts when wallet is ready
    useEffect(() => {
        const walletReady = walletAddress || (bridgeInitialized && hasWallet)

        if (walletReady && hasBankAccounts === null && !isLoadingExternalAccounts) {
            fetchExternalAccounts()
        }
    }, [bridgeInitialized, hasWallet, walletAddress])

    // Refresh bank accounts when opening flows that need them
    useEffect(() => {
        const walletReady = walletAddress || (bridgeInitialized && hasWallet)
        const needsBankAccounts = actionView === 'transfer_from_external'
            || actionView === 'withdraw_to_external'
            || actionView === 'services_menu'

        if (needsBankAccounts && walletReady && hasBankAccounts === null && !isLoadingExternalAccounts) {
            fetchExternalAccounts()
        }
    }, [actionView])

    useEffect(() => {
        if (actionView === 'addMoney') {
            fetchDepositInstructions()
        } else if (actionView !== 'addMoney') {
            setDepositInstructions(null)
        }
    }, [actionView])

    // Check card wallet status when wallet is initialized or when navigating to services menu
    useEffect(() => {
        // Check if wallet is initialized (either has wallet address or bridge is initialized)
        const walletReady = walletAddress || (bridgeInitialized && hasWallet)
        
        if (walletReady && hasCardWallet === null) {
            checkCardWallet()
        }
    }, [bridgeInitialized, hasWallet, walletAddress])

    // Also check when navigating to services menu or virtual card
    useEffect(() => {
        if ((actionView === 'services_menu' || actionView === 'virtual_card') && hasCardWallet === null) {
            // Only check if we have a wallet
            if (walletAddress || (bridgeInitialized && hasWallet)) {
                checkCardWallet()
            }
        }
    }, [actionView])

    const checkCardWallet = async () => {
        // Don't check if already checking
        if (isCheckingCardWallet) {
            return
        }
        
        setIsCheckingCardWallet(true)
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
            // Set hasCardWallet based on has_card_account field
            // If success is false or has_card_account is false/null, user doesn't have a card wallet
            if (data.success && data.has_card_account === true) {
                setHasCardWallet(true)
            } else {
                // Either request failed or has_card_account is false/null/undefined
                setHasCardWallet(false)
            }
        } catch (error) {
            console.error('Failed to check card wallet:', error)
            setHasCardWallet(false)
        } finally {
            setIsCheckingCardWallet(false)
        }
    }

    const handleSelectRecipient = (recipient: { id: string; type: string; name: string; email?: string; display_name: string; address?: string }) => {
        setSelectedRecipient(recipient)
        setRecipientSearch(recipient.name)
        setShowDropdown(false)
    }

    const handleSend = async () => {
        if (!selectedRecipient) {
            showErrorToast('Please select a recipient')
            return
        }

        const amount = parseFloat(sendAmount)
        if (!sendAmount || isNaN(amount) || amount <= 0) {
            showErrorToast('Please enter a valid amount')
            return
        }

        if (walletBalance !== null && amount > walletBalance) {
            showErrorToast(`Insufficient balance. Available: $${walletBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`)
            return
        }

        setIsLoading(true)

        try {
            const response = await fetch('/wallet/bridge/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    amount: amount,
                    recipient_id: selectedRecipient.id,
                }),
            })

            const data = await response.json()

            // Check if KYC/KYB verification is required
            if (data.requires_verification) {
                setRequiresVerification(true)
                setVerificationType(data.verification_type || 'kyb')
                showErrorToast(data.message || 'Verification required')
                setIsLoading(false)
                return
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to send money')
            }

            // Update balance from response
            if (data.data?.sender_balance !== undefined) {
                setWalletBalance(data.data.sender_balance)
            } else {
                // Refresh balance
                const balanceResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                })

                if (balanceResponse.ok) {
                    const balanceData = await balanceResponse.json()
                    if (balanceData.success) {
                        setWalletBalance(pickWalletBalance(balanceData))
                    }
                }
            }

            // Show success
            setSuccessType('send')
            setSuccessMessage(data.message || `Successfully sent $${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} to ${selectedRecipient.name}`)
            setShowSuccess(true)

            const bridgeTransferId = typeof data.data?.bridge_transfer_id === 'string'
                ? data.data.bridge_transfer_id
                : ''

            if (bridgeTransferId !== '') {
                setActivities((prev) =>
                    prependPendingTransferActivity(
                        prev,
                        bridgeTransferId,
                        amount,
                        selectedRecipient.name,
                        selectedRecipient.type,
                    ),
                )
            }

            // Refresh activities to show the new transaction
            if (actionView === 'main') {
                try {
                    const activityResponse = await fetch(`/wallet/activity?page=1&per_page=5&t=${Date.now()}`, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': getCsrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'include',
                        cache: 'no-store',
                    })

                    if (activityResponse.ok) {
                        const activityData = await activityResponse.json()
                        if (activityData.success) {
                            setActivities(activityData.activities || [])
                            setHasMoreActivities(activityData.has_more || false)
                            setCurrentPage(1)
                        }
                    }
                } catch (error) {
                    console.error('Failed to refresh activities:', error)
                }
            }

            // Clear form
            setSendAmount('')
            setSelectedRecipient(null)
            setRecipientSearch('')
            setIsLoading(false)

            // Hide success and return to main after 3 seconds
            setTimeout(() => {
                setShowSuccess(false)
                setSuccessType(null)
                setActionView('main')
            }, 3000)
        } catch (error) {
            console.error('Send error:', error)
            const errorMessage = error instanceof Error ? error.message : 'Failed to send money. Please try again.'
            showErrorToast(errorMessage)
            setIsLoading(false)
        }
    }

    // const handleSwap = () => {
    //     if (!swapAmount || swapFrom === swapTo) {
    //         showErrorToast('Please enter amount and select different currencies')
    //         return
    //     }
    //     
    //     setIsLoading(true)
    //     
    //     // Simulate API call delay
    //     setTimeout(() => {
    //         setSuccessType('swap')
    //         setSuccessMessage(`Successfully swapped ${swapAmount} ${swapFrom} to ${swapTo}`)
    //         setShowSuccess(true)
    //         setSwapAmount('')
    //         setIsLoading(false)
    //         
    //         // Hide success and return to main after 3 seconds
    //         setTimeout(() => {
    //             setShowSuccess(false)
    //             setSuccessType(null)
    //             setActionView('main')
    //         }, 3000)
    //     }, 1000)
    // }

    const handleSwap = () => {
        showErrorToast('Coming Soon')
    }

    const handleAddMoney = async () => {
        const amount = parseFloat(addMoneyAmount)
        if (!addMoneyAmount || isNaN(amount) || amount <= 0) {
            showErrorToast('Please enter a valid amount')
            return
        }

        setIsLoading(true)

        try {
            // For now, simulate the API call
            const response = await fetch('/wallet/bridge/deposit', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    amount: amount,
                }),
            })

            if (response.ok) {
                const data = await response.json()

                // Check if KYC/KYB verification is required
                if (data.requires_verification) {
                    setRequiresVerification(true)
                    setVerificationType(data.verification_type || 'kyc')
                    showErrorToast(data.message || 'Verification required')
                    setIsLoading(false)
                    return
                }

                if (data.success) {
                    // Update balance from response
                    if (data.data?.balance !== undefined) {
                        setWalletBalance(data.data.balance)
                    } else {
                        // Refresh balance
                        const balanceResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': getCsrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'include',
                            cache: 'no-store',
                        })

                        if (balanceResponse.ok) {
                            const balanceData = await balanceResponse.json()
                            if (balanceData.success) {
                                setWalletBalance(pickWalletBalance(balanceData))
                            }
                        }
                    }

                    // Show success
                    setSuccessType('addMoney')
                    setSuccessMessage(data.message || `Successfully added $${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} to your wallet`)
                    setShowSuccess(true)

                    // Always refresh activities to show the new transaction (regardless of active tab)
                    try {
                        const activityResponse = await fetch(`/wallet/activity?page=1&per_page=10&t=${Date.now()}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': getCsrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'include',
                            cache: 'no-store',
                        })

                        if (activityResponse.ok) {
                            const activityData = await activityResponse.json()
                            if (activityData.success) {
                                setActivities(activityData.activities || [])
                                setHasMoreActivities(activityData.has_more || false)
                                setCurrentPage(1)
                            }
                        }
                    } catch (error) {
                        console.error('Failed to refresh activities:', error)
                    }

                    // Clear form
                    setAddMoneyAmount('')
                    setIsLoading(false)

                    // Hide success and return to main after 3 seconds
                    setTimeout(() => {
                        setShowSuccess(false)
                        setSuccessType(null)
                        setActionView('main')
                    }, 3000)
                } else {
                    throw new Error(data.message || 'Failed to add money')
                }
            } else {
                const errorData = await response.json().catch(() => ({}))
                throw new Error(errorData.message || 'Failed to add money. Please try again.')
            }
        } catch (error) {
            console.error('Add money error:', error)
            const errorMessage = error instanceof Error ? error.message : 'Failed to add money. Please try again.'
            showErrorToast(errorMessage)
            setIsLoading(false)
        }
    }

    const handleCopyReceiveAddress = () => {
        if (walletAddress) {
            navigator.clipboard.writeText(walletAddress)
            setCopied(true)
            setSuccessType('receive')
            setSuccessMessage('Address copied to clipboard!')
            setShowSuccess(true)
            setTimeout(() => {
                setCopied(false)
                setShowSuccess(false)
                setSuccessType(null)
            }, 2000)
        }
    }

    // Splash Screen and Skeleton components are now imported from './wallet'
    // Removed inline definitions to reduce file size

    if (typeof document === 'undefined') {
        return null
    }

    return createPortal(
        <AnimatePresence>
            {isOpen && (
                <React.Fragment key="wallet-popup">
                    {/* Backdrop - No blur */}
                    <motion.div
                        key="wallet-backdrop"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={onClose}
                        className="fixed inset-0 z-[80] bg-black/40"
                    />

                    {/* Popup - full screen on mobile, panel on desktop */}
                    <motion.div
                        key="wallet-popup-content"
                        initial={{ opacity: 0, scale: 0.95, y: -10 }}
                        animate={{ opacity: 1, scale: 1, y: 0 }}
                        exit={{ opacity: 0, scale: 0.95, y: -10 }}
                        transition={{ duration: 0.2 }}
                        className="fixed inset-0 z-[80] flex h-[100dvh] w-full flex-col overflow-hidden border-border bg-card shadow-2xl sm:inset-x-auto sm:inset-y-auto sm:top-16 sm:right-4 sm:h-[600px] sm:w-80 sm:rounded-xl sm:border md:w-96"
                    >
                        {/* Header - MetaMask style */}
                        <div className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                            <div className="flex items-center justify-between p-3 border-b border-white/10">
                                <div className="flex items-center gap-2">
                                    {actionView !== 'main' && (
                                        <button
                                            onClick={() => setActionView('main')}
                                            className="p-1 rounded-lg hover:bg-white/20 transition-colors mr-1"
                                        >
                                            <ArrowLeft className="h-4 w-4" />
                                        </button>
                                    )}
                                    <div className="p-1.5 bg-white/20 rounded-lg">
                                        <Wallet className="h-4 w-4" />
                                    </div>
                                    <span className="font-semibold text-sm">
                                        {actionView === 'send' ? 'Send' :
                                            actionView === 'receive' ? 'Receive' :
                                                actionView === 'swap' ? 'Swap' :
                                                    actionView === 'addMoney' ? 'Deposit' :
                                                        actionView === 'external_accounts' ? 'Bank Accounts' :
                                                            actionView === 'transfer_from_external' ? 'Transfer from Bank' :
                                                                actionView === 'withdraw_to_external' ? 'Withdraw to Bank' :
                                                                actionView === 'virtual_card' ? 'Cards' :
                                                                actionView === 'services_menu' ? 'Services' :
                                                                actionView === 'activity' ? 'Activity' :
                                                                actionView === 'transaction_details' ? 'Transaction Details' : 'Account'}
                                    </span>
                                </div>
                                <button
                                    onClick={onClose}
                                    className="p-1.5 rounded-lg hover:bg-white/20 transition-colors"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>

                            {/* Account Name - Only show on main view */}
                            {organizationName && actionView === 'main' && (
                                <div className="px-3 py-2 border-b border-white/10">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs text-white/80">{organizationName}</span>
                                        <ChevronDown className="h-4 w-4 text-white/80" />
                                    </div>
                                </div>
                            )}
                        </div>


                        {/* Content */}
                        <div className="flex-1 overflow-y-auto overflow-x-hidden relative flex flex-col min-h-0 wallet-scroll wallet-kyc-scroll">
                            {/* Splash Screen - Show during initial load */}
                            {isInitialLoading ? (
                                <SplashScreen key="splash-screen" />
                            ) : (
                                <motion.div
                                    key="main-content"
                                    initial={{ opacity: 0 }}
                                    animate={{ opacity: 1 }}
                                    exit={{ opacity: 0 }}
                                    className="flex-1 flex flex-col"
                                >
                                    {/* Success Animation Overlay */}
                                    <AnimatePresence>
                                        {showSuccess && (
                                        <SuccessMessage
                                                key="success-message"
                                            show={showSuccess}
                                            successType={successType}
                                            message={successMessage}
                                        />
                                        )}
                                    </AnimatePresence>

                                    {/* Balance Display - sticky top bar on wallet sub-pages */}
                                    {(
                                        actionView === 'send' ||
                                        actionView === 'receive' ||
                                        actionView === 'swap' ||
                                        actionView === 'addMoney' ||
                                        actionView === 'transfer_from_external' ||
                                        actionView === 'withdraw_to_external' ||
                                        actionView === 'external_accounts' ||
                                        actionView === 'virtual_card' ||
                                        actionView === 'services_menu'
                                    ) && !showSuccess && (
                                        walletBalance === null && isLoading ? (
                                            <BalanceSkeleton />
                                        ) : (
                                            <BalanceDisplay
                                                balance={walletBalance}
                                                isLoading={isLoading}
                                                onRefresh={handleRefresh}
                                                isSandbox={isSandbox}
                                            />
                                        )
                                    )}

                                    {!showSuccess && actionView === 'send' ? (
                                            <SendMoney
                                                key="send-money"
                                                sendAmount={sendAmount}
                                                walletBalance={walletBalance}
                                                recipientSearch={recipientSearch}
                                                searchResults={searchResults}
                                                selectedRecipient={selectedRecipient}
                                                isLoading={isLoading}
                                                isLoadingSearch={isLoadingSearch}
                                                showDropdown={showDropdown}
                                                searchInputRef={searchInputRef}
                                                dropdownRef={dropdownRef}
                                                onAmountChange={setSendAmount}
                                                onSearchChange={(value) => {
                                                    setRecipientSearch(value)
                                                    setShowDropdown(true)
                                                    if (!value) {
                                                        setSelectedRecipient(null)
                                                    }
                                                }}
                                                onSearchFocus={() => {
                                                    if (recipientSearch.length >= 2) {
                                                        setShowDropdown(true)
                                                    }
                                                }}
                                                onSelectRecipient={handleSelectRecipient}
                                                onSend={handleSend}
                                            />
                                    ) : !showSuccess && actionView === 'external_accounts' ? (
                                        isLoadingExternalAccounts && externalAccounts.length === 0 ? (
                                            <div key="external-accounts-loading" className="p-4 space-y-4">
                                                <div className="space-y-3">
                                                    {[1, 2, 3].map((i) => (
                                                        <div key={i} className="p-4 border border-border rounded-lg space-y-2">
                                                            <Skeleton className="h-5 w-32" />
                                                            <Skeleton className="h-4 w-24" />
                                                            <Skeleton className="h-4 w-40" />
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ) : (
                                            <ExternalAccounts
                                                key="external-accounts"
                                                externalAccounts={externalAccounts}
                                                isLoading={isLoadingExternalAccounts}
                                                isLinking={isLoading}
                                                isRemoving={isRemovingBankAccount}
                                                initialShowAddForm={showAddBankFormOnEntry}
                                                onRefresh={fetchExternalAccounts}
                                                onLinkAccount={handleLinkExternalAccount}
                                                onRemoveAccount={handleRemoveExternalAccount}
                                                onWithdraw={() => {
                                                    setActionView('withdraw_to_external')
                                                    setSelectedExternalAccount('')
                                                    setWithdrawAmount('')
                                                }}
                                            />
                                        )
                                    ) : !showSuccess && actionView === 'transfer_from_external' ? (
                                        isLoadingExternalAccounts && hasBankAccounts === null ? (
                                            <div key="transfer-from-external-loading" className="p-4 space-y-4">
                                                <Skeleton className="h-10 w-full" />
                                                <Skeleton className="h-10 w-full" />
                                                <Skeleton className="h-10 w-full" />
                                            </div>
                                        ) : (
                                        <TransferFromExternal
                                            key="transfer-from-external"
                                            externalAccounts={externalAccounts}
                                            selectedExternalAccount={selectedExternalAccount}
                                            transferAmount={transferAmount}
                                            isLoading={isLoading}
                                            onAccountChange={setSelectedExternalAccount}
                                            onAmountChange={setTransferAmount}
                                            onTransfer={handleTransferFromExternal}
                                                onAddBankAccount={goToAddBankAccount}
                                        />
                                        )
                                    ) : !showSuccess && actionView === 'withdraw_to_external' ? (
                                        isLoadingExternalAccounts && hasBankAccounts === null ? (
                                            <div key="withdraw-to-external-loading" className="p-4 space-y-4">
                                                <Skeleton className="h-10 w-full" />
                                                <Skeleton className="h-10 w-full" />
                                                <Skeleton className="h-10 w-full" />
                                            </div>
                                        ) : (
                                        <WithdrawToExternal
                                            key="withdraw-to-external"
                                            externalAccounts={externalAccounts}
                                            selectedExternalAccount={selectedExternalAccount}
                                            withdrawAmount={withdrawAmount}
                                                withdrawPaymentRail={withdrawPaymentRail}
                                            walletBalance={walletBalance}
                                            isLoading={isLoading}
                                                isSandbox={isSandbox}
                                                bankWithdrawalAvailable={bankWithdrawalAvailable}
                                            onAccountChange={setSelectedExternalAccount}
                                            onAmountChange={setWithdrawAmount}
                                                onPaymentRailChange={setWithdrawPaymentRail}
                                            onWithdraw={handleWithdrawToExternal}
                                                onAddBankAccount={goToAddBankAccount}
                                        />
                                        )
                                    ) : !showSuccess && actionView === 'services_menu' ? (
                                        <ServicesMenu
                                            onNavigate={setActionView}
                                            isCheckingCardWallet={isCheckingCardWallet}
                                            hasBankAccounts={hasBankAccounts}
                                            isCheckingBankAccounts={isLoadingExternalAccounts && hasBankAccounts === null}
                                            onAddBankAccount={goToAddBankAccount}
                                            onOpenMyGiftCards={() => {
                                                onClose()
                                                router.visit(route('gift-cards.my-cards'))
                                            }}
                                        />
                                    ) : !showSuccess && actionView === 'activity' ? (
                                        <ActivityScreen
                                            userId={auth?.user?.id ?? null}
                                            onBack={() => setActionView('main')}
                                            onActivityClick={handleActivityClick}
                                        />
                                    ) : !showSuccess && actionView === 'transaction_details' && selectedActivity ? (
                                        <TransactionDetails
                                            activity={selectedActivity}
                                            onBack={() => {
                                                // Go back to previous view (activity or main)
                                                if (activities.length > 10) {
                                                    setActionView('activity')
                                                } else {
                                                    setActionView('main')
                                                }
                                            }}
                                        />
                                    ) : !showSuccess && actionView === 'virtual_card' ? (
                                        <VirtualCard
                                            cardNumber="4532 •••• •••• 1234"
                                            cardholderName={auth?.user?.name?.toUpperCase() || 'CARDHOLDER'}
                                            expiryDate="12/25"
                                            cvv="123"
                                            onBack={() => setActionView('main')}
                                            onOpenCardsEndorsementVerification={openCardsEndorsementVerification}
                                            onCardCreated={() => {
                                                // Refresh card wallet status after card is created
                                                setHasCardWallet(null)
                                                checkCardWallet()
                                            }}
                                        />
                                    ) : !showSuccess && actionView === 'receive' ? (
                                        isLoadingReceiveData ? (
                                            <div key="receive-loading" className="p-4 space-y-4">
                                                <QRCodeSkeleton />
                                                <DepositInstructionsSkeleton />
                                            </div>
                                        ) : (
                                            <ReceiveMoney
                                                key="receive-money"
                                                isLoading={isLoadingReceiveData}
                                                qrCodeUrl={qrCodeUrl}
                                                depositInstructions={receiveDepositInstructions}
                                                walletAddress={walletAddress}
                                                copied={copied}
                                                onCopyAddress={handleCopyReceiveAddress}
                                                isSandbox={isSandbox}
                                            />
                                        )
                                    ) : !showSuccess && actionView === 'swap' ? (
                                        <SwapView key="swap-view" />
                                    ) : !showSuccess && actionView === 'addMoney' ? (
                                        isLoadingDepositInstructions ? (
                                            <div key="add-money-loading" className="p-4 space-y-4">
                                                <DepositInstructionsSkeleton />
                                            </div>
                                        ) : (
                                            <AddMoney
                                                key="add-money"
                                                isLoading={isLoadingDepositInstructions && !isCreatingDepositAccount}
                                                depositInstructions={depositInstructions}
                                                selectedPaymentMethod={selectedPaymentMethod}
                                                onPaymentMethodChange={setSelectedPaymentMethod}
                                                isCreatingDepositAccount={isCreatingDepositAccount}
                                                onCreateDepositAccount={handleCreateBridgeDepositAccount}
                                                isSandbox={isSandbox}
                                                regionBlocksUsAch={bridgeEndorsements?.region_blocks_us_ach === true}
                                                sepaAvailable={bridgeEndorsements?.sepa_available === true}
                                                usAchNotice={bridgeEndorsements?.base_customer_reason ?? null}
                                            />
                                        )
                                    ) : actionView === 'main' ? (
                                        (() => {
                                            // Show skeleton while initial loading
                                            if (isInitialLoading || (isLoading && walletBalance === null && walletAddress === null)) {
                                                return 'skeleton'
                                            }

                                            // PRIORITY 1: Local Bridge wallet OR display address → full wallet UI
                                            // Org wallets may have hasWallet without a displayable walletAddress.
                                            const walletReady = Boolean(walletAddress) || hasWallet
                                            if (walletReady) {
                                                return 'wallet_screen'
                                            }

                                            // PRIORITY 2: Check if account is approved but wallet doesn't exist
                                            const isApproved = verificationType && (
                                                (verificationType === 'kyb' && kybStatus === 'approved') ||
                                                (verificationType === 'kyc' && kycStatus === 'approved')
                                            )

                                            // Only show create wallet screen if approved AND we don't have a wallet yet
                                            if (isApproved && !walletReady) {
                                                return 'create_wallet' // Show Create Wallet screen
                                            }

                                            // PRIORITY 3: Check if bridge is not initialized
                                            if (!bridgeInitialized && !isLoading) {
                                                return 'connect_wallet' // Show Connect Wallet screen
                                            }

                                            return 'other' // Continue to other checks
                                        })() === 'skeleton' ? (
                                            <div key="account-skeleton" className="space-y-4">
                                                <BalanceSkeleton variant="hero" />
                                                <div className="px-4">
                                                    <Skeleton className="h-12 w-full rounded-xl mb-4" />
                                                    <Skeleton className="h-3.5 w-24 mb-2" />
                                                    <div className="grid grid-cols-2 gap-2">
                                                    {[1, 2, 3, 4].map((i) => (
                                                            <Skeleton key={i} className="h-[68px] rounded-xl" />
                                                    ))}
                                                </div>
                                                </div>
                                                <ActivitySkeleton />
                                            </div>
                                        ) : (() => {
                                            // PRIORITY 1: Local Bridge wallet OR display address → full wallet UI
                                            const walletReady = Boolean(walletAddress) || hasWallet
                                            if (walletReady) {
                                                return 'wallet_screen'
                                            }

                                            // PRIORITY 2: Check if account is approved but wallet doesn't exist
                                            const isApproved = verificationType && (
                                                (verificationType === 'kyb' && kybStatus === 'approved') ||
                                                (verificationType === 'kyc' && kycStatus === 'approved')
                                            )

                                            if (isApproved && !walletReady) {
                                                return 'create_wallet'
                                            }

                                            // PRIORITY 3: Check if bridge is not initialized
                                            if (!bridgeInitialized && !isLoading) {
                                                return 'connect_wallet'
                                            }

                                            return 'other'
                                        })() === 'wallet_screen' ? (
                                            <WalletScreen
                                                key="wallet-screen"
                                                walletBalance={walletBalance}
                                                walletAddress={walletAddress}
                                                isLoading={isLoading}
                                                copied={copied}
                                                isSandbox={isSandbox}
                                                onRefresh={handleRefresh}
                                                onCopyAddress={handleCopyAddress}
                                                onActionViewChange={setActionView}
                                                regionBlocksUsAch={bridgeEndorsements?.region_blocks_us_ach === true}
                                                sepaAvailable={bridgeEndorsements?.sepa_available === true}
                                                usAchNotice={bridgeEndorsements?.base_customer_reason ?? null}
                                            />
                                        ) : (() => {
                                            // PRIORITY 1: Check if account is approved but wallet doesn't exist
                                            const walletReady = Boolean(walletAddress) || hasWallet
                                            const isApproved = verificationType && (
                                                (verificationType === 'kyb' && kybStatus === 'approved') ||
                                                (verificationType === 'kyc' && kycStatus === 'approved')
                                            )

                                            if (isApproved && !walletReady) {
                                                return 'create_wallet' // Show Create Wallet screen
                                            }

                                            // PRIORITY 2: Check if bridge is not initialized
                                            if (!bridgeInitialized && !isLoading) {
                                                return 'connect_wallet' // Show Connect Wallet screen
                                            }

                                            return 'other' // Continue to other checks
                                        })() === 'create_wallet' ? (
                                            <CreateWallet
                                                key="create-wallet"
                                                isLoading={isLoading}
                                                isSandbox={isSandbox}
                                                verificationType={verificationType}
                                                onCreateWallet={handleCreateWallet}
                                            />
                                        ) : isConnectingWallet ? (
                                            // Show animated loading screen when connecting wallet
                                            <div className="flex-1 flex flex-col items-center justify-center p-6 space-y-6 relative z-10">
                                                {/* Animated Spinner with Center Icon */}
                                                <motion.div
                                                    className="relative w-24 h-24 flex items-center justify-center"
                                                    initial={{ scale: 0, opacity: 0 }}
                                                    animate={{ scale: 1, opacity: 1 }}
                                                    transition={{ duration: 0.3 }}
                                                >
                                                    {/* Glowing Background Ring */}
                                                    <motion.div
                                                        className="absolute inset-0 rounded-full bg-gradient-to-r from-purple-600/20 to-blue-600/20 blur-xl"
                                                        animate={{
                                                            scale: [1, 1.2, 1],
                                                            opacity: [0.3, 0.5, 0.3],
                                                        }}
                                                        transition={{
                                                            duration: 2,
                                                            repeat: Infinity,
                                                            ease: "easeInOut",
                                                        }}
                                                    />

                                                    {/* Outer Ring */}
                                                    <motion.div
                                                        className="absolute inset-0 border-4 border-purple-200 dark:border-purple-800 rounded-full"
                                                        animate={{ rotate: 360 }}
                                                        transition={{
                                                            duration: 2,
                                                            repeat: Infinity,
                                                            ease: "linear",
                                                        }}
                                                    />

                                                    {/* Inner Spinner */}
                                                    <motion.div
                                                        className="absolute inset-2 border-4 border-transparent border-t-purple-600 dark:border-t-purple-400 rounded-full"
                                                        animate={{ rotate: -360 }}
                                                        transition={{
                                                            duration: 1,
                                                            repeat: Infinity,
                                                            ease: "linear",
                                                        }}
                                                    />

                                                    {/* Center Icon - Larger and More Prominent */}
                                                    <motion.div
                                                        className="relative z-10"
                                                        animate={{
                                                            scale: [1, 1.1, 1],
                                                        }}
                                                        transition={{
                                                            duration: 1.5,
                                                            repeat: Infinity,
                                                            ease: "easeInOut",
                                                        }}
                                                    >
                                                        <Wallet className="h-12 w-12 text-purple-600 dark:text-purple-400" />
                                                    </motion.div>
                                                </motion.div>

                                                {/* Loading Text */}
                                                <motion.div
                                                    className="text-center space-y-2"
                                                    initial={{ opacity: 0, y: 10 }}
                                                    animate={{ opacity: 1, y: 0 }}
                                                    transition={{ delay: 0.2 }}
                                                >
                                                    <h3 className="text-xl font-semibold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent">
                                                        Connecting Wallet...
                                                    </h3>
                                                    <p className="text-sm text-muted-foreground">
                                                        Setting up your Bridge account
                                                    </p>
                                                </motion.div>

                                                {/* Animated Dots */}
                                                <motion.div
                                                    className="flex gap-2"
                                                    initial={{ opacity: 0 }}
                                                    animate={{ opacity: 1 }}
                                                    transition={{ delay: 0.4 }}
                                                >
                                                    {[0, 1, 2].map((i) => (
                                                        <motion.div
                                                            key={i}
                                                            className="w-2 h-2 bg-purple-600 dark:bg-purple-400 rounded-full"
                                                            animate={{
                                                                y: [0, -8, 0],
                                                                opacity: [0.5, 1, 0.5],
                                                            }}
                                                            transition={{
                                                                duration: 0.6,
                                                                repeat: Infinity,
                                                                delay: i * 0.2,
                                                                ease: "easeInOut",
                                                            }}
                                                        />
                                                    ))}
                                                </motion.div>
                                            </div>
                                        ) : (() => {
                                            // Check if we should show Connect Wallet
                                            if (!bridgeInitialized && !isLoading) {
                                                return 'connect_wallet'
                                            }
                                            return 'other'
                                        })() === 'connect_wallet' ? (
                                            <ConnectWallet
                                                key="connect-wallet"
                                                isLoading={isLoading}
                                                organizationName={organizationName}
                                                onConnect={handleConnectWallet}
                                            />
                                        ) : (() => {
                                            // PRIORITY: If status is approved, ALWAYS show wallet screen (return false)
                                            if (bridgeInitialized && verificationType) {
                                                const isKybApproved = verificationType === 'kyb' && kybStatus === 'approved'
                                                const isKycApproved = verificationType === 'kyc' && kycStatus === 'approved'

                                                if (isKybApproved || isKycApproved) {
                                                    return false // Show wallet screen - status is approved!
                                                }
                                            }

                                            // PRIORITY: For KYC - if submitted and pending, show simple waiting screen instead of full verification screen
                                            // Check if user is a regular user (not organization)
                                            const isRegularUser = auth?.user?.role === 'user' || !auth?.user?.role
                                            const isKycPending = isRegularUser && 
                                                verificationType === 'kyc' && 
                                                isBridgeKycPending(kycStatus)
                                            
                                            if (isKycPending) {
                                                return 'kyc_waiting' // Show simple waiting screen
                                            }

                                            // Show verification screen only if:
                                            // 1. Bridge is initialized
                                            // 2. Verification type is set  
                                            // 3. Status is NOT approved
                                            if (!bridgeInitialized || !verificationType) {
                                                return false // Show wallet screen if not initialized or no verification type
                                            }

                                            // Check if status is NOT approved
                                            const isKybNotApproved = verificationType === 'kyb' && kybStatus && kybStatus !== 'approved'
                                            const isKycNotApproved = verificationType === 'kyc' && kycStatus && kycStatus !== 'approved'

                                            // Show verification screen if status exists and is NOT approved
                                            return isKybNotApproved || isKycNotApproved
                                        })() === 'kyc_waiting' ? (
                                            <motion.div
                                                key="kyc-waiting-screen"
                                                initial={{ opacity: 0, y: 20 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -20 }}
                                                transition={{ duration: 0.3 }}
                                                className="flex-1 flex flex-col items-center justify-center p-3 sm:p-4"
                                            >
                                                <KycVerificationStatusPanel
                                                    kycStatus={kycStatus}
                                                    onRefresh={() => void checkBridgeAndFetchBalance()}
                                                    isRefreshing={isLoading}
                                                />
                                            </motion.div>
                                        ) : (() => {
                                            const walletVerified = isWalletBridgeAccountVerified({
                                                initialized: bridgeInitialized,
                                                verification_type: verificationType ?? undefined,
                                                kyb_status: kybStatus,
                                                kyc_status: kycStatus,
                                                tos_status: tosStatus,
                                                tos_accepted:
                                                    tosStatus === 'accepted' || tosStatus === 'approved',
                                                requires_verification: requiresVerification,
                                            })

                                            if (walletVerified) {
                                                return false
                                            }

                                            // PRIORITY: If status is approved, ALWAYS show wallet screen (return false)
                                            if (bridgeInitialized && verificationType) {
                                                const isKybApproved = verificationType === 'kyb' && kybStatus === 'approved'
                                                const isKycApproved = verificationType === 'kyc' && kycStatus === 'approved'

                                                if (isKybApproved || isKycApproved) {
                                                    return false // Show wallet screen - status is approved!
                                                }
                                            }

                                            // Show verification screen only if:
                                            // 1. Bridge is initialized
                                            // 2. Verification type is set  
                                            // 3. Status is NOT approved
                                            if (!bridgeInitialized || !verificationType) {
                                                return false // Show wallet screen if not initialized or no verification type
                                            }

                                            // Check if status is NOT approved
                                            const isKybNotApproved = verificationType === 'kyb' && kybStatus && kybStatus !== 'approved'
                                            const isKycNotApproved = verificationType === 'kyc' && kycStatus && kycStatus !== 'approved'

                                            // Show verification screen if status exists and is NOT approved
                                            return isKybNotApproved || isKycNotApproved
                                        })() ? (
                                            /* Verification Required Screen */
                                            <motion.div
                                                key="verification-screen"
                                                initial={{ opacity: 0, y: 20 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                exit={{ opacity: 0, y: -20 }}
                                                transition={{ duration: 0.3 }}
                                                className="flex flex-col items-center p-3 sm:p-4 space-y-3 sm:space-y-4 w-full"
                                            >
                                                <div className="text-center space-y-3 sm:space-y-4 w-full">
                                                    {/* Icon */}
                                                    <motion.div
                                                        initial={{ scale: 0.8 }}
                                                        animate={{ scale: 1 }}
                                                        transition={{ duration: 0.3, delay: 0.1 }}
                                                        className="mx-auto w-16 h-16 sm:w-20 sm:h-20 bg-gradient-to-br from-amber-500 to-orange-600 rounded-full flex items-center justify-center shadow-xl relative flex-shrink-0"
                                                    >
                                                        <Shield className="h-8 w-8 sm:h-10 sm:w-10 text-white" />
                                                        <motion.div
                                                            animate={{
                                                                scale: [1, 1.2, 1],
                                                                opacity: [0.5, 0.8, 0.5]
                                                            }}
                                                            transition={{
                                                                duration: 2,
                                                                repeat: Infinity,
                                                                ease: "easeInOut"
                                                            }}
                                                            className="absolute inset-0 rounded-full bg-amber-400/30"
                                                        />
                                                    </motion.div>

                                                    {/* Title and Description */}
                                                    <div className="space-y-1 sm:space-y-2">
                                                        <h3 className="text-lg sm:text-xl font-bold">Verification Required</h3>
                                                        <p className="text-xs sm:text-sm text-muted-foreground">
                                                            {verificationType === 'kyb'
                                                                ? 'Complete your business verification (KYB) to access all wallet features and start making transactions.'
                                                                : 'Complete your identity verification (KYC) to access all wallet features and start making transactions.'}
                                                        </p>
                                                    </div>

                                                    {/* Verification Steps */}
                                                    <div className="space-y-2 sm:space-y-3 w-full">
                                                        {/* Step 1: Terms of Service */}
                                                        <motion.div
                                                            initial={{ opacity: 0, x: -20 }}
                                                            animate={{ opacity: 1, x: 0 }}
                                                            transition={{ duration: 0.3, delay: 0.2 }}
                                                            className={`py-3 sm:py-4 pl-2 sm:pl-3 pr-3 sm:pr-4 rounded-lg border-2 transition-all w-full ${(tosStatus === 'accepted' || tosStatus === 'approved')
                                                                    ? 'bg-green-50 dark:bg-green-900/20 border-green-500 dark:border-green-600'
                                                                    : 'bg-muted border-border'
                                                                }`}
                                                        >
                                                            {/* Title Section - Flex Layout */}
                                                            <div className="flex items-start gap-2 sm:gap-3 mb-3">
                                                                <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${(tosStatus === 'accepted' || tosStatus === 'approved')
                                                                        ? 'bg-green-500 text-white'
                                                                        : 'bg-muted-foreground/20 text-muted-foreground'
                                                                    }`}>
                                                                    {(tosStatus === 'accepted' || tosStatus === 'approved') ? (
                                                                        <CheckCircle2 className="h-5 w-5" />
                                                                    ) : (
                                                                        <span className="text-sm font-bold">1</span>
                                                                    )}
                                                                </div>
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="flex items-center justify-between mb-1">
                                                                        <h4 className="font-semibold text-sm text-foreground">Accept Terms of Service</h4>
                                                                        {(tosStatus === 'accepted' || tosStatus === 'approved') && (
                                                                            <CheckCircle2 className="h-4 w-4 text-green-500" />
                                                                        )}
                                                                    </div>
                                                                    <p className="text-xs text-muted-foreground dark:text-muted-foreground mb-0 text-left">
                                                                        Review and accept Bridge's terms of service to proceed
                                                                    </p>
                                                                </div>
                                                            </div>

                                                            {/* TOS Content - Full Width */}
                                                            {tosStatus !== 'accepted' && tosStatus !== 'approved' && (
                                                                <div className="w-full">
                                                                    {tosIframeUrl ? (
                                                                        <TermsOfService
                                                                            tosUrl={tosIframeUrl}
                                                                            onAccept={handleConfirmTosAcceptance}
                                                                            onCancel={() => setTosIframeUrl(null)}
                                                                            isAccepted={tosStatus === 'accepted' || tosStatus === 'approved'}
                                                                            isLoading={isLoading}
                                                                        />
                                                                    ) : (
                                                                        <Button
                                                                            size="sm"
                                                                            onClick={handleAcceptTos}
                                                                            className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white dark:text-white text-xs"
                                                                            disabled={isLoading}
                                                                        >
                                                                            <FileCheck className="h-3 w-3 mr-2 text-white" />
                                                                            {isLoading ? 'Loading...' : 'Terms of Service'}
                                                                        </Button>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </motion.div>

                                                        {/* Step 2: KYC/KYB — Bridge Persona modal only */}
                                                        <motion.div
                                                            initial={{ opacity: 0, x: -20 }}
                                                            animate={{ opacity: 1, x: 0 }}
                                                            transition={{ duration: 0.3, delay: 0.3 }}
                                                            className={`py-3 sm:py-4 pl-2 sm:pl-3 pr-3 sm:pr-4 rounded-lg border-2 transition-all w-full ${(verificationType === 'kyb' ? kybStatus : kycStatus) === 'approved'
                                                                    ? 'bg-green-50 dark:bg-green-900/20 border-green-500 dark:border-green-600'
                                                                    : (tosStatus === 'accepted' || tosStatus === 'approved')
                                                                        ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-500 dark:border-blue-600'
                                                                        : 'bg-muted border-border opacity-60'
                                                                }`}
                                                        >
                                                            <div className="flex items-start gap-2 sm:gap-3 mb-3">
                                                                <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ${(verificationType === 'kyb' ? kybStatus : kycStatus) === 'approved'
                                                                        ? 'bg-green-500 text-white'
                                                                        : (tosStatus === 'accepted' || tosStatus === 'approved')
                                                                            ? 'bg-blue-500 text-white'
                                                                            : 'bg-muted-foreground/20 text-muted-foreground'
                                                                    }`}>
                                                                    {(verificationType === 'kyb' ? kybStatus : kycStatus) === 'approved' ? (
                                                                        <CheckCircle2 className="h-5 w-5" />
                                                                    ) : (
                                                                        <span className="text-sm font-bold">2</span>
                                                                    )}
                                                                </div>
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="flex items-center justify-between mb-1">
                                                                        <h4 className="font-semibold text-sm">
                                                                            {verificationType === 'kyb' ? 'Business Verification (KYB)' : 'Identity Verification (KYC)'}
                                                                        </h4>
                                                                        {(verificationType === 'kyb' ? kybStatus : kycStatus) === 'approved' && (
                                                                            <CheckCircle2 className="h-4 w-4 text-green-500" />
                                                                        )}
                                                                        {(verificationType === 'kyb' ? kybStatus : kycStatus) !== 'approved' &&
                                                                            (verificationType === 'kyb' ? kybStatus : kycStatus) !== 'rejected' &&
                                                                            (verificationType === 'kyb' ? kybStatus : kycStatus) !== 'not_started' && (
                                                                                <Clock className="h-4 w-4 text-blue-500" />
                                                                            )}
                                                                    </div>
                                                                    <p className="text-xs text-muted-foreground mb-0 text-left">
                                                                        {verificationType === 'kyb'
                                                                            ? 'Complete business verification in the Bridge window'
                                                                            : 'Complete identity verification in the Bridge window'}
                                                                    </p>
                                                                </div>
                                                            </div>

                                                            {(tosStatus === 'accepted' || tosStatus === 'approved') && (verificationType === 'kyb' ? kybStatus : kycStatus) !== 'approved' && (
                                                                    <div className="space-y-2 sm:space-y-3 w-full mt-3">
                                                                    <BridgePersonaVerificationPanel
                                                                        verificationType={verificationType}
                                                                                            kycStatus={kycStatus}
                                                                        kybStatus={kybStatus}
                                                                                        isLoading={isLoading}
                                                                        isLoadingVerificationWidget={isLoadingVerificationWidget}
                                                                        onOpenVerification={() => void openBridgeVerificationWidget()}
                                                                        onRefresh={() => void checkBridgeAndFetchBalance()}
                                                                    />
                                                                    </div>
                                                                )}
                                                            {tosStatus !== 'accepted' && tosStatus !== 'approved' && (
                                                                <p className="text-xs text-amber-600 dark:text-amber-400 italic mt-3">
                                                                    Complete step 1 first
                                                                </p>
                                                            )}
                                                        </motion.div>

                                                    </div>

                                                    {/* Status Badge - Show for all "in progress" statuses */}
                                                    {/* Bridge KYC/KYB statuses: not_started, incomplete, under_review, awaiting_questionnaire, awaiting_ubo, approved, rejected, paused, offboarded */}
                                                    {(verificationType === 'kyb'
                                                        ? isBridgeKybPending(kybStatus)
                                                        : isBridgeKycPending(kycStatus)) && (
                                                            <motion.div
                                                                initial={{ opacity: 0 }}
                                                                animate={{ opacity: 1 }}
                                                                transition={{ delay: 0.4 }}
                                                                className="p-2 sm:p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800"
                                                            >
                                                                <div className="flex items-center gap-2 text-xs sm:text-sm">
                                                                    <Clock className="h-3 w-3 sm:h-4 sm:w-4 text-blue-600 dark:text-blue-400 flex-shrink-0" />
                                                                    <span className="text-blue-900 dark:text-blue-100">
                                                                        Status:{' '}
                                                                        {formatBridgeVerificationStatusLabel(
                                                                            verificationType === 'kyb'
                                                                                ? kybStatus
                                                                                : kycStatus,
                                                                        )}
                                                                        . This usually takes a few minutes.
                                                                    </span>
                                                                </div>
                                                            </motion.div>
                                                        )}

                                                    {(verificationType === 'kyb' ? kybStatus : kycStatus) === 'rejected' && (
                                                        <motion.div
                                                            initial={{ opacity: 0 }}
                                                            animate={{ opacity: 1 }}
                                                            transition={{ delay: 0.4 }}
                                                            className="p-2 sm:p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800"
                                                        >
                                                            <div className="flex items-center gap-2 text-xs sm:text-sm">
                                                                <AlertCircle className="h-3 w-3 sm:h-4 sm:w-4 text-red-600 dark:text-red-400 flex-shrink-0" />
                                                                <span className="text-red-900 dark:text-red-100">
                                                                    Verification was rejected. Please try again or contact support.
                                                                </span>
                                                            </div>
                                                        </motion.div>
                                                    )}

                                                    {/* Info */}
                                                    <p className="text-xs text-muted-foreground">
                                                        After completing verification, you'll be able to deposit, send, and receive funds securely.
                                                    </p>
                                                </div>
                                            </motion.div>
                                        ) : (Boolean(walletAddress) || hasWallet) ? (
                                            /* Verified Bridge account with a wallet — never fall through to balance-only UI */
                                            <WalletScreen
                                                key="wallet-screen-verified"
                                                walletBalance={walletBalance}
                                                walletAddress={walletAddress}
                                                isLoading={isLoading}
                                                copied={copied}
                                                isSandbox={isSandbox}
                                                onRefresh={handleRefresh}
                                                onCopyAddress={handleCopyAddress}
                                                onActionViewChange={setActionView}
                                                regionBlocksUsAch={bridgeEndorsements?.region_blocks_us_ach === true}
                                                sepaAvailable={bridgeEndorsements?.sepa_available === true}
                                                usAchNotice={bridgeEndorsements?.base_customer_reason ?? null}
                                            />
                                        ) : (
                                            /* Verified but no local wallet yet — prompt create instead of empty Balance */
                                            <CreateWallet
                                                key="create-wallet-verified"
                                                isLoading={isLoading}
                                                isSandbox={isSandbox}
                                                verificationType={verificationType}
                                                onCreateWallet={handleCreateWallet}
                                            />
                                        )
                                    ) : null}

                                    {/* Activity - show once Bridge account is verified (org wallets may lack local wallet_address) */}
                                    {actionView === 'main' && (hasWallet || walletAddress || kybStatus === 'approved' || kycStatus === 'approved') && (
                                        <div className="flex-shrink-0 max-h-[350px] min-h-0 flex flex-col">
                                            {isLoadingActivities && activities.length === 0 ? (
                                            <ActivitySkeleton />
                                        ) : (
                                            <ActivityList
                                                activities={activities}
                                                isLoading={isLoadingActivities}
                                                hasMore={hasMoreActivities}
                                                isLoadingMore={isLoadingMore}
                                                onScroll={() => {}}
                                                onSeeMore={handleSeeMore}
                                                showSeeMore={activities.length >= 10}
                                                onActivityClick={handleActivityClick}
                                            />
                                    )}
                                        </div>
                                    )}
                                </motion.div>
                            )}
                        </div>

                        {/* Footer Actions */}
                        <div className="border-t border-border p-3 bg-muted/30">
                            <div className="text-xs text-muted-foreground text-center">
                                Secure wallet powered by Believe In Unity
                            </div>
                        </div>

                    </motion.div>
                </React.Fragment>
            )}

            {/* Subscription Required Modal */}
            {showSubscriptionModal && (
            <SubscriptionRequiredModal
                    key="subscription-modal"
                isOpen={showSubscriptionModal}
                onClose={() => {
                    setShowSubscriptionModal(false)
                    onClose() // Also close the wallet popup
                }}
                feature="wallet"
            />
            )}

            <BridgeVerificationModal
                isOpen={showVerificationIframe}
                onClose={handleCloseVerificationModal}
                onVerificationComplete={handleBridgeVerificationComplete}
                widgetUrl={verificationModalWidgetUrl}
                fallbackLinkUrl={verificationType === 'kyb' ? kybLinkUrl : kycLinkUrl}
                verificationType={verificationType === 'kyb' ? 'kyb' : 'kyc'}
                isLoading={isLoadingVerificationWidget}
            />
        </AnimatePresence>,
        document.body,
    )
}



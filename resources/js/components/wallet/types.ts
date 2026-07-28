// Shared types for wallet components

export type ActionView = 'main' | 'send' | 'receive' | 'swap' | 'addMoney' | 'external_accounts' | 'transfer_from_external' | 'withdraw_to_external' | 'virtual_card' | 'services_menu' | 'activity' | 'transaction_details'
export type ActiveTab = 'account' | 'activity'
export type SuccessType = 'send' | 'receive' | 'swap' | 'addMoney' | null
export type PaymentMethod = 'ach' | 'wire'
export type VerificationStatus = 'not_started' | 'incomplete' | 'under_review' | 'awaiting_questionnaire' | 'awaiting_ubo' | 'approved' | 'rejected' | 'paused' | 'offboarded'
export type TosStatus = 'pending' | 'accepted' | 'rejected'
export type VerificationType = 'kyc' | 'kyb' | null
export type KybStep = 'control_person' | 'business_documents' | 'kyc_verification'
export type BusinessType = '' | 'cooperative' | 'corporation' | 'llc' | 'other' | 'partnership' | 'sole_prop' | 'trust'

export interface DepositInstructions {
    bank_name?: string
    bank_address?: string
    bank_routing_number?: string
    bank_account_number?: string
    bank_beneficiary_name?: string
    bank_beneficiary_address?: string
    /** EUR Virtual IBAN (SEPA) */
    iban?: string
    bic?: string
    payment_rail?: string
    payment_rails?: string[]
    currency?: string
}

export interface Activity {
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
    /** Bridge on-ramp rail: ach, wire, fednow, sepa, etc. */
    payment_method?: string | null
    /** Human label for UI: ACH, Wire, FedNow, … */
    payment_method_label?: string | null
    /** Raw Bridge lifecycle state (transfer state, VA event type, wallet history type) */
    bridge_state?: string | null
    /** Primary one-line label for activity lists (from Bridge API) */
    display_label?: string | null
    /** Raw Bridge transfer lifecycle state from GET /transfers/{id} */
    bridge_transfer_state?: string | null
    /** Human-readable Bridge state label for UI subtitles */
    bridge_state_label?: string | null
    deposit_id?: string | null
    bridge_transfer_id?: string | null
    virtual_account_event_id?: string | null
}

export interface ExternalAccount {
    id: string
    account_number: string
    routing_number: string
    account_type: string
    account_holder_name: string
    bank_name?: string
    status: string
}

export interface Recipient {
    id: string
    type: string
    name: string
    email?: string
    display_name: string
}

export interface KycFormData {
    first_name: string
    last_name: string
    email: string
    birth_date: string
    street_line_1: string
    city: string
    subdivision: string
    postal_code: string
    country: string
    ssn: string
    id_type: string
    id_number: string
    id_front_image: string
    id_back_image: string
}

export interface KybFormData {
    business_name: string
    email: string
    street_line_1: string
    city: string
    subdivision: string
    postal_code: string
    country: string
    ein: string
    business_description: string
    business_industry: string
    primary_website: string
    business_type: BusinessType
    entity_type: string
    dao_status: boolean
    physical_address: {
        street_line_1: string
        street_line_2: string
        city: string
        subdivision: string
        postal_code: string
        country: string
    }
    source_of_funds: string
    annual_revenue: string
    transaction_volume: string
    account_purpose: string
    high_risk_activities: string
    high_risk_geographies: string
    control_person: {
        first_name: string
        last_name: string
        email: string
        birth_date: string
        ssn: string
        title: string
        ownership_percentage: string
        street_line_1: string
        city: string
        state: string
        postal_code: string
        country: string
        id_type: string
        id_number: string
        id_front_image: string
        id_back_image: string
    }
    business_formation_document: string
    business_ownership_document: string
    proof_of_address_document: string
    proof_of_nature_of_business: string
    determination_letter_501c3: string
}

export interface WalletPopupState {
    walletBalance: number | null
    walletAddress: string | null
    isLoading: boolean
    hasSubscription: boolean | null
    showSubscriptionModal: boolean
    copied: boolean
    activeTab: ActiveTab
    actionView: ActionView
    showSuccess: boolean
    successMessage: string
    successType: SuccessType
    bridgeInitialized: boolean
    hasWallet: boolean
    isSandbox: boolean
    depositInstructions: DepositInstructions | null
    isLoadingDepositInstructions: boolean
    selectedPaymentMethod: PaymentMethod
    qrCodeUrl: string | null
    receiveDepositInstructions: DepositInstructions | null
    isLoadingReceiveData: boolean
    kycStatus: VerificationStatus
    kybStatus: VerificationStatus
    kycLinkUrl: string | null
    kybLinkUrl: string | null
    kycWidgetUrl: string | null
    kybWidgetUrl: string | null
    tosLinkUrl: string | null
    tosStatus: TosStatus
    requiresVerification: boolean
    verificationType: VerificationType
    showVerificationIframe: boolean
    useCustomKyc: boolean
    showTosIframe: boolean
    tosIframeUrl: string | null
    signedAgreementId: string | null
}


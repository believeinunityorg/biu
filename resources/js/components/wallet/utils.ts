// Utility functions for wallet components

import { getCsrfToken, syncCsrfMetaFromCookie } from '@/lib/csrf'
import type { Activity } from './types'

export { getCsrfToken, syncCsrfMetaFromCookie }

/**
 * True when Laravel rejected the request due to CSRF/session mismatch.
 */
export const isCsrfMismatch = (status: number, message?: string | null): boolean => {
    if (status === 419) {
        return true
    }

    if (!message) {
        return false
    }

    const lower = message.toLowerCase()

    return lower.includes('csrf') || lower.includes('page expired')
}

/**
 * Refresh CSRF token by making a GET request
 */
export const refreshCsrfToken = async (): Promise<string> => {
    try {
        await fetch(`/wallet/bridge/status?t=${Date.now()}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'include',
            cache: 'no-store',
        })
        syncCsrfMetaFromCookie()
        return getCsrfToken()
    } catch (e) {
        console.warn('Failed to refresh CSRF token:', e)
        return ''
    }
}

/**
 * Reusable fetch function with automatic CSRF token handling and retry logic
 * 
 * @param url - The URL to fetch
 * @param options - Fetch options (method, body, headers, etc.)
 * @param retryOnCsrfError - Whether to retry on CSRF errors (default: true)
 * @param maxRetries - Maximum number of retries (default: 1)
 * @returns Promise<Response>
 */
export interface FetchOptions extends RequestInit {
    method?: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'
    body?: BodyInit | object | null
    headers?: HeadersInit
    retryOnCsrfError?: boolean
    maxRetries?: number
}

export const walletFetch = async (
    url: string,
    options: FetchOptions = {},
    retryOnCsrfError: boolean = true,
    maxRetries: number = 1
): Promise<Response> => {
    const {
        method = 'GET',
        body,
        headers = {},
        retryOnCsrfError: optionRetryOnCsrf = retryOnCsrfError,
        maxRetries: optionMaxRetries = maxRetries,
        ...restOptions
    } = options

    // Get CSRF token
    let csrfToken = getCsrfToken()

    // If token is missing, try to refresh it
    if (!csrfToken && optionRetryOnCsrf) {
        csrfToken = await refreshCsrfToken()
    }

    // Prepare headers
    const defaultHeaders: HeadersInit = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrfToken && { 'X-CSRF-TOKEN': csrfToken }),
    }

    // Add Content-Type for requests with body
    if (body && method !== 'GET') {
        if (typeof body === 'object' && !(body instanceof FormData) && !(body instanceof Blob)) {
            defaultHeaders['Content-Type'] = 'application/json'
        }
    }

    // Merge with provided headers
    const mergedHeaders = {
        ...defaultHeaders,
        ...headers,
    }

    // Prepare body
    let requestBody: BodyInit | null = null
    if (body) {
        if (typeof body === 'object' && !(body instanceof FormData) && !(body instanceof Blob) && !(body instanceof ArrayBuffer)) {
            requestBody = JSON.stringify(body)
        } else {
            requestBody = body as BodyInit
        }
    }

    // Make the request
    const makeRequest = async (token: string): Promise<Response> => {
        const requestHeaders = {
            ...mergedHeaders,
            'X-CSRF-TOKEN': token,
        }

        return fetch(url, {
            method,
            headers: requestHeaders,
            body: requestBody,
            credentials: 'include',
            cache: 'no-store',
            ...restOptions,
        })
    }

    try {
        let response = await makeRequest(csrfToken)

        // Handle CSRF token mismatch with retry logic
        if (!response.ok && optionRetryOnCsrf && optionMaxRetries > 0) {
            const contentType = response.headers.get('content-type')
            if (contentType && contentType.includes('application/json')) {
                try {
                    const errorData = await response.clone().json()
                    const isCsrfError =
                        isCsrfMismatch(response.status, errorData.message) ||
                        (response.status === 403 && typeof errorData.message === 'string' && errorData.message.toLowerCase().includes('csrf'))

                    if (isCsrfError) {
                        console.warn('CSRF token mismatch detected, refreshing token and retrying...')
                        
                        // Refresh token
                        const newToken = await refreshCsrfToken()
                        if (newToken) {
                            // Retry the request with fresh token
                            response = await makeRequest(newToken)
                        } else {
                            console.error('Failed to refresh CSRF token for retry')
                            // Return the original response instead of throwing
                            return response
                        }
                    }
                } catch (e) {
                    // If JSON parsing fails, check status code
                    if (response.status === 419 || response.status === 403) {
                        const newToken = await refreshCsrfToken()
                        if (newToken) {
                            response = await makeRequest(newToken)
                        }
                    }
                }
            } else {
                // For non-JSON responses, still check for CSRF errors
                if (response.status === 419 || response.status === 403) {
                    const newToken = await refreshCsrfToken()
                    if (newToken) {
                        response = await makeRequest(newToken)
                    }
                }
            }
        }

        return response
    } catch (error) {
        // If fetch itself fails (network error, etc.), re-throw it
        console.error('walletFetch network error:', error)
        throw error
    }
}

/**
 * Format address for display (truncate if too long)
 */
export const formatAddress = (address: string, maxLength: number = 20): string => {
    if (!address) return ''
    if (address.length <= maxLength) return address
    return `${address.slice(0, maxLength)}...`
}

/**
 * Format currency amount
 */
export const formatCurrency = (amount: number | null, decimals: number = 2): string => {
    if (amount === null) return '0.00'
    return amount.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    })
}

/**
 * Origins allowed to postMessage from Bridge/Persona verification iframes.
 */
export const isAllowedBridgePersonaMessageOrigin = (origin: string): boolean => {
    if (origin === window.location.origin) {
        return true
    }

    const allowedHosts = [
        'bridge.withpersona.com',
        'withpersona.com',
        'bridge.xyz',
        'sandbox.bridge.xyz',
    ]

    return allowedHosts.some((host) => origin.includes(host))
}

/**
 * Persona/Bridge widget signals the user finished the flow (e.g. clicked Done).
 */
export const isBridgePersonaVerificationCompleteMessage = (data: unknown): boolean => {
    if (!data || typeof data !== 'object') {
        return false
    }

    const payload = data as Record<string, unknown>

    if (payload.action === 'close') {
        return true
    }

    const type = typeof payload.type === 'string' ? payload.type : ''
    if (
        type === 'persona:inquiry:complete' ||
        type === 'persona:complete' ||
        type === 'persona:dialog:complete' ||
        type === 'persona:inquiry:exit'
    ) {
        return true
    }

    if (payload.name === 'complete' || payload.event === 'complete') {
        return true
    }

    return false
}

/**
 * Convert Bridge/Persona verify URL to embeddable widget URL (Bridge docs: /verify → /widget + iframe-origin).
 * Strip redirect params so "Next" / completion stays in the modal (postMessage) instead of opening a new window.
 */
export const convertBridgeVerifyLinkToWidgetUrl = (linkUrl: string): string => {
    try {
        const url = new URL(linkUrl)
        if (url.pathname.includes('/verify')) {
            url.pathname = url.pathname.replace('/verify', '/widget')
        }
        // Hosted redirect breaks out of the iframe on final steps.
        url.searchParams.delete('redirect-uri')
        url.searchParams.delete('redirect_uri')
        url.searchParams.set('iframe-origin', window.location.origin)
        return url.toString()
    } catch {
        return linkUrl
    }
}

/**
 * Prefer stored widget URL; otherwise derive from verify/link URL.
 */
export const resolveBridgeVerificationWidgetUrl = (
    widgetUrl: string | null | undefined,
    linkUrl: string | null | undefined,
): string | null => {
    if (widgetUrl) {
        return convertBridgeVerifyLinkToWidgetUrl(widgetUrl)
    }
    if (linkUrl) {
        return convertBridgeVerifyLinkToWidgetUrl(linkUrl)
    }
    return null
}

/**
 * Format date for display
 */
export const formatDate = (dateString: string): string => {
    try {
        const date = new Date(dateString)
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        })
    } catch (e) {
        return dateString
    }
}

/** Prefer Bridge-computed display_label, then message, then legacy donor_name patterns. */
export function getActivityDisplayLabel(activity: Activity): string {
    const displayLabel = activity.display_label?.trim()
    if (displayLabel) {
        return displayLabel
    }

    const message = activity.message?.trim()
    if (message) {
        return message
    }

    const name = activity.donor_name?.trim() || 'Unknown'

    switch (activity.type) {
        case 'believe_points_wallet':
            return 'Believe Points to wallet'
        case 'transfer_sent':
            return `Sent to ${name}`
        case 'withdrawal':
            return activity.payment_method_label
                ? `${activity.payment_method_label} to ${name}`
                : `Withdrawal to ${name}`
        case 'transfer_received':
            return `Received from ${name}`
        case 'deposit':
            if (activity.payment_method_label) {
                return `${activity.payment_method_label} deposit${name && !['Bank deposit', 'Deposit'].includes(name) ? ` · ${name}` : ''}`
            }
            return `Deposit · ${name}`
        case 'card_spend':
            return `Card · ${name}`
        case 'donation':
            return activity.is_outgoing ? `Donation to ${name}` : `Donation from ${name}`
        default:
            return name
    }
}

export type ActivityIconKind = 'outgoing' | 'incoming' | 'deposit' | 'incoming-default' | 'believe-points'

/** Shared icon + amount colors for recent activity and full activity list. */
export function getActivityVisualMeta(activity: Activity): {
    isOutgoing: boolean
    isTransferSent: boolean
    isTransferReceived: boolean
    isWithdrawal: boolean
    isDeposit: boolean
    isDonation: boolean
    isCardSpend: boolean
    iconKind: ActivityIconKind
    iconContainerClass: string
    iconClass: string
    amountClass: string
} {
    const isTransferSent = activity.type === 'transfer_sent'
    const isTransferReceived = activity.type === 'transfer_received'
    const isBelievePointsWallet = activity.type === 'believe_points_wallet'
    const isWithdrawal = activity.type === 'withdrawal'
    const isDonation = activity.type === 'donation'
    const isDeposit = activity.type === 'deposit'
    const isCardSpend = activity.type === 'card_spend'
    const isDonationOutgoing = isDonation && activity.is_outgoing === true
    const isOutgoing = isTransferSent || isWithdrawal || isDonationOutgoing || isCardSpend

    if (isOutgoing) {
        return {
            isOutgoing: true,
            isTransferSent,
            isTransferReceived,
            isWithdrawal,
            isDeposit,
            isDonation,
            isCardSpend,
            iconKind: 'outgoing',
            iconContainerClass: 'bg-red-500/10',
            iconClass: 'text-red-500',
            amountClass: 'text-red-600 dark:text-red-400',
        }
    }

    if (isBelievePointsWallet) {
        return {
            isOutgoing: false,
            isTransferSent,
            isTransferReceived,
            isWithdrawal,
            isDeposit,
            isDonation,
            isCardSpend,
            iconKind: 'believe-points',
            iconContainerClass: 'bg-purple-500/10 dark:bg-purple-950/30',
            iconClass: 'text-purple-600 dark:text-purple-400',
            amountClass: 'text-green-600 dark:text-green-400',
        }
    }

    if (isTransferReceived) {
        return {
            isOutgoing: false,
            isTransferSent,
            isTransferReceived,
            isWithdrawal,
            isDeposit,
            isDonation,
            isCardSpend,
            iconKind: 'incoming',
            iconContainerClass: 'bg-blue-500/10',
            iconClass: 'text-blue-500',
            amountClass: 'text-green-600 dark:text-green-400',
        }
    }

    if (isDeposit) {
        return {
            isOutgoing: false,
            isTransferSent,
            isTransferReceived,
            isWithdrawal,
            isDeposit,
            isDonation,
            isCardSpend,
            iconKind: 'deposit',
            iconContainerClass: 'bg-emerald-500/10',
            iconClass: 'text-emerald-500',
            amountClass: 'text-green-600 dark:text-green-400',
        }
    }

    return {
        isOutgoing: false,
        isTransferSent,
        isTransferReceived,
        isWithdrawal,
        isDeposit,
        isDonation,
        isCardSpend,
        iconKind: 'incoming-default',
        iconContainerClass: 'bg-green-500/10',
        iconClass: 'text-green-500',
        amountClass: 'text-green-600 dark:text-green-400',
    }
}


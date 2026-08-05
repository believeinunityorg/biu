import { usePage } from '@inertiajs/react'
import { useEffect, useId, useRef, useState } from 'react'
import InputError from '@/components/input-error'
import { cn } from '@/lib/utils'
import type { SharedData } from '@/types'

declare global {
    interface Window {
        turnstile?: {
            render: (
                element: HTMLElement,
                options: {
                    sitekey: string
                    callback?: (token: string) => void
                    'expired-callback'?: () => void
                    'error-callback'?: () => void
                    theme?: 'light' | 'dark' | 'auto'
                    size?: 'normal' | 'flexible' | 'compact'
                }
            ) => string
            reset: (widgetId?: string) => void
            remove: (widgetId?: string) => void
        }
    }
}

type TurnstileTheme = 'light' | 'dark' | 'auto'

type TurnstileFieldProps = {
    value?: string
    onToken: (token: string) => void
    onExpire?: () => void
    error?: string
    className?: string
    /** Override widget theme. Default `auto` follows the app light/dark class. */
    theme?: TurnstileTheme
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
let scriptLoadPromise: Promise<void> | null = null

function loadTurnstileScript(): Promise<void> {
    if (typeof window === 'undefined') {
        return Promise.resolve()
    }
    if (window.turnstile) {
        return Promise.resolve()
    }
    if (scriptLoadPromise) {
        return scriptLoadPromise
    }

    scriptLoadPromise = new Promise((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(`script[src^="${SCRIPT_SRC}"]`)
        if (existing) {
            existing.addEventListener('load', () => resolve())
            existing.addEventListener('error', () => reject(new Error('Turnstile script failed to load')))
            if (window.turnstile) {
                resolve()
            }
            return
        }

        const script = document.createElement('script')
        script.src = SCRIPT_SRC
        script.async = true
        script.defer = true
        script.onload = () => resolve()
        script.onerror = () => reject(new Error('Turnstile script failed to load'))
        document.head.appendChild(script)
    })

    return scriptLoadPromise
}

function readDocumentTheme(): 'light' | 'dark' {
    if (typeof document === 'undefined') {
        return 'light'
    }

    return document.documentElement.classList.contains('dark') ? 'dark' : 'light'
}

/** Resolve Turnstile theme against the app's current appearance. */
export function useResolvedTurnstileTheme(theme: TurnstileTheme = 'auto'): 'light' | 'dark' {
    const [resolved, setResolved] = useState<'light' | 'dark'>(() => {
        if (theme === 'light' || theme === 'dark') {
            return theme
        }
        return readDocumentTheme()
    })

    useEffect(() => {
        if (theme === 'light' || theme === 'dark') {
            setResolved(theme)
            return
        }

        const sync = () => setResolved(readDocumentTheme())
        sync()

        const observer = new MutationObserver(sync)
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        })

        return () => observer.disconnect()
    }, [theme])

    return resolved
}

/**
 * Cloudflare Turnstile widget for Inertia forms.
 * Posts the token as `cf_turnstile_response` via the parent form's onToken setter.
 * Theme follows app light/dark mode and remounts when appearance toggles.
 */
export default function TurnstileField({
    onToken,
    onExpire,
    error,
    className,
    theme = 'auto',
}: TurnstileFieldProps) {
    const { turnstile } = usePage<SharedData>().props
    const containerRef = useRef<HTMLDivElement | null>(null)
    const widgetIdRef = useRef<string | null>(null)
    const onTokenRef = useRef(onToken)
    const onExpireRef = useRef(onExpire)
    const reactId = useId()
    const resolvedTheme = useResolvedTurnstileTheme(theme)

    onTokenRef.current = onToken
    onExpireRef.current = onExpire

    const enabled = Boolean(turnstile?.enabled && turnstile?.siteKey)

    useEffect(() => {
        if (!enabled || !turnstile?.siteKey || !containerRef.current) {
            return
        }

        let cancelled = false

        loadTurnstileScript()
            .then(() => {
                if (cancelled || !containerRef.current || !window.turnstile) {
                    return
                }

                if (widgetIdRef.current) {
                    try {
                        window.turnstile.remove(widgetIdRef.current)
                    } catch {
                        // ignore
                    }
                    widgetIdRef.current = null
                }

                // Clear previous token while remounting (e.g. theme switch).
                onTokenRef.current('')

                containerRef.current.innerHTML = ''
                widgetIdRef.current = window.turnstile.render(containerRef.current, {
                    sitekey: turnstile.siteKey,
                    theme: resolvedTheme,
                    size: 'flexible',
                    callback: (token: string) => onTokenRef.current(token),
                    'expired-callback': () => {
                        onTokenRef.current('')
                        onExpireRef.current?.()
                    },
                    'error-callback': () => {
                        onTokenRef.current('')
                    },
                })
            })
            .catch(() => {
                onTokenRef.current('')
            })

        return () => {
            cancelled = true
            if (widgetIdRef.current && window.turnstile) {
                try {
                    window.turnstile.remove(widgetIdRef.current)
                } catch {
                    // ignore
                }
                widgetIdRef.current = null
            }
        }
    }, [enabled, turnstile?.siteKey, resolvedTheme, reactId])

    if (!enabled) {
        return null
    }

    return (
        <div className={cn('w-full', className)}>
            <div ref={containerRef} className="w-full min-w-0" />
            <InputError message={error} className="mt-2" />
        </div>
    )
}

export function useTurnstileEnabled(): boolean {
    const { turnstile } = usePage<SharedData>().props
    return Boolean(turnstile?.enabled && turnstile?.siteKey)
}

/** When Turnstile is configured, submit stays blocked until a token is present. */
export function useTurnstileGate(token: string | null | undefined): {
    turnstileEnabled: boolean
    turnstileReady: boolean
    turnstileBlocksSubmit: boolean
} {
    const turnstileEnabled = useTurnstileEnabled()
    const turnstileReady = !turnstileEnabled || Boolean(token && String(token).trim() !== '')

    return {
        turnstileEnabled,
        turnstileReady,
        turnstileBlocksSubmit: turnstileEnabled && !turnstileReady,
    }
}

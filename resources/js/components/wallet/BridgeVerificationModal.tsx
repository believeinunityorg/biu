"use client"

import { useCallback, useEffect, useRef } from "react"
import { AnimatePresence, motion } from "framer-motion"
import { ExternalLink, Loader2, Shield, X } from "lucide-react"
import { createPortal } from "react-dom"
import { Button } from "@/components/ui/button"
import {
    isAllowedBridgePersonaMessageOrigin,
    isBridgePersonaVerificationCompleteMessage,
} from "./utils"

export interface BridgeVerificationModalProps {
    isOpen: boolean
    onClose: () => void
    onVerificationComplete?: () => void
    widgetUrl: string | null
    verificationType: "kyc" | "kyb"
    isLoading?: boolean
    fallbackLinkUrl?: string | null
}

/**
 * Keep Persona inside this modal.
 * Do not allow top-window navigation — that makes the last "Next" open a new window/tab.
 * Completion is via postMessage (iframe-origin), not redirect_uri.
 */
const VERIFICATION_IFRAME_SANDBOX =
    "allow-same-origin allow-scripts allow-forms allow-popups allow-modals"

function isSameOriginVerificationCallback(href: string): boolean {
    try {
        const url = new URL(href)
        if (url.origin !== window.location.origin) {
            return false
        }

        return (
            url.pathname.includes("/wallet/kyc-callback") ||
            url.pathname.includes("/wallet/kyb-callback")
        )
    } catch {
        return false
    }
}

export function BridgeVerificationModal({
    isOpen,
    onClose,
    onVerificationComplete,
    widgetUrl,
    verificationType,
    isLoading = false,
    fallbackLinkUrl = null,
}: BridgeVerificationModalProps) {
    const iframeRef = useRef<HTMLIFrameElement | null>(null)
    const completedRef = useRef(false)

    const finishVerification = useCallback(() => {
        if (completedRef.current) {
            return
        }
        completedRef.current = true
        if (onVerificationComplete) {
            onVerificationComplete()
        } else {
            onClose()
        }
    }, [onClose, onVerificationComplete])

    useEffect(() => {
        if (!isOpen) {
            completedRef.current = false
            return
        }

        const handleMessage = (event: MessageEvent) => {
            if (!isAllowedBridgePersonaMessageOrigin(event.origin)) {
                return
            }

            if (isBridgePersonaVerificationCompleteMessage(event.data)) {
                finishVerification()
            }
        }

        window.addEventListener("message", handleMessage)

        return () => {
            window.removeEventListener("message", handleMessage)
        }
    }, [isOpen, finishVerification])

    const handleIframeLoad = useCallback(() => {
        const frame = iframeRef.current
        if (!frame) {
            return
        }

        try {
            const href = frame.contentWindow?.location?.href
            if (href && isSameOriginVerificationCallback(href)) {
                finishVerification()
            }
        } catch {
            // Cross-origin Persona pages — expected until completion redirects same-origin.
        }
    }, [finishVerification])

    if (typeof document === "undefined") {
        return null
    }

    const title = verificationType === "kyb" ? "Business verification" : "Identity verification"
    const subtitle =
        verificationType === "kyb"
            ? "Complete your business verification securely with Believe Cash."
            : "Verify your identity and card eligibility with Believe Cash. One flow covers your wallet, date of birth, address, and ID."

    return createPortal(
        <AnimatePresence>
            {isOpen && (
                <motion.div
                    key="bridge-verification-modal"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={{ opacity: 0 }}
                    className="fixed inset-0 z-[100] flex items-end justify-center p-0 sm:items-center sm:p-4"
                >
                    <motion.button
                        type="button"
                        aria-label="Close verification"
                        className="absolute inset-0 bg-black/60 backdrop-blur-[2px]"
                        onClick={onClose}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                    />

                    <motion.div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="bridge-verification-title"
                        initial={{ opacity: 0, y: 24, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 24, scale: 0.98 }}
                        transition={{ duration: 0.22, ease: "easeOut" }}
                        className="relative flex h-[92dvh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl border border-border bg-card shadow-2xl sm:h-[min(88dvh,820px)] sm:rounded-2xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="bg-gradient-to-r from-purple-600 to-blue-600 px-4 py-4 text-white sm:px-5">
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-start gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/20">
                                        <Shield className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0">
                                        <h2 id="bridge-verification-title" className="text-lg font-semibold leading-tight">
                                            {title}
                                        </h2>
                                        <p className="mt-1 text-xs text-white/85 sm:text-sm">{subtitle}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={onClose}
                                    className="rounded-lg p-2 text-white/90 transition-colors hover:bg-white/15"
                                    aria-label="Close"
                                >
                                    <X className="h-5 w-5" />
                                </button>
                            </div>
                        </div>

                        <div className="relative min-h-0 flex-1 bg-muted/20">
                            {isLoading && !widgetUrl ? (
                                <div className="flex h-full min-h-[420px] flex-col items-center justify-center gap-3 px-6 text-center">
                                    <Loader2 className="h-8 w-8 animate-spin text-purple-600" />
                                    <p className="text-sm font-medium text-foreground">Preparing secure verification…</p>
                                    <p className="text-xs text-muted-foreground">This usually takes a few seconds.</p>
                                </div>
                            ) : widgetUrl ? (
                                <iframe
                                    ref={iframeRef}
                                    key={widgetUrl}
                                    src={widgetUrl}
                                    allow="camera; microphone; fullscreen"
                                    className="absolute inset-0 h-full w-full border-0 bg-white"
                                    title={title}
                                    referrerPolicy="origin"
                                    sandbox={VERIFICATION_IFRAME_SANDBOX}
                                    onLoad={handleIframeLoad}
                                />
                            ) : (
                                <div className="flex h-full min-h-[320px] flex-col items-center justify-center gap-4 px-6 text-center">
                                    <p className="text-sm text-muted-foreground">
                                        We couldn&apos;t load the verification widget. Try again or open verification in a new tab.
                                    </p>
                                    {fallbackLinkUrl && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            className="bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                                            onClick={() => window.open(fallbackLinkUrl, "_blank", "noopener,noreferrer")}
                                        >
                                            <ExternalLink className="mr-2 h-4 w-4" />
                                            Open in new tab
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="flex items-center justify-between gap-2 border-t border-border bg-card px-4 py-3 sm:px-5">
                            <p className="text-[11px] text-muted-foreground sm:text-xs">
                                Secure verification powered by Believe Cash
                            </p>
                            <Button type="button" variant="outline" size="sm" onClick={onClose}>
                                Done for now
                            </Button>
                        </div>
                    </motion.div>
                </motion.div>
            )}
        </AnimatePresence>,
        document.body,
    )
}

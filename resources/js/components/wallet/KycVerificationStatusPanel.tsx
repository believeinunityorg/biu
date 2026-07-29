"use client"

import { motion } from "framer-motion"
import { Clock, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
    formatBridgeVerificationStatusLabel,
    isBridgeVerificationAwaitingReview,
} from "@/lib/bridge-verification"

interface KycVerificationStatusPanelProps {
    kycStatus: string
    compact?: boolean
    onRefresh?: () => void
    isRefreshing?: boolean
    title?: string
    description?: string
}

export function KycVerificationStatusPanel({
    kycStatus,
    compact = false,
    onRefresh,
    isRefreshing = false,
    title = "KYC Verification Pending",
    description = "Your identity verification has been submitted and is being reviewed for Believe Cash.",
}: KycVerificationStatusPanelProps) {
    const pending = isBridgeVerificationAwaitingReview(kycStatus)
    const statusLabel = formatBridgeVerificationStatusLabel(kycStatus)

    if (!pending && kycStatus !== "rejected") {
        return null
    }

    return (
        <div
            className={`flex flex-col items-center justify-center space-y-4 w-full ${
                compact ? "py-4 px-2" : "py-8 px-4"
            }`}
        >
            <motion.div
                initial={{ scale: 0.8, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                transition={{ duration: 0.3 }}
                className="w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center"
            >
                <Clock className="h-8 w-8 text-blue-600 dark:text-blue-400" />
            </motion.div>

            <div className="text-center space-y-2">
                <h3 className="text-lg font-bold text-foreground">{title}</h3>
                <div className="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-800 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-200">
                    Status: {statusLabel}
                </div>
                <p className="text-sm text-muted-foreground max-w-sm">
                    {description}
                </p>
                <p className="text-xs text-muted-foreground mt-1">
                    This usually takes a few minutes. You can close the wallet and check back later — we&apos;ll
                    update your status automatically.
                </p>
            </div>

            {onRefresh && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={onRefresh}
                    disabled={isRefreshing}
                    className="text-xs"
                >
                    {isRefreshing ? (
                        <>
                            <RefreshCw className="mr-2 h-3 w-3 animate-spin" />
                            Checking status…
                        </>
                    ) : (
                        <>
                            <RefreshCw className="mr-2 h-3 w-3" />
                            Refresh status
                        </>
                    )}
                </Button>
            )}
        </div>
    )
}

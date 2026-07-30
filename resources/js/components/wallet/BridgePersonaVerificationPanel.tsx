"use client"

import { RefreshCw, Shield } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
    isBridgeKycPending,
    isBridgeKybPending,
} from "@/lib/bridge-verification"
import { KycVerificationStatusPanel } from "./KycVerificationStatusPanel"

interface BridgePersonaVerificationPanelProps {
    verificationType: "kyc" | "kyb" | null
    kycStatus: string
    kybStatus: string
    isLoading?: boolean
    isLoadingVerificationWidget?: boolean
    onOpenVerification: () => void
    onRefresh: () => void
}

/** KYC/KYB via Bridge Persona modal only — no custom forms or admin approval. */
export function BridgePersonaVerificationPanel({
    verificationType,
    kycStatus,
    kybStatus,
    isLoading = false,
    isLoadingVerificationWidget = false,
    onOpenVerification,
    onRefresh,
}: BridgePersonaVerificationPanelProps) {
    if (verificationType === "kyc" && isBridgeKycPending(kycStatus)) {
        return (
            <KycVerificationStatusPanel
                kycStatus={kycStatus}
                compact
                onRefresh={onRefresh}
                isRefreshing={isLoading}
            />
        )
    }

    if (verificationType === "kyb" && isBridgeKybPending(kybStatus)) {
        return (
            <KycVerificationStatusPanel
                kycStatus={kybStatus}
                compact
                title="KYB Verification Pending"
                description="Your business verification has been submitted and is being reviewed for Believe Cash."
                onRefresh={onRefresh}
                isRefreshing={isLoading}
            />
        )
    }

    return (
        <div className="space-y-3">
            <div className="rounded-lg border border-border bg-muted/30 p-3 text-center">
                <p className="text-xs text-muted-foreground">
                    Complete verification in the secure Believe Cash window (Persona). Have your ID ready and allow
                    camera access when prompted.
                </p>
            </div>
            <Button
                size="sm"
                type="button"
                onClick={onOpenVerification}
                className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white text-xs"
                disabled={isLoading || isLoadingVerificationWidget}
            >
                {isLoadingVerificationWidget ? (
                    <>
                        <RefreshCw className="h-3 w-3 mr-2 animate-spin" />
                        Opening verification…
                    </>
                ) : (
                    <>
                        <Shield className="h-3 w-3 mr-2" />
                        Start {verificationType === "kyb" ? "KYB" : "KYC"} Verification
                    </>
                )}
            </Button>
        </div>
    )
}

"use client"

import { useEffect, useState } from "react"
import { createPortal } from "react-dom"
import { AnimatePresence, motion } from "framer-motion"
import {
  AlertCircle,
  ArrowRight,
  Coins,
  Loader2,
  Wallet,
  X,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { cn } from "@/lib/utils"

interface WalletTransferSettings {
  enabled: boolean
  eligible?: boolean
  eligibility_message?: string | null
  min_amount: number
  max_amount: number
  sandbox_unavailable: boolean
}

type BpMoveToWalletPopupProps = {
  isOpen: boolean
  onClose: () => void
  balance: number
  amount: string
  onAmountChange: (value: string) => void
  walletTransfer?: WalletTransferSettings
  isSubmitting: boolean
  onSubmit: () => void
  formatCurrency: (value: number | string) => string
  formatPoints: (value: number | string) => string
}

export function BpMoveToWalletPopup({
  isOpen,
  onClose,
  balance,
  amount,
  onAmountChange,
  walletTransfer,
  isSubmitting,
  onSubmit,
  formatCurrency,
  formatPoints,
}: BpMoveToWalletPopupProps) {
  const [mounted, setMounted] = useState(false)
  const [confirmStep, setConfirmStep] = useState(false)

  useEffect(() => {
    setMounted(true)
  }, [])

  useEffect(() => {
    if (!isOpen) {
      setConfirmStep(false)
    }
  }, [isOpen])

  const min = walletTransfer?.min_amount ?? 1
  const max = walletTransfer?.max_amount ?? 10000
  const parsedAmount = parseFloat(amount)
  const validAmount =
    amount !== "" &&
    !Number.isNaN(parsedAmount) &&
    parsedAmount >= min &&
    parsedAmount <= max &&
    parsedAmount <= balance + 0.0001

  const handleMax = () => {
    const capped = Math.min(balance, max)
    if (capped > 0) {
      onAmountChange(capped.toFixed(2))
    }
  }

  const handlePrimaryAction = () => {
    if (walletTransfer?.sandbox_unavailable || !walletTransfer?.enabled) {
      return
    }
    if (!confirmStep) {
      if (!validAmount) return
      setConfirmStep(true)
      return
    }
    onSubmit()
  }

  if (!mounted) {
    return null
  }

  return createPortal(
    <AnimatePresence>
      {isOpen && (
        <>
          <motion.div
            key="bp-wallet-backdrop"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="fixed inset-0 z-[80] bg-black/40"
          />

          <div className="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:p-4 pointer-events-none">
            <motion.div
              key="bp-wallet-popup"
              initial={{ opacity: 0, y: 24, scale: 0.98 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 24, scale: 0.98 }}
              transition={{ duration: 0.2 }}
              className="pointer-events-auto flex h-[min(92dvh,640px)] w-full max-w-md flex-col overflow-hidden border-border bg-card shadow-2xl sm:h-auto sm:max-h-[85dvh] sm:rounded-xl sm:border"
            >
              <div className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                <div className="flex items-center justify-between border-b border-white/10 p-3">
                  <div className="flex items-center gap-2">
                    {confirmStep && (
                      <button
                        type="button"
                        onClick={() => setConfirmStep(false)}
                        className="mr-0.5 rounded-lg p-1 transition-colors hover:bg-white/20"
                        aria-label="Back"
                      >
                        <ArrowRight className="h-4 w-4 rotate-180" />
                      </button>
                    )}
                    <div className="rounded-lg bg-white/20 p-1.5">
                      <Wallet className="h-4 w-4" />
                    </div>
                    <span className="text-sm font-semibold">
                      {confirmStep ? "Confirm transfer" : "Move to Wallet"}
                    </span>
                  </div>
                  <button
                    type="button"
                    onClick={onClose}
                    disabled={isSubmitting}
                    className="rounded-lg p-1.5 transition-colors hover:bg-white/20 disabled:opacity-50"
                    aria-label="Close"
                  >
                    <X className="h-4 w-4" />
                  </button>
                </div>
              </div>

              <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <div className="space-y-4 p-4">
                  {walletTransfer?.sandbox_unavailable ? (
                    <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/25">
                      <AlertCircle className="h-4 w-4 text-amber-600" />
                      <AlertDescription>
                        Wallet funding from Believe Points is only available in production Bridge mode.
                      </AlertDescription>
                    </Alert>
                  ) : !walletTransfer?.eligible ? (
                    <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/25">
                      <AlertCircle className="h-4 w-4 text-amber-600" />
                      <AlertDescription>
                        {walletTransfer?.eligibility_message ??
                          "Moving Believe Points to your wallet is available for Prime Supporters and organization accounts."}
                      </AlertDescription>
                    </Alert>
                  ) : !walletTransfer?.enabled ? (
                    <Alert>
                      <AlertCircle className="h-4 w-4" />
                      <AlertDescription>
                        Moving Believe Points to your wallet is not enabled yet. Contact support if you need help.
                      </AlertDescription>
                    </Alert>
                  ) : confirmStep ? (
                    <div className="space-y-4">
                      <div className="relative overflow-hidden rounded-xl border border-border bg-card">
                        <div className="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-purple-600 to-blue-600" />
                        <div className="space-y-3 p-4">
                          <p className="text-xs font-medium text-muted-foreground">You are moving</p>
                          <p className="text-3xl font-bold tabular-nums bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent">
                            {formatCurrency(parsedAmount)}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {formatPoints(parsedAmount)} BP will be deducted from your purchased balance and funded to
                            your verified Bridge wallet.
                          </p>
                        </div>
                      </div>
                      <div className="rounded-xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-100">
                        This cannot be reversed once Bridge processes the transfer.
                      </div>
                    </div>
                  ) : (
                    <>
                      <div className="relative overflow-hidden rounded-xl border border-border bg-card">
                        <div className="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-purple-600 to-blue-600" />
                        <div className="flex items-center gap-3 p-4">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600/15 to-blue-600/10">
                            <Coins className="h-4 w-4 text-purple-600 dark:text-purple-400" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                              Available to move
                            </p>
                            <p className="text-xl font-bold tabular-nums text-foreground">
                              {formatPoints(balance)} BP
                            </p>
                            <p className="text-xs text-muted-foreground">Purchased points only · gifted BP excluded</p>
                          </div>
                        </div>
                      </div>

                      <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                          <Label htmlFor="bp_wallet_amount">Amount (USD)</Label>
                          <button
                            type="button"
                            onClick={handleMax}
                            disabled={balance < min}
                            className="text-xs font-semibold text-purple-600 hover:underline disabled:opacity-50 dark:text-purple-400"
                          >
                            Max
                          </button>
                        </div>
                        <Input
                          id="bp_wallet_amount"
                          type="number"
                          min={min}
                          max={Math.min(max, balance)}
                          step="0.01"
                          placeholder={`${min.toFixed(2)} – ${max.toFixed(2)}`}
                          value={amount}
                          onChange={(e) => onAmountChange(e.target.value)}
                          className={cn(
                            "h-12 border-2 text-lg font-semibold tabular-nums focus-visible:border-purple-500 focus-visible:ring-purple-500/20",
                            amount !== "" && !validAmount && "border-destructive focus-visible:border-destructive",
                          )}
                          disabled={isSubmitting || balance < min}
                        />
                        <p className="text-xs text-muted-foreground">
                          Between {formatCurrency(min)} and {formatCurrency(Math.min(max, balance))}
                        </p>
                      </div>
                    </>
                  )}
                </div>
              </div>

              {walletTransfer?.enabled && !walletTransfer.sandbox_unavailable && (
                <div className="border-t border-border bg-muted/20 p-4">
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      className="flex-1"
                      onClick={confirmStep ? () => setConfirmStep(false) : onClose}
                      disabled={isSubmitting}
                    >
                      {confirmStep ? "Back" : "Cancel"}
                    </Button>
                    <Button
                      type="button"
                      className="flex-1 bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                      disabled={isSubmitting || (!confirmStep && !validAmount) || balance < min}
                      onClick={handlePrimaryAction}
                    >
                      {isSubmitting ? (
                        <>
                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                          Processing…
                        </>
                      ) : confirmStep ? (
                        "Confirm"
                      ) : (
                        <>
                          Continue
                          <ArrowRight className="ml-2 h-4 w-4" />
                        </>
                      )}
                    </Button>
                  </div>
                </div>
              )}
            </motion.div>
          </div>
        </>
      )}
    </AnimatePresence>,
    document.body,
  )
}

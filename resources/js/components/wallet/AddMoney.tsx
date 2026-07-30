import { motion } from 'framer-motion'
import { RefreshCw, Copy, Building2, AlertCircle, Plus } from 'lucide-react'
import { Icon } from '@iconify/react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { DepositInstructions, PaymentMethod } from './types'
import { showSuccessToast } from '@/lib/toast'
import { CryptoDepositPanel } from './CryptoDepositPanel'

interface AddMoneyProps {
    isLoading: boolean
    depositInstructions: DepositInstructions | null
    selectedPaymentMethod: PaymentMethod
    onPaymentMethodChange: (method: PaymentMethod) => void
    isCreatingDepositAccount?: boolean
    onCreateDepositAccount?: () => void
    isSandbox?: boolean
    /** Bridge base (US ACH) blocked for region — prefer SEPA / crypto messaging */
    regionBlocksUsAch?: boolean
    sepaAvailable?: boolean
    usAchNotice?: string | null
}

export function AddMoney({
    isLoading,
    depositInstructions,
    selectedPaymentMethod,
    onPaymentMethodChange,
    isCreatingDepositAccount,
    onCreateDepositAccount,
    isSandbox = false,
    regionBlocksUsAch = false,
    sepaAvailable = false,
    usAchNotice = null,
}: AddMoneyProps) {
    const [depositMethod, setDepositMethod] = useState<'bank' | 'crypto'>(
        regionBlocksUsAch && !sepaAvailable ? 'crypto' : 'bank',
    )

    if (isLoading) {
        return (
            <div className="flex items-center justify-center py-8">
                <RefreshCw className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
        )
    }

    const hasAch =
        (depositInstructions?.payment_rails && depositInstructions.payment_rails.includes('ach_push')) ||
        depositInstructions?.payment_rail === 'ach_push'
    const hasWire =
        (depositInstructions?.payment_rails && depositInstructions.payment_rails.includes('wire')) ||
        depositInstructions?.payment_rail === 'wire'
    const hasSepa =
        (depositInstructions?.payment_rails && depositInstructions.payment_rails.includes('sepa')) ||
        depositInstructions?.payment_rail === 'sepa' ||
        Boolean(depositInstructions?.iban) ||
        String(depositInstructions?.currency ?? '').toLowerCase() === 'eur'
    const isEurSepa = hasSepa && !hasAch && !hasWire
    const hasMultiple =
        (hasAch && hasWire) ||
        (depositInstructions?.payment_rails && depositInstructions.payment_rails.length > 1)
    const bankTabLabel = regionBlocksUsAch || isEurSepa || sepaAvailable
        ? (sepaAvailable || hasSepa || isEurSepa ? 'Bank (SEPA)' : 'Bank')
        : 'Bank (ACH / Wire)'
    const bankEmptyCopy = regionBlocksUsAch || sepaAvailable
        ? (sepaAvailable
            ? 'Create a EUR Virtual Account (IBAN) for SEPA deposits. Believe Cash converts EUR to USDC automatically.'
            : 'US ACH/wire is not available in your region. Use crypto deposit instead.')
        : 'Create a Believe Cash deposit bank account to receive ACH and wire transfers.'
    const depositTitle = isEurSepa
        ? 'SEPA Deposit Details (EUR IBAN)'
        : selectedPaymentMethod === 'ach'
            ? 'ACH Deposit Details'
            : 'Wire Transfer Details'

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: -20 }}
            transition={{ duration: 0.3 }}
            className="p-4 space-y-4"
        >
            {(regionBlocksUsAch || usAchNotice) && (
                <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 flex gap-2 text-left">
                    <AlertCircle className="h-3.5 w-3.5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                    <p className="text-xs text-amber-900 dark:text-amber-100 leading-relaxed">
                        {usAchNotice
                            || (sepaAvailable
                                ? 'US bank transfers (ACH/wire) are not available in your region. SEPA and crypto still work with Believe Cash.'
                                : 'US bank transfers (ACH/wire) are not available in your region. Prefer crypto deposit with Believe Cash.')}
                    </p>
                </div>
            )}

            <div className="relative flex gap-1.5 p-1 bg-muted rounded-lg">
                <button
                    type="button"
                    onClick={() => setDepositMethod('bank')}
                    className={`relative flex-1 px-3 py-1.5 text-xs font-medium rounded-md z-10 ${
                        depositMethod === 'bank'
                            ? 'bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    }`}
                >
                    {bankTabLabel}
                </button>
                <button
                    type="button"
                    onClick={() => setDepositMethod('crypto')}
                    className={`relative flex-1 px-3 py-1.5 text-xs font-medium rounded-md z-10 flex items-center justify-center gap-1.5 ${
                        depositMethod === 'crypto'
                            ? 'bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    }`}
                >
                    <Icon icon="cryptocurrency-color:usdc" width={14} height={14} className="rounded-full" />
                    Crypto
                </button>
            </div>

            {depositMethod === 'crypto' ? (
                <CryptoDepositPanel isSandbox={isSandbox} variant="deposit" />
            ) : !depositInstructions ? (
                <div className="text-center py-8 border border-dashed border-border rounded-lg space-y-3">
                    <Building2 className="h-8 w-8 text-muted-foreground mx-auto" />
                    <div className="space-y-1 px-4">
                        <p className="text-sm font-medium">No deposit account yet</p>
                        <p className="text-xs text-muted-foreground">
                            {bankEmptyCopy}
                        </p>
                    </div>
                    {onCreateDepositAccount && !(regionBlocksUsAch && !sepaAvailable) && (
                        <Button
                            onClick={onCreateDepositAccount}
                            disabled={isCreatingDepositAccount}
                            size="sm"
                            className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                        >
                            {isCreatingDepositAccount ? (
                                <>
                                    <RefreshCw className="h-3 w-3 mr-1 animate-spin" />
                                    Creating…
                                </>
                            ) : (
                                <>
                                    <Plus className="h-3 w-3 mr-1" />
                                    Create Deposit Account
                                </>
                            )}
                        </Button>
                    )}
                    {regionBlocksUsAch && !sepaAvailable && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setDepositMethod('crypto')}
                        >
                            Switch to crypto deposit
                        </Button>
                    )}
                </div>
            ) : (
                <div className="space-y-4">
                    {hasMultiple && (
                        <div className="flex justify-center">
                            <div className="relative inline-flex gap-1 p-0.5 bg-muted rounded-md">
                                <motion.div
                                    className="absolute inset-y-0.5 rounded-sm bg-gradient-to-r from-purple-600 to-blue-600 shadow-sm"
                                    initial={false}
                                    animate={{
                                        x: selectedPaymentMethod === 'ach' ? 0 : '100%',
                                    }}
                                    transition={{
                                        type: 'spring',
                                        stiffness: 300,
                                        damping: 30,
                                    }}
                                    style={{
                                        width: 'calc(50% - 0.125rem)',
                                    }}
                                />

                                {hasAch && (
                                    <motion.button
                                        type="button"
                                        onClick={() => onPaymentMethodChange('ach')}
                                        className={`relative min-w-[4.5rem] px-3 py-1 text-[11px] font-semibold uppercase tracking-wide rounded-sm z-10 ${
                                            selectedPaymentMethod === 'ach'
                                                ? 'text-white'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                        whileTap={{ scale: 0.98 }}
                                    >
                                        ACH
                                    </motion.button>
                                )}
                                {hasWire && (
                                    <motion.button
                                        type="button"
                                        onClick={() => onPaymentMethodChange('wire')}
                                        className={`relative min-w-[4.5rem] px-3 py-1 text-[11px] font-semibold uppercase tracking-wide rounded-sm z-10 ${
                                            selectedPaymentMethod === 'wire'
                                                ? 'text-white'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                        whileTap={{ scale: 0.98 }}
                                    >
                                        Wire
                                    </motion.button>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="p-4 bg-gradient-to-br from-purple-600/10 via-blue-600/10 to-purple-600/10 dark:from-purple-900/30 dark:via-blue-900/30 dark:to-purple-900/30 rounded-xl border border-purple-200/50 dark:border-purple-800/50 backdrop-blur-sm">
                        <div className="flex items-center gap-2 mb-4 pb-3 border-b border-purple-200/30 dark:border-purple-700/30">
                            <div className="p-2 bg-gradient-to-r from-purple-600 to-blue-600 rounded-lg">
                                <Building2 className="h-4 w-4 text-white" />
                            </div>
                            <h3 className="text-base font-bold text-foreground">
                                {depositTitle}
                            </h3>
                        </div>

                        <div className="space-y-3.5">
                            {depositInstructions.bank_name && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Bank Name</p>
                                    <p className="text-sm font-semibold text-foreground">{depositInstructions.bank_name}</p>
                                </div>
                            )}

                            {depositInstructions.bank_address && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Bank Address</p>
                                    <p className="text-sm text-foreground break-words">{depositInstructions.bank_address}</p>
                                </div>
                            )}

                            {depositInstructions.iban && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">IBAN</p>
                                    <div className="flex items-center gap-2">
                                        <code className="flex-1 px-3 py-2 bg-background/50 dark:bg-background/30 border border-border rounded-lg font-mono text-sm font-semibold text-foreground break-all">
                                            {depositInstructions.iban}
                                        </code>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                navigator.clipboard.writeText(depositInstructions.iban || '')
                                                showSuccessToast('IBAN copied!')
                                            }}
                                            className="p-2 hover:bg-background/50 rounded-lg border border-border transition-colors"
                                            title="Copy IBAN"
                                        >
                                            <Copy className="h-4 w-4 text-muted-foreground" />
                                        </button>
                                    </div>
                                </div>
                            )}

                            {depositInstructions.bic && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">BIC / SWIFT</p>
                                    <div className="flex items-center gap-2">
                                        <code className="flex-1 px-3 py-2 bg-background/50 dark:bg-background/30 border border-border rounded-lg font-mono text-sm font-semibold text-foreground">
                                            {depositInstructions.bic}
                                        </code>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                navigator.clipboard.writeText(depositInstructions.bic || '')
                                                showSuccessToast('BIC copied!')
                                            }}
                                            className="p-2 hover:bg-background/50 rounded-lg border border-border transition-colors"
                                            title="Copy BIC"
                                        >
                                            <Copy className="h-4 w-4 text-muted-foreground" />
                                        </button>
                                    </div>
                                </div>
                            )}

                            {!depositInstructions.iban && depositInstructions.bank_routing_number && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Routing Number</p>
                                    <div className="flex items-center gap-2">
                                        <code className="flex-1 px-3 py-2 bg-background/50 dark:bg-background/30 border border-border rounded-lg font-mono text-sm font-semibold text-foreground">
                                            {depositInstructions.bank_routing_number}
                                        </code>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                navigator.clipboard.writeText(depositInstructions.bank_routing_number || '')
                                                showSuccessToast('Routing number copied!')
                                            }}
                                            className="p-2 hover:bg-background/50 rounded-lg border border-border transition-colors"
                                            title="Copy routing number"
                                        >
                                            <Copy className="h-4 w-4 text-muted-foreground" />
                                        </button>
                                    </div>
                                </div>
                            )}

                            {!depositInstructions.iban && depositInstructions.bank_account_number && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Account Number</p>
                                    <div className="flex items-center gap-2">
                                        <code className="flex-1 px-3 py-2 bg-background/50 dark:bg-background/30 border border-border rounded-lg font-mono text-sm font-semibold text-foreground">
                                            {depositInstructions.bank_account_number}
                                        </code>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                navigator.clipboard.writeText(depositInstructions.bank_account_number || '')
                                                showSuccessToast('Account number copied!')
                                            }}
                                            className="p-2 hover:bg-background/50 rounded-lg border border-border transition-colors"
                                            title="Copy account number"
                                        >
                                            <Copy className="h-4 w-4 text-muted-foreground" />
                                        </button>
                                    </div>
                                </div>
                            )}

                            {depositInstructions.bank_beneficiary_name && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                                        {isEurSepa ? 'Account Holder' : 'Beneficiary Name'}
                                    </p>
                                    <p className="text-sm font-semibold text-foreground break-words">{depositInstructions.bank_beneficiary_name}</p>
                                </div>
                            )}

                            {depositInstructions.bank_beneficiary_address && (
                                <div className="space-y-1">
                                    <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Beneficiary Address</p>
                                    <p className="text-sm text-foreground break-words">{depositInstructions.bank_beneficiary_address}</p>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="p-3.5 bg-blue-50/50 dark:bg-blue-900/20 border border-blue-200/50 dark:border-blue-800/50 rounded-lg">
                        <div className="flex items-start gap-2.5">
                            <AlertCircle className="h-4 w-4 text-blue-600 dark:text-blue-400 mt-0.5 flex-shrink-0" />
                            <div className="flex-1 min-w-0">
                                <p className="text-xs font-semibold text-blue-900 dark:text-blue-100 mb-1.5">
                                    How to Deposit via {selectedPaymentMethod === 'ach' ? 'ACH' : 'Wire Transfer'}
                                </p>
                                <p className="text-xs leading-relaxed text-blue-700 dark:text-blue-300">
                                    {selectedPaymentMethod === 'ach'
                                        ? 'Use the bank details above to make an ACH deposit. ACH transfers typically take 1-3 business days to process. Funds will be credited to your wallet once the transfer is processed.'
                                        : 'Use the bank details above to make a wire transfer. Wire transfers are typically processed same-day or within 1 business day. Funds will be credited to your wallet once the transfer is processed.'}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </motion.div>
    )
}

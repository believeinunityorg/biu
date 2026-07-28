import { motion } from 'framer-motion'
import {
    Plus,
    ArrowUpRight,
    ArrowDownLeft,
    LayoutGrid,
    Wallet,
    Copy,
    Check,
} from 'lucide-react'
import { ActionView } from './types'
import { formatAddress } from './utils'
import { BalanceCard } from './BalanceCard'

interface WalletScreenProps {
    walletBalance: number | null
    walletAddress: string | null
    isLoading: boolean
    copied: boolean
    isSandbox: boolean
    onRefresh: () => void
    onCopyAddress: () => void
    onActionViewChange: (view: ActionView) => void
    /** When Bridge base (US ACH) is region-blocked but SEPA/crypto still work */
    regionBlocksUsAch?: boolean
    sepaAvailable?: boolean
    usAchNotice?: string | null
}

interface QuickAction {
    id: ActionView
    label: string
    description: string
    icon: React.ReactNode
}

const quickActions: QuickAction[] = [
    {
        id: 'addMoney',
        label: 'Deposit',
        description: 'Add funds',
        icon: <Plus className="h-4 w-4" />,
    },
    {
        id: 'send',
        label: 'Send',
        description: 'Transfer out',
        icon: <ArrowUpRight className="h-4 w-4" />,
    },
    {
        id: 'receive',
        label: 'Receive',
        description: 'Get paid',
        icon: <ArrowDownLeft className="h-4 w-4" />,
    },
    {
        id: 'services_menu',
        label: 'More',
        description: 'Cards & gifts',
        icon: <LayoutGrid className="h-4 w-4" />,
    },
]

export function WalletScreen({
    walletBalance,
    walletAddress,
    isLoading,
    copied,
    isSandbox,
    onRefresh,
    onCopyAddress,
    onActionViewChange,
    regionBlocksUsAch = false,
    sepaAvailable = false,
    usAchNotice = null,
}: WalletScreenProps) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.3 }}
            className="p-4 space-y-4"
        >
            <BalanceCard
                balance={walletBalance}
                isLoading={isLoading}
                onRefresh={onRefresh}
                isSandbox={isSandbox}
                variant="hero"
            />

            {(regionBlocksUsAch || usAchNotice) && (
                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-left">
                    <p className="text-xs text-amber-900 dark:text-amber-100 leading-relaxed">
                        {usAchNotice
                            || (sepaAvailable
                                ? 'US bank transfers (ACH/wire) are not available in your region. You can still use SEPA and crypto deposits.'
                                : 'US bank transfers (ACH/wire) are not available in your region. Crypto deposits may still be available.')}
                    </p>
                </div>
            )}

            {/* Wallet address */}
            {walletAddress && (
                <button
                    type="button"
                    onClick={onCopyAddress}
                    className="w-full flex items-center gap-3 rounded-xl border border-border bg-muted/40 px-3 py-2.5 text-left hover:border-purple-500/40 hover:bg-muted/60 transition-colors group"
                >
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600">
                        <Wallet className="h-3.5 w-3.5 text-white" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">Wallet address</p>
                        <p className="text-xs font-mono truncate text-foreground">{formatAddress(walletAddress, 32)}</p>
                    </div>
                    <div className="shrink-0 text-muted-foreground group-hover:text-foreground transition-colors">
                        {copied ? (
                            <Check className="h-4 w-4 text-green-500" />
                        ) : (
                            <Copy className="h-4 w-4" />
                        )}
                    </div>
                </button>
            )}

            {/* Quick actions */}
            <div className="space-y-2">
                <p className="text-xs font-medium text-muted-foreground px-0.5">Quick actions</p>
                <div className="grid grid-cols-2 gap-2">
                    {quickActions.map((action, index) => (
                        <motion.button
                            key={action.id}
                            type="button"
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.2, delay: index * 0.05 }}
                            onClick={() => onActionViewChange(action.id)}
                            className="flex items-center gap-3 rounded-xl border border-border bg-card p-3 text-left hover:border-purple-500/30 hover:bg-muted/40 transition-all group"
                        >
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm group-hover:scale-105 transition-transform">
                                {action.icon}
                            </div>
                            <div className="min-w-0">
                                <p className="text-sm font-medium">{action.label}</p>
                                <p className="text-[11px] text-muted-foreground">{action.description}</p>
                            </div>
                        </motion.button>
                    ))}
                </div>
            </div>
        </motion.div>
    )
}

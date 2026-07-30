import { motion } from 'framer-motion'
import { RefreshCw, Sparkles } from 'lucide-react'
import { formatCurrency } from './utils'

export type BalanceCardVariant = 'hero' | 'compact'

interface BalanceCardProps {
    balance: number | null
    isLoading: boolean
    onRefresh: () => void
    isSandbox?: boolean
    variant?: BalanceCardVariant
    className?: string
}

export function BalanceCard({
    balance,
    isLoading,
    onRefresh,
    isSandbox = false,
    variant = 'compact',
    className = '',
}: BalanceCardProps) {
    const isHero = variant === 'hero'

    return (
        <div className={className}>
            <div className="relative overflow-hidden rounded-xl border border-border bg-card">
                <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-purple-600 to-blue-600" />
                {!isHero && (
                    <div className="absolute -right-6 -top-6 h-16 w-16 rounded-full bg-gradient-to-br from-purple-600/10 to-blue-600/5 blur-xl pointer-events-none" />
                )}
                {isHero && (
                    <div className="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-gradient-to-br from-purple-600/10 to-blue-600/5 blur-2xl pointer-events-none" />
                )}

                <div className={`relative ${isHero ? 'p-4 space-y-3' : 'px-4 py-3'}`}>
                    <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2 min-w-0">
                            <div
                                className={`flex shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-600/15 to-blue-600/10 ${
                                    isHero ? 'h-7 w-7' : 'h-6 w-6'
                                }`}
                            >
                                <Sparkles
                                    className={`text-purple-600 dark:text-purple-400 ${
                                        isHero ? 'h-3.5 w-3.5' : 'h-3 w-3'
                                    }`}
                                />
                            </div>
                            <span className="text-xs font-medium text-muted-foreground truncate">
                                Available balance
                            </span>
                        </div>
                        <div className="flex items-center gap-1.5 shrink-0">
                            {isSandbox && (
                                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                    Sandbox
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={onRefresh}
                                disabled={isLoading}
                                className={`flex items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground transition-colors disabled:opacity-50 ${
                                    isHero ? 'h-7 w-7' : 'h-6 w-6'
                                }`}
                                title="Refresh balance"
                            >
                                <RefreshCw className={`${isHero ? 'h-3.5 w-3.5' : 'h-3 w-3'} ${isLoading ? 'animate-spin' : ''}`} />
                            </button>
                        </div>
                    </div>

                    <motion.div
                        key={balance}
                        initial={{ opacity: 0.6, y: 2 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.2 }}
                        className={`flex items-baseline gap-1 ${isHero ? 'mt-0' : ''}`}
                    >
                        <span
                            className={`font-semibold text-muted-foreground ${
                                isHero ? 'text-2xl' : 'text-lg'
                            }`}
                        >
                            $
                        </span>
                        <span
                            className={`font-bold tracking-tight bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent ${
                                isHero ? 'text-4xl' : 'text-2xl'
                            }`}
                        >
                            {formatCurrency(balance)}
                        </span>
                    </motion.div>

                    {isHero && (
                        <p className="text-xs text-muted-foreground">USD · Believe Cash</p>
                    )}
                </div>
            </div>
        </div>
    )
}

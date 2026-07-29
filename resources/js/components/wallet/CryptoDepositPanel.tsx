import { useEffect, useMemo, useRef, useState } from 'react'
import { QrCode, Copy, Check, AlertCircle, RefreshCw, Shield, ArrowDownToLine } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { showSuccessToast, showErrorToast } from '@/lib/toast'
import { getCsrfToken } from './utils'
import {
    CryptoAssetIcon,
    CryptoNetworkBadge,
    getCryptoChainLabel,
    getCryptoTokenLabel,
} from './CryptoAssetIcon'

interface LiquidationAddress {
    id: string
    chain: string
    currency: string
    address: string
    destination_payment_rail: string
    destination_currency: string
    state: string
}

interface CryptoOption {
    chain: string
    currency: string
}

const ALL_CRYPTO_OPTIONS: CryptoOption[] = [
    { chain: 'solana', currency: 'usdc' },
    { chain: 'ethereum', currency: 'usdc' },
    { chain: 'ethereum', currency: 'usdt' },
]

const SANDBOX_CRYPTO_OPTIONS: CryptoOption[] = [{ chain: 'ethereum', currency: 'usdc' }]

interface CryptoDepositPanelProps {
    isSandbox?: boolean
    variant?: 'deposit' | 'receive'
}

export function CryptoDepositPanel({ isSandbox = false, variant = 'deposit' }: CryptoDepositPanelProps) {
    const cryptoOptions = useMemo(
        () => (isSandbox ? SANDBOX_CRYPTO_OPTIONS : ALL_CRYPTO_OPTIONS),
        [isSandbox],
    )

    const defaultCrypto = useMemo(
        () => ({ chain: cryptoOptions[0].chain, currency: cryptoOptions[0].currency }),
        [cryptoOptions],
    )

    const [liquidationAddresses, setLiquidationAddresses] = useState<LiquidationAddress[]>([])
    const [selectedCrypto, setSelectedCrypto] = useState<{ chain: string; currency: string } | null>(null)
    const [cryptoQrCodeUrl, setCryptoQrCodeUrl] = useState<string | null>(null)
    const [loadingCrypto, setLoadingCrypto] = useState(true)
    const [cryptoCopied, setCryptoCopied] = useState(false)
    const [addressesReady, setAddressesReady] = useState(false)
    const creatingAddressRef = useRef(false)

    const mergeLiquidationAddress = (address: LiquidationAddress) => {
        setLiquidationAddresses((prev) => {
            const exists = prev.some(
                (item) => item.chain === address.chain && item.currency === address.currency,
            )
            return exists ? prev : [...prev, address]
        })
    }

    const normalizeLiquidationAddress = (raw: Record<string, unknown>, chain: string, currency: string): LiquidationAddress => ({
        id: String(raw.id ?? ''),
        chain: String(raw.chain ?? chain),
        currency: String(raw.currency ?? currency),
        address: String(raw.address ?? ''),
        destination_payment_rail: String(raw.destination_payment_rail ?? chain),
        destination_currency: String(raw.destination_currency ?? currency),
        state: String(raw.state ?? 'active'),
    })

    useEffect(() => {
        setSelectedCrypto(defaultCrypto)
    }, [defaultCrypto.chain, defaultCrypto.currency])

    useEffect(() => {
        void fetchLiquidationAddresses().finally(() => setAddressesReady(true))
    }, [])

    useEffect(() => {
        if (selectedCrypto && addressesReady) {
            void fetchCryptoQrCode()
        }
    }, [selectedCrypto, liquidationAddresses, addressesReady])

    const fetchLiquidationAddresses = async (): Promise<LiquidationAddress[]> => {
        try {
            setLoadingCrypto(true)
            const response = await fetch(`/wallet/bridge/liquidation-addresses?t=${Date.now()}`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
            })

            if (response.ok) {
                const data = await response.json()
                if (data.success && data.data) {
                    const addresses = (Array.isArray(data.data) ? data.data : (data.data.data || [])) as Record<string, unknown>[]
                    const normalized = addresses
                        .filter((item) => item.address)
                        .map((item) =>
                            normalizeLiquidationAddress(
                                item,
                                String(item.chain ?? ''),
                                String(item.currency ?? ''),
                            ),
                        )
                    setLiquidationAddresses(normalized)
                    return normalized
                }
            }
        } catch (error) {
            console.error('Failed to fetch liquidation addresses:', error)
        } finally {
            setLoadingCrypto(false)
        }

        return []
    }

    const findAddressForSelection = (
        addresses: LiquidationAddress[],
        chain: string,
        currency: string,
    ) =>
        addresses.find((addr) => addr.chain === chain && addr.currency === currency)

    const createLiquidationAddress = async (): Promise<LiquidationAddress | null> => {
        if (!selectedCrypto || creatingAddressRef.current) {
            return null
        }

        creatingAddressRef.current = true

        try {
            setLoadingCrypto(true)
            const response = await fetch('/wallet/bridge/liquidation-address', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-store',
                body: JSON.stringify({
                    chain: selectedCrypto.chain,
                    currency: selectedCrypto.currency,
                }),
            })

            const data = await response.json().catch(() => ({}))
            const message = String(data.message ?? '').toLowerCase()

            if (response.ok && data.success && data.data) {
                const created = normalizeLiquidationAddress(
                    data.data as Record<string, unknown>,
                    selectedCrypto.chain,
                    selectedCrypto.currency,
                )
                mergeLiquidationAddress(created)
                return created
            }

            if (message.includes('already exists') || data.already_exists) {
                const refreshed = await fetchLiquidationAddresses()
                return findAddressForSelection(
                    refreshed,
                    selectedCrypto.chain,
                    selectedCrypto.currency,
                )
            }

            if (!message.includes('already exists')) {
                showErrorToast(data.message || 'Failed to create crypto deposit address')
            }

            return null
        } catch (error) {
            console.error('Failed to create liquidation address:', error)
            showErrorToast('Failed to create crypto deposit address')
            return null
        } finally {
            creatingAddressRef.current = false
            setLoadingCrypto(false)
        }
    }

    const fetchCryptoQrCode = async () => {
        if (!selectedCrypto) {
            return
        }

        let liquidationAddress = findAddressForSelection(
            liquidationAddresses,
            selectedCrypto.chain,
            selectedCrypto.currency,
        )

        if (!liquidationAddress) {
            liquidationAddress = (await createLiquidationAddress()) ?? undefined
            if (!liquidationAddress) {
                return
            }
        }

        try {
            setLoadingCrypto(true)
            const response = await fetch(
                `/wallet/bridge/liquidation-address-qr-code?id=${liquidationAddress.id}&t=${Date.now()}`,
                {
                    method: 'GET',
                    headers: {
                        Accept: 'image/svg+xml, image/png, image/*',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'include',
                    cache: 'no-store',
                },
            )

            if (response.ok) {
                const blob = await response.blob()
                setCryptoQrCodeUrl(URL.createObjectURL(blob))
            }
        } catch (error) {
            console.error('Failed to fetch crypto QR code:', error)
        } finally {
            setLoadingCrypto(false)
        }
    }

    const getSelectedLiquidationAddress = () => {
        if (!selectedCrypto) {
            return null
        }

        return liquidationAddresses.find(
            (addr) => addr.chain === selectedCrypto.chain && addr.currency === selectedCrypto.currency,
        )
    }

    const handleCopyCryptoAddress = () => {
        const selectedAddress = getSelectedLiquidationAddress()
        if (!selectedAddress?.address) {
            return
        }

        navigator.clipboard.writeText(selectedAddress.address)
        setCryptoCopied(true)
        showSuccessToast('Address copied to clipboard!')
        setTimeout(() => setCryptoCopied(false), 2000)
    }

    const selectedAddress = getSelectedLiquidationAddress()
    const title = variant === 'receive' ? 'Receive crypto' : 'Deposit crypto'
    const subtitle =
        variant === 'receive'
            ? 'Share your address or QR code to receive stablecoins into your wallet.'
            : 'Send stablecoins on-chain — Believe Cash converts and credits your wallet balance.';

    return (
        <div className="space-y-5">
            {/* Hero */}
            <div className="relative overflow-hidden rounded-2xl border border-purple-200/40 bg-gradient-to-br from-purple-600/10 via-blue-600/10 to-purple-600/5 p-4 dark:border-purple-800/40 dark:from-purple-900/30 dark:via-blue-900/25 dark:to-purple-900/15">
                <div className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-purple-500/10 blur-2xl" />
                <div className="pointer-events-none absolute -bottom-8 -left-4 h-20 w-20 rounded-full bg-blue-500/10 blur-2xl" />
                <div className="relative flex items-start gap-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 shadow-md">
                        <ArrowDownToLine className="h-5 w-5 text-white" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h3 className="text-base font-bold text-foreground">{title}</h3>
                        <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">{subtitle}</p>
                    </div>
                </div>
            </div>

            {isSandbox && (
                <div className="flex items-start gap-2.5 rounded-xl border border-amber-200/60 bg-amber-50/80 p-3.5 dark:border-amber-800/50 dark:bg-amber-900/20">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p className="text-xs leading-relaxed text-amber-900 dark:text-amber-100">
                        <span className="font-semibold">Sandbox:</span> USDC on Ethereum only. Create a bank deposit
                        account first if address setup fails.
                    </p>
                </div>
            )}

            {/* Asset picker */}
            <div className="space-y-2.5">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Select asset</p>
                <div
                    className={
                        cryptoOptions.length === 1
                            ? 'grid grid-cols-1'
                            : 'grid grid-cols-3 gap-2'
                    }
                >
                    {cryptoOptions.map((option) => {
                        const isSelected =
                            selectedCrypto?.chain === option.chain &&
                            selectedCrypto?.currency === option.currency

                        return (
                            <button
                                key={`${option.chain}-${option.currency}`}
                                type="button"
                                onClick={() => {
                                    setCryptoQrCodeUrl(null)
                                    setSelectedCrypto({ chain: option.chain, currency: option.currency })
                                }}
                                className={`relative flex min-w-0 flex-col items-center gap-2 rounded-xl border px-2 py-3 text-center transition-all ${
                                    isSelected
                                        ? 'border-purple-500 bg-purple-500/10 shadow-sm ring-1 ring-purple-500/40'
                                        : 'border-border bg-card hover:border-purple-400/40 hover:bg-muted/40'
                                }`}
                            >
                                {isSelected && (
                                    <span className="absolute right-1.5 top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600">
                                        <Check className="h-2.5 w-2.5 text-white" strokeWidth={3} />
                                    </span>
                                )}
                                <CryptoAssetIcon
                                    currency={option.currency}
                                    chain={option.chain}
                                    size="md"
                                    showChainBadge={false}
                                />
                                <div className="w-full min-w-0 space-y-0.5">
                                    <p className="truncate text-sm font-semibold leading-tight text-foreground">
                                        {getCryptoTokenLabel(option.currency)}
                                    </p>
                                    <p className="truncate text-[11px] leading-tight text-muted-foreground">
                                        {getCryptoChainLabel(option.chain)}
                                    </p>
                                </div>
                            </button>
                        )
                    })}
                </div>
            </div>

            {loadingCrypto ? (
                <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border bg-muted/30 py-12">
                    <RefreshCw className="h-6 w-6 animate-spin text-purple-600" />
                    <p className="text-sm text-muted-foreground">Preparing your deposit address…</p>
                </div>
            ) : selectedAddress && selectedCrypto ? (
                <div className="space-y-4">
                    {/* QR + address card */}
                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        <div className="border-b border-border/80 bg-muted/30 px-4 py-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-semibold text-foreground">Your deposit address</p>
                                <CryptoNetworkBadge
                                    currency={selectedCrypto.currency}
                                    chain={selectedCrypto.chain}
                                />
                            </div>
                        </div>

                        <div className="flex flex-col items-center px-4 py-6">
                            {cryptoQrCodeUrl ? (
                                <div className="relative mb-5 rounded-2xl border-2 border-border bg-white p-3 shadow-inner dark:bg-zinc-950">
                                    <img
                                        src={cryptoQrCodeUrl}
                                        alt={`${getCryptoTokenLabel(selectedCrypto.currency)} deposit QR code`}
                                        className="h-52 w-52 sm:h-56 sm:w-56"
                                    />
                                    <div className="absolute left-1/2 top-1/2 flex h-11 w-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white shadow-md ring-4 ring-white dark:bg-zinc-900 dark:ring-zinc-900">
                                        <CryptoAssetIcon
                                            currency={selectedCrypto.currency}
                                            chain={selectedCrypto.chain}
                                            size="sm"
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="mb-5 flex h-52 w-52 items-center justify-center rounded-2xl border-2 border-dashed border-border bg-muted/40 sm:h-56 sm:w-56">
                                    <QrCode className="h-14 w-14 text-muted-foreground/60" />
                                </div>
                            )}

                            <p className="mb-3 max-w-xs text-center text-xs text-muted-foreground">
                                Scan with your wallet app or copy the address below. Only send{' '}
                                <span className="font-medium text-foreground">
                                    {getCryptoTokenLabel(selectedAddress.currency)}
                                </span>{' '}
                                on{' '}
                                <span className="font-medium text-foreground">
                                    {getCryptoChainLabel(selectedAddress.chain)}
                                </span>
                                .
                            </p>

                            <div className="w-full space-y-2">
                                <div className="flex items-start gap-2 rounded-xl border border-border bg-muted/40 p-3">
                                    <code className="flex-1 break-all text-left font-mono text-xs leading-relaxed text-foreground">
                                        {selectedAddress.address}
                                    </code>
                                    <button
                                        type="button"
                                        onClick={handleCopyCryptoAddress}
                                        className="shrink-0 rounded-lg border border-border bg-background p-2 transition-colors hover:bg-muted"
                                        title="Copy address"
                                    >
                                        {cryptoCopied ? (
                                            <Check className="h-4 w-4 text-green-600" />
                                        ) : (
                                            <Copy className="h-4 w-4 text-muted-foreground" />
                                        )}
                                    </button>
                                </div>
                                <Button
                                    onClick={handleCopyCryptoAddress}
                                    className="w-full bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                                >
                                    <Copy className="mr-2 h-4 w-4" />
                                    Copy address
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Safety + how it works */}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-xl border border-red-200/50 bg-red-50/50 p-3.5 dark:border-red-900/40 dark:bg-red-950/20">
                            <div className="flex items-start gap-2">
                                <Shield className="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" />
                                <div>
                                    <p className="text-xs font-semibold text-red-900 dark:text-red-100">
                                        Network warning
                                    </p>
                                    <p className="mt-1 text-xs leading-relaxed text-red-800/90 dark:text-red-200/90">
                                        Wrong network or token = lost funds. Double-check before sending.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-xl border border-green-200/50 bg-green-50/50 p-3.5 dark:border-green-900/40 dark:bg-green-950/20">
                            <div className="flex items-start gap-2">
                                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-green-600 dark:text-green-400" />
                                <div>
                                    <p className="text-xs font-semibold text-green-900 dark:text-green-100">
                                        After you send
                                    </p>
                                    <p className="mt-1 text-xs leading-relaxed text-green-800/90 dark:text-green-200/90">
                                        Believe Cash confirms on-chain, converts, and credits your wallet balance.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            ) : selectedCrypto ? (
                <div className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-border py-10">
                    <RefreshCw className="h-6 w-6 animate-spin text-muted-foreground" />
                    <p className="text-sm text-muted-foreground">Setting up your address…</p>
                </div>
            ) : null}
        </div>
    )
}

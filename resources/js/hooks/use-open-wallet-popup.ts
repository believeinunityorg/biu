import { fetchWalletBalance } from "@/lib/wallet-balance-fetch"
import { hasCareAllianceRole } from "@/lib/mobile-nav-routes"
import type { ActionView } from "@/components/wallet/types"
import { useCallback, useState } from "react"
import toast from "react-hot-toast"

type WalletAuthUser = {
  role?: string
  wallet_header_visible?: boolean
  email_verified_at?: string | null
}

export function useOpenWalletPopup(auth?: { user?: WalletAuthUser; roles?: string[] }) {
  const [showWalletPopup, setShowWalletPopup] = useState(false)
  const [showSubscriptionModal, setShowSubscriptionModal] = useState(false)
  const [initialView, setInitialView] = useState<ActionView>("main")

  const openWallet = useCallback(async (view: ActionView = "main") => {
    const user = auth?.user
    if (!user) {
      return false
    }

    const careAlliance = hasCareAllianceRole(auth)

    if (user.wallet_header_visible === false) {
      toast.error(
        careAlliance
          ? "Add a valid 9-digit EIN under Settings → Alliance Settings (profile) to use the wallet."
          : "Add a valid 9-digit EIN under your organization profile to use the wallet.",
      )
      return false
    }

    const isRegularUser = user.role === "user" || !user.role

    if (isRegularUser) {
      try {
        const balanceData = await fetchWalletBalance({ force: true })
        if (balanceData?.has_subscription !== true) {
          setShowSubscriptionModal(true)
          return false
        }
      } catch {
        setShowSubscriptionModal(true)
        return false
      }
    }

    setInitialView(view)
    setShowWalletPopup(true)
    return true
  }, [auth])

  const closeWallet = useCallback(() => {
    setShowWalletPopup(false)
    setInitialView("main")
  }, [])

  const closeSubscriptionModal = useCallback(() => {
    setShowSubscriptionModal(false)
  }, [])

  return {
    showWalletPopup,
    showSubscriptionModal,
    initialView,
    openWallet,
    closeWallet,
    closeSubscriptionModal,
  }
}

export function showWalletInMobileNav(auth?: { user?: WalletAuthUser }): boolean {
  const user = auth?.user
  if (!user) return false
  if (user.role === "admin") return false
  return user.wallet_header_visible !== false
}

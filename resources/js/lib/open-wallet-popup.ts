import type { ActionView } from "@/components/wallet/types"

export const OPEN_WALLET_POPUP_EVENT = "biu:open-wallet-popup"

export type OpenWalletPopupDetail = {
  view?: ActionView
}

/** Ask the layout (navbar / mobile nav) to open WalletPopup, optionally on a deep view. */
export function openWalletPopup(detail: OpenWalletPopupDetail = {}): void {
  if (typeof window === "undefined") {
    return
  }

  window.dispatchEvent(
    new CustomEvent<OpenWalletPopupDetail>(OPEN_WALLET_POPUP_EVENT, {
      detail: {
        view: detail.view ?? "main",
      },
    }),
  )
}

/**
 * Helpers for displaying Phaze gift card credentials.
 * Null/empty values are omitted by callers.
 */

export type GiftCardCredentials = {
  pin?: string | null
  card_number?: string | null
  voucher?: string | null
  barcode?: string | null
  barcode_url?: string | null
  qr_code?: string | null
  qr_code_url?: string | null
  claim_url?: string | null
  redemption_instructions?: string | null
  how_to_use?: string | null
}

function pickString(...candidates: unknown[]): string | null {
  for (const candidate of candidates) {
    if (typeof candidate === "string" && candidate.trim() !== "") {
      return candidate.trim()
    }
    if (typeof candidate === "number" && Number.isFinite(candidate)) {
      return String(candidate)
    }
  }
  return null
}

export function resolveGiftCardCredentials(
  giftCard: GiftCardCredentials & {
    meta?: Record<string, unknown> | null
  },
  phazePurchaseData?: Record<string, unknown> | null,
): Required<GiftCardCredentials> {
  const meta = (giftCard.meta ?? {}) as Record<string, unknown>
  const stored = (meta.phaze_credentials ?? {}) as Record<string, unknown>
  const phaze = (phazePurchaseData ??
    meta.phaze_purchase ??
    meta.phaze_initial_response ??
    {}) as Record<string, unknown>

  const vouchers = Array.isArray(phaze.vouchers) ? phaze.vouchers : []
  const firstVoucher =
    vouchers[0] && typeof vouchers[0] === "object"
      ? (vouchers[0] as Record<string, unknown>)
      : {}

  const voucherCode = pickString(
    giftCard.voucher,
    stored.voucher,
    phaze.voucher,
    firstVoucher.code,
    firstVoucher.voucher,
    phaze.code,
  )

  // Phaze often returns only vouchers[].code (short numeric PIN) for Walmart-style cards.
  const pinFromCode =
    voucherCode && /^\d{3,12}$/.test(voucherCode) ? voucherCode : null

  return {
    pin: pickString(
      giftCard.pin,
      stored.pin,
      phaze.pin,
      phaze.PIN,
      phaze.cardPin,
      phaze.card_pin,
      firstVoucher.pin,
      firstVoucher.cardPin,
      phaze.securityCode,
      phaze.security_code,
      pinFromCode,
    ),
    card_number: pickString(
      giftCard.card_number,
      stored.card_number,
      phaze.cardNumber,
      phaze.card_number,
      firstVoucher.cardNumber,
      firstVoucher.card_number,
    ),
    voucher: voucherCode,
    barcode: pickString(giftCard.barcode, stored.barcode, phaze.barcode, phaze.barCode),
    barcode_url: pickString(
      giftCard.barcode_url,
      stored.barcode_url,
      phaze.barcodeUrl,
      phaze.barcode_url,
    ),
    qr_code: pickString(giftCard.qr_code, stored.qr_code, phaze.qrCode, phaze.qr_code),
    qr_code_url: pickString(
      giftCard.qr_code_url,
      stored.qr_code_url,
      phaze.qrCodeUrl,
      phaze.qr_code_url,
    ),
    claim_url: pickString(
      giftCard.claim_url,
      stored.claim_url,
      firstVoucher.url,
      firstVoucher.claimUrl,
      phaze.claimUrl,
      phaze.claim_url,
      phaze.redemptionUrl,
      phaze.redemption_url,
    ),
    how_to_use: pickString(
      giftCard.how_to_use,
      stored.how_to_use,
      meta.howToUse,
      phaze.howToUse,
    ),
    redemption_instructions: pickString(
      giftCard.redemption_instructions,
      stored.redemption_instructions,
      phaze.redemptionInstructions,
      phaze.instructions,
      giftCard.how_to_use,
      stored.how_to_use,
      meta.howToUse,
      phaze.howToUse,
    ),
  }
}

export function formatGiftCardNumber(cardNumber: string | null | undefined): string | null {
  if (!cardNumber) return null
  // Only format pure digit card numbers; leave alphanumeric codes as-is.
  if (!/^\d+$/.test(cardNumber)) return cardNumber
  return cardNumber.replace(/(\d{4})(?=\d)/g, "$1-")
}

/** Human labels for provider payload keys (org/admin full dump). */
export function labelPhazeField(key: string): string {
  return key
    .replace(/_/g, " ")
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

/** Phaze admin shows "Processed"; API returns processed/completed/success. */
export function isPhazeSuccessStatus(status?: string | null): boolean {
  const normalized = (status ?? "").toLowerCase().trim()
  return ["processed", "completed", "success", "complete", "fulfilled"].includes(normalized)
}

export function isPhazeFailedStatus(status?: string | null): boolean {
  const normalized = (status ?? "").toLowerCase().trim()
  return ["failed", "error", "cancelled", "canceled", "rejected"].includes(normalized)
}

export function labelPhazeStatus(status?: string | null): string {
  const normalized = (status ?? "").toLowerCase().trim()
  if (!normalized) return "Unknown"
  if (isPhazeSuccessStatus(normalized)) return "Processed"
  if (isPhazeFailedStatus(normalized)) return "Failed"
  if (["pending", "processing", "created"].includes(normalized)) return "Pending"
  return normalized.charAt(0).toUpperCase() + normalized.slice(1)
}

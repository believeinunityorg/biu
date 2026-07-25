/**
 * Human-readable labels for `transactions.type` values used across the app.
 * Badge UI applies uppercase; these are written in sentence case for readability.
 *
 * @see database migrations / Transaction model — additional types may exist in production.
 */
export const TRANSACTION_TYPE_LABELS: Record<string, string> = {
  // Core wallet (enum)
  deposit: "Deposit",
  withdrawal: "Withdrawal",
  purchase: "Purchase",
  refund: "Refund",
  commission: "Commission",
  transfer_out: "Transfer out",
  transfer_in: "Transfer in",
  transfer: "Transfer",
  adjustment: "Adjustment",
  // Donations & wallet flows
  donation: "Donation",
  // Subscriptions & platform fees
  plan_subscription: "Plan subscription",
  wallet_subscription: "Wallet subscription",
  kyc_fee: "KYC fee",
  merchant_subscription: "Merchant subscription",
  // Marketplace / checkout
  gift_card_purchase: "Gift card purchase",
  enrollment: "Enrollment",
  event_registration: "Meetup registration",
  course_enrollment: "Course enrollment",
  companion_enrollment: "Companion enrollment",
  earning_enrollment: "Earning enrollment",
  connection_hub_enrollment: "Connection Hub enrollment",
  free: "Free",
  paid: "Paid",
  cancellation: "Cancellation",
  merchant_hub_redemption: "Merchant Hub redemption",
  referral_reward: "Referral reward",
  raffle_sale: "Raffle sale",
  raffle_tickets: "Raffle tickets",
  administrative_fee: "Administrative fee",
  // Believe Points
  believe_points_purchase: "Believe Points purchase",
  brp_participation_reward: "BRP Participation Reward",
  believe_points_wallet_transfer: "BP Redemption",
  bp_redemption: "BP Redemption",
  bridge_wallet_transfer: "Bridge Wallet Transfer",
  bp_settlement: "BP settlement",
  bp_gift: "BP Gift",
  bp_gift_sent: "Gift Sent",
  bp_gift_claimed: "Gift Claimed",
  bp_gift_cancelled: "Gift Cancelled",
  bp_gift_expired: "Gift Expired",
  bp_gift_refunded: "Gift Refunded",
  bp_gift_hold: "Gift Sent",
  bp_gift_claim: "Gift Claimed",
  bp_gift_hold_refund: "Gift Refunded",
  bp_gift_email_changed: "BP Gift",
  believe_points_auto_replenish: "Believe Points auto-replenish",
  believe_points_auto_replenish_setup: "Believe Points auto-replenish setup",
  // Campaigns & fundraising (Support a project → Give / FundMe)
  fundme_donation: "FundMe donation",
  fundme_contribution: "Support a project contribution",
  // Newsletter / credits / email
  sms_purchase: "SMS purchase",
  newsletter_pro_targeting_lifetime: "Newsletter Pro targeting (lifetime)",
  /** Unified ledger `transaction_type` (presenter), not raw wallet enum */
  newsletter_pro_targeting_purchase: "Newsletter Pro targeting purchase",
  sms_credit_purchase: "SMS credit purchase",
  email_credit_purchase: "Email credit purchase",
  organization_subscription_paid: "Organization subscription",
  supporter_subscription_paid: "Supporter subscription",
  email_purchase: "Email purchase",
  credit_purchase: "Credit purchase",
  // Service Hub
  service_order: "Service order",
  // Other verticals
  animal_purchase: "Animal purchase",
  fractional_ownership: "Fractional ownership",
  form_1023_application: "Form 1023 application",
  direct_referral: "Direct referral",
  big_boss_override: "Big Boss override",
  compliance_application: "Compliance application",
  winning_bid: "Winning bid",
  redemption: "Redemption",
}

/** Keys in a stable order for admin filters (union of known types + core enum). */
export const TRANSACTION_TYPE_FILTER_ORDER: string[] = [
  "adjustment",
  "administrative_fee",
  "animal_purchase",
  "believe_points_auto_replenish",
  "believe_points_auto_replenish_setup",
  "believe_points_purchase",
  "believe_points_wallet_transfer",
  "bp_gift_sent",
  "bp_gift_claimed",
  "bp_gift_cancelled",
  "bp_gift_expired",
  "bp_gift_refunded",
  "bp_redemption",
  "bridge_wallet_transfer",
  "bp_settlement",
  "big_boss_override",
  "cancellation",
  "commission",
  "compliance_application",
  "credit_purchase",
  "deposit",
  "direct_referral",
  "donation",
  "email_purchase",
  "enrollment",
  "form_1023_application",
  "fractional_ownership",
  "free",
  "fundme_contribution",
  "fundme_donation",
  "gift_card_purchase",
  "kyc_fee",
  "merchant_hub_redemption",
  "merchant_subscription",
  "newsletter_pro_targeting_lifetime",
  "paid",
  "plan_subscription",
  "purchase",
  "raffle_sale",
  "raffle_tickets",
  "referral_reward",
  "refund",
  "service_order",
  "supporter_subscription_paid",
  "sms_purchase",
  "transfer",
  "transfer_in",
  "transfer_out",
  "wallet_subscription",
  "winning_bid",
  "withdrawal",
]

export function transactionTypeDisplayLabel(type: string | null | undefined): string {
  if (type == null || String(type).trim() === "") {
    return "Transaction"
  }
  const key = String(type).trim().toLowerCase()
  if (TRANSACTION_TYPE_LABELS[key]) {
    return TRANSACTION_TYPE_LABELS[key]
  }
  return String(type)
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

/**
 * Tailwind classes for the wallet-type pill on the admin ledger (matches previous buckets, extended).
 */
export function transactionTypeBadgeClass(type: string): string {
  const t = (type || "").toLowerCase()
  if (t === "refund" || t === "cancellation") {
    return "border-sky-500/40 bg-sky-500/[0.12] text-sky-900 dark:text-sky-100"
  }
  if (t === "withdrawal" || t === "transfer_out") {
    return "border-orange-500/40 bg-orange-500/[0.12] text-orange-900 dark:text-orange-100"
  }
  if (t === "commission") {
    return "border-primary/35 bg-primary/12 text-primary"
  }
  if (t === "deposit" || t === "transfer_in" || t === "transfer") {
    return "border-teal-500/40 bg-teal-500/[0.12] text-teal-900 dark:text-teal-100"
  }
  if (t === "donation" || t === "fundme_donation" || t === "fundme_contribution" || t.endsWith("_donation")) {
    return "border-rose-500/40 bg-rose-500/[0.12] text-rose-900 dark:text-rose-100"
  }
  if (
    t === "plan_subscription" ||
    t === "wallet_subscription" ||
    t === "merchant_subscription" ||
    t === "kyc_fee" ||
    t === "newsletter_pro_targeting_lifetime" ||
    t === "newsletter_pro_targeting_purchase" ||
    t === "organization_subscription_paid" ||
    t === "supporter_subscription_paid"
  ) {
    return "border-indigo-500/40 bg-indigo-500/[0.12] text-indigo-950 dark:text-indigo-100"
  }
  if (
    t.startsWith("believe_points") ||
    t === "credit_purchase" ||
    t === "sms_purchase" ||
    t === "email_purchase"
  ) {
    return "border-amber-500/40 bg-amber-500/[0.12] text-amber-950 dark:text-amber-100"
  }
  if (
    t === "referral_reward" ||
    t === "direct_referral" ||
    t === "big_boss_override" ||
    t === "merchant_hub_redemption"
  ) {
    return "border-emerald-500/40 bg-emerald-500/[0.12] text-emerald-950 dark:text-emerald-100"
  }
  if (t === "service_order" || t === "enrollment" || t === "gift_card_purchase" || t === "raffle_tickets" || t === "raffle_sale") {
    return "border-violet-500/40 bg-violet-500/[0.12] text-violet-950 dark:text-violet-100"
  }
  if (t === "adjustment" || t === "administrative_fee") {
    return "border-slate-500/40 bg-slate-500/[0.12] text-slate-900 dark:text-slate-100"
  }
  if (t === "purchase" || t === "paid" || t === "free" || t === "winning_bid" || t === "redemption") {
    return "border-primary/35 bg-primary/12 text-primary"
  }
  return "border-primary/35 bg-primary/12 text-primary"
}

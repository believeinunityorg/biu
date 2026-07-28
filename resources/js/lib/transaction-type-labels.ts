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
  gift_card_purchase: "Gift Card Purchase",
  enrollment: "Enrollment",
  event_registration: "Event Registration",
  course_enrollment: "Course Enrollment",
  companion_enrollment: "Companion Enrollment",
  earning_enrollment: "Earning Enrollment",
  connection_hub_enrollment: "Connection Hub Enrollment",
  free: "Free",
  paid: "Paid",
  cancellation: "Cancellation",
  merchant_hub_redemption: "Merchant Hub Redemption",
  referral_reward: "Referral Reward",
  raffle_sale: "Raffle Sale",
  raffle_tickets: "Raffle Tickets",
  administrative_fee: "Administrative Fee",
  // Believe Points
  believe_points_purchase: "BP Purchase",
  brp_participation_reward: "BRP Participation Reward",
  believe_points_wallet_transfer: "Transfer to Bridge Wallet",
  bp_redemption: "Transfer to Bridge Wallet",
  bridge_wallet_transfer: "Bridge Wallet Transfer",
  bp_settlement: "BP Settlement",
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
  believe_points_auto_replenish: "Believe Points Auto-Replenish",
  believe_points_auto_replenish_setup: "Believe Points Auto-Replenish Setup",
  // Campaigns & fundraising (Support a project → Give / FundMe)
  fundme_donation: "FundMe Donation",
  fundme_contribution: "Support a Project Contribution",
  // Newsletter / credits / email
  sms_purchase: "SMS Purchase",
  newsletter_pro_targeting_lifetime: "Newsletter Pro Targeting (Lifetime)",
  /** Unified ledger `transaction_type` (presenter), not raw wallet enum */
  newsletter_pro_targeting_purchase: "Newsletter Pro Targeting Purchase",
  sms_credit_purchase: "SMS Credit Purchase",
  email_credit_purchase: "Email Credit Purchase",
  organization_subscription_paid: "Organization Subscription",
  supporter_subscription_paid: "Supporter Subscription",
  email_purchase: "Email Purchase",
  credit_purchase: "Credit Purchase",
  // Service Hub
  service_order: "Service Order",
  // Other verticals
  animal_purchase: "Animal Purchase",
  fractional_ownership: "Fractional Ownership",
  form_1023_application: "Form 1023 Application",
  direct_referral: "Direct Referral",
  big_boss_override: "Big Boss Override",
  compliance_application: "Compliance Application",
  winning_bid: "Winning Bid",
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

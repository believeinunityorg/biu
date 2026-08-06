import { ShieldCheck } from "lucide-react"
import { Badge } from "@/components/frontend/ui/badge"
import { cn } from "@/lib/utils"

type EinVerifiedBadgeProps = {
  /** True when the organization registered with a real EIN. */
  verified?: boolean | null
  className?: string
  /** Compact text for dense lists / donate dropdowns. */
  size?: "sm" | "md"
  /** Show only the icon (e.g. next to an org name). */
  iconOnly?: boolean
}

/**
 * Trust badge for organizations that registered with a real EIN.
 * Non-EIN orgs intentionally do not show this badge.
 */
export function EinVerifiedBadge({
  verified = false,
  className,
  size = "md",
  iconOnly = false,
}: EinVerifiedBadgeProps) {
  if (!verified) return null

  if (iconOnly) {
    return (
      <span
        className={cn(
          "inline-flex shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white",
          size === "sm" ? "h-4 w-4" : "h-5 w-5",
          className,
        )}
        title="EIN Verified"
        aria-label="EIN Verified"
      >
        <ShieldCheck className={size === "sm" ? "h-2.5 w-2.5" : "h-3 w-3"} />
      </span>
    )
  }

  return (
    <Badge
      className={cn(
        "bg-emerald-600 hover:bg-emerald-600 text-white flex items-center gap-1 border-0",
        size === "sm" ? "text-[10px] px-1.5 py-0" : "text-xs px-2 py-0.5",
        className,
      )}
      title="This organization registered with a verified EIN"
    >
      <ShieldCheck className={size === "sm" ? "h-2.5 w-2.5" : "h-3 w-3"} />
      EIN Verified
    </Badge>
  )
}

export function isEinVerifiedOrg(org: {
  has_ein?: boolean | null
  ein_verified?: boolean | null
  registered_organization?: { has_ein?: boolean | null } | null
} | null | undefined): boolean {
  if (!org) return false
  if (org.ein_verified === true || org.has_ein === true) return true
  if (org.registered_organization?.has_ein === true) return true
  return false
}

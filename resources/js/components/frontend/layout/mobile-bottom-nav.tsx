"use client"

import {
  MobileBrowseAllSheet,
  MobileCustomizeSheet,
  MobileFavoritesHubSheet,
  MobileFavoritesOnboarding,
  MobileGuestHubSheet,
  MobileProfileMenuSheet,
} from "@/components/frontend/layout/mobile-favorites-sheets"
import { useMobileNav } from "@/contexts/mobile-nav-context"
import { showWalletInMobileNav, useOpenWalletPopup } from "@/hooks/use-open-wallet-popup"
import { mobileNavRouteContext } from "@/lib/mobile-nav-routes"
import { resolveSiteMenuIcon } from "@/lib/site-menu-icons"
import type { MobileNavMenuItem, MobileNavPayload } from "@/types/mobile-nav"
import { isMobileNavItemActive } from "@/types/mobile-nav"
import { WalletPopup } from "@/components/WalletPopup"
import { UserWalletSubscriptionModal } from "@/components/UserWalletSubscriptionModal"
import { Link, usePage } from "@inertiajs/react"
import { motion } from "framer-motion"
import { Heart, Star } from "lucide-react"
import { useEffect, useMemo, useState } from "react"
import { cn } from "@/lib/utils"

const fabTransition = { duration: 0.28, ease: [0.25, 0.1, 0.25, 1] as const }

function normalizePath(url: string): string {
  return url.split("?")[0]?.split("#")[0] ?? "/"
}

function NavTab({
  item,
  path,
  isActiveOverride,
  onPress,
  ariaExpanded,
}: {
  item: MobileNavMenuItem
  path: string
  isActiveOverride?: boolean
  onPress?: () => void
  ariaExpanded?: boolean
}) {
  if (item.isHub || (!item.href && !item.opensWallet && !onPress)) return null
  const Icon = resolveSiteMenuIcon(item.icon)
  const isActive = isActiveOverride ?? isMobileNavItemActive(path, item)

  const content = (
    <>
      <span
        className={cn(
          "flex h-9 w-9 items-center justify-center rounded-xl transition-all duration-200",
          isActive
            ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm shadow-purple-500/30"
            : "text-muted-foreground",
        )}
      >
        <Icon className={cn("h-5 w-5 shrink-0", isActive && "stroke-[2.5px]")} />
      </span>
      <span
        className={cn(
          "max-w-full truncate px-0.5 text-[10px] font-semibold leading-tight transition-colors duration-200",
          isActive ? "text-purple-500 dark:text-purple-400" : "text-muted-foreground",
        )}
      >
        {item.title}
      </span>
    </>
  )

  if ((item.opensWallet || onPress) && onPress) {
    return (
      <button
        type="button"
        onClick={onPress}
        aria-expanded={ariaExpanded}
        aria-haspopup={item.menuKey === "profile" ? "menu" : undefined}
        className="relative z-10 flex h-full w-full flex-col items-center justify-end gap-1 px-1 pb-1.5 pt-1 touch-manipulation active:opacity-80"
      >
        {content}
      </button>
    )
  }

  return (
    <Link
      href={item.href ?? "#"}
      className="relative z-10 flex h-full flex-col items-center justify-end gap-1 px-1 pb-1.5 pt-1 touch-manipulation active:opacity-80"
    >
      {content}
    </Link>
  )
}

function roleAwareBottomNav(
  routes: ReturnType<typeof mobileNavRouteContext>,
  showWalletInSlot2: boolean,
): MobileNavMenuItem[] {
  const slot2: MobileNavMenuItem = showWalletInSlot2
    ? {
        menuKey: "wallet",
        title: "Wallet",
        href: null,
        icon: "Wallet",
        opensWallet: true,
        slot: 2,
      }
    : {
        menuKey: routes.isAdmin || routes.isOrgUser || routes.hasCareAllianceRole ? "dashboard" : "organizations",
        title: routes.slot2Title,
        href: routes.slot2Href,
        icon: routes.slot2Icon,
        activePathPrefix: routes.slot2ActivePrefix,
        slot: 2,
      }

  return [
    { menuKey: "home", title: "Home", href: "/", icon: "Home", activePathPrefix: "/", slot: 1 },
    slot2,
    { menuKey: "my_favorites", title: "Favorites", href: null, icon: "Star", isHub: true, slot: 3 },
    { menuKey: "chat", title: "Chat", href: routes.chatHref, icon: "MessageCircle", activePathPrefix: "/chat", slot: 4 },
    {
      menuKey: "profile",
      title: routes.isLoggedIn ? "Profile" : "Sign in",
      href: routes.profileHref,
      icon: "User",
      activePathPrefix: routes.isAdmin || routes.isOrgUser || routes.hasCareAllianceRole ? routes.slot2ActivePrefix : "/profile",
      slot: 5,
    },
  ]
}

export default function MobileBottomNav() {
  const page = usePage<{ auth?: { user?: { role?: string } }; roles?: string[]; mobileNav?: MobileNavPayload | null }>()
  const { auth, roles, mobileNav: mobileNavProp } = page.props
  const path = normalizePath(page.url)
  const { closeMenu, isMenuOpen } = useMobileNav()

  const [favoritesOpen, setFavoritesOpen] = useState(false)
  const [browseOpen, setBrowseOpen] = useState(false)
  const [customizeOpen, setCustomizeOpen] = useState(false)
  const [profileMenuOpen, setProfileMenuOpen] = useState(false)
  const [showOnboarding, setShowOnboarding] = useState(false)

  const isLoggedIn = Boolean(auth?.user)
  const routes = useMemo(() => mobileNavRouteContext(auth), [auth])
  const showWalletInSlot2 = showWalletInMobileNav(auth)
  const isSupporter = routes.isSupporter
  const {
    showWalletPopup,
    showSubscriptionModal,
    initialView,
    openWallet,
    closeWallet,
    closeSubscriptionModal,
  } = useOpenWalletPopup(auth)

  const mobileNav = mobileNavProp ?? null

  const slots = useMemo(() => {
    if (mobileNav?.bottomNavSlots?.length) {
      return mobileNav.bottomNavSlots
    }
    return roleAwareBottomNav(routes, showWalletInSlot2)
  }, [mobileNav, routes, showWalletInSlot2])

  useEffect(() => {
    setFavoritesOpen(false)
    setBrowseOpen(false)
    setCustomizeOpen(false)
    setProfileMenuOpen(false)
    closeWallet()
  }, [path, closeWallet])

  useEffect(() => {
    if (isMenuOpen) {
      setFavoritesOpen(false)
      setBrowseOpen(false)
      setProfileMenuOpen(false)
    }
  }, [isMenuOpen])

  useEffect(() => {
    if (mobileNav?.needsOnboarding && isSupporter) {
      setShowOnboarding(true)
    }
  }, [mobileNav?.needsOnboarding, isSupporter])

  useEffect(() => {
    const open = favoritesOpen || browseOpen || customizeOpen
    if (!open) return
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = "hidden"
    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [favoritesOpen, browseOpen, customizeOpen])

  const hubActive = favoritesOpen || browseOpen || customizeOpen

  const handleWalletPress = () => {
    closeMenu()
    setFavoritesOpen(false)
    setBrowseOpen(false)
    setCustomizeOpen(false)
    setProfileMenuOpen(false)
    void openWallet()
  }

  const handleProfilePress = () => {
    closeMenu()
    setFavoritesOpen(false)
    setBrowseOpen(false)
    setCustomizeOpen(false)
    setProfileMenuOpen((open) => !open)
  }

  return (
    <>
      {showWalletPopup && (
        <WalletPopup isOpen={showWalletPopup} onClose={closeWallet} initialView={initialView} />
      )}
      {showSubscriptionModal && (
        <UserWalletSubscriptionModal isOpen={showSubscriptionModal} onClose={closeSubscriptionModal} />
      )}

      {showOnboarding && mobileNav && (
        <MobileFavoritesOnboarding mobileNav={mobileNav} onDone={() => setShowOnboarding(false)} />
      )}

      {!mobileNav && (
        <MobileGuestHubSheet open={favoritesOpen} onClose={() => setFavoritesOpen(false)} />
      )}

      {mobileNav && (
        <>
          <MobileFavoritesHubSheet
            mobileNav={mobileNav}
            path={path}
            favoritesOpen={favoritesOpen}
            onCloseFavorites={() => setFavoritesOpen(false)}
            onOpenBrowse={() => setBrowseOpen(true)}
            onOpenCustomize={() => {
              setFavoritesOpen(false)
              setCustomizeOpen(true)
            }}
            canCustomize={mobileNav.canCustomize || mobileNav.canCustomizeQuick}
          />
          <MobileBrowseAllSheet mobileNav={mobileNav} open={browseOpen} onClose={() => setBrowseOpen(false)} />
          <MobileCustomizeSheet mobileNav={mobileNav} open={customizeOpen} onClose={() => setCustomizeOpen(false)} />
        </>
      )}

      {isLoggedIn && (
        <MobileProfileMenuSheet
          open={profileMenuOpen}
          onClose={() => setProfileMenuOpen(false)}
          profileHref={routes.profileHref}
        />
      )}

      <nav
        aria-label="Mobile bottom navigation"
        className="pointer-events-none fixed inset-x-0 bottom-0 z-40 px-3 2xl:hidden"
        style={{ paddingBottom: "max(calc(0.75rem + env(safe-area-inset-bottom, 0px)), 0.75rem)" }}
      >
        <div className="pointer-events-auto relative mx-auto max-w-lg">
          <div className="overflow-visible rounded-[1.75rem] border border-border/70 bg-background/95 shadow-[0_8px_32px_rgba(15,23,42,0.14)] backdrop-blur-xl supports-[backdrop-filter]:bg-background/80 dark:border-border/50 dark:shadow-[0_8px_32px_rgba(0,0,0,0.45)]">
            <div className="relative grid h-[3.75rem] grid-cols-5 items-end px-1">
              {slots.map((item) => {
                if (item.slot === 3 || item.isHub) {
                  return (
                    <div key="hub" className="relative z-20 flex h-full flex-col items-center justify-end pb-1.5">
                      <motion.button
                        type="button"
                        onClick={() => {
                          closeMenu()
                          setProfileMenuOpen(false)
                          setFavoritesOpen((open) => !open)
                        }}
                        aria-expanded={hubActive}
                        aria-label={hubActive ? "Close favorites" : "Open My Favorites"}
                        whileTap={{ scale: 0.96 }}
                        animate={{ y: hubActive ? -7 : -5 }}
                        transition={fabTransition}
                        style={{ bottom: "1.375rem" }}
                        className={cn(
                          "absolute left-1/2 flex h-14 w-14 -translate-x-1/2 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-[0_10px_28px_rgba(124,58,237,0.45)] ring-4 ring-background transition-shadow duration-300",
                          hubActive && "shadow-[0_12px_36px_rgba(124,58,237,0.6)]",
                        )}
                      >
                        {mobileNav ? (
                          <Star className="h-6 w-6 fill-white/15 stroke-[2.5px]" />
                        ) : (
                          <Heart className="h-6 w-6 fill-white/15 stroke-[2.5px]" />
                        )}
                      </motion.button>
                      <span
                        className={cn(
                          "relative z-10 text-[10px] font-semibold leading-tight",
                          hubActive ? "text-purple-500 dark:text-purple-400" : "text-muted-foreground",
                        )}
                      >
                        {item.title}
                      </span>
                    </div>
                  )
                }

                const opensWallet = item.opensWallet || item.menuKey === "wallet"
                const opensProfileMenu = isLoggedIn && item.menuKey === "profile" && item.slot === 5

                return (
                  <NavTab
                    key={`${item.slot}-${item.menuKey}`}
                    item={item}
                    path={path}
                    isActiveOverride={
                      opensWallet ? showWalletPopup : opensProfileMenu ? profileMenuOpen || isMobileNavItemActive(path, item) : undefined
                    }
                    onPress={opensWallet ? handleWalletPress : opensProfileMenu ? handleProfilePress : undefined}
                    ariaExpanded={opensProfileMenu ? profileMenuOpen : undefined}
                  />
                )
              })}
            </div>
          </div>
        </div>
      </nav>
    </>
  )
}

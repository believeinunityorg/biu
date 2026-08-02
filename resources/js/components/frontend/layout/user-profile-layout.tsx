"use client"
import type React from "react"
import { useEffect, useMemo, useState } from "react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { motion } from "framer-motion"
import {
  User,
  Heart,
  CreditCard,
  ShoppingBag,
  TrendingUp,
  Receipt,
  Shield,
  Calendar,
  Edit3,
  Plus,
  Minus,
  Copy,
  Check,
  Award,
  BadgeCheck,
  Target,
  BookOpen,
  Settings,
  Star,
  Activity,
  X,
  Tag,
  Text,
  MessageCircle,
  GraduationCap,
  UserCheck, // Added MessageCircle icon
  MessagesSquare,
  PieChart,
  Gift,
  Coins,
  Briefcase,
  QrCode,
  Globe,
  Youtube,
  Gavel,
  Video,
  ChevronsUpDown,
  ArrowLeft,
  Menu,
} from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent } from "@/components/frontend/ui/card"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/frontend/ui/avatar"
import { resolveStorageUrl } from "@/lib/storage-url"
import { Badge } from "@/components/frontend/ui/badge"
import { Input } from "@/components/ui/input"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/frontend/ui/sheet"
import { Link, router, usePage } from "@inertiajs/react"
import { LogOut } from "lucide-react"
import { showErrorToast, showSuccessToast } from "@/lib/toast"
import {
  getSupporterPlanBadge,
  type SupporterSubscriptionState,
} from "@/lib/supporter-pricing-display"
import { cn } from "@/lib/utils"

interface ProfileLayoutProps {
  children: React.ReactNode
  title: string
  description?: string
}

interface Topic {
  id: number;
  name: string;
  color: string;
}

interface PageProps {
  auth: {
    user: {
      id: number
      slug?: string
      name: string
      email: string
      phone?: string
      image?: string
      joined: string
      total_donated?: number
      favorite_organizations_count?: number
      total_orders?: number
      impact_score?: number
      referral_link?: string
      wallet_balance?: number
    }
  }
  [key: string]: any
}

const navigationItems = [
  {
    name: "Overview",
    href: "/profile",
    icon: User,
    description: "Profile overview",
    color: "from-blue-500 to-blue-600",
  },
  {
    name: "Groups Chat",
    href: "/profile/topics/select",
    icon: Text,
    description: "Groups Chat",
    color: "from-green-400 to-blue-600",
  },
  {
    name: "My Groups",
    href: route("my-chat-groups.index"),
    icon: MessagesSquare,
    description: "Chats and groups you joined",
    color: "from-purple-600 to-blue-600",
  },
  {
    name: "Following",
    href: "/profile/following",
    icon: UserCheck,
    description: "Following organizations",
    color: "from-red-500 to-pink-600",
  },
  {
    name: "My Memberships",
    href: "/profile/memberships",
    icon: BadgeCheck,
    description: "Membership requests and verified memberships",
    color: "from-indigo-500 to-blue-600",
  },
  {
    name: "Project Applications",
    href: "/profile/project-applications",
    icon: TrendingUp,
    description: "Project applications requested",
    color: "from-violet-500 to-purple-600",
  },
  {
    name: "Donations",
    href: "/profile/donations",
    icon: CreditCard,
    description: "Donation history",
    color: "from-green-500 to-emerald-600",
  },
  {
    name: "BP Wallet",
    href: "/believe-points",
    icon: Coins,
    description: "Fund your Believe Points wallet",
    color: "from-blue-500 to-purple-600",
  },
  {
    name: "Orders",
    href: "/profile/orders",
    icon: ShoppingBag,
    description: "Purchase history",
    color: "from-orange-500 to-amber-600",
  },
  {
    name: "My Bids",
    href: "/profile/bids",
    icon: Gavel,
    description: "Your bids on auction & blind-bid products",
    color: "from-violet-500 to-purple-600",
  },
  {
    name: "Winning Bids",
    href: "/profile/bid-wins",
    icon: Award,
    description: "Pay for products you won",
    color: "from-amber-500 to-yellow-600",
  },
  {
    name: "Connection Hub",
    href: "/profile/course",
    icon: GraduationCap,
    description: "Manage listings",
    color: "from-purple-500 to-indigo-600",
  },
  {
    name: "Enrollments",
    href: "/profile/my-enrollments",
    icon: BookOpen,
    description: "Your enrollments",
    color: "from-purple-500 to-violet-600",
  },
  {
    name: "Events",
    href: "/profile/events",
    icon: Calendar,
    description: "My events",
    color: "from-blue-500 to-cyan-600",
  },
  {
    name: "Sweepstakes entries",
    href: "/profile/raffle-tickets",
    icon: Award,
    description: "My sweepstakes entries",
    color: "from-yellow-500 to-orange-600",
  },
  {
    name: "My Gift Cards",
    href: "/gift-cards/my-cards",
    icon: Gift,
    description: "View, redeem & check balances",
    color: "from-pink-500 to-rose-600",
  },
  {
    name: "Gift BP",
    href: "/gift-bp",
    icon: Gift,
    description: "Send Believe Points or invite by email",
    color: "from-violet-500 to-blue-600",
  },
  {
    name: "My Applications",
    href: "/profile/job-applications",
    icon: Briefcase,
    description: "Job & volunteer applications",
    color: "from-emerald-500 to-teal-600",
  },
  {
    name: "Reward Points",
    href: "/profile/reward-points-ledger",
    icon: Gift,
    description: "Reward points transaction history",
    color: "from-amber-500 to-yellow-600",
  },
  {
    name: "Merchant claims",
    href: "/profile/redemptions",
    icon: QrCode,
    description: "Offers you claimed with reward points",
    color: "from-purple-500 to-pink-600",
  },
  // {
  //   name: "Node Boss",
  //   href: "/nodeboss/shares",
  //   icon: TrendingUp,
  //   description: "Investment shares",
  //   color: "from-indigo-500 to-blue-600",
  // },
  {
    name: "Fractional Ownership",
    href: "/profile/fractional-ownership",
    icon: PieChart,
    description: "My fractional ownership investments",
    color: "from-indigo-500 to-blue-600",
  },
  {
    name: "Transactions",
    href: "/profile/transactions",
    icon: Receipt,
    description: "All transactions",
    color: "from-teal-500 to-cyan-600",
  },
  {
    name: "Billings",
    href: "/profile/billing",
    icon: CreditCard,
    description: "Billing & wallet",
    color: "from-indigo-500 to-purple-600",
  },
  {
    name: "Payment Methods",
    href: "/profile/payment-methods",
    icon: CreditCard,
    description: "Saved cards and bank accounts",
    color: "from-purple-500 to-blue-600",
  },
  {
    name: "AI Video Studio",
    href: "/ai-media-studio",
    icon: Video,
    description: "Short AI videos (OpenAI + fal.ai), queued in the background",
    color: "from-fuchsia-500 to-purple-600",
  },
  {
    name: "Integrations",
    href: route("user.profile.integrations"),
    icon: Youtube,
    description: "YouTube channel connect / disconnect",
    color: "from-red-500 to-red-600",
  },
  {
    name: "Settings",
    href: "/profile/edit",
    icon: Settings,
    description: "Profile and account settings",
    color: "from-slate-500 to-gray-600",
  },
  {
    name: "Security",
    href: "/profile/change-password",
    icon: Shield,
    description: "Password & security",
    color: "from-gray-500 to-slate-600",
  },
]

function isProfileNavActive(href: string, currentPath: string): boolean {
  if (href === "/profile") {
    return currentPath === "/profile"
  }

  return currentPath === href || currentPath.startsWith(`${href}/`)
}

function ProfileHeroHeader({
  user,
  planBadge,
  PlanBadgeIcon,
  isImpersonating,
  showEditProfile,
}: {
  user: PageProps["auth"]["user"]
  planBadge: ReturnType<typeof getSupporterPlanBadge>
  PlanBadgeIcon: ReturnType<typeof getSupporterPlanBadge>["Icon"]
  isImpersonating?: boolean
  showEditProfile: boolean
}) {
  return (
    <div className="relative overflow-hidden bg-gradient-to-r from-purple-600 to-blue-600">
      <div className="absolute inset-0 bg-black/10" />

      <div className="absolute top-0 left-0 h-full w-full overflow-hidden">
        <div className="absolute -top-10 -right-10 h-40 w-40 rounded-full bg-white/10 blur-xl" />
        <div className="absolute top-20 -left-10 h-32 w-32 rounded-full bg-white/5 blur-xl" />
        <div className="absolute bottom-10 right-20 h-24 w-24 rounded-full bg-white/10 blur-xl" />
      </div>

      <div className="relative container mx-auto px-3 py-8 sm:px-4 sm:py-12">
        {isImpersonating && (
          <div className="absolute top-4 right-4 z-10">
            <Button
              variant="destructive"
              size="sm"
              onClick={() => {
                router.post(route("users.stop-impersonate"), {}, {
                  onSuccess: () => {
                    showSuccessToast("Impersonation stopped. You are now logged in as yourself.")
                  },
                  onError: (errors) => {
                    console.error("Error stopping impersonation:", errors)
                  },
                })
              }}
              className="flex items-center gap-2"
            >
              <LogOut className="h-4 w-4" />
              <span className="hidden sm:inline">Exit Impersonation</span>
              <span className="sm:hidden">Exit</span>
            </Button>
          </div>
        )}

        <motion.div
          initial={{ opacity: 0, y: 30 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.8 }}
          className="flex flex-col items-center gap-6 lg:flex-row lg:gap-8"
        >
          <div className="flex w-full min-w-0 flex-1 flex-col items-center gap-6 sm:flex-row sm:items-center">
            <div className="relative">
              <div className="absolute -inset-1 rounded-full bg-gradient-to-r from-white/30 to-white/10 blur-sm" />
              <Avatar className="relative h-24 w-24 border-4 border-white/20 shadow-2xl sm:h-28 sm:w-28">
                <AvatarImage
                  src={resolveStorageUrl(user.image, "/placeholder.svg?height=112&width=112")}
                  alt="Profile"
                />
                <AvatarFallback className="bg-gradient-to-br from-white/20 to-white/10 text-2xl font-bold text-white backdrop-blur-sm">
                  {user.name?.split(" ")[0]?.[0] || "J"}
                  {user.name?.split(" ")[1]?.[0] || "D"}
                </AvatarFallback>
              </Avatar>
              <div className="absolute -bottom-2 -right-2 flex h-8 w-8 items-center justify-center rounded-full border-4 border-white bg-green-500">
                <Check className="h-4 w-4 text-white" />
              </div>
            </div>

            <div className="min-w-0 w-full text-center sm:text-left">
              <div className="mb-3 flex flex-col items-center gap-3 sm:flex-row sm:items-center">
                <h1 className="max-w-full break-words text-2xl font-bold text-white sm:text-3xl lg:text-4xl">
                  {user.name}
                </h1>
                <Badge className={cn("mx-auto w-fit sm:mx-0", planBadge.className)}>
                  <PlanBadgeIcon className="mr-1 h-3 w-3" />
                  {planBadge.label}
                </Badge>
              </div>
              <p className="mb-4 break-all text-base text-white/90 sm:text-lg">{user.email}</p>
              <div className="flex items-center justify-center gap-2 text-white/80 sm:justify-start">
                <Calendar className="h-4 w-4" />
                <span>Member since {user.joined}</span>
              </div>
            </div>
          </div>

          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:gap-3">
            {showEditProfile ? (
              <Link href="/profile/edit" className="w-full sm:w-auto">
                <Button className="w-full border-white/30 bg-white/20 text-white backdrop-blur-sm transition-all duration-300 hover:scale-105 hover:bg-white/30 sm:w-auto">
                  <Edit3 className="mr-2 h-4 w-4" />
                  Edit Profile
                </Button>
              </Link>
            ) : null}
            {user?.slug || user?.id ? (
              <Link href={route("users.show", user.slug || user.id)} className="w-full sm:w-auto">
                <Button className="w-full border-white/30 bg-white/20 text-white backdrop-blur-sm transition-all duration-300 hover:scale-105 hover:bg-white/30 sm:w-auto">
                  <Globe className="mr-2 h-4 w-4" />
                  Public View
                </Button>
              </Link>
            ) : null}
          </div>
        </motion.div>
      </div>
    </div>
  )
}

function ProfileSubPageHeader({
  title,
  description,
  isImpersonating,
  onOpenMenu,
  showMenuButton = false,
  sticky = false,
}: {
  title: string
  description?: string
  isImpersonating?: boolean
  onOpenMenu?: () => void
  showMenuButton?: boolean
  sticky?: boolean
}) {
  return (
    <div
      className={cn(
        "border-b border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900",
        sticky && "sticky top-0 z-20 shadow-sm backdrop-blur-md supports-[backdrop-filter]:bg-white/95 dark:supports-[backdrop-filter]:bg-gray-900/95",
      )}
    >
      <div className="container mx-auto px-3 py-3 sm:px-4 sm:py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <Link
              href="/profile"
              preserveScroll
              preserveState
              className="inline-flex items-center gap-1.5 text-sm font-medium text-purple-600 transition-colors hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300"
            >
              <ArrowLeft className="h-4 w-4 shrink-0" />
              Back
            </Link>
            <h1 className="mt-2 break-words text-xl font-bold text-gray-900 dark:text-white sm:text-2xl">
              {title}
            </h1>
            {description ? (
              <p className="mt-1 text-sm text-gray-600 dark:text-gray-400 sm:text-base">{description}</p>
            ) : null}
          </div>

          <div className="flex shrink-0 items-center gap-2">
            {isImpersonating && (
              <Button
                variant="destructive"
                size="sm"
                onClick={() => {
                  router.post(route("users.stop-impersonate"), {}, {
                    onSuccess: () => {
                      showSuccessToast("Impersonation stopped. You are now logged in as yourself.")
                    },
                    onError: (errors) => {
                      console.error("Error stopping impersonation:", errors)
                    },
                  })
                }}
                className="flex items-center gap-1.5 px-2 sm:gap-2 sm:px-3"
              >
                <LogOut className="h-4 w-4" />
                <span className="sr-only sm:not-sr-only sm:inline">Exit</span>
              </Button>
            )}
            {showMenuButton && onOpenMenu ? (
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={onOpenMenu}
                aria-label="Open profile menu"
                className="h-9 w-9 shrink-0 border-gray-200 dark:border-gray-700"
              >
                <Menu className="h-4 w-4" />
              </Button>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}

function ProfileNavLinks({
  currentPath,
  onNavigate,
  animate = true,
}: {
  currentPath: string
  onNavigate?: () => void
  animate?: boolean
}) {
  return (
    <div className="space-y-1">
      {navigationItems.map((item, index) => {
        const isActive = isProfileNavActive(item.href, currentPath)
        const Icon = item.icon
        const rowClass = `group flex cursor-pointer items-center gap-3 rounded-lg px-4 py-3 transition-all duration-200 ${
          isActive
            ? "border-l-4 border-purple-600 bg-gradient-to-r from-purple-50 to-blue-50 shadow-sm dark:from-purple-900/30 dark:to-blue-900/30"
            : "hover:bg-gray-50 dark:hover:bg-gray-700/50"
        }`

        const inner = (
          <>
            <div className={`rounded-lg bg-gradient-to-br p-2 shadow-sm transition-transform duration-200 group-hover:scale-110 ${item.color}`}>
              <Icon className="h-4 w-4 text-white" />
            </div>
            <div className="min-w-0 flex-1">
              <h3 className={`text-sm font-semibold ${
                isActive ? "text-purple-900 dark:text-purple-100" : "text-gray-900 dark:text-white"
              }`}>
                {item.name}
              </h3>
              <p className={`mt-0.5 truncate text-xs ${
                isActive ? "text-purple-600 dark:text-purple-300" : "text-gray-500 dark:text-gray-400"
              }`}>
                {item.description}
              </p>
            </div>
            {isActive && (
              <div className="h-2 w-2 shrink-0 rounded-full bg-gradient-to-r from-purple-600 to-blue-600" />
            )}
          </>
        )

        return (
          <Link
            key={item.name}
            href={item.href}
            preserveScroll={!item.href.includes("#")}
            preserveState
            onClick={onNavigate}
          >
            {animate ? (
              <motion.div
                initial={{ opacity: 0, x: -10 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ duration: 0.3, delay: index * 0.05 }}
                className={rowClass}
              >
                {inner}
              </motion.div>
            ) : (
              <div className={rowClass}>{inner}</div>
            )}
          </Link>
        )
      })}
    </div>
  )
}

export default function ProfileLayout({ children, title, description }: ProfileLayoutProps) {
  const { auth, isImpersonating, impact_score, impact_breakdown, success, error, supporterSubscription } =
    usePage<
      PageProps & {
        isImpersonating?: boolean
        impact_score?: any
        impact_breakdown?: any
        success?: string
        error?: string
        supporterSubscription?: SupporterSubscriptionState | null
      }
    >().props
  const user = auth.user
  const planBadge = getSupporterPlanBadge(supporterSubscription)
  const PlanBadgeIcon = planBadge.Icon
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    if (typeof success === "string" && success.trim() !== "") {
      showSuccessToast(success)
    }
    if (typeof error === "string" && error.trim() !== "") {
      showErrorToast(error)
    }
  }, [success, error])
    const [showBalance, setShowBalance] = useState(false)
    const [topics, setTopics] = useState<Topic[]>([]);
  const [addFundsAmount, setAddFundsAmount] = useState("")
  const [withdrawAmount, setWithdrawAmount] = useState("")
  const [isAddFundsOpen, setIsAddFundsOpen] = useState(false)
  const [isWithdrawOpen, setIsWithdrawOpen] = useState(false)

    const [isTopicsModalOpen, setIsTopicsModalOpen] = useState(false)
     const [loading, setLoading] = useState(true);

    // Fetch topics on component mount
  useEffect(() => {
    const fetchTopics = async () => {
      try {
        const response = await fetch('/chat/user/topics', {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include' // Important for sessions/cookies
        });

        if (response.ok) {
            const data = await response.json();
          setTopics(data || []);
        } else {
          console.error('Failed to fetch topics');
        }
      } catch (error) {
        console.error('Error fetching topics:', error);
      } finally {
        setLoading(false);
      }
    };

    fetchTopics();
  }, []);

  const handleDeleteTopic = (topicId: number) => {
    if (confirm('Are you sure you want to remove this topic?')) {
      router.delete(`/chat/user/topics/${topicId}`, {
        preserveScroll: true,
          onSuccess: () => {
            setTopics(prevTopics => prevTopics.filter(topic => topic.id !== topicId))
          // Inertia will automatically re-render the page with updated data
        }
      });
    }
  };

  const navigateToChat = (topicId: number, e: React.MouseEvent) => {
    e.stopPropagation(); // Prevent triggering parent events
    router.get(route("chat.index", { topic: topicId }));
  };

  const page = usePage()
  const currentPath = useMemo(() => {
    const raw = typeof page.url === "string" ? page.url : ""
    const path = raw.split("?")[0]
    if (path) return path
    return typeof window !== "undefined" ? window.location.pathname : ""
  }, [page.url])

  const [mobileNavOpen, setMobileNavOpen] = useState(false)

  useEffect(() => {
    setMobileNavOpen(false)
  }, [currentPath])

  const activeNavItem =
    navigationItems.find((item) => isProfileNavActive(item.href, currentPath)) ?? navigationItems[0]
  const ActiveNavIcon = activeNavItem.icon
  const isProfileHome = currentPath === "/profile"

  const handleCopy = () => {
    navigator.clipboard.writeText(user?.referral_link || "")
    setCopied(true)
    setTimeout(() => setCopied(false), 2000)
  }

  const handleAddFunds = () => {
    // Implementation for adding funds
  }

  const handleWithdraw = () => {
    // Implementation for withdrawing funds
  }

  return (
    <FrontendLayout>
      <div className="min-h-screen bg-gradient-to-br from-gray-50 via-white to-gray-100 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900">
        {isProfileHome ? (
          <ProfileHeroHeader
            user={user}
            planBadge={planBadge}
            PlanBadgeIcon={PlanBadgeIcon}
            isImpersonating={isImpersonating}
            showEditProfile={currentPath !== "/profile/edit"}
          />
        ) : (
          <div className="lg:hidden">
            <ProfileSubPageHeader
              title={title}
              description={description}
              isImpersonating={isImpersonating}
              showMenuButton
              sticky
              onOpenMenu={() => setMobileNavOpen(true)}
            />
          </div>
        )}

        <div
          className={cn(
            "relative z-10 container mx-auto px-3 sm:px-4",
            isProfileHome ? "-mt-6 sm:-mt-8" : "pt-4 sm:pt-6",
          )}
        >
          <div className="flex flex-col gap-4 lg:flex-row lg:gap-6">
            {/* Mobile profile menu — home only (sub-pages use header menu button) */}
            {isProfileHome ? (
              <div className="lg:hidden">
                <button
                  type="button"
                  onClick={() => setMobileNavOpen(true)}
                  aria-expanded={mobileNavOpen}
                  aria-controls="profile-mobile-nav"
                  className="flex w-full items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-left shadow-sm transition-colors hover:border-purple-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-purple-500/40"
                >
                  <div className={`shrink-0 rounded-lg bg-gradient-to-br p-2 shadow-sm ${activeNavItem.color}`}>
                    <ActiveNavIcon className="h-4 w-4 text-white" aria-hidden />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium text-gray-500 dark:text-gray-400">Profile menu</p>
                    <p className="truncate text-sm font-semibold text-gray-900 dark:text-white">{activeNavItem.name}</p>
                  </div>
                  <ChevronsUpDown className="h-4 w-4 shrink-0 text-gray-400" aria-hidden />
                </button>
              </div>
            ) : null}

            {/* Desktop: sticky sidebar */}
            <motion.div
              initial={{ opacity: 0, x: -20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.6, delay: 0.2 }}
              className="hidden lg:block lg:w-64 lg:shrink-0"
            >
              <Card className="border-0 bg-white shadow-xl dark:bg-gray-800 lg:sticky lg:top-6">
                <CardContent className="p-4">
                  <ProfileNavLinks currentPath={currentPath} />
                </CardContent>
              </Card>
            </motion.div>

            {/* Main Content Area */}
            <div className="min-w-0 w-full flex-1">
              {/* Stats Cards - Only show on overview page */}
              {isProfileHome && (
                <>
                  <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, delay: 0.2 }}
                    className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8"
                  >
                    <Card className="bg-white dark:bg-gray-800 shadow-xl border-0 hover:shadow-2xl transition-all duration-300 hover:-translate-y-1">
                      <CardContent className="p-6">
                        <div className="flex items-center justify-between">
                          <div>
                            <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Total Donated</p>
                            <p className="text-3xl font-bold text-gray-900 dark:text-white">
                              ${user.total_donated?.toLocaleString() || 0}
                            </p>
                            <p className="text-xs text-green-600 dark:text-green-400 mt-1 flex items-center">
                              <TrendingUp className="w-3 h-3 mr-1" />
                              +12% this month
                            </p>
                          </div>
                          <div className="bg-gradient-to-br from-green-500 to-emerald-600 p-4 rounded-2xl shadow-lg">
                            <Heart className="w-6 h-6 text-white" />
                          </div>
                        </div>
                      </CardContent>
                    </Card>

                    <Card className="bg-white dark:bg-gray-800 shadow-xl border-0 hover:shadow-2xl transition-all duration-300 hover:-translate-y-1">
                      <CardContent className="p-6">
                        <div className="flex items-center justify-between">
                          <div>
                            <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Organizations</p>
                            <p className="text-3xl font-bold text-gray-900 dark:text-white">
                              {user.favorite_organizations_count || 0}
                            </p>
                            <p className="text-xs text-blue-600 dark:text-blue-400 mt-1 flex items-center">
                              <Activity className="w-3 h-3 mr-1" />
                              Following
                            </p>
                          </div>
                          <div className="bg-gradient-to-br from-blue-500 to-indigo-600 p-4 rounded-2xl shadow-lg">
                            <UserCheck className="w-6 h-6 text-white" />
                          </div>
                        </div>
                      </CardContent>
                    </Card>

                    <Card className="bg-white dark:bg-gray-800 shadow-xl border-0 hover:shadow-2xl transition-all duration-300 hover:-translate-y-1">
                      <CardContent className="p-6">
                        <div className="flex items-center justify-between">
                          <div>
                            <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Total Orders</p>
                            <p className="text-3xl font-bold text-gray-900 dark:text-white">{user.total_orders || 0}</p>
                            <p className="text-xs text-orange-600 dark:text-orange-400 mt-1 flex items-center">
                              <ShoppingBag className="w-3 h-3 mr-1" />
                              Completed
                            </p>
                          </div>
                          <div className="bg-gradient-to-br from-orange-500 to-amber-600 p-4 rounded-2xl shadow-lg">
                            <ShoppingBag className="w-6 h-6 text-white" />
                          </div>
                        </div>
                      </CardContent>
                    </Card>

                    <Card className="bg-white dark:bg-gray-800 shadow-xl border-0 hover:shadow-2xl transition-all duration-300 hover:-translate-y-1">
                      <CardContent className="p-6">
                        <div className="flex items-center justify-between">
                          <div>
                            <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Impact Score</p>
                            {impact_score ? (
                              <>
                                <p className="text-3xl font-bold text-gray-900 dark:text-white">
                                  {impact_score.impact_score.toLocaleString('en-US', {
                                    minimumFractionDigits: 1,
                                    maximumFractionDigits: 1,
                                  })}
                                </p>
                                <p className="text-xs text-purple-600 dark:text-purple-400 mt-1 flex items-center">
                                  <Award className="w-3 h-3 mr-1" />
                                  {impact_score.badge?.name || 'Supporter'}
                                </p>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                  {impact_score.total_points.toLocaleString('en-US', {
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0,
                                  })} points this month
                                </p>
                              </>
                            ) : (
                              <>
                                <p className="text-3xl font-bold text-gray-900 dark:text-white">0.0</p>
                                <p className="text-xs text-purple-600 dark:text-purple-400 mt-1 flex items-center">
                                  <Award className="w-3 h-3 mr-1" />
                                  Get Started
                                </p>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                  Start volunteering or donating to earn points
                                </p>
                              </>
                            )}
                          </div>
                          <div className="bg-gradient-to-br from-purple-500 to-violet-600 p-4 rounded-2xl shadow-lg">
                            <Target className="w-6 h-6 text-white" />
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  </motion.div>

                  {/* Referral and Topics Section - Only show on overview page */}
                  <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, delay: 0.3 }}
                    className="mb-8"
                  >
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                      {/* Referral Section */}
                      <Card className="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 border-indigo-200 dark:border-indigo-700 shadow-xl">
                        <CardContent className="p-6">
                          <div className="flex flex-col gap-4">
                            <div>
                              <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-2 flex items-center">
                                <Award className="w-5 h-5 mr-2 text-indigo-600" />
                                Share Your Referral Link
                              </h3>
                              <p className="text-gray-600 dark:text-gray-300 mb-4">
                                Invite friends and earn rewards when they join our community.
                              </p>
                              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <div className="relative min-w-0 flex-1">
                                  <Input
                                    type="text"
                                    value={user?.referral_link || ""}
                                    readOnly
                                    className="w-full border-gray-300 bg-white pr-12 font-mono text-sm dark:border-gray-600 dark:bg-gray-800"
                                  />
                                </div>
                                <Button
                                  onClick={handleCopy}
                                  className="w-full shrink-0 bg-indigo-600 transition-all duration-300 hover:scale-105 hover:bg-indigo-700 sm:w-auto"
                                >
                                  {copied ? (
                                    <>
                                      <Check className="w-4 h-4 mr-2" />
                                      Copied!
                                    </>
                                  ) : (
                                    <>
                                      <Copy className="w-4 h-4 mr-2" />
                                      Copy Link
                                    </>
                                  )}
                                </Button>
                              </div>
                            </div>
                          </div>
                        </CardContent>
                      </Card>

                      {/* Topics Section */}
                      <Card className="bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20 border-emerald-200 dark:border-emerald-700 shadow-xl">
                        <CardContent className="p-6">
                          <div className="flex flex-col gap-4">
                            <div className="flex items-center justify-between">
                              <h3 className="text-xl font-bold text-gray-900 dark:text-white flex items-center">
                                <Text className="w-5 h-5 mr-2 text-emerald-600" />
                                Your Groups Chat
                              </h3>
                              <div className="flex items-center gap-2">
                                <Badge className="bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">
                                  {topics.length} Active
                                </Badge>
                              </div>
                            </div>

                            <div className="grid grid-cols-2 gap-2 mb-4">
                              {topics.slice(0, 4).map((topic) => (
                                <div
                                  key={topic.id}
                                  className="group relative flex items-center gap-2 p-2 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 hover:shadow-md transition-all duration-200"
                                >
                                  <div className={`w-3 h-3 rounded-full ${topic.color}`}></div>
                                  <span className="text-sm font-medium text-gray-700 dark:text-gray-300 truncate flex-1">
                                    {topic.name}
                                  </span>
                                  <div className="flex items-center gap-1">
                                    <button
                                      onClick={(e) => navigateToChat(topic.id, e)}
                                      className="p-1 hover:bg-blue-100 dark:hover:bg-blue-900/20 rounded transition-all duration-200"
                                      title="Go to chat"
                                    >
                                      <MessageCircle className="w-3 h-3 text-blue-500" />
                                    </button>
                                    <button
                                      onClick={() => handleDeleteTopic(topic.id)}
                                      className="opacity-0 group-hover:opacity-100 p-1 hover:bg-red-100 dark:hover:bg-red-900/20 rounded transition-all duration-200"
                                    >
                                      <X className="w-3 h-3 text-red-500" />
                                    </button>
                                  </div>
                                </div>
                              ))}
                            </div>

                            {topics.length > 4 && (
                              <Button
                                onClick={() => setIsTopicsModalOpen(true)}
                                variant="outline"
                                className="w-full border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-600 dark:text-emerald-300 dark:hover:bg-emerald-900/20 transition-all duration-300"
                              >
                                View {topics.length - 4} more topics
                              </Button>
                            )}
                          </div>
                        </CardContent>
                      </Card>
                    </div>
                  </motion.div>
                </>
              )}

              {/* Page Content */}
              <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.6, delay: 0.4 }}
                className="pb-6 sm:pb-8"
              >
                <Card
                  className={cn(
                    "overflow-hidden border-0 bg-white dark:bg-gray-800",
                    isProfileHome ? "shadow-xl" : "shadow-none lg:shadow-xl",
                  )}
                >
                  <CardContent className="p-3 sm:p-6">
                    {isProfileHome ? (
                      <div className="mb-4 sm:mb-6">
                        <h2 className="mb-2 break-words text-xl font-bold text-gray-900 dark:text-white sm:mb-3 sm:text-2xl lg:text-3xl">
                          {title}
                        </h2>
                        {description ? (
                          <p className="text-sm text-gray-600 dark:text-gray-300 sm:text-base lg:text-lg">
                            {description}
                          </p>
                        ) : null}
                      </div>
                    ) : (
                      <div className="mb-4 hidden sm:mb-6 lg:block">
                        <Link
                          href="/profile"
                          preserveScroll
                          preserveState
                          className="inline-flex items-center gap-1.5 text-sm font-medium text-purple-600 transition-colors hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300"
                        >
                          <ArrowLeft className="h-4 w-4 shrink-0" />
                          Back
                        </Link>
                        <h2 className="mt-2 break-words text-xl font-bold text-gray-900 dark:text-white sm:text-2xl">
                          {title}
                        </h2>
                        {description ? (
                          <p className="mt-1 text-sm text-gray-600 dark:text-gray-400 sm:text-base">
                            {description}
                          </p>
                        ) : null}
                      </div>
                    )}
                    <div className="min-w-0">{children}</div>
                  </CardContent>
                </Card>
              </motion.div>
            </div>
          </div>
        </div>

        {/* Add Funds Dialog */}
        <Dialog open={isAddFundsOpen} onOpenChange={setIsAddFundsOpen}>
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Plus className="w-5 h-5 text-green-600" />
                Add Funds to Wallet
              </DialogTitle>
            </DialogHeader>
            <div className="space-y-4">
              <div>
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                <Input
                  type="number"
                  placeholder="Enter amount"
                  value={addFundsAmount}
                  onChange={(e) => setAddFundsAmount(e.target.value)}
                  className="mt-1"
                />
              </div>
              <div className="flex gap-3">
                <Button onClick={handleAddFunds} className="flex-1 bg-green-600 hover:bg-green-700">
                  Add Funds
                </Button>
                <Button variant="outline" onClick={() => setIsAddFundsOpen(false)}>
                  Cancel
                </Button>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        {/* Withdraw Dialog */}
        <Dialog open={isWithdrawOpen} onOpenChange={setIsWithdrawOpen}>
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Minus className="w-5 h-5 text-red-600" />
                Withdraw from Wallet
              </DialogTitle>
            </DialogHeader>
            <div className="space-y-4">
              <div>
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                <Input
                  type="number"
                  placeholder="Enter amount"
                  value={withdrawAmount}
                  onChange={(e) => setWithdrawAmount(e.target.value)}
                  max={user.wallet_balance || 0}
                  className="mt-1"
                />
                <p className="text-xs text-gray-500 mt-1">Available balance: ${user.wallet_balance || 0}</p>
              </div>
              <div className="flex gap-3">
                <Button onClick={handleWithdraw} className="flex-1 bg-red-600 hover:bg-red-700">
                  Withdraw
                </Button>
                <Button variant="outline" onClick={() => setIsWithdrawOpen(false)}>
                  Cancel
                </Button>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        {/* Topics Modal */}
        <Dialog open={isTopicsModalOpen} onOpenChange={setIsTopicsModalOpen}>
          <DialogContent className="sm:max-w-2xl max-h-[80vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2 text-xl">
                <Tag className="w-5 h-5 text-emerald-600" />
                Your Groups Chat ({topics.length})
              </DialogTitle>
            </DialogHeader>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
              {topics.map((topic) => (
                <motion.div
                  key={topic.id}
                  initial={{ opacity: 0, scale: 0.9 }}
                  animate={{ opacity: 1, scale: 1 }}
                  exit={{ opacity: 0, scale: 0.9 }}
                  className="group relative flex items-center gap-3 p-4 bg-gradient-to-r from-gray-50 to-white dark:from-gray-800 dark:to-gray-700 rounded-xl border border-gray-200 dark:border-gray-600 hover:shadow-lg transition-all duration-300 hover:-translate-y-1"
                >
                  <div className={`w-4 h-4 rounded-full ${topic.color} shadow-lg`}></div>
                  <span className="font-medium text-gray-800 dark:text-gray-200 flex-1">{topic.name}</span>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={(e) => navigateToChat(topic.id, e)}
                      className="p-2 hover:bg-blue-100 dark:hover:bg-blue-900/20 rounded-lg transition-all duration-200 hover:scale-110"
                      title="Go to chat"
                    >
                      <MessageCircle className="w-4 h-4 text-blue-500" />
                    </button>
                    <button
                      onClick={() => handleDeleteTopic(topic.id)}
                      className="opacity-0 group-hover:opacity-100 p-2 hover:bg-red-100 dark:hover:bg-red-900/20 rounded-lg transition-all duration-200 hover:scale-110"
                    >
                      <X className="w-4 h-4 text-red-500" />
                    </button>
                  </div>
                </motion.div>
              ))}
            </div>
            {topics.length === 0 && (
              <div className="text-center py-8">
                <Tag className="w-12 h-12 text-gray-400 mx-auto mb-3" />
                <p className="text-gray-500 dark:text-gray-400">No topics selected yet.</p>
              </div>
            )}
          </DialogContent>
        </Dialog>

        <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
          <SheetContent
            id="profile-mobile-nav"
            side="left"
            className="flex w-[min(100vw-1rem,20rem)] max-w-xs flex-col gap-0 p-0 sm:max-w-sm"
          >
            <SheetHeader className="border-b border-gray-200 px-4 py-4 text-left dark:border-gray-700">
              <SheetTitle className="text-base font-semibold text-gray-900 dark:text-white">
                Profile sections
              </SheetTitle>
            </SheetHeader>
            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 py-3 pb-[calc(1rem+env(safe-area-inset-bottom))]">
              <ProfileNavLinks
                currentPath={currentPath}
                animate={false}
                onNavigate={() => setMobileNavOpen(false)}
              />
            </div>
          </SheetContent>
        </Sheet>
      </div>
    </FrontendLayout>
  )
}

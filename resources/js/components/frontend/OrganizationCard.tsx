"use client"

import type React from "react"
import { useState } from "react"
import { createPortal } from "react-dom"
import toast from "react-hot-toast"

import { MapPin, Star, ArrowRight, Heart, UserCheck, UserPlus, Building2, Target } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Badge } from "@/components/frontend/ui/badge"
import { motion } from "framer-motion"
import { Link, router, usePage } from "@inertiajs/react"
import SignInPopup from "@/components/frontend/SignInPopup"
import { EinVerifiedBadge, isEinVerifiedOrg } from "@/components/frontend/organization/EinVerifiedBadge"

interface Organization {
  id: number
  ein: string
  name: string
  ico?: string
  street: string
  city: string
  state: string
  zip: string
    ntee_code?: string
    ntee_category_description?: string
  classification?: string
  created_at: string
  is_favorited?: boolean
  is_registered?: boolean // Add is_registered field
  has_ein?: boolean
  ein_verified?: boolean
}

interface OrganizationCardProps {
  organization: Organization
  index?: number
  showRating?: boolean
  rating?: number
  linkUrl?: string
  customButton?: React.ReactNode
  showFavorite?: boolean
}

export default function OrganizationCard({
  organization,
  index = 0,
  showRating = false,
  rating = 4.8,
  linkUrl,
  customButton,
  showFavorite = true,
}: OrganizationCardProps) {
    const {auth} = usePage<PageProps>().props
  const defaultLinkUrl = linkUrl || `/organizations/${organization.id}`
  const [isFavorited, setIsFavorited] = useState(organization.is_favorited || false)
  const [isLoading, setIsLoading] = useState(false)
  const [showSignInPopup, setShowSignInPopup] = useState(false)

  // Check if user is authenticated
  const isAuthenticated = auth?.user !== null && auth?.user !== undefined
  // Do not show follow for own organization (org user viewing their org card)
  const isOwnOrganization = !!(auth?.user?.organization?.ein && organization.ein && auth.user.organization.ein === organization.ein)

  // Handle Learn More button click
  const handleLearnMore = (e: React.MouseEvent) => {
    e.preventDefault()

    // If user is not authenticated, show sign-in popup
    if (!isAuthenticated) {
      setShowSignInPopup(true)
      return
    }

    // If authenticated, navigate to organization page
    router.visit(defaultLinkUrl)
  }

  const handleToggleFavorite = async (e: React.MouseEvent) => {
    e.preventDefault()
    e.stopPropagation()

    if (isLoading) return

    if (!organization.is_registered) {
      return // Don't allow favoriting unregistered organizations
    }

    setIsLoading(true)
    const newFavoriteState = !isFavorited

    // Optimistic UI update
    setIsFavorited(newFavoriteState)

    try {
      router.post(
        route("organizations.toggle-favorite", organization.id),
        { toggle_favorite_context: 'organization' },
        {
          preserveScroll: true,
          preserveState: false,
          onSuccess: page => {
            const flash = (page.props as { flash?: { error?: string } }).flash
            const err = flash?.error ?? (page.props as { error?: string }).error
            if (err) {
              toast.error(err)
              setIsFavorited(!newFavoriteState)
              return
            }
            setIsFavorited(newFavoriteState)
          },
          onError: () => {
            toast.error('Following is for supporter accounts only. Please log in with your personal (supporter) account to follow organizations.')
            setIsFavorited(!newFavoriteState)
          },
          onFinish: () => {
            setIsLoading(false)
          },
        },
      )
    } catch (error) {
      setIsFavorited(!newFavoriteState)
      setIsLoading(false)
    }
  }

  // Generate a gradient based on organization ID for consistent colors
  const gradientColors = [
    "from-blue-500 to-purple-600",
    "from-green-500 to-teal-600",
    "from-amber-500 to-orange-600",
    "from-rose-500 to-pink-600",
    "from-indigo-500 to-blue-600",
    "from-emerald-500 to-green-600"
  ]
  const gradientClass = gradientColors[organization.id % gradientColors.length]

  return (
    <motion.div
      initial={{ opacity: 0, y: 30 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.6, delay: index * 0.1 }}
      whileHover={{ y: -8, scale: 1.02 }}
      className="group"
    >
      <Card className="h-full border-0 shadow-xl hover:shadow-2xl transition-all duration-500 bg-white dark:bg-gray-800 overflow-hidden relative">
        {/* Header with gradient background */}
        <div className={`bg-gradient-to-r ${gradientClass} p-6 text-white relative overflow-hidden`}>
          <div className="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -translate-y-16 translate-x-16"></div>
          <div className="absolute bottom-0 left-0 w-24 h-24 bg-white/10 rounded-full -translate-x-12 translate-y-12"></div>

          <div className="relative z-10">
            <div className="flex items-start justify-between mb-4">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-white/20 rounded-lg backdrop-blur-sm">
                  <Building2 className="h-6 w-6" />
                </div>
                <div>
                  <Badge variant="secondary" className="bg-white/20 text-white backdrop-blur-sm border-0 font-medium">
                    {organization.ntee_code === ' - ' || !organization.ntee_code
                      ? "Non-Profit"
                      : `${organization.ntee_code}`
                    }
                  </Badge>
                  <div className="flex flex-wrap items-center gap-2 mt-2">
                    <div className="flex items-center gap-2">
                      <div className={`w-2 h-2 rounded-full ${organization.is_registered ? 'bg-green-300' : 'bg-amber-300'}`}></div>
                      <span className="text-sm font-medium text-white/90">
                        {organization.is_registered ? "Registered" : "Listed"}
                      </span>
                    </div>
                    <EinVerifiedBadge verified={isEinVerifiedOrg(organization)} size="sm" className="bg-white/20 hover:bg-white/20" />
                  </div>
                </div>
              </div>

              {showFavorite && organization.is_registered && !isOwnOrganization && (
                <div className="cursor-pointer">
                  <button
                    onClick={handleToggleFavorite}
                    disabled={isLoading}
                    className={`bg-white/20 rounded-lg p-2 backdrop-blur-sm transition-all duration-200 ${
                      isFavorited ? "text-white bg-white/30" : "text-white/80 hover:bg-white/30"
                    } ${isLoading ? "opacity-50 cursor-not-allowed" : ""}`}
                  >
                    {isFavorited ? (
                      <UserCheck className="h-5 w-5" />
                    ) : (
                      <UserPlus className="h-5 w-5" />
                    )}
                  </button>
                </div>
              )}
            </div>

            <CardTitle className="text-xl font-bold text-white line-clamp-2 mb-2">
              {organization.name}
            </CardTitle>
            <CardDescription className="text-white/80 line-clamp-2 leading-relaxed">
              {organization.classification || "Non-profit organization serving the community"}
            </CardDescription>
          </div>
        </div>

        <CardContent className="p-6">
          {/* Location and Rating */}
          <div className="flex items-center justify-between mb-6">
            <div className="flex items-center gap-2 bg-gray-100 dark:bg-gray-700 px-3 py-2 rounded-lg">
              <MapPin className="h-4 w-4 text-blue-500" />
              <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                {organization.city}, {organization.state}
              </span>
            </div>
            {showRating && (
              <div className="flex items-center gap-1 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 rounded-lg">
                <Star className="h-4 w-4 text-amber-400 fill-current" />
                <span className="text-sm font-semibold text-amber-700 dark:text-amber-300">{rating}</span>
              </div>
            )}
          </div>

          {/* Category and Details */}
          <div className="space-y-4 mb-6">
            <div className="flex items-center gap-3">
              <Target className="h-4 w-4 text-gray-400" />
              <div>
                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Category</span>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  {organization.ntee_code === ' - ' || !organization.ntee_code
                    ? "Not Available"
                    : organization.ntee_code
                  }
                </p>
                          </div>
                {organization.ntee_category_description && (
                <div className="ml-6">
                  <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Category Description</span>
                  <p className="text-sm text-gray-600 dark:text-gray-400">
                    {organization.ntee_category_description}
                  </p>
                </div>
              )}
            </div>

            <div className="flex items-center gap-3">
              <Building2 className="h-4 w-4 text-gray-400" />
              <div>
                <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Status</span>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  {organization.is_registered ? "Fully Registered Organization" : "Pending Registration"}
                </p>
              </div>
            </div>
          </div>

          {/* Action Button */}
          {customButton ? (
            customButton
                  ) : (
         <div className="flex gap-3">
  {/* Learn More Button */}
  <Button
    onClick={handleLearnMore}
    className="flex-1 w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 font-semibold py-3 text-base shadow-lg hover:shadow-xl transition-all duration-300 group/btn"
  >
    <span>Learn More</span>
    <ArrowRight className="ml-2 h-4 w-4 group-hover/btn:translate-x-1 transition-transform" />
  </Button>

  {/* Claim Ownership Button - শুধু যদি unregistered হয় */}
  {!organization.is_registered && !auth.user && (
    <Link
      href={route('register.organization')}
      className="flex-1"
    >
      <Button
        variant="outline"
        className="w-full border-2 border-amber-500 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 font-semibold py-3 text-base shadow-md hover:shadow-lg transition-all duration-300"
      >
        <Building2 className="mr-2 h-4 w-4" />
        Claim Ownership
      </Button>
    </Link>
  )}
</div>
          )}
        </CardContent>

        {/* Subtle border for better separation in light mode */}
        <div className="absolute inset-0 border border-gray-100 dark:border-gray-700 pointer-events-none rounded-lg"></div>
      </Card>

      {/* Sign In Popup - Rendered via portal to document body */}
      {typeof window !== 'undefined' && createPortal(
        <SignInPopup
          isOpen={showSignInPopup}
          onClose={() => setShowSignInPopup(false)}
          onSignIn={() => setShowSignInPopup(false)}
        />,
        document.body
      )}
    </motion.div>
  )
}

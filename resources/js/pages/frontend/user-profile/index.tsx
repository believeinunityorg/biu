"use client"

import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import { PageHead } from "@/components/frontend/PageHead"
import { Mail, Phone, MapPin } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { PointsBalanceSummary } from "@/components/frontend/layout/points-balance-summary"
import { usePage } from "@inertiajs/react"

interface User {
  id: number
  first_name: string
  last_name: string
  email: string
  phone?: string
  location?: string
  bio?: string
  avatar?: string
  created_at: string
}

interface Donation {
  id: number
  organization_name: string
  amount: number
  created_at: string
  impact?: string
}

interface ImpactScore {
  total_points: number
  impact_score: number
  volunteer_points: number
  donation_points: number
  follow_points: number
  bonus_points: number
  badge: {
    name: string
    level: number
    emoji: string
    color: string
  }
  period: string
}

interface ImpactBreakdown {
  volunteer: { points: number; count: number }
  donation: { points: number; count: number }
  follow: { points: number; count: number }
  bonus: { points: number; count: number }
}

interface PageProps {
  auth: {
    user: User & {
      reward_points?: number
      available_reward_points?: number
      processing_reward_points?: number
      reward_points_total?: number
      believe_points?: number
      processing_believe_points?: number
      processing_believe_points_batches?: Array<{ amount: number; available_on: string | null }>
      processing_believe_points_release_at?: string | null
      gifted_believe_points?: number
      holding_believe_points?: number
    }
  }
  recentDonations: Donation[]
  reward_points: number
  impact_score?: ImpactScore
  impact_breakdown?: ImpactBreakdown
}

export default function ProfileIndex() {
  const { auth, recentDonations, reward_points, impact_score, impact_breakdown } = usePage<PageProps>().props
  const user = auth.user

  const formatDate = (dateString: string | null) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  }

  return (
    <ProfileLayout title="Profile Overview" description="Your account information and recent activity">
      <PageHead title="My Profile" description="View your profile, impact score, and recent activity. Manage your account and donations." />
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
        {/* Personal Information */}
        <Card className="bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700">
          <CardHeader className="pb-4">
            <CardTitle className="text-gray-900 dark:text-white text-lg sm:text-xl">Personal Information</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-3">
              <Mail className="h-4 w-4 text-gray-400" />
              <span className="text-gray-900 dark:text-white break-all text-sm sm:text-base">{user.email}</span>
            </div>
            {user.phone && (
              <div className="flex items-center gap-3">
                <Phone className="h-4 w-4 text-gray-400" />
                <span className="text-gray-900 dark:text-white text-sm sm:text-base">{user.phone}</span>
              </div>
            )}
            {user.location && (
              <div className="flex items-center gap-3">
                <MapPin className="h-4 w-4 text-gray-400" />
                <span className="text-gray-900 dark:text-white text-sm sm:text-base">{user.location}</span>
              </div>
            )}
            {user.bio && (
              <div className="pt-2 border-t border-gray-200 dark:border-gray-600">
                <p className="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">{user.bio}</p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Points Display */}
        {(user.reward_points !== undefined ||
          user.available_reward_points !== undefined ||
          user.processing_reward_points !== undefined ||
          user.believe_points !== undefined) && (
          <Card className="bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700">
            <CardHeader className="pb-4">
              <CardTitle className="text-gray-900 dark:text-white text-lg sm:text-xl">Points</CardTitle>
            </CardHeader>
            <CardContent>
              <PointsBalanceSummary user={user} />
            </CardContent>
          </Card>
        )}

        {/* Recent Activity */}
        <Card className="bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 lg:col-span-2">
          <CardHeader className="pb-4">
            <CardTitle className="text-gray-900 dark:text-white text-lg sm:text-xl">Recent Activity</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {recentDonations && recentDonations.length > 0 ? (
                recentDonations.slice(0, 3).map((donation) => (
                  <div
                    key={donation.id}
                    className="flex items-center gap-3 sm:gap-4 p-3 rounded-lg bg-gray-50 dark:bg-gray-700"
                  >
                    <div className="w-2 h-2 bg-green-500 rounded-full flex-shrink-0"></div>
                    <div className="flex-1 min-w-0">
                      <h4 className="font-medium text-gray-900 dark:text-white text-sm sm:text-base truncate">
                        {donation.organization_name}
                      </h4>
                      <p className="text-xs sm:text-sm text-gray-600 dark:text-gray-300">
                        Donated ${donation.amount} on {new Date(donation.created_at).toLocaleDateString()}
                      </p>
                      {donation.impact && (
                        <p className="text-xs text-green-600 dark:text-green-400">{donation.impact}</p>
                      )}
                    </div>
                  </div>
                ))
              ) : (
                <div className="text-center py-8">
                  <p className="text-gray-600 dark:text-gray-300">No recent activity</p>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>

    </ProfileLayout>
  )
}

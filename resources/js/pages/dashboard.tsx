"use client"

import React from "react"
import { useState, useEffect, useRef } from "react"
import AppLayout from "@/layouts/app-layout"
import type { BreadcrumbItem } from "@/types"
import {
  Activity,
  Building,
  Calendar,
  Check,
  DollarSign,
  ExternalLink,
  FileText,
  Globe,
  Heart,
  Mail,
  Inbox,
  MapPin,
  Phone,
  User,
  UserCheck,
  AlertTriangle,
  Users,
  FileCheck,
  ClipboardList,
  Shield,
  TrendingUp,
  Eye,
  ArrowRight,
  CheckCircle,
  Clock,
  AlertCircle,
  XCircle,
  Wallet,
  Megaphone,
  Sparkles,
  Landmark,
} from "lucide-react"
import PromotionalBanner from "@/components/PromotionalBanner"
import MarketplaceSavingsHighlight from "@/components/frontend/MarketplaceSavingsHighlight"
import ProfileCompletionBanner from "@/components/ProfileCompletionBanner"
import { CareAllianceOrgInvitesInline } from "@/components/CareAllianceOrgInvitesInline"
import { WalletPopup } from "@/components/WalletPopup"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import TopicsCard from "@/components/admin/topics-card"
import { router, usePage, Link } from "@inertiajs/react"
import { ChartContainer, ChartTooltip, ChartTooltipContent } from "@/components/frontend/ui/chart"
import { Area, AreaChart, XAxis } from "recharts"
import { pickWalletBalance } from "@/lib/wallet-balance-fetch"

const showSuccessToast = (message: string) => {
  console.log("Success:", message)
}

const showErrorToast = (message: string) => {
  console.log("Error:", message)
}

const VerificationBanner = ({ user }: { user: any }) => {
  if (!user) return null
  return (
    <div className="mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 rounded-md">
      <p className="text-sm text-blue-700 dark:text-blue-300">Account verified</p>
    </div>
  )
}

// Global route helper (provided by Laravel/Inertia)
declare global {
  function route(name: string, params?: any): string
}

const Head = ({ title }: { title: string }) => {
  React.useEffect(() => {
    document.title = title
  }, [title])
  return null
}


const breadcrumbs: BreadcrumbItem[] = [
  {
    title: "Dashboard",
    href: "/dashboard",
  },
]

function formatUsdFromCents(cents: number): string {
  return new Intl.NumberFormat(undefined, { style: "currency", currency: "USD" }).format(cents / 100)
}

function formatUsd(dollars: number): string {
  return new Intl.NumberFormat(undefined, { style: "currency", currency: "USD" }).format(dollars)
}

type StatCardProps = {
  title: string
  value: string | number
  change?: number
  icon: React.ReactNode
}

const StatCard = ({ title, value, change, icon }: StatCardProps) => {
  return (
    <div className="bg-card border-border rounded-lg border p-6 shadow-sm">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-muted-foreground text-sm font-medium">{title}</p>
          <h3 className="text-2xl font-bold">{value}</h3>
          {change !== undefined && (
            <p className={`text-sm ${change >= 0 ? "text-green-500" : "text-red-500"}`}>
              {change >= 0 ? "↑" : "↓"} {Math.abs(change)}% from last month
            </p>
          )}
        </div>
        <div className="bg-primary/15 rounded-lg p-3 text-primary">{icon}</div>
      </div>
    </div>
  )
}

type ActivityItem = {
  id: number
  type: "donation" | "event" | "volunteer" | "other"
  title: string
  description: string
  date: string
  user?: string
}

const RecentActivityItem = ({ activity }: { activity: ActivityItem }) => {
  const getIcon = () => {
    switch (activity.type) {
      case "donation":
        return <DollarSign className="h-5 w-5 text-green-500" />
      case "event":
        return <Calendar className="h-5 w-5 text-blue-500" />
      case "volunteer":
        return <User className="h-5 w-5 text-purple-500" />
      default:
        return <Activity className="h-5 w-5 text-gray-500" />
    }
  }

  return (
    <div className="flex items-start gap-4 py-3">
      <div className="mt-1">{getIcon()}</div>
      <div className="flex-1">
        <h4 className="font-medium">{activity.title}</h4>
        <p className="text-muted-foreground text-sm">{activity.description}</p>
        {activity.user && <p className="text-muted-foreground text-xs">By {activity.user}</p>}
      </div>
      <div className="text-muted-foreground text-xs">{activity.date}</div>
    </div>
  )
}

// Admin Dashboard Component
const AdminDashboard = ({
  stats,
  recentForm1023Applications,
  recentOrganizations,
  paymentStats,
  promotionalBanner,
  promotionalBanners,
  recentTransactions = [],
  monthlyRevenue = [],
  phazeBalanceSummary,
}: AdminDashboardProps) => {
  const [isDark, setIsDark] = React.useState(false)

  React.useEffect(() => {
    const checkTheme = () => {
      setIsDark(document.documentElement.classList.contains('dark'))
    }

    checkTheme()
    const observer = new MutationObserver(checkTheme)
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class']
    })

    return () => observer.disconnect()
  }, [])

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount)
  }

  const formatDate = (dateString: string | null) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    })
  }

  // Theme-aware colors
  const chartColor = isDark ? 'hsl(264, 70%, 58%)' : 'hsl(25, 95%, 53%)'
  const tickColor = isDark ? 'hsl(0, 0%, 70.8%)' : 'hsl(0, 0%, 55.6%)' // muted-foreground colors

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { label: string; className: string }> = {
      draft: { label: 'Draft', className: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
      pending_payment: { label: 'Payment Pending', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
      awaiting_review: { label: 'Awaiting Review', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
      needs_more_info: { label: 'Needs Info', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
      approved: { label: 'Approved', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300' },
      declined: { label: 'Declined', className: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' },
      pending: { label: 'Pending', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' },
      under_review: { label: 'Under Review', className: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' },
    }
    const config = statusConfig[status] || { label: status, className: 'bg-gray-100 text-gray-700' }
    return <Badge className={config.className}>{config.label}</Badge>
  }

  const getPaymentStatusBadge = (status: string) => {
    if (status === 'paid') {
      return <Badge className="bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">Paid</Badge>
    }
    return <Badge className="bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300">Pending</Badge>
  }

  const getRegistrationStatusBadge = (status: string) => {
    if (status === 'approved') {
      return <Badge className="bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">Approved</Badge>
    }
    return <Badge className="bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300">Pending</Badge>
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Admin Dashboard" />
      <div className="m-3 md:m-6 space-y-6">
        {/* Promotional Banner - Only shown for organization users, not admins */}
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-3xl font-bold mb-2">Admin Dashboard</h1>
          <p className="text-muted-foreground">Overview of system statistics and recent activity</p>
        </div>

        {/* Statistics Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card className="border-l-4 border-l-blue-500">
            <CardContent className="p-3">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Total Organizations</p>
                  <p className="text-3xl font-bold mt-2">{stats?.totalOrganizations || 0}</p>
                  <p className="text-xs text-muted-foreground mt-1">
                    {stats?.approvedOrganizations || 0} approved
                  </p>
                </div>
                <div className="bg-blue-500/10 rounded-lg p-3">
                  <Building className="h-6 w-6 text-blue-500" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border-l-4 border-l-emerald-500">
            <CardContent className="p-3">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Total Users</p>
                  <p className="text-3xl font-bold mt-2">{stats?.totalUsers || 0}</p>
                </div>
                <div className="bg-emerald-500/10 rounded-lg p-3">
                  <Users className="h-6 w-6 text-emerald-500" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border-l-4 border-l-purple-500">
            <CardContent className="p-3">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Form 1023 Applications</p>
                  <p className="text-3xl font-bold mt-2">{stats?.totalForm1023Applications || 0}</p>
                  <p className="text-xs text-muted-foreground mt-1">
                    {stats?.pendingForm1023Applications || 0} pending
                  </p>
                </div>
                <div className="bg-purple-500/10 rounded-lg p-3">
                  <FileCheck className="h-6 w-6 text-purple-500" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border-l-4 border-l-amber-500">
            <CardContent className="p-3">
              <div className="flex items-center justify-between mb-2">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Total Revenue</p>
                  <p className="text-3xl font-bold mt-2">{formatCurrency(paymentStats?.totalRevenue || 0)}</p>
                  <p className="text-xs text-muted-foreground mt-1">
                    {paymentStats?.paidApplications || 0} paid
                  </p>
                </div>
                <div className="bg-amber-500/10 rounded-lg p-3">
                  <DollarSign className="h-6 w-6 text-amber-500" />
                </div>
              </div>
              {monthlyRevenue && monthlyRevenue.length > 0 && (
                <div className="h-12 -mx-3 -mb-2">
                  <ChartContainer
                    config={{
                      revenue: {
                        label: "Revenue",
                        theme: {
                          light: "hsl(25, 95%, 53%)",
                          dark: "hsl(264, 70%, 58%)",
                        },
                      },
                    }}
                    className="h-full w-full [&>div]:!aspect-auto"
                  >
                    <AreaChart data={monthlyRevenue} margin={{ left: 2, right: 2, top: 0, bottom: 0 }}>
                      <defs>
                        <linearGradient id={`fillRevenue-${isDark ? 'dark' : 'light'}`} x1="0" y1="0" x2="0" y2="1">
                          <stop offset="5%" stopColor={chartColor} stopOpacity={0.3} />
                          <stop offset="95%" stopColor={chartColor} stopOpacity={0} />
                        </linearGradient>
                      </defs>
                      <XAxis
                        dataKey="monthShort"
                        tickLine={false}
                        axisLine={false}
                        tickMargin={2}
                        interval={0}
                        angle={0}
                        tick={{
                          fontSize: 9,
                          fill: tickColor
                        }}
                      />
                      <ChartTooltip
                        content={
                          <ChartTooltipContent
                            formatter={(value) => formatCurrency(Number(value))}
                          />
                        }
                      />
                      <Area
                        type="monotone"
                        dataKey="revenue"
                        stroke={chartColor}
                        strokeWidth={1.5}
                        fill={`url(#fillRevenue-${isDark ? 'dark' : 'light'})`}
                      />
                    </AreaChart>
                  </ChartContainer>
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {phazeBalanceSummary ? (
          <Card className="border-l-4 border-l-emerald-500">
            <CardHeader className="pb-3">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <CardTitle className="flex items-center gap-2 text-lg">
                    <Landmark className="h-5 w-5 text-emerald-600" />
                    Phaze prefunded balance
                  </CardTitle>
                  <p className="text-sm text-muted-foreground mt-1">
                    Internal balance controls purchases. Live Phaze balance shown for reconciliation.
                  </p>
                </div>
                <Button variant="outline" size="sm" asChild>
                  <Link href="/admin/phaze-balance">Manage balance</Link>
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground">Internal available</p>
                  <p className="text-2xl font-bold">{formatCurrency(phazeBalanceSummary.available_balance)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Total funds added</p>
                  <p className="text-2xl font-bold">{formatCurrency(phazeBalanceSummary.total_funded)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Total consumed</p>
                  <p className="text-2xl font-bold">{formatCurrency(phazeBalanceSummary.total_consumed)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Remaining</p>
                  <p className="text-2xl font-bold">{formatCurrency(phazeBalanceSummary.remaining_balance)}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Live Phaze (read-only)</p>
                  <p className="text-2xl font-bold">
                    {phazeBalanceSummary.phaze_live?.available != null
                      ? formatCurrency(phazeBalanceSummary.phaze_live.available)
                      : "—"}
                  </p>
                  {phazeBalanceSummary.phaze_live?.variance != null &&
                  Math.abs(phazeBalanceSummary.phaze_live.variance) >= 0.01 ? (
                    <Badge variant="destructive" className="mt-1">
                      Variance {formatCurrency(phazeBalanceSummary.phaze_live.variance)}
                    </Badge>
                  ) : null}
                </div>
              </div>
            </CardContent>
          </Card>
        ) : null}

        {/* Secondary Stats */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Pending Organizations</p>
                  <p className="text-2xl font-bold">{stats?.pendingOrganizations || 0}</p>
                </div>
                <Clock className="h-5 w-5 text-amber-500" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Approved Applications</p>
                  <p className="text-2xl font-bold">{stats?.approvedForm1023Applications || 0}</p>
                </div>
                <CheckCircle className="h-5 w-5 text-emerald-500" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Compliance Applications</p>
                  <p className="text-2xl font-bold">{stats?.totalComplianceApplications || 0}</p>
                </div>
                <ClipboardList className="h-5 w-5 text-blue-500" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Pending Payments</p>
                  <p className="text-2xl font-bold">{formatCurrency(paymentStats?.pendingPayments || 0)}</p>
                </div>
                <Clock className="h-5 w-5 text-amber-500" />
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Recent Transactions Table */}
        <Card>
          <CardHeader>
            <CardTitle>Recent Transactions</CardTitle>
          </CardHeader>
          <CardContent>
            {recentTransactions.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-8">No transactions yet</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b">
                      <th className="text-left py-3 px-4 text-sm font-medium text-muted-foreground">Date</th>
                      <th className="text-left py-3 px-4 text-sm font-medium text-muted-foreground">Type</th>
                      <th className="text-left py-3 px-4 text-sm font-medium text-muted-foreground">Description</th>
                      <th className="text-left py-3 px-4 text-sm font-medium text-muted-foreground">User</th>
                      <th className="text-right py-3 px-4 text-sm font-medium text-muted-foreground">Amount</th>
                      <th className="text-center py-3 px-4 text-sm font-medium text-muted-foreground">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentTransactions.map((transaction) => (
                      <tr key={transaction.id} className="border-b hover:bg-muted/50 transition-colors">
                        <td className="py-3 px-4 text-sm">
                          {formatDate(transaction.date)}
                        </td>
                        <td className="py-3 px-4">
                          <Badge className={
                            transaction.type === 'Fractional Ownership'
                              ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300'
                              : transaction.type === 'Form 1023'
                              ? 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300'
                              : 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300'
                          }>
                            {transaction.type}
                          </Badge>
                        </td>
                        <td className="py-3 px-4 text-sm font-medium">{transaction.description}</td>
                        <td className="py-3 px-4 text-sm text-muted-foreground">{transaction.user_name}</td>
                        <td className="py-3 px-4 text-sm font-bold text-right">
                          {new Intl.NumberFormat('en-US', {
                            style: 'currency',
                            currency: transaction.currency || 'USD'
                          }).format(transaction.amount)}
                        </td>
                        <td className="py-3 px-4 text-center">
                          <Badge className="bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">
                            {transaction.status === 'completed' ? 'Completed' : transaction.status}
                          </Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Recent Activity */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Recent Form 1023 Applications */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Recent Form 1023 Applications</CardTitle>
              <Link href={route('admin.form1023.index')}>
                <Button variant="ghost" size="sm">
                  View All <ArrowRight className="h-4 w-4 ml-2" />
                </Button>
              </Link>
            </CardHeader>
            <CardContent>
              {recentForm1023Applications.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">No applications yet</p>
              ) : (
                <div className="space-y-4">
                  {recentForm1023Applications.map((app) => (
                    <div key={app.id} className="flex items-center justify-between p-3 border rounded-lg hover:bg-muted/50 transition-colors">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                          <p className="font-medium text-sm truncate">{app.application_number}</p>
                          {getStatusBadge(app.status)}
                          {getPaymentStatusBadge(app.payment_status)}
                        </div>
                        <p className="text-xs text-muted-foreground truncate">{app.organization_name}</p>
                        <p className="text-xs text-muted-foreground mt-1">
                          {formatDate(app.submitted_at)} • {formatCurrency(app.amount)}
                        </p>
                      </div>
                      <Link href={route('admin.form1023.show', app.id)}>
                        <Button variant="ghost" size="sm">
                          <Eye className="h-4 w-4" />
                        </Button>
                      </Link>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Recent Organizations */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Recent Organizations</CardTitle>
              <Button variant="ghost" size="sm">
                View All <ArrowRight className="h-4 w-4 ml-2" />
              </Button>
            </CardHeader>
            <CardContent>
              {recentOrganizations.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">No organizations yet</p>
              ) : (
                <div className="space-y-4">
                  {recentOrganizations.map((org) => (
                    <div key={org.id} className="flex items-center justify-between p-3 border rounded-lg hover:bg-muted/50 transition-colors">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                          <p className="font-medium text-sm truncate">{org.name}</p>
                          {getRegistrationStatusBadge(org.registration_status)}
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">
                          Created {formatDate(org.created_at)}
                        </p>
                      </div>
                      <Button variant="ghost" size="sm">
                        <Eye className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  )
}

interface AdminDashboardProps {
  isAdmin?: boolean
  promotionalBanner?: {
    id: number
    title?: string | null
    type: 'image' | 'text'
    image_url?: string | null
    text_content?: string | null
    external_link?: string | null
    background_color?: string | null
    text_color?: string | null
    description?: string | null
  } | null
  stats?: {
    totalOrganizations: number
    approvedOrganizations: number
    pendingOrganizations: number
    totalUsers: number
    totalForm1023Applications: number
    pendingForm1023Applications: number
    approvedForm1023Applications: number
    totalComplianceApplications: number
    pendingComplianceApplications: number
    totalRoles: number
    totalPermissions: number
  }
  recentForm1023Applications?: Array<{
    id: number
    application_number: string
    status: string
    payment_status: string
    organization_name: string
    submitted_at: string | null
    amount: number
  }>
  recentOrganizations?: Array<{
    id: number
    name: string
    registration_status: string
    created_at: string
  }>
  paymentStats?: {
    totalRevenue: number
    pendingPayments: number
    paidApplications: number
  }
  recentTransactions?: Array<{
    id: string
    type: string
    description: string
    amount: number
    status: string
    user_name: string
    date: string | null
    currency: string
  }>
  monthlyRevenue?: Array<{
    month: string
    monthShort: string
    revenue: number
  }>
  phazeBalanceSummary?: {
    available_balance: number
    total_funded: number
    total_consumed: number
    remaining_balance: number
    currency: string
    phaze_live: {
      available: number | null
      currency: string
      fetched_at: string | null
      error: string | null
      variance: number | null
    }
  } | null
}

export default function Dashboard({
  totalOrg = 5,
  orgInfo,
    totalFav = 12,
    volunteers,
    donations,
    events,
  topics = [],
  form1023Application = null,
  form990Filings = null,
  overdueForm990Filings = [],
  isAdmin = false,
  stats,
  recentForm1023Applications = [],
  recentOrganizations = [],
  paymentStats,
  recentTransactions = [],
  monthlyRevenue = [],
  phazeBalanceSummary = null,
  promotionalBanner = null,
  promotionalBanners = null,
  hasSubscription = false,
  profileCompletion = null,
  careAllianceDashboard = null,
  careAllianceProfile = null,
}: {
  totalOrg?: number
  orgInfo?: any
        totalFav?: number
   volunteers?: number,
    donations?: number,
    events?: number,
  topics?: { id: number; name: string }[]
  form1023Application?: any
  form990Filings?: any
  overdueForm990Filings?: any[]
  hasSubscription?: boolean
  profileCompletion?: {
    percent: number
    completed: number
    total: number
    missing: Array<{ id: string; label: string; benefit: string; route: string; connected: boolean }>
    completeSetupHref: string | null
  } | null
  careAllianceDashboard?: {
    total_raised_cents: number
    active_members_count: number
    campaigns_count: number
    pending_join_requests_count: number
    completed_gift_count: number
  } | null
  careAllianceProfile?: {
    id: number
    name: string
    slug: string
    ein: string | null
    description: string | null
    city: string | null
    state: string | null
    website: string | null
  } | null
} & AdminDashboardProps) {
  const auth = usePage().props.auth
  const organization = orgInfo
  const userRole = auth?.user?.role // 'admin' or 'organization'
  const isOrgUser = userRole === "organization" || userRole === "organization_pending"
  const userRoles = (auth?.roles ?? []) as string[]
  const isCareAllianceHub = userRoles.some((r) => String(r).toLowerCase() === "care_alliance")
  /** Nonprofits joining an alliance — not Unity Impact Alliance administrators */
  const showOrgAllianceMembershipUi = isOrgUser && !isCareAllianceHub
  const userId = auth?.user?.id // Get user ID to detect user changes

  // Wallet Connect Popup State
  const [showWalletPopup, setShowWalletPopup] = useState(false)
  const [walletConnected, setWalletConnected] = useState(false)
  const [walletBalance, setWalletBalance] = useState<number | null>(null)
  const [isCheckingWallet, setIsCheckingWallet] = useState(true)
  const [userDismissedPopup, setUserDismissedPopup] = useState(false)

  const walletHeaderVisible = auth?.user?.wallet_header_visible !== false
  const isNonprofitDashboardUser =
    isOrgUser || userRole === "care_alliance" || isCareAllianceHub
  // Check wallet connection status - FORCE CHECK FOR EACH ORGANIZATION USER
  // Re-check when user ID changes (different organization account)
  useEffect(() => {
    const checkWalletStatus = async () => {
      if (!isNonprofitDashboardUser || !walletHeaderVisible) {
        setIsCheckingWallet(false)
        setShowWalletPopup(false)
        setWalletConnected(false)
        return
      }

      // Reset state when checking (but preserve user dismissal if switching users)
      setIsCheckingWallet(true)
      setWalletConnected(false)
      // Reset dismissal flag when user changes (different organization account)
      if (userId) {
        setUserDismissedPopup(false)
      }
      setShowWalletPopup(false)

      try {
        // Add cache-busting parameter to ensure fresh check
        const response = await fetch(`/wallet/status?t=${Date.now()}`, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
          cache: 'no-cache',
        })

        if (response.ok) {
          const data = await response.json()
          if (data.success && data.connected) {
            // Wallet IS connected
            setWalletConnected(true)
            setShowWalletPopup(false)

            // Fetch balance
            try {
              const balanceResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
                method: 'GET',
                headers: {
                  'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                  'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                cache: 'no-cache',
              })

              if (balanceResponse.ok) {
                const balanceData = await balanceResponse.json()
                if (balanceData.success) {
                  setWalletBalance(pickWalletBalance(balanceData))
                }
              }
            } catch (balanceError) {
              // Balance fetch failed, but wallet is connected
            }
          } else {
            // Wallet NOT connected - Don't show popup
            setWalletConnected(false)
            setShowWalletPopup(false)
          }
        } else {
          // Error checking status - Don't show popup
          setWalletConnected(false)
          setShowWalletPopup(false)
        }
      } catch (error) {
        // Error - Don't show popup
        setWalletConnected(false)
        setShowWalletPopup(false)
      } finally {
        setIsCheckingWallet(false)
      }
    }

    // Always check wallet status for organization users
    // Check immediately when component mounts or user changes
    checkWalletStatus()
  }, [isNonprofitDashboardUser, userId, walletHeaderVisible])

  // Check if wallet is connected
  const isWalletConnected = walletConnected || (auth.user?.balance !== undefined && auth.user?.balance !== null)

  const handleWalletConnect = async () => {
    // Wallet is connected via the popup form
    // Hide the popup and refresh wallet data
    setShowWalletPopup(false)
    setWalletConnected(true)

    try {
      // Fetch wallet balance with cache-busting
      const balanceResponse = await fetch(`/wallet/balance?t=${Date.now()}`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        cache: 'no-cache',
      })

      if (balanceResponse.ok) {
        const balanceData = await balanceResponse.json()
        if (balanceData.success) {
          setWalletBalance(pickWalletBalance(balanceData))
        }
      }

      // Refresh user data to get updated wallet status
      router.reload({ only: ['auth'] })
    } catch (error) {
      console.error('Failed to fetch wallet balance:', error)
    }
  }

  const handleCloseWalletPopup = () => {
    // Allow closing the popup and remember user dismissed it
    setShowWalletPopup(false)
    setUserDismissedPopup(true)
  }

  // Admin Dashboard
  if (isAdmin && stats) {
    return <AdminDashboard promotionalBanner={promotionalBanner} promotionalBanners={promotionalBanners}
      stats={stats}
      recentForm1023Applications={recentForm1023Applications}
      recentOrganizations={recentOrganizations}
      paymentStats={paymentStats}
      recentTransactions={recentTransactions}
      monthlyRevenue={monthlyRevenue}
      phazeBalanceSummary={phazeBalanceSummary}
    />
  }

  // Organization Dashboard (existing code continues below)

  const complianceStatus = organization?.tax_compliance_status ?? "unknown"
  const complianceMeta = (organization?.tax_compliance_meta as Record<string, any> | undefined) ?? {}
  const isComplianceLocked = Boolean(organization?.is_compliance_locked)
  const lastFiledPeriod = complianceMeta?.parsed_tax_period_date ?? null
  const monthsSinceTaxPeriod = complianceMeta?.months_since_tax_period ?? null
  const formattedLastFiledPeriod = lastFiledPeriod ? new Date(lastFiledPeriod).toLocaleDateString(undefined, { month: "long", year: "numeric" }) : null
  const complianceStatusLabel = complianceStatus.replace(/_/g, " ")
  const complianceMessage = (() => {
    if (!organization) return ""

    switch (complianceStatus) {
      case "missing":
        return "We could not locate a recent tax period for your organization."
      case "invalid":
        return "The tax period on file appears to be invalid."
      case "expired":
        return "The tax period on file has expired."
      default:
        return ""
    }
  })()

  const isRegistrationPending = organization?.registration_status === "pending"

  // Check if Form 1023 application has been submitted (not draft)
  const hasSubmittedForm1023 = form1023Application && form1023Application.status !== 'draft'

  // If user has "organization" role (not "organization_pending"), they've submitted the application
  // Show full dashboard with application submitted card
  const hasOrganizationRole = userRole === "organization"

  // Don't restrict dashboard if:
  // 1. User has "organization" role (they've submitted Form 1023)
  // 2. Form 1023 has been submitted (status is not 'draft')
  const shouldRestrictDashboard = Boolean(
    organization &&
    !isCareAllianceHub &&
    (isComplianceLocked || isRegistrationPending) &&
    !hasSubmittedForm1023 &&
    !hasOrganizationRole
  )

  const limitedTitle = isComplianceLocked ? "Organization access limited" : "Application under review"
  const limitedDescription = isComplianceLocked
    ? complianceMessage || "Access is limited until your 501(c)(3) status is verified."
    : "Thanks for submitting your organization details. Our onboarding team is reviewing your documentation. We'll unlock the full experience once approval is complete."

  const limitedCtaCopy = isComplianceLocked
    ? "Start 501(c)(3) assistance application"
    : "Submit compliance application"

  // Transform the topics from backend to include colors
  const [userTopics, setUserTopics] = useState(
    topics.map(topic => ({
      id: topic.id,
      name: topic.name,
      color: getColorForTopic(topic.id) // Generate consistent color based on ID
    }))
  );

  // Helper function to generate consistent colors based on topic ID
  function getColorForTopic(id: number) {
    const colors = [
      "bg-green-500", "bg-blue-500", "bg-red-500", "bg-purple-500",
      "bg-yellow-500", "bg-indigo-500", "bg-orange-500", "bg-pink-500",
      "bg-cyan-500", "bg-teal-500", "bg-emerald-500", "bg-rose-500"
    ];
    return colors[id % colors.length];
  }

  // Update the handleDeleteTopic to make an API call
  const handleDeleteTopic = async (topicId: number) => {
        router.delete(`/chat/user/topics/${topicId}`, {
            onSuccess: () => {
                setUserTopics((prev) => prev.filter((topic) => topic.id !== topicId));
                // showSuccessToast("Topic removed successfully");
            },
            onError: () => {
                showErrorToast("Failed to remove topic");
            }
        });
};

  const commonStats = {
    totalDonations: donations,
    // donationChange: 12.5,
    totalEvents: events,
    // eventsChange: -2,
  }

  const adminStats = {
    ...commonStats,
    totalVolunteers: volunteers,
    volunteersChange: 5,
    upcomingEvents: 3,
    organizationsManaged: totalOrg,
  }

  const organizationStats = {
    ...commonStats,
    myVolunteers: volunteers,
    myUpcomingEvents: events,
    totalFav: totalFav,
  }

  const localStats = userRole === "admin" ? adminStats : organizationStats

  const hubWalletUsd =
    walletBalance !== null && !Number.isNaN(Number(walletBalance))
      ? Number(walletBalance)
      : auth?.user?.balance !== undefined && auth?.user?.balance !== null
        ? Number(auth.user.balance)
        : null
  const showHubWalletLoading =
    Boolean(isCareAllianceHub && careAllianceDashboard && hubWalletUsd === null && isCheckingWallet)

  const formatAllianceEin = (ein: string | null | undefined) => {
    if (!ein) return "—"
    const d = String(ein).replace(/\D/g, "")
    if (d.length !== 9) return ein
    return `${d.slice(0, 2)}-${d.slice(2)}`
  }

  const welcomeMessages: Record<string, string> = {
    admin: `Welcome back, Administrator ${auth.user?.name}!`,
    organization: `Welcome, ${organization?.name}!`,
    organization_pending: `Welcome, ${organization?.name}!`,
    care_alliance: `Welcome, ${careAllianceProfile?.name ?? auth.user?.name ?? "Unity Impact Alliance"}!`,
  }

  const quickActions = {
    admin: [
      { label: "Manage Organizations", color: "primary" },
      { label: "System Settings", color: "secondary" },
      { label: "Create Global Event", color: "accent" },
      { label: "Generate System Report", color: "muted" },
    ],
    organization: [
      { label: "Create New Event", color: "primary" },
      { label: "Record Donation", color: "secondary" },
      { label: "Add Volunteer", color: "accent" },
      { label: "Generate Report", color: "muted" },
    ],
    organization_pending: [],
  }

  const recentActivities: ActivityItem[] = [
    {
      id: 1,
      type: "donation",
      title: "New donation received",
      description: "$1,250 from John Doe",
      date: "2 hours ago",
      user: "Jane Smith",
    },
    {
      id: 2,
      type: "event",
      title: "Community outreach scheduled",
      description: "Event planned for next Saturday",
      date: "1 day ago",
    },
    {
      id: 3,
      type: "volunteer",
      title: "New volunteer joined",
      description: "Michael Johnson signed up",
      date: "2 days ago",
    },
    {
      id: 4,
      type: "donation",
      title: "Monthly recurring donation",
      description: "$200 from Acme Corp",
      date: "3 days ago",
    },
  ]

  const [socialMedias, setSocialMedias] = useState<Record<string, string>>({
    youtube: organization?.social_accounts?.youtube || "",
    facebook: organization?.social_accounts?.facebook || "",
    instagram: organization?.social_accounts?.instagram || "",
    twitter: organization?.social_accounts?.twitter || "",
    linkedin: organization?.social_accounts?.linkedin || "",
    tiktok: organization?.social_accounts?.tiktok || "",
  })

  const handleSocialChange = (platform: string, value: string) => {
    setSocialMedias((prev) => ({ ...prev, [platform]: value }))
  }

  const handleSaveSocial = async (platform: string) => {
    const value = socialMedias[platform]
    if (!value) {
      showErrorToast("Please enter a valid URL before saving.")
      return
    }
    try {
      const response = await fetch("/settings/social-accounts", {
        method: "PATCH",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ social_accounts: { ...socialMedias, [platform]: value } }),
      })
      if (response.ok) {
        showSuccessToast("Social media link saved!")
      } else {
        showErrorToast("Failed to save. Please try again.")
      }
    } catch (error) {
      showErrorToast("An error occurred. Please try again.")
    }
  }

  if (shouldRestrictDashboard) {
    return (
      <AppLayout breadcrumbs={breadcrumbs}>
        <Head title="Dashboard" />
        <div className="m-3 space-y-6 md:m-6">
          <Card className="border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-100">
            <CardHeader>
              <div className="flex items-start gap-3">
                <div className="rounded-full bg-amber-100 p-2 text-amber-600 dark:bg-amber-800">
                  <AlertTriangle className="h-6 w-6" />
                </div>
                <div className="space-y-2">
                  <CardTitle className="text-xl font-semibold md:text-2xl">{limitedTitle}</CardTitle>
                  <p className="text-sm md:text-base">{limitedDescription}</p>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-6">
              {isComplianceLocked && (
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="text-xs uppercase tracking-wide text-amber-600 dark:text-amber-300">Compliance status</p>
                    <p className="text-lg font-semibold capitalize">{complianceStatusLabel}</p>
                  </div>
                  {formattedLastFiledPeriod && (
                    <div>
                      <p className="text-xs uppercase tracking-wide text-amber-600 dark:text-amber-300">Last tax period on file</p>
                      <p className="text-lg font-semibold">{formattedLastFiledPeriod}</p>
                    </div>
                  )}
                  {typeof monthsSinceTaxPeriod === "number" && (
                    <div>
                      <p className="text-xs uppercase tracking-wide text-amber-600 dark:text-amber-300">Months since filing</p>
                      <p className="text-lg font-semibold">{monthsSinceTaxPeriod}</p>
                    </div>
                  )}
                </div>
              )}
              <div className="space-y-4">
                {isComplianceLocked ? (
                  <p className="text-sm text-muted-foreground">
                    {organization?.has_edited_irs_data ? (
                      <>Since your EIN wasn't found in the IRS database, you can apply for 501(c)(3) tax exemption by completing Form 1023. Our team will review your application after payment is received.</>
                    ) : (
                      <>Submit your 501(c)(3) assistance application to regain full access to Believe. Our team will review your documentation after payment is received.</>
                    )}
                  </p>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    We'll send you an email as soon as the review finishes. In the meantime you can reach us anytime if you need to share additional paperwork.
                  </p>
                )}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  {organization?.has_edited_irs_data ? (
                    <>
                      {form1023Application ? (
                        <div className="flex flex-col gap-3 sm:flex-row">
                          <Button size="lg" onClick={() => router.visit(`/dashboard/form1023/apply/${form1023Application.id}/view`)}>
                            View Form 1023 Application
                          </Button>
                          {(form1023Application.status === 'draft' || form1023Application.status === 'needs_more_info') && (
                            <Button
                              size="lg"
                              variant="outline"
                              onClick={() => router.visit("/dashboard/form1023/apply")}
                              className="bg-green-600 hover:bg-green-700 text-white border-green-600"
                            >
                              Edit Application
                            </Button>
                          )}
                        </div>
                      ) : (
                        <Button size="lg" onClick={() => router.visit("/dashboard/form1023/apply")}>
                          Start Form 1023 Application
                        </Button>
                      )}
                    </>
                  ) : (
                    <Button size="lg" onClick={() => router.visit("/dashboard/compliance/apply")}>{limitedCtaCopy}</Button>
                  )}
                  <p className="text-xs text-muted-foreground">
                    Need help? <a className="underline" href="mailto:support@believeinunity.org">Contact support</a>
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      </AppLayout>
    )
  }

  // Show application submitted card if:
  // 1. User has "organization" role (they've submitted Form 1023), OR
  // 2. Form 1023 has been submitted (status is not 'draft')
  const showApplicationSubmittedCard = (hasOrganizationRole || hasSubmittedForm1023) && organization?.has_edited_irs_data && form1023Application

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Dashboard" />
      <div className="flex flex-col gap-6 m-3 md:m-6">
        {/* Pending Unity Impact Alliance invites — org dashboard: sign in → /dashboard → this card (also linked from invitation emails) */}
        {showOrgAllianceMembershipUi && <CareAllianceOrgInvitesInline />}
        {/* Profile completion: required org onboarding documents — not shown on Unity Impact Alliance dashboard. */}
        {isOrgUser && profileCompletion && profileCompletion.percent < 100 && (
          <ProfileCompletionBanner profileCompletion={profileCompletion} variant="organization" />
        )}
        {/* Marketplace savings callout + promotional banners (org dashboard) */}
        {isOrgUser && (
          <div className="flex flex-col gap-3">
            <MarketplaceSavingsHighlight variant="dashboard" />
            {(promotionalBanners || promotionalBanner) && (
              <PromotionalBanner banner={promotionalBanner} banners={promotionalBanners || null} />
            )}
          </div>
        )}
        {/* <div className="bg-card border-border rounded-lg border p-6 shadow-sm">
          <h1 className="text-2xl font-bold">
            {welcomeMessages[userRole as keyof typeof welcomeMessages] || "Welcome!"}
          </h1>
          <VerificationBanner user={auth?.user} />
          <p className="text-muted-foreground mt-2">
            {userRole === "admin" ? "System overview and management tools" : "EIN: " + organization?.ein}
          </p>
        </div> */}

        {isCareAllianceHub && careAllianceDashboard ? (
          <div className="bg-card border-border mb-6 flex flex-col gap-6 rounded-lg border p-6 shadow-sm lg:flex-row lg:items-stretch lg:gap-8">
            <div className="flex min-w-0 flex-1 flex-col gap-6 md:flex-row md:items-start">
              <div className="flex-shrink-0">
                <div className="h-24 w-24 overflow-hidden rounded-full border-4 border-white shadow-lg md:h-32 md:w-32">
                  <img
                    src={auth?.user?.image ? auth.user.image : "/placeholder-user.jpg"}
                    alt={auth.user?.name || "User"}
                    className="h-full w-full object-cover"
                  />
                </div>
              </div>
              <div className="flex-1 text-center md:text-left">
                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                  <div>
                    <h1 className="text-2xl font-bold text-white md:text-3xl">
                      {(userRole && welcomeMessages[userRole]) || "Welcome!"}
                    </h1>
                    <VerificationBanner user={auth?.user} />
                    <p className="text-muted-foreground mt-2">
                      {userRole === "admin"
                        ? "System overview and management tools"
                        : `EIN: ${formatAllianceEin(careAllianceProfile?.ein ?? null)}`}
                    </p>
                  </div>
                </div>
              </div>
            </div>
            <div className="border-border flex w-full shrink-0 flex-col border-t pt-6 lg:w-[min(100%,20rem)] lg:border-t-0 lg:border-l lg:pt-0 lg:pl-8">
              <div className="relative overflow-hidden rounded-2xl border border-blue-200/90 bg-gradient-to-br from-blue-50 via-indigo-50/90 to-purple-50/70 shadow-md shadow-blue-500/10 ring-1 ring-blue-500/15 dark:border-blue-800/70 dark:from-blue-950/60 dark:via-indigo-950/40 dark:to-purple-950/35 dark:shadow-blue-950/30 dark:ring-blue-500/20">
                <div
                  aria-hidden
                  className="pointer-events-none absolute -right-8 -top-12 h-32 w-32 rounded-full bg-blue-400/40 blur-3xl dark:bg-blue-500/20"
                />
                <div
                  aria-hidden
                  className="pointer-events-none absolute -bottom-8 -left-6 h-24 w-24 rounded-full bg-purple-400/30 blur-2xl dark:bg-purple-500/15"
                />
                <div className="relative p-5 sm:p-6">
                  <div className="flex items-start justify-between gap-3">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <Badge
                          variant="secondary"
                          className="border-blue-500/30 bg-blue-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-blue-800 dark:border-blue-400/35 dark:bg-blue-500/15 dark:text-blue-200"
                        >
                          Wallet
                        </Badge>
                        <Sparkles className="h-3.5 w-3.5 text-blue-600/80 dark:text-indigo-400/90" />
                      </div>
                      <h2 className="text-foreground text-lg font-semibold tracking-tight">Available balance</h2>
                    </div>
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-600/25 dark:from-blue-500 dark:to-indigo-600 dark:shadow-blue-500/20">
                      <Wallet className="h-5 w-5" strokeWidth={2} />
                    </div>
                  </div>
                  <div className="mt-5 rounded-xl border border-blue-200/60 bg-gradient-to-br from-white/90 to-blue-50/50 px-4 py-4 backdrop-blur-sm dark:border-blue-800/50 dark:from-blue-950/50 dark:to-indigo-950/30">
                    <p className="text-muted-foreground text-[11px] font-medium uppercase tracking-wide">
                      Current balance
                    </p>
                    <p className="text-foreground mt-1 text-3xl font-bold tracking-tight tabular-nums sm:text-4xl">
                      {showHubWalletLoading ? (
                        <span className="text-muted-foreground inline-block animate-pulse">···</span>
                      ) : hubWalletUsd !== null ? (
                        formatUsd(hubWalletUsd)
                      ) : (
                        "—"
                      )}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div className="bg-card border-border mb-6 flex flex-col gap-6 rounded-lg border p-6 shadow-sm md:flex-row md:items-start">
            {isOrgUser && (
              <div className="flex-shrink-0">
                <div className="h-24 w-24 overflow-hidden rounded-full border-4 border-white shadow-lg md:h-32 md:w-32">
                  <img
                    src={organization?.registered_user_image ? organization?.registered_user_image : "/placeholder-user.jpg"}
                    alt={auth.user?.name || "User"}
                    className="h-full w-full object-cover"
                  />
                </div>
              </div>
            )}
            <div className="flex-1 text-center md:text-left">
              <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                  <h1 className="text-2xl font-bold text-white md:text-3xl">
                    {welcomeMessages[userRole as keyof typeof welcomeMessages] || "Welcome!"}
                  </h1>
                  <VerificationBanner user={auth?.user} />
                  <p className="text-muted-foreground mt-2">
                    {userRole === "admin" ? "System overview and management tools" : "EIN: " + organization?.ein}
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Form 990 Filing Alert — nonprofit org records only, not Unity Impact Alliance dashboard */}
        {!isCareAllianceHub && overdueForm990Filings && overdueForm990Filings.length > 0 && (
          <Card className="border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-900/20 dark:text-red-100">
            <CardHeader>
              <div className="flex items-start gap-3">
                <div className="rounded-full bg-red-100 p-2 text-red-600 dark:bg-red-800">
                  <AlertTriangle className="h-6 w-6" />
                </div>
                <div className="space-y-2 flex-1">
                  <CardTitle className="text-xl font-semibold">Form 990 Filing Required</CardTitle>
                  <p className="text-sm">
                    Your organization has {overdueForm990Filings.length} overdue Form 990 filing{overdueForm990Filings.length > 1 ? 's' : ''}.
                    Please file immediately to avoid penalties.
                  </p>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-3">
                {overdueForm990Filings.map((filing: any) => (
                  <div key={filing.id} className="bg-white dark:bg-gray-800 rounded-lg p-4 border border-red-200 dark:border-red-800">
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="font-semibold">Form {filing.form_type || '990'} - Tax Year {filing.tax_year}</p>
                        {filing.due_date && (
                          <p className="text-sm text-muted-foreground mt-1">
                            Due Date: {new Date(filing.due_date).toLocaleDateString()}
                            {filing.days_until_due !== null && filing.days_until_due < 0 && (
                              <span className="ml-2 font-semibold text-red-600">
                                ({Math.abs(filing.days_until_due)} days overdue)
                              </span>
                            )}
                          </p>
                        )}
                      </div>
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => window.open('https://www.irs.gov/charities-non-profits/form-990-series-due-dates', '_blank')}
                        className="border-red-300 text-red-700 hover:bg-red-100 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/30"
                      >
                        File Now
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <AlertCircle className="h-4 w-4" />
                <span>We check IRS records monthly. If you've already filed, it may take time to appear in our system.</span>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Form 990 Filing Status (if not overdue but due soon) */}
        {!isCareAllianceHub &&
          form990Filings &&
          !form990Filings.is_filed &&
          !form990Filings.is_overdue &&
          form990Filings.days_until_due !== null &&
          form990Filings.days_until_due > 0 &&
          form990Filings.days_until_due <= 30 && (
          <Card className="border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-100">
            <CardHeader>
              <div className="flex items-start gap-3">
                <div className="rounded-full bg-amber-100 p-2 text-amber-600 dark:bg-amber-800">
                  <Clock className="h-6 w-6" />
                </div>
                <div className="space-y-2 flex-1">
                  <CardTitle className="text-xl font-semibold">Form 990 Filing Due Soon</CardTitle>
                  <p className="text-sm">
                    Your Form {form990Filings.form_type || '990'} for tax year {form990Filings.tax_year} is due in {form990Filings.days_until_due} days.
                  </p>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {form990Filings.due_date && (
                <p className="text-sm text-muted-foreground mb-4">
                  Due Date: {new Date(form990Filings.due_date).toLocaleDateString()}
                </p>
              )}
              <Button
                variant="outline"
                size="sm"
                onClick={() => window.open('https://www.irs.gov/charities-non-profits/form-990-series-due-dates', '_blank')}
                className="border-amber-300 text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/30"
              >
                Learn More
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Application Submitted Card - shown after Welcome card */}
        {showApplicationSubmittedCard && form1023Application && (() => {
          // Determine card colors based on status
          const getCardColors = (status: string) => {
            switch (status) {
              case 'pending_payment':
                return {
                  border: 'border-blue-200 dark:border-blue-700',
                  bg: 'bg-blue-50 dark:bg-blue-900/20',
                  text: 'text-blue-900 dark:text-blue-100',
                  iconBg: 'bg-blue-100 dark:bg-blue-800',
                  iconText: 'text-blue-600',
                  labelText: 'text-blue-600 dark:text-blue-300',
                  button: 'bg-blue-600 hover:bg-blue-700'
                }
              case 'awaiting_review':
                return {
                  border: 'border-yellow-200 dark:border-yellow-700',
                  bg: 'bg-yellow-50 dark:bg-yellow-900/20',
                  text: 'text-yellow-900 dark:text-yellow-100',
                  iconBg: 'bg-yellow-100 dark:bg-yellow-800',
                  iconText: 'text-yellow-600',
                  labelText: 'text-yellow-600 dark:text-yellow-300',
                  button: 'bg-yellow-600 hover:bg-yellow-700'
                }
              case 'needs_more_info':
                return {
                  border: 'border-orange-200 dark:border-orange-700',
                  bg: 'bg-orange-50 dark:bg-orange-900/20',
                  text: 'text-orange-900 dark:text-orange-100',
                  iconBg: 'bg-orange-100 dark:bg-orange-800',
                  iconText: 'text-orange-600',
                  labelText: 'text-orange-600 dark:text-orange-300',
                  button: 'bg-orange-600 hover:bg-orange-700'
                }
              case 'approved':
                return {
                  border: 'border-green-200 dark:border-green-700',
                  bg: 'bg-green-50 dark:bg-green-900/20',
                  text: 'text-green-900 dark:text-green-100',
                  iconBg: 'bg-green-100 dark:bg-green-800',
                  iconText: 'text-green-600',
                  labelText: 'text-green-600 dark:text-green-300',
                  button: 'bg-green-600 hover:bg-green-700'
                }
              case 'draft':
                return {
                  border: 'border-gray-200 dark:border-gray-700',
                  bg: 'bg-gray-50 dark:bg-gray-900/20',
                  text: 'text-gray-900 dark:text-gray-100',
                  iconBg: 'bg-gray-100 dark:bg-gray-800',
                  iconText: 'text-gray-600',
                  labelText: 'text-gray-600 dark:text-gray-300',
                  button: 'bg-gray-600 hover:bg-gray-700'
                }
              default:
                return {
                  border: 'border-blue-200 dark:border-blue-700',
                  bg: 'bg-blue-50 dark:bg-blue-900/20',
                  text: 'text-blue-900 dark:text-blue-100',
                  iconBg: 'bg-blue-100 dark:bg-blue-800',
                  iconText: 'text-blue-600',
                  labelText: 'text-blue-600 dark:text-blue-300',
                  button: 'bg-blue-600 hover:bg-blue-700'
                }
            }
          }

          const colors = getCardColors(form1023Application.status)
          const statusMessages: Record<string, string> = {
            'pending_payment': 'Your Form 1023 application is ready. Please complete the payment to proceed.',
            'awaiting_review': 'Your Form 1023 application has been submitted successfully. Our team is reviewing your application and will notify you once the review is complete.',
            'needs_more_info': 'Your Form 1023 application needs additional information. Please review and provide the requested details.',
            'approved': 'Congratulations! Your Form 1023 application has been approved.',
            'draft': 'Your Form 1023 application is saved as a draft. Complete and submit when ready.',
          }

          return (
            <Card className={`${colors.border} ${colors.bg} ${colors.text}`}>
              <CardHeader>
                <div className="flex items-start gap-3">
                  <div className={`rounded-full ${colors.iconBg} p-2 ${colors.iconText}`}>
                    <Check className="h-6 w-6" />
                  </div>
                  <div className="flex-1">
                    <CardTitle className="text-xl font-semibold md:text-2xl">Form 1023 Application</CardTitle>
                    <p className="text-sm md:text-base mt-2">
                      {statusMessages[form1023Application.status] || 'Your Form 1023 application status has been updated.'}
                    </p>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-3">
                  <div>
                    <p className={`text-xs uppercase tracking-wide ${colors.labelText}`}>Application Number</p>
                    <p className="text-lg font-semibold">#{form1023Application.application_number}</p>
                  </div>
                  <div>
                    <p className={`text-xs uppercase tracking-wide ${colors.labelText}`}>Status</p>
                    <Badge
                      className={
                        form1023Application.status === 'awaiting_review'
                          ? "bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200"
                          : form1023Application.status === 'pending_payment'
                          ? "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"
                          : form1023Application.status === 'needs_more_info'
                          ? "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200"
                          : form1023Application.status === 'approved'
                          ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"
                          : "bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200"
                      }
                    >
                      {form1023Application.status === 'awaiting_review' ? 'Pending Review' :
                       form1023Application.status === 'pending_payment' ? 'Pending Payment' :
                       form1023Application.status === 'needs_more_info' ? 'Needs More Info' :
                       form1023Application.status === 'approved' ? 'Approved' :
                       form1023Application.status === 'draft' ? 'Draft' :
                       form1023Application.status}
                    </Badge>
                  </div>
                  {form1023Application.submitted_at ? (
                    <div>
                      <p className={`text-xs uppercase tracking-wide ${colors.labelText}`}>Submitted</p>
                      <p className="text-lg font-semibold">
                        {new Date(form1023Application.submitted_at).toLocaleDateString()}
                      </p>
                    </div>
                  ) : form1023Application.amount && form1023Application.payment_status === 'paid' ? (
                    <div>
                      <p className={`text-xs uppercase tracking-wide ${colors.labelText}`}>Amount Paid</p>
                      <p className="text-lg font-semibold">${Number(form1023Application.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                    </div>
                  ) : null}
                </div>
                {form1023Application.amount && form1023Application.payment_status === 'paid' && (
                  <div className="pt-2 border-t">
                    <div className="flex items-center justify-between">
                      <p className={`text-sm ${colors.labelText}`}>Payment Status</p>
                      <Badge className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                        Paid - ${Number(form1023Application.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                      </Badge>
                    </div>
                  </div>
                )}
                <div className="flex flex-col gap-3 sm:flex-row">
                  <Button
                    onClick={() => router.visit(`/dashboard/form1023/apply/${form1023Application.id}/view`)}
                    className={colors.button}
                  >
                    View Application
                  </Button>
                  {(form1023Application.status === 'draft' || form1023Application.status === 'needs_more_info') && (
                    <Button
                      variant="outline"
                      onClick={() => router.visit("/dashboard/form1023/apply")}
                      className="bg-white hover:bg-gray-50 border-gray-300"
                    >
                      Edit Application
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          )
        })()}

        {isCareAllianceHub && careAllianceDashboard ? (
          <div className="space-y-6">
            <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-5">
              <StatCard
                title="Total raised (alliance)"
                value={formatUsdFromCents(careAllianceDashboard.total_raised_cents)}
                icon={<DollarSign className="h-6 w-6" />}
              />
              <StatCard
                title="Active members"
                value={careAllianceDashboard.active_members_count}
                icon={<Users className="h-6 w-6" />}
              />
              <StatCard
                title="Campaigns"
                value={careAllianceDashboard.campaigns_count}
                icon={<Megaphone className="h-6 w-6" />}
              />
              <StatCard
                title="Completed gifts"
                value={careAllianceDashboard.completed_gift_count}
                icon={<Heart className="h-6 w-6" />}
              />
              <StatCard
                title="Pending join requests"
                value={careAllianceDashboard.pending_join_requests_count}
                icon={<Inbox className="h-6 w-6" />}
              />
            </div>
          </div>
        ) : (
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            <StatCard
              title="Completed donations"
              value={localStats.totalDonations}
              change={localStats.donationChange}
              icon={<DollarSign className="h-6 w-6" />}
            />
            <StatCard
              title="Total Events"
              value={localStats.totalEvents}
              change={localStats.eventsChange}
              icon={<Calendar className="h-6 w-6" />}
            />

            {userRole === "admin" && (
              <>
                <StatCard
                  title="Active Volunteers"
                  value={localStats.totalVolunteers}
                  change={localStats.volunteersChange}
                  icon={<User className="h-6 w-6" />}
                />
                <StatCard title="Organizations" value={localStats.organizationsManaged} icon={<User className="h-6 w-6" />} />
              </>
            )}

            {isOrgUser && (
              <>
                <StatCard title="My Volunteers" value={localStats.myVolunteers} icon={<User className="h-6 w-6" />} />
                <StatCard title="Total Followers" value={localStats.totalFav} icon={<UserCheck className="h-6 w-6" />} />
              </>
            )}
          </div>
        )}

              {isOrgUser && (
        <div className="grid gap-6 md:grid-cols-3 lg:grid-cols-3">
          <div className="col-span-3 xl:col-span-2 2xl:col-span-1">
            <TopicsCard topics={userTopics} onDeleteTopic={handleDeleteTopic} />
          </div>
                  </div>
        )}

        {/* Blue Form 1023 card removed - using green "Application Submitted" card instead */}

        {userRole === "organization" && (
          <div className="mx-auto w-full px-0 py-8">
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
              <div className="lg:col-span-3">
                <Tabs defaultValue="about" className="w-full">
                  <TabsList className="grid w-full grid-cols-7 mb-8">
                    <TabsTrigger value="about" className="text-xs sm:text-sm cursor-pointer">
                      About
                    </TabsTrigger>
                    <TabsTrigger value="impact" className="text-xs sm:text-sm cursor-pointer">
                      Impact
                    </TabsTrigger>
                    <TabsTrigger value="details" className="text-xs sm:text-sm cursor-pointer">
                      Details
                    </TabsTrigger>
                    <TabsTrigger value="product" className="text-xs sm:text-sm cursor-pointer">
                      <Link href={route("products.index")} className="w-full">
                        Products
                      </Link>
                    </TabsTrigger>
                    <TabsTrigger value="events" className="text-xs sm:text-sm cursor-pointer">
                      <Link href={route("events.index")} className="w-full">
                        Events
                      </Link>
                    </TabsTrigger>
                    <TabsTrigger value="social" className="text-xs sm:text-sm cursor-pointer">
                      Social Media
                    </TabsTrigger>
                    <TabsTrigger value="contact" className="text-xs sm:text-sm cursor-pointer">
                      Contact
                    </TabsTrigger>
                  </TabsList>

                  <TabsContent value="about" className="space-y-6">
                    <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700">
                      <CardHeader>
                        <CardTitle className="text-gray-900 dark:text-white text-xl">About Our Mission</CardTitle>
                      </CardHeader>
                      <CardContent>
                        <p className="text-gray-600 dark:text-gray-300 leading-relaxed mb-6">
                          {organization?.description}
                        </p>

                        <div className="border-l-4 border-blue-500 bg-blue-50 dark:bg-blue-900/20 p-6 rounded-r-lg">
                          <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-3">Our Mission</h3>
                          <p className="text-gray-700 dark:text-gray-300">{organization?.mission}</p>
                        </div>
                      </CardContent>
                    </Card>
                  </TabsContent>

                  <TabsContent value="impact" className="space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                      <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700 text-center">
                        <CardContent className="pt-6">
                          <div className="text-3xl font-bold text-blue-600 mb-2">250,000+</div>
                          <div className="text-gray-600 dark:text-gray-300">People Served</div>
                        </CardContent>
                      </Card>

                      <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700 text-center">
                        <CardContent className="pt-6">
                          <div className="text-3xl font-bold text-green-600 mb-2">150+</div>
                          <div className="text-gray-600 dark:text-gray-300">Projects Completed</div>
                        </CardContent>
                      </Card>

                      <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700 text-center">
                        <CardContent className="pt-6">
                          <div className="text-3xl font-bold text-purple-600 mb-2">25+</div>
                          <div className="text-gray-600 dark:text-gray-300">Countries Active</div>
                        </CardContent>
                      </Card>
                    </div>

                    <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700">
                      <CardHeader>
                        <CardTitle className="text-gray-900 dark:text-white text-xl">Recent Projects</CardTitle>
                      </CardHeader>
                      <CardContent className="space-y-4">
                        <div className="flex items-start gap-4">
                          <div className="w-1 h-16 rounded-full bg-blue-500" />
                          <div className="flex-1">
                            <h4 className="font-semibold text-gray-900 dark:text-white mb-1">
                              Water Well Construction
                            </h4>
                            <p className="text-sm text-gray-600 dark:text-gray-300">
                              Completed March 2024 • Serving 2,500 people
                            </p>
                          </div>
                        </div>
                        <div className="flex items-start gap-4">
                          <div className="w-1 h-16 rounded-full bg-green-500" />
                          <div className="flex-1">
                            <h4 className="font-semibold text-gray-900 dark:text-white mb-1">
                              Community Training Program
                            </h4>
                            <p className="text-sm text-gray-600 dark:text-gray-300">
                              Completed February 2024 • 150 families trained
                            </p>
                          </div>
                        </div>
                        <div className="flex items-start gap-4">
                          <div className="w-1 h-16 rounded-full bg-purple-500" />
                          <div className="flex-1">
                            <h4 className="font-semibold text-gray-900 dark:text-white mb-1">School Water System</h4>
                            <p className="text-sm text-gray-600 dark:text-gray-300">
                              Completed January 2024 • 800 students benefited
                            </p>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  </TabsContent>

                  <TabsContent value="details" className="space-y-6">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                      <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700">
                        <CardHeader>
                          <CardTitle className="text-gray-900 dark:text-white flex items-center">
                            <FileText className="mr-2 h-5 w-5" />
                            IRS Information
                          </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                          <div className="grid grid-cols-2 gap-4">
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">EIN</span>
                              <div className="font-mono text-gray-900 dark:text-white">{organization?.ein}</div>
                            </div>
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">Classification</span>
                              <div className="text-gray-900 dark:text-white">{organization?.classification}</div>
                            </div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Legal Name</span>
                            <div className="text-gray-900 dark:text-white">{organization?.name}</div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">In Care Of</span>
                            <div className="text-gray-900 dark:text-white">{organization?.ico || "N/A"}</div>
                          </div>

                          <div className="grid grid-cols-2 gap-4">
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">Address</span>
                              <div className="text-gray-900 dark:text-white">{organization?.street}</div>
                              <div className="text-gray-900 dark:text-white">
                                {organization?.city}, {organization?.state} {organization?.zip}
                              </div>
                            </div>
                          </div>

                          <div className="grid grid-cols-2 gap-4">
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">Ruling Year</span>
                              <div className="text-gray-900 dark:text-white">{organization?.ruling}</div>
                            </div>
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">Tax Deductible</span>
                              <div className="text-gray-900 dark:text-white">{organization?.deductibility || "Yes"}</div>
                            </div>
                          </div>

                          <div className="grid grid-cols-2 gap-4">
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">Organization Type</span>
                              <div className="text-gray-900 dark:text-white">{organization?.organization}</div>
                            </div>
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">Status</span>
                              <br />
                              <Badge
                                variant="secondary"
                                className="bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"
                              >
                                {organization?.status}
                              </Badge>
                            </div>
                          </div>

                          {form1023Application && (
                            <div className="grid grid-cols-2 gap-4">
                              <div>
                                <span className="text-sm text-gray-600 dark:text-gray-300">Form 1023 Application</span>
                                <br />
                                <Badge
                                  variant="secondary"
                                  className={
                                    form1023Application.status === 'awaiting_review'
                                      ? "bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200"
                                      : form1023Application.status === 'draft'
                                      ? "bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200"
                                      : form1023Application.status === 'approved'
                                      ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"
                                      : "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"
                                  }
                                >
                                  {form1023Application.status === 'awaiting_review' ? 'Pending Review' :
                                   form1023Application.status === 'draft' ? 'Draft' :
                                   form1023Application.status === 'approved' ? 'Approved' :
                                   form1023Application.status === 'pending_payment' ? 'Pending Payment' :
                                   form1023Application.status}
                                </Badge>
                              </div>
                            </div>
                          )}

                          <div className="grid grid-cols-2 gap-4">
                            <div>
                              <span className="text-sm text-gray-600 dark:text-gray-300">NTEE Code</span>
                              <div className="text-gray-900 dark:text-white">{organization?.ntee_code || "N/A"}</div>
                            </div>
                          </div>
                        </CardContent>
                      </Card>

                      <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700">
                        <CardHeader>
                          <CardTitle className="text-gray-900 dark:text-white flex items-center">
                            <Building className="mr-2 h-5 w-5" />
                            Organization Details
                          </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Primary Contact</span>
                            <div className="text-gray-900 dark:text-white">{organization?.contact_name}</div>
                            <div className="text-sm text-gray-600 dark:text-gray-300">{organization?.contact_title}</div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Email</span>
                            <div className="text-blue-600 hover:underline">
                              <a href={`mailto:${organization?.email}`}>{organization?.email}</a>
                            </div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Phone</span>
                            <div className="text-gray-900 dark:text-white">{organization?.phone}</div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Website</span>
                            <div className="text-blue-600 hover:underline">
                              <a
                                href={organization?.website}
                                target="_blank"
                                className="flex items-center gap-1"
                                rel="noreferrer"
                              >
                                {organization?.website}
                                <ExternalLink className="h-3 w-3" />
                              </a>
                            </div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Founded</span>
                            <div className="text-gray-900 dark:text-white">{organization?.ruling}</div>
                          </div>

                          <div>
                            <span className="text-sm text-gray-600 dark:text-gray-300">Verification Status</span>
                            <div className="flex items-center gap-2 text-green-600">
                              <Check className="h-4 w-4" />
                              <span>Verified Organization</span>
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    </div>
                  </TabsContent>

                  <TabsContent value="social" className="space-y-6">
                    <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700">
                      <CardHeader>
                        <CardTitle className="text-gray-900 dark:text-white text-xl">Social Media Management</CardTitle>
                      </CardHeader>
                      <CardContent>
                        <div className="space-y-6">
                          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div className="space-y-3">
                              <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                YouTube Channel
                              </label>
                              <div className="flex gap-2">
                                <input
                                  type="url"
                                  value={socialMedias.youtube}
                                  onChange={(e) => handleSocialChange("youtube", e.target.value)}
                                  placeholder="https://youtube.com/@yourchannel"
                                  className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <Button
                                  size="sm"
                                  className="bg-red-600 hover:bg-red-700"
                                  onClick={() => handleSaveSocial("youtube")}
                                >
                                  Save
                                </Button>
                              </div>
                            </div>

                            <div className="space-y-3">
                              <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                Facebook Page
                              </label>
                              <div className="flex gap-2">
                                <input
                                  type="url"
                                  value={socialMedias.facebook}
                                  onChange={(e) => handleSocialChange("facebook", e.target.value)}
                                  placeholder="https://facebook.com/yourpage"
                                  className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <Button
                                  size="sm"
                                  className="bg-red-600 hover:bg-red-700"
                                  onClick={() => handleSaveSocial("facebook")}
                                >
                                  Save
                                </Button>
                              </div>
                            </div>

                            <div className="space-y-3">
                              <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Instagram</label>
                              <div className="flex gap-2">
                                <input
                                  type="url"
                                  value={socialMedias.instagram}
                                  onChange={(e) => handleSocialChange("instagram", e.target.value)}
                                  placeholder="https://instagram.com/yourhandle"
                                  className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <Button
                                  size="sm"
                                  className="bg-red-600 hover:bg-red-700"
                                  onClick={() => handleSaveSocial("instagram")}
                                >
                                  Save
                                </Button>
                              </div>
                            </div>

                            <div className="space-y-3">
                              <label className="text-sm font-medium text-gray-700 dark:text-gray-300">Twitter/X</label>
                              <div className="flex gap-2">
                                <input
                                  type="url"
                                  value={socialMedias.twitter}
                                  onChange={(e) => handleSocialChange("twitter", e.target.value)}
                                  placeholder="https://twitter.com/yourhandle"
                                  className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <Button
                                  size="sm"
                                  className="bg-red-600 hover:bg-red-700"
                                  onClick={() => handleSaveSocial("twitter")}
                                >
                                  Save
                                </Button>
                              </div>
                            </div>

                            <div className="space-y-3">
                              <label className="text-sm font-medium text-gray-700 dark:text-gray-300">LinkedIn</label>
                              <div className="flex gap-2">
                                <input
                                  type="url"
                                  value={socialMedias.linkedin}
                                  onChange={(e) => handleSocialChange("linkedin", e.target.value)}
                                  placeholder="https://linkedin.com/company/yourcompany"
                                  className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <Button
                                  size="sm"
                                  className="bg-red-600 hover:bg-red-700"
                                  onClick={() => handleSaveSocial("linkedin")}
                                >
                                  Save
                                </Button>
                              </div>
                            </div>

                            <div className="space-y-3">
                              <label className="text-sm font-medium text-gray-700 dark:text-gray-300">TikTok</label>
                              <div className="flex gap-2">
                                <input
                                  type="url"
                                  value={socialMedias.tiktok}
                                  onChange={(e) => handleSocialChange("tiktok", e.target.value)}
                                  placeholder="https://tiktok.com/@yourhandle"
                                  className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                <Button
                                  size="sm"
                                  className="bg-red-600 hover:bg-red-700"
                                  onClick={() => handleSaveSocial("tiktok")}
                                >
                                  Save
                                </Button>
                              </div>
                            </div>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  </TabsContent>

                  <TabsContent value="contact" className="space-y-6">
                    <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-700">
                      <CardHeader>
                        <CardTitle className="text-gray-900 dark:text-white text-xl">Contact Information</CardTitle>
                      </CardHeader>
                      <CardContent>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                          <div className="space-y-6">
                            <div>
                              <div className="space-y-3">
                                <div className="flex items-center gap-3">
                                  <Mail className="h-4 w-4 text-gray-500" />
                                  <a href={`mailto:${organization?.email}`} className="text-blue-600 hover:underline">
                                    {organization?.email}
                                  </a>
                                </div>
                                <div className="flex items-center gap-3">
                                  <Phone className="h-4 w-4 text-gray-500" />
                                  <span className="text-gray-600 dark:text-gray-300">{organization?.phone}</span>
                                </div>
                                <div className="flex items-center gap-3">
                                  <Globe className="h-4 w-4 text-gray-500" />
                                  <a
                                    href={organization?.website}
                                    target="_blank"
                                    className="text-blue-600 hover:underline"
                                    rel="noreferrer"
                                  >
                                    {organization?.website}
                                  </a>
                                </div>
                                <div className="flex items-start gap-3">
                                  <MapPin className="h-4 w-4 text-gray-500 mt-1" />
                                  <div>
                                    <div className="text-gray-600 dark:text-gray-300">{organization?.street}</div>
                                    <div className="text-gray-600 dark:text-gray-300">
                                      {organization?.city}, {organization?.state} {organization?.zip}
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>

                            <div>
                              <h4 className="font-semibold text-gray-900 dark:text-white mb-2">Primary Contact</h4>
                              <div className="text-gray-600 dark:text-gray-300">{organization?.contact_name}</div>
                              <div className="text-sm text-gray-500 dark:text-gray-400">
                                {organization?.contact_title}
                              </div>
                            </div>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  </TabsContent>
                </Tabs>
              </div>
            </div>
          </div>
        )}

        {userRole === "admin" && (
          <div className="grid gap-6 lg:grid-cols-3">
            <div className="bg-card border-border rounded-lg border p-6 shadow-sm lg:col-span-2">
              <h2 className="mb-4 text-xl font-semibold">
                {userRole === "admin" ? "System Activity" : "Recent Activity"}
              </h2>
              <div className="divide-y">
                {recentActivities.map((activity) => (
                  <RecentActivityItem key={activity.id} activity={activity} />
                ))}
              </div>
            </div>

            <div className="bg-card border-border rounded-lg border p-6 shadow-sm">
              <h2 className="mb-4 text-xl font-semibold">Quick Actions</h2>
              <div className="space-y-3">
                {quickActions[userRole as keyof typeof quickActions]?.map((action, index) => (
                  <button
                    key={index}
                    className={`hover:bg-${action.color}/90 w-full rounded-lg bg-${action.color} px-4 py-2 text-sm font-medium text-white transition-colors`}
                  >
                    {action.label}
                  </button>
                ))}
              </div>

              <div className="mt-6">
                <h3 className="mb-3 text-lg font-medium">Upcoming Events</h3>
                <div className="space-y-4">
                  <div className="flex items-center gap-3">
                    <div className="bg-primary/10 text-primary flex h-10 w-10 items-center justify-center rounded-full">
                      <Calendar className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="font-medium">Food Drive</p>
                      <p className="text-muted-foreground text-sm">Tomorrow, 10 AM</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className="bg-primary/10 text-primary flex h-10 w-10 items-center justify-center rounded-full">
                      <Calendar className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="font-medium">Board Meeting</p>
                      <p className="text-muted-foreground text-sm">June 15, 2 PM</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>

    </AppLayout>
  )
}

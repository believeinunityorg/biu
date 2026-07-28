"use client"

import type { BreadcrumbItem, SharedData } from "@/types"
import { Head, Link, usePage } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Card, CardContent } from "@/components/frontend/ui/card"
import {
  User,
  Lock,
  Shield,
  CreditCard,
  Globe,
  Webhook,
  Settings,
  ShoppingBag,
  FileText,
  MapPin,
  Landmark,
  Code2,
  Wallet,
  ClipboardList,
  HandCoins,
  MessageSquare,
} from "lucide-react"
import type { PropsWithChildren } from "react"

interface SettingsLayoutProps extends PropsWithChildren {
  activeTab?: string
  /** Unity Impact Alliance hub: use Alliance Settings copy and breadcrumbs */
  settingsBranding?: "default" | "alliance"
  /** Override the default page title / subtitle (e.g. Financial Settings page) */
  pageTitle?: string
  pageSubtitle?: string
}

export default function SettingsLayout({
  children,
  activeTab = "profile",
  settingsBranding = "default",
  pageTitle: pageTitleProp,
  pageSubtitle: pageSubtitleProp,
}: SettingsLayoutProps) {
  const { auth } = usePage<SharedData>().props
  const isAllianceBranding = settingsBranding === "alliance"
  const pageTitle =
    pageTitleProp ?? (isAllianceBranding ? "Alliance Settings" : "Settings")
  const pageSubtitle =
    pageSubtitleProp ??
    (isAllianceBranding
      ? "Manage your Unity Impact Alliance and organization profile information."
      : "Manage your account settings and preferences")
  const profileNavLabel = isAllianceBranding ? "Alliance Settings" : "Profile Information"

  const breadcrumbs: BreadcrumbItem[] = [
    {
      title: pageTitle,
      href: "/settings/profile",
    },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={pageTitle} />

      <div className="space-y-6 p-4 sm:p-6">
        {/* Page Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">{pageTitle}</h1>
            <p className="text-gray-600 dark:text-gray-400 mt-1.5">{pageSubtitle}</p>
          </div>
        </div>

        {/* Settings Navigation & Content */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-4 md:gap-6">
          {/* Sidebar Navigation */}
          <div className="md:col-span-3">
            <div className="md:sticky md:top-4">
              <Card className="border-gray-200 dark:border-gray-800">
                <CardContent className="p-0">
                {/* Mobile/Tablet Navigation */}
                <nav className="md:hidden p-2">
                  <div className="space-y-1">
                    <div className="px-3 py-2 mb-2">
                      <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Account</h3>
                    </div>
                    <Link
                      href={route("profile.edit")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "profile"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <User className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">{profileNavLabel}</span>
                    </Link>
                    <Link
                      href={route("password.edit")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "password"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <Lock className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Password & Security</span>
                    </Link>
                    {auth.user.role === "care_alliance" && (
                      <Link
                        href={route("profile.financial.edit")}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                          activeTab === "financial"
                            ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                        }`}
                      >
                        <Landmark className="h-4 w-4 flex-shrink-0" />
                        <span className="truncate">Financial Settings</span>
                      </Link>
                    )}

                    {auth.user.role === "admin" && (
                      <>
                        <div className="px-3 py-2 mt-4 mb-2">
                          <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Administration</h3>
                        </div>
                        <Link
                          href="/settings/payment-methods"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "payment-methods"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <CreditCard className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Stripe &amp; PayPal Keys</span>
                        </Link>
                        <Link
                          href="/settings/bridge"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "bridge"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <Webhook className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Bridge Wallet</span>
                        </Link>
                        <Link
                          href="/settings/twilio"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "twilio"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <MessageSquare className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Twilio WhatsApp / SMS</span>
                        </Link>
                        <Link
                          href="/settings/application"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "application"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <Settings className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Application Settings</span>
                        </Link>
                        <Link
                          href={route("admin.webhooks.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "webhook-manage"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <Globe className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Webhook Management</span>
                        </Link>
                      </>
                    )}

                    <div className="px-3 py-2 mt-4 mb-2">
                      <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Billing</h3>
                    </div>
                    <Link
                      href={route("billing.index")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "billing"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <CreditCard className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Billing & Wallet</span>
                    </Link>
                    {(auth.user.role === "organization" || auth.user.role === "organization_pending" || auth.user.role === "care_alliance") && (
                      <Link
                        href={route("setup-checklist.index")}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                          activeTab === "setup-checklist"
                            ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                        }`}
                      >
                        <ClipboardList className="h-4 w-4 flex-shrink-0" />
                        <span className="truncate">Setup checklist</span>
                      </Link>
                    )}
                    {(auth.user.role === "organization" || auth.user.role === "organization_pending" || auth.user.role === "care_alliance") && (
                      <Link
                        href={route("pay-as-you-go.index")}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                          activeTab === "pay-as-you-go"
                            ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                        }`}
                      >
                        <Wallet className="h-4 w-4 flex-shrink-0" />
                        <span className="truncate">Pay-As-You-Go Services</span>
                      </Link>
                    )}
                    <Link
                      href={route("settings.saved-payment-methods.index")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "saved-payment-methods"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <CreditCard className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Payment Methods</span>
                    </Link>
                    <Link
                      href={route("referral.edit")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "referral"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <Shield className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Referral Link</span>
                    </Link>

                    {(auth.user.role === "organization" || auth.user.role === "care_alliance") && (
                      <>
                        <div className="px-3 py-2 mt-4 mb-2">
                          <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Fundraising</h3>
                        </div>
                        <Link
                          href={route("organization.payment-settings.edit")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "donation-payments"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <HandCoins className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Donation Payments</span>
                        </Link>
                        <Link
                          href={route("organization.manual-payments.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "manual-payments"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <ClipboardList className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Manual Payments</span>
                        </Link>
                        {auth.user.role === "organization" && (
                          <Link
                            href="/settings/donate-widget"
                            className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                              activeTab === "donate-widget"
                                ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                                : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                            }`}
                          >
                            <Code2 className="h-4 w-4 flex-shrink-0" />
                            <span className="truncate">Donation Widget</span>
                          </Link>
                        )}
                      </>
                    )}

                    {auth.user.role === "organization" && (
                      <>
                        <div className="px-3 py-2 mt-4 mb-2">
                          <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tax Exemptions</h3>
                        </div>
                        <Link
                          href={route("exemption-certificates.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "exemption-certificates"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <FileText className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Exemption Certificates</span>
                        </Link>
                        <Link
                          href={route("state-sales-tax.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "state-sales-tax"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <MapPin className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">State Sales Tax</span>
                        </Link>
                      </>
                    )}
                  </div>
                </nav>

                {/* Desktop Sidebar */}
                <nav className="hidden md:block p-2">
                  <div className="space-y-1">
                    <div className="px-3 py-2 mb-2">
                      <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Account</h3>
                    </div>
                    <Link
                      href={route("profile.edit")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "profile"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <User className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">{profileNavLabel}</span>
                    </Link>
                    <Link
                      href={route("password.edit")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "password"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <Lock className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Password & Security</span>
                    </Link>
                    {auth.user.role === "care_alliance" && (
                      <Link
                        href={route("profile.financial.edit")}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                          activeTab === "financial"
                            ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                        }`}
                      >
                        <Landmark className="h-4 w-4 flex-shrink-0" />
                        <span className="truncate">Financial Settings</span>
                      </Link>
                    )}

                    {auth.user.role === "admin" && (
                      <>
                        <div className="px-3 py-2 mt-4 mb-2">
                          <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Administration</h3>
                        </div>
                        <Link
                          href="/settings/payment-methods"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "payment-methods"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <CreditCard className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Stripe &amp; PayPal Keys</span>
                        </Link>
                        <Link
                          href="/settings/bridge"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "bridge"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <Webhook className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Bridge Wallet</span>
                        </Link>
                        <Link
                          href="/settings/twilio"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "twilio"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <MessageSquare className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Twilio WhatsApp / SMS</span>
                        </Link>
                        <Link
                          href="/settings/application"
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "application"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <Settings className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Application Settings</span>
                        </Link>
                        <Link
                          href={route("admin.webhooks.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "webhook-manage"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <Globe className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Webhook Management</span>
                        </Link>
                      </>
                    )}

                    <div className="px-3 py-2 mt-4 mb-2">
                      <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Billing</h3>
                    </div>
                    <Link
                      href={route("billing.index")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "billing"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <CreditCard className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Billing & Wallet</span>
                    </Link>
                    {(auth.user.role === "organization" || auth.user.role === "organization_pending" || auth.user.role === "care_alliance") && (
                      <Link
                        href={route("setup-checklist.index")}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                          activeTab === "setup-checklist"
                            ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                        }`}
                      >
                        <ClipboardList className="h-4 w-4 flex-shrink-0" />
                        <span className="truncate">Setup checklist</span>
                      </Link>
                    )}
                    {(auth.user.role === "organization" || auth.user.role === "organization_pending" || auth.user.role === "care_alliance") && (
                      <Link
                        href={route("pay-as-you-go.index")}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                          activeTab === "pay-as-you-go"
                            ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                        }`}
                      >
                        <Wallet className="h-4 w-4 flex-shrink-0" />
                        <span className="truncate">Pay-As-You-Go Services</span>
                      </Link>
                    )}
                    <Link
                      href={route("settings.saved-payment-methods.index")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "saved-payment-methods"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <CreditCard className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Payment Methods</span>
                    </Link>
                    <Link
                      href={route("referral.edit")}
                      className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                        activeTab === "referral"
                          ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                          : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                      }`}
                    >
                      <Shield className="h-4 w-4 flex-shrink-0" />
                      <span className="truncate">Referral Link</span>
                    </Link>

                    {(auth.user.role === "organization" || auth.user.role === "care_alliance") && (
                      <>
                        <div className="px-3 py-2 mt-4 mb-2">
                          <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Fundraising</h3>
                        </div>
                        <Link
                          href={route("organization.payment-settings.edit")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "donation-payments"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <HandCoins className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Donation Payments</span>
                        </Link>
                        <Link
                          href={route("organization.manual-payments.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "manual-payments"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <ClipboardList className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Manual Payments</span>
                        </Link>
                        {auth.user.role === "organization" && (
                          <Link
                            href="/settings/donate-widget"
                            className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                              activeTab === "donate-widget"
                                ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                                : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                            }`}
                          >
                            <Code2 className="h-4 w-4 flex-shrink-0" />
                            <span className="truncate">Donation Widget</span>
                          </Link>
                        )}
                      </>
                    )}

                    {auth.user.role === "organization" && (
                      <>
                        <div className="px-3 py-2 mt-4 mb-2">
                          <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tax Exemptions</h3>
                        </div>
                        <Link
                          href={route("exemption-certificates.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "exemption-certificates"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <FileText className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">Exemption Certificates</span>
                        </Link>
                        <Link
                          href={route("state-sales-tax.index")}
                          className={`flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium transition-all ${
                            activeTab === "state-sales-tax"
                              ? "bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary"
                              : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                          }`}
                        >
                          <MapPin className="h-4 w-4 flex-shrink-0" />
                          <span className="truncate">State Sales Tax</span>
                        </Link>
                      </>
                    )}
                  </div>
                </nav>
              </CardContent>
              </Card>
            </div>
          </div>

          {/* Main Content Area */}
          <div className="md:col-span-9">
            {children}
          </div>
        </div>
      </div>
    </AppLayout>
  )
}

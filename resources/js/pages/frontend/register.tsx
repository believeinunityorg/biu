"use client"

import { useEffect, useState, type ComponentType } from "react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardTitle } from "@/components/frontend/ui/card"
import { BelieveInUnityBrandMark } from "@/components/site-title"
import {
  Users,
  Building2,
  ArrowRight,
  Network,
  HeartHandshake,
  HandHeart,
  ShoppingBag,
  CalendarDays,
  Globe2,
  UserPlus,
  Megaphone,
  MessagesSquare,
  BarChart3,
  CircleDollarSign,
  Heart,
  ShieldCheck,
  Sparkles,
  Store,
  TrendingUp,
} from "lucide-react"
import { motion } from "framer-motion"
import { Link, usePage } from "@inertiajs/react"
import { PageHead } from "@/components/frontend/PageHead"

interface RegisterPageProps {
  seo?: { title: string; description?: string }
}

const orgExamples = [
  "Nonprofits",
  "Churches",
  "Family Reunions",
  "Fraternities & Sororities",
  "Alumni Associations",
  "Clubs & Associations",
  "Sports Organizations",
]

const orgCapabilities = [
  { label: "Memberships", icon: Users },
  { label: "Events", icon: CalendarDays },
  { label: "Marketplace", icon: Store },
  { label: "Donations & Campaigns", icon: HandHeart },
  { label: "AI & Communications", icon: Sparkles },
]

const cardTaglines = [
  { label: "100% Secure • Your Privacy Matters", icon: ShieldCheck, tone: "text-sky-300" },
  { label: "Build Your Community. Grow Your Impact.", icon: Sparkles, tone: "text-emerald-300" },
  { label: "Stronger Together. Greater Impact.", icon: Heart, tone: "text-violet-300" },
] as const

const valueProps = [
  { title: "One Ecosystem", subtitle: "Everyone. Connected.", icon: Users },
  { title: "More Opportunities", subtitle: "Give, Grow, & Get Involved.", icon: Heart },
  { title: "Trusted & Secure", subtitle: "Your Data. Our Priority.", icon: ShieldCheck },
  { title: "Stronger Communities", subtitle: "Local Impact. Global Unity.", icon: TrendingUp },
]

function FeatureRow({
  icon: Icon,
  label,
  tone,
}: {
  icon: ComponentType<{ className?: string }>
  label: string
  tone: "blue" | "violet"
}) {
  const toneClass =
    tone === "blue"
      ? "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
      : "bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300"

  return (
    <li className="flex items-start gap-3">
      <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${toneClass}`}>
        <Icon className="h-4 w-4" />
      </div>
      <span className="pt-1 text-sm leading-relaxed text-gray-700 sm:text-base dark:text-gray-300">
        {label}
      </span>
    </li>
  )
}

const cardHeaderThemes = {
  blue: {
    header: "bg-[#2563eb]",
    icon: "text-[#2563eb]",
    ring: "ring-[#2563eb]",
  },
  green: {
    header: "bg-[#16a34a]",
    icon: "text-[#16a34a]",
    ring: "ring-[#16a34a]",
  },
  violet: {
    header: "bg-[#7c3aed]",
    icon: "text-[#7c3aed]",
    ring: "ring-[#7c3aed]",
  },
} as const

function RegisterCardHeader({
  icon: Icon,
  title,
  description,
  tone,
}: {
  icon: ComponentType<{ className?: string }>
  title: string
  description: string
  tone: keyof typeof cardHeaderThemes
}) {
  const theme = cardHeaderThemes[tone]

  return (
    <div className={`relative rounded-t-xl ${theme.header} px-6 pb-7 pt-14 text-center sm:px-7 sm:pb-8 sm:pt-16`}>
      {/* White circle with matching colored border — sits on the top edge */}
      <div className="absolute left-1/2 top-0 z-20 -translate-x-1/2 -translate-y-1/2">
        <div
          className={`flex h-[4.5rem] w-[4.5rem] items-center justify-center rounded-full bg-white shadow-[0_8px_24px_rgba(0,0,0,0.18)] ring-[5px] ${theme.ring} sm:h-20 sm:w-20`}
        >
          <Icon className={`h-9 w-9 sm:h-10 sm:w-10 ${theme.icon}`} strokeWidth={1.75} />
        </div>
      </div>
      <CardTitle className="mb-2 text-2xl font-bold text-white sm:text-3xl">{title}</CardTitle>
      <CardDescription className="mx-auto max-w-sm text-sm leading-relaxed text-white/95 sm:text-base">
        {description}
      </CardDescription>
    </div>
  )
}

export default function RegisterPage() {
  const { seo } = usePage<RegisterPageProps>().props
  const [referralCode, setReferralCode] = useState<string | null>(null)

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const ref = params.get("ref")
    if (ref) {
      setReferralCode(ref)
    }
  }, [])

  const supporterHref = referralCode ? `/register/user?ref=${referralCode}` : "/register/user"
  const organizationHref = referralCode
    ? `/register/organization?ref=${referralCode}`
    : "/register/organization"

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Create Account"} description={seo?.description} />
      <div className="relative min-h-screen overflow-hidden bg-gradient-to-br from-slate-950 via-indigo-950 to-purple-950">
        <div
          className="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-25"
          style={{ backgroundImage: "url(/images/believe-hero.png)" }}
        />
        <div className="absolute inset-0 bg-slate-950/70" />
        <div className="pointer-events-none absolute inset-y-0 left-0 w-1/3 bg-gradient-to-r from-blue-900/40 to-transparent" />
        <div className="pointer-events-none absolute inset-y-0 right-0 w-1/3 bg-gradient-to-l from-purple-900/40 to-transparent" />

        <div className="relative z-10 container mx-auto px-4 py-10 sm:px-6 sm:py-14 lg:px-8 lg:py-16">
          <motion.div
            initial={{ opacity: 0, y: 24 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7 }}
            className="mb-8 text-center sm:mb-12"
          >
            <div className="mb-5 flex justify-center">
              <BelieveInUnityBrandMark
                className="rounded-full bg-white/10 px-4 py-2 backdrop-blur-sm"
                imageClassName="h-8 w-8 object-contain brightness-0 invert sm:h-9 sm:w-9"
                wordmarkClassName="text-sm font-bold tracking-wide text-white sm:text-base"
              />
            </div>
            <h1 className="mb-3 text-3xl font-bold tracking-tight text-white sm:text-4xl md:text-5xl">
              Join Believe In Unity
            </h1>
            <p className="mx-auto max-w-2xl text-base text-white/85 sm:text-xl">
              Choose how you'd like to get started and make a difference today.
            </p>
          </motion.div>

          <div className="mx-auto grid max-w-7xl grid-cols-1 gap-6 pt-10 md:grid-cols-3 md:gap-5 md:gap-y-10 lg:gap-7">
            {/* Supporter */}
            <motion.div
              initial={{ opacity: 0, y: 28 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.7, delay: 0.1 }}
              whileHover={{ y: -6 }}
              className="h-full"
            >
              <Card className="group relative flex h-full flex-col overflow-visible rounded-xl border-0 bg-white shadow-2xl dark:bg-gray-900">
                <RegisterCardHeader
                  icon={Users}
                  tone="blue"
                  title="I'm a Supporter"
                  description="Join as an individual to connect, support, volunteer, shop, and participate in communities that matter to you."
                />
                <CardContent className="flex flex-1 flex-col rounded-b-xl bg-white p-6 sm:p-7 dark:bg-gray-900">
                  <ul className="mb-8 flex-1 space-y-3.5">
                    <FeatureRow icon={HeartHandshake} label="Join Organizations and Groups" tone="blue" />
                    <FeatureRow icon={HandHeart} label="Donate and Volunteer" tone="blue" />
                    <FeatureRow icon={ShoppingBag} label="Shop Marketplace Stores" tone="blue" />
                    <FeatureRow icon={CalendarDays} label="Attend Events" tone="blue" />
                    <FeatureRow icon={Globe2} label="Build Your Community Network" tone="blue" />
                  </ul>
                  <Link href={supporterHref} className="mt-auto block">
                    <Button className="h-12 w-full rounded-xl bg-gradient-to-r from-blue-600 to-blue-700 text-base font-bold text-white shadow-lg transition hover:from-blue-700 hover:to-blue-800 sm:h-14 sm:text-lg">
                      Register as a Supporter
                      <ArrowRight className="ml-2 h-5 w-5" />
                    </Button>
                  </Link>
                </CardContent>
              </Card>
            </motion.div>

            {/* Organization */}
            <motion.div
              initial={{ opacity: 0, y: 28 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.7, delay: 0.2 }}
              whileHover={{ y: -6 }}
              className="h-full"
            >
              <Card className="group relative flex h-full flex-col overflow-visible rounded-xl border-0 bg-white shadow-2xl dark:bg-gray-900">
                <RegisterCardHeader
                  icon={Building2}
                  tone="green"
                  title="I'm an Organization"
                  description="Create your community and engage members, supporters, volunteers, customers, and partners—all from one platform."
                />
                <CardContent className="flex flex-1 flex-col rounded-b-xl bg-white p-6 sm:p-7 dark:bg-gray-900">
                  <div className="mb-5 flex flex-wrap justify-start gap-2">
                    {orgExamples.map((label) => (
                      <span
                        key={label}
                        className="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800 sm:text-xs dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                      >
                        {label}
                      </span>
                    ))}
                  </div>

                  <div className="mb-6 grid grid-cols-3 gap-1.5">
                    {orgCapabilities.map(({ label, icon: Icon }) => (
                      <div
                        key={label}
                        className="flex flex-col items-center gap-1 rounded-lg border border-emerald-100 bg-emerald-50/70 px-1 py-1.5 text-center dark:border-emerald-900/50 dark:bg-emerald-950/30"
                      >
                        <div className="flex h-5 w-5 items-center justify-center rounded-md bg-emerald-600 text-white">
                          <Icon className="h-3 w-3" />
                        </div>
                        <span className="text-[9px] font-semibold leading-tight text-emerald-900 dark:text-emerald-100">
                          {label}
                        </span>
                      </div>
                    ))}
                  </div>

                  <Link href={organizationHref} className="mt-auto block">
                    <Button className="h-12 w-full rounded-xl bg-gradient-to-r from-emerald-600 to-green-700 text-base font-bold text-white shadow-lg transition hover:from-emerald-700 hover:to-green-800 sm:h-14 sm:text-lg">
                      Register Organization
                      <ArrowRight className="ml-2 h-5 w-5" />
                    </Button>
                  </Link>
                </CardContent>
              </Card>
            </motion.div>

            {/* Unity Impact Alliance */}
            <motion.div
              initial={{ opacity: 0, y: 28 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.7, delay: 0.3 }}
              whileHover={{ y: -6 }}
              className="h-full"
            >
              <Card className="group relative flex h-full flex-col overflow-visible rounded-xl border-0 bg-white shadow-2xl dark:bg-gray-900">
                <RegisterCardHeader
                  icon={Network}
                  tone="violet"
                  title="Unity Impact Alliance"
                  description="Connect multiple Organizations under one account to collaborate, communicate, and create greater community impact."
                />
                <CardContent className="flex flex-1 flex-col rounded-b-xl bg-white p-6 sm:p-7 dark:bg-gray-900">
                  <ul className="mb-8 flex-1 space-y-3.5">
                    <FeatureRow icon={UserPlus} label="Invite Organizations" tone="violet" />
                    <FeatureRow icon={Megaphone} label="Joint Campaigns" tone="violet" />
                    <FeatureRow icon={MessagesSquare} label="Shared Communications" tone="violet" />
                    <FeatureRow icon={BarChart3} label="Alliance Reporting" tone="violet" />
                    <FeatureRow icon={CircleDollarSign} label="Revenue Distribution" tone="violet" />
                  </ul>
                  <Link href="/register/care-alliance" className="mt-auto block">
                    <Button className="h-12 w-full rounded-xl bg-gradient-to-r from-violet-600 to-purple-700 text-base font-bold text-white shadow-lg transition hover:from-violet-700 hover:to-purple-800 sm:h-14 sm:text-lg">
                      Register Unity Impact Alliance
                      <ArrowRight className="ml-2 h-5 w-5" />
                    </Button>
                  </Link>
                </CardContent>
              </Card>
            </motion.div>
          </div>

          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.7, delay: 0.45 }}
            className="mx-auto mt-10 max-w-7xl sm:mt-12"
          >
            {/* Taglines outside cards — each centered under its matching card */}
            <div className="mb-4 grid grid-cols-1 gap-3 px-1 md:grid-cols-3 md:gap-5 lg:gap-7">
              {cardTaglines.map(({ label, icon: Icon, tone }) => (
                <div
                  key={label}
                  className={`flex items-center justify-center gap-2 text-center text-xs font-medium sm:text-sm ${tone}`}
                >
                  <Icon className="h-4 w-4 shrink-0" strokeWidth={2} />
                  <span>{label}</span>
                </div>
              ))}
            </div>

            {/* Bottom value card — horizontal 4-col with dividers */}
            <div className="rounded-2xl border border-indigo-300/25 bg-[#0b1630]/85 px-3 py-4 shadow-xl backdrop-blur-md sm:px-4 sm:py-5">
              <div className="grid grid-cols-1 divide-y divide-indigo-300/15 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
                {valueProps.map(({ title, subtitle, icon: Icon }) => (
                  <div
                    key={title}
                    className="flex items-center gap-3 px-3 py-3 text-left sm:px-4 sm:py-2"
                  >
                    <Icon className="h-7 w-7 shrink-0 text-violet-400 sm:h-8 sm:w-8" strokeWidth={1.5} />
                    <div className="min-w-0">
                      <p className="text-sm font-bold leading-tight text-white">{title}</p>
                      <p className="mt-0.5 text-xs leading-snug text-indigo-200/75">{subtitle}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </motion.div>

          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.7, delay: 0.55 }}
            className="mt-8 text-center"
          >
            <p className="mb-3 text-sm text-white/80">Already have an account?</p>
            <Link href="/login">
              <Button
                variant="outline"
                size="lg"
                className="border-2 border-white/80 bg-transparent text-white hover:bg-white hover:text-purple-700"
              >
                Sign In
              </Button>
            </Link>
          </motion.div>
        </div>
      </div>
    </FrontendLayout>
  )
}

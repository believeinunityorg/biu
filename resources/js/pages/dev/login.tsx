"use client"

import { FormEventHandler } from "react"
import { Head, Link, useForm, usePage } from "@inertiajs/react"
import { Building2, Copy, ExternalLink, LoaderCircle, Lock, User } from "lucide-react"
import InputError from "@/components/input-error"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"
import { PageHead } from "@/components/frontend/PageHead"
import type { SharedData } from "@/types"

type LoginForm = {
  email: string
  password: string
  remember: boolean
  cf_turnstile_response: string
}

interface DevLoginProps {
  seo?: { title: string; description?: string }
  status?: string | null
  devHost: string
  productionUrl: string
  organizationRegisterUrl: string
}

export default function DevLoginPage({
  seo,
  status,
  devHost,
  productionUrl,
  organizationRegisterUrl,
}: DevLoginProps) {
  const { auth } = usePage<SharedData>().props
  const signedInUser = auth.user

  const { data, setData, post, processing, errors, reset } = useForm<Required<LoginForm>>({
    email: "",
    password: "",
    remember: true,
    cf_turnstile_response: "",
  })

  const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

  const submit: FormEventHandler = (e) => {
    e.preventDefault()
    post("/login", {
      onFinish: () => reset("password", "cf_turnstile_response"),
    })
  }

  const year = new Date().getFullYear()

  return (
    <div className="relative flex min-h-screen flex-col overflow-hidden bg-[#12081f] text-white">
      <PageHead
        title={seo?.title ?? "Developer Access"}
        description={seo?.description ?? "Sign in to the Believe In Unity development environment."}
      />
      <Head>
        <meta name="robots" content="noindex, nofollow" />
      </Head>

      {/* Ambient background */}
      <div className="pointer-events-none absolute inset-0">
        <div className="absolute -left-32 top-1/4 h-[28rem] w-[28rem] rounded-full bg-purple-600/20 blur-3xl" />
        <div className="absolute -right-24 bottom-0 h-[24rem] w-[24rem] rounded-full bg-blue-600/15 blur-3xl" />
        <div
          className="absolute inset-0 opacity-30"
          style={{
            backgroundImage:
              "radial-gradient(circle at 20% 80%, rgba(147, 51, 234, 0.35) 0%, transparent 45%), radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.25) 0%, transparent 40%)",
          }}
        />
        <svg
          className="absolute bottom-0 left-0 w-full opacity-40"
          viewBox="0 0 1440 320"
          preserveAspectRatio="none"
          aria-hidden
        >
          <path
            fill="url(#devWave)"
            d="M0,192L48,197.3C96,203,192,213,288,229.3C384,245,480,267,576,250.7C672,235,768,181,864,181.3C960,181,1056,235,1152,234.7C1248,235,1344,181,1392,154.7L1440,128L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"
          />
          <defs>
            <linearGradient id="devWave" x1="0%" y1="0%" x2="100%" y2="0%">
              <stop offset="0%" stopColor="#7c3aed" stopOpacity="0.5" />
              <stop offset="100%" stopColor="#2563eb" stopOpacity="0.35" />
            </linearGradient>
          </defs>
        </svg>
        <div className="absolute inset-0 bg-[url('/images/believe-hero.png')] bg-cover bg-center opacity-[0.07]" />
      </div>

      <main className="relative z-10 flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
        <div className="w-full max-w-md text-center">
          <div className="mb-6 flex flex-col items-center gap-3">
            <img
              src="/favicon-96x96.png"
              alt="Believe In Unity"
              className="h-16 w-16 object-contain sm:h-20 sm:w-20"
            />
            <span className="font-serif text-2xl tracking-wide text-white sm:text-3xl">Believe In Unity</span>
            <p className="text-sm font-medium tracking-wide text-blue-300/90">{devHost}</p>
          </div>

          <h1 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">Developing For Purpose</h1>
          <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-white/70 sm:text-base">
            Building technology that helps nonprofits serve more people.
          </p>

          {status && (
            <div className="mt-6 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
              {status}
            </div>
          )}

          {signedInUser ? (
            <div className="mt-8 space-y-4 text-left">
              <div className="rounded-lg border border-purple-400/30 bg-purple-500/10 px-4 py-3 text-sm text-purple-100">
                Signed in as{" "}
                <span className="font-medium text-white">
                  {signedInUser.name?.trim() || signedInUser.email}
                </span>
              </div>
              <Link
                href={route("dashboard")}
                className="flex h-12 w-full items-center justify-center rounded-lg bg-purple-600 text-base font-semibold text-white transition hover:bg-purple-500"
              >
                Continue to development app
              </Link>
              <Link
                href={route("logout.main")}
                method="post"
                as="button"
                className="flex h-12 w-full items-center justify-center rounded-lg border border-white/20 bg-transparent text-sm font-medium text-white/90 transition hover:border-white/35 hover:bg-white/5"
              >
                Sign out
              </Link>
            </div>
          ) : (
          <form className="mt-8 space-y-4 text-left" onSubmit={submit}>
            <div>
              <label htmlFor="email" className="sr-only">
                Email Address
              </label>
              <div className="relative">
                <User className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-white/40" />
                <input
                  id="email"
                  type="email"
                  required
                  autoComplete="email"
                  autoFocus
                  placeholder="Email Address"
                  value={data.email}
                  onChange={(e) => setData("email", e.target.value)}
                  className="h-12 w-full rounded-lg border border-white/10 bg-white/5 pl-11 pr-4 text-white placeholder:text-white/40 outline-none transition focus:border-purple-400/50 focus:ring-2 focus:ring-purple-500/30"
                />
              </div>
              <InputError message={errors.email} className="mt-1.5 text-red-300" />
            </div>

            <div>
              <label htmlFor="password" className="sr-only">
                Password
              </label>
              <div className="relative">
                <Lock className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-white/40" />
                <input
                  id="password"
                  type="password"
                  required
                  autoComplete="current-password"
                  placeholder="Password"
                  value={data.password}
                  onChange={(e) => setData("password", e.target.value)}
                  className="h-12 w-full rounded-lg border border-white/10 bg-white/5 pl-11 pr-4 text-white placeholder:text-white/40 outline-none transition focus:border-purple-400/50 focus:ring-2 focus:ring-purple-500/30"
                />
              </div>
              <InputError message={errors.password} className="mt-1.5 text-red-300" />
            </div>

            <TurnstileField
              theme="dark"
              onToken={(token) => setData("cf_turnstile_response", token)}
              error={errors.cf_turnstile_response}
            />

            <button
              type="submit"
              disabled={processing || turnstileBlocksSubmit}
              className="flex h-12 w-full items-center justify-center rounded-lg bg-purple-600 text-base font-semibold text-white transition hover:bg-purple-500 disabled:cursor-not-allowed disabled:opacity-70"
            >
              {processing ? (
                <>
                  <LoaderCircle className="mr-2 h-5 w-5 animate-spin" />
                  Signing in…
                </>
              ) : (
                "Sign In"
              )}
            </button>
          </form>
          )}

          <div className="my-6 flex items-center gap-3">
            <div className="h-px flex-1 bg-white/15" />
            <span className="text-xs uppercase tracking-wider text-white/40">or</span>
            <div className="h-px flex-1 bg-white/15" />
          </div>

          <div className="space-y-3 text-left">
            <Link
              href={organizationRegisterUrl}
              className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-lg border border-blue-400/35 bg-blue-500/10 text-sm font-semibold text-blue-100 transition hover:border-blue-300/50 hover:bg-blue-500/20"
            >
              <Building2 className="h-4 w-4 shrink-0" />
              Register your organization
            </Link>
            <div className="rounded-lg border border-white/10 bg-white/5 px-3 py-2.5">
              <p className="text-xs font-medium text-white/55">Share this link with new organizations (development only)</p>
              <div className="mt-2 flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate text-left text-xs text-blue-200/90">{organizationRegisterUrl}</code>
                <button
                  type="button"
                  onClick={() => void navigator.clipboard?.writeText(organizationRegisterUrl)}
                  className="inline-flex shrink-0 items-center gap-1 rounded-md border border-white/15 px-2 py-1 text-xs text-white/80 transition hover:bg-white/10"
                  aria-label="Copy organization registration link"
                >
                  <Copy className="h-3.5 w-3.5" />
                  Copy
                </button>
              </div>
            </div>
          </div>

          <div className="my-6 flex items-center gap-3">
            <div className="h-px flex-1 bg-white/15" />
            <span className="text-xs uppercase tracking-wider text-white/40">or</span>
            <div className="h-px flex-1 bg-white/15" />
          </div>

          <a
            href={productionUrl}
            className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-lg border border-white/20 bg-transparent text-sm font-medium text-white/90 transition hover:border-white/35 hover:bg-white/5"
          >
            Go to Believe In Unity
            <ExternalLink className="h-4 w-4 opacity-70" />
          </a>
        </div>
      </main>

      <footer className="relative z-10 pb-8 text-center text-xs text-white/45">
        © {year} Believe In Unity | Developer Access Only
      </footer>
    </div>
  )
}

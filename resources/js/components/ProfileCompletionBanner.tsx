"use client"

import React from "react"
import { Link } from "@inertiajs/react"
import { AlertTriangle, ArrowRight, FileCheck2 } from "lucide-react"
import { Button } from "@/components/ui/button"

export type ProfileCompletionItem = {
  id: string
  label: string
  benefit: string
  route: string
  connected: boolean
  type?: "upload" | "form" | "board_members"
  description?: string
}

export type ProfileCompletion = {
  percent: number
  completed: number
  total: number
  missing: ProfileCompletionItem[]
  completeSetupHref: string | null
}

export default function ProfileCompletionBanner({
  profileCompletion,
  variant = "organization",
}: {
  profileCompletion: ProfileCompletion
  variant?: "organization" | "care_alliance"
}) {
  if (!profileCompletion || profileCompletion.percent >= 100) return null
  const { percent, completed, total, missing, completeSetupHref } = profileCompletion
  const headline =
    variant === "care_alliance"
      ? `Your Unity Impact Alliance profile is ${percent}% Complete`
      : `Your Organization Profile is ${percent}% Complete`

  const actionLabel = (_item: ProfileCompletionItem) => "Complete"

  const mainCtaLabel = completed > 0 ? "Continue" : "Complete"

  return (
    <div className="relative overflow-hidden rounded-2xl border border-amber-200/80 dark:border-amber-800/60 bg-gradient-to-br from-amber-50 via-white to-orange-50/50 dark:from-amber-950/40 dark:via-neutral-950/50 dark:to-amber-950/30 shadow-lg shadow-amber-500/5 dark:shadow-amber-500/10 ring-1 ring-black/5 dark:ring-white/5">
      <div className="absolute left-0 top-0 bottom-0 w-1 bg-gradient-to-b from-amber-400 via-amber-500 to-orange-500 dark:from-amber-500 dark:via-amber-600 dark:to-orange-600" />
      <div className="relative pl-5 pr-5 pt-5 pb-5 md:pl-6 md:pr-6 md:pt-6 md:pb-6">
        <div className="flex flex-col gap-5 md:gap-6">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div className="flex items-start gap-3 min-w-0">
              <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-900/60 flex items-center justify-center ring-2 ring-amber-200/80 dark:ring-amber-700/50">
                <FileCheck2 className="h-5 w-5 text-amber-600 dark:text-amber-400" aria-hidden />
              </div>
              <div className="min-w-0">
                <p className="text-xs font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400 mb-0.5">
                  Profile completion
                </p>
                <h2 className="text-xl md:text-2xl font-bold text-amber-900 dark:text-amber-50 tracking-tight">
                  {headline}
                </h2>
                <p className="text-sm text-amber-700/90 dark:text-amber-300/80 mt-1">
                  {completed} of {total} required onboarding items complete — documents are stored in Governance Storage.
                </p>
                <div className="mt-3 w-full max-w-sm">
                  <div className="h-2.5 w-full rounded-full bg-amber-200/80 dark:bg-amber-800/60 overflow-hidden">
                    <div
                      className="h-full rounded-full bg-gradient-to-r from-amber-500 to-orange-500 dark:from-amber-500 dark:to-amber-600 transition-all duration-700 ease-out"
                      style={{ width: `${Math.max(percent, 4)}%` }}
                    />
                  </div>
                </div>
              </div>
            </div>
            {completeSetupHref && (
              <Link href={completeSetupHref} className="flex-shrink-0 self-start sm:self-center">
                <Button
                  size="lg"
                  className="bg-amber-600 hover:bg-amber-700 text-white dark:bg-amber-600 dark:hover:bg-amber-700 shadow-md hover:shadow-lg transition-all duration-200 gap-2 font-semibold rounded-xl"
                >
                  {mainCtaLabel}
                  <ArrowRight className="h-4 w-4" />
                </Button>
              </Link>
            )}
          </div>
          {missing.length > 0 && (
            <div className="border-t border-amber-200/60 dark:border-amber-800/40 pt-4">
              <p className="text-xs font-semibold uppercase tracking-wider text-amber-600/90 dark:text-amber-400/90 mb-3">
                Still required
              </p>
              <ul className="grid gap-2 sm:grid-cols-1 md:grid-cols-2">
                {missing.map((item) => (
                  <li key={item.id}>
                    {item.route ? (
                      <Link
                        href={item.route}
                        className="flex items-center gap-3 rounded-lg border border-amber-200/70 dark:border-amber-800/50 bg-white/60 dark:bg-neutral-900/40 px-3 py-2.5 text-sm font-medium text-amber-900 dark:text-amber-100 hover:bg-amber-50 dark:hover:bg-amber-950/40 hover:border-amber-300 dark:hover:border-amber-700 transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                      >
                        <AlertTriangle className="h-4 w-4 flex-shrink-0 text-amber-500 dark:text-amber-400" aria-hidden />
                        <span>
                          <strong>{actionLabel(item)}</strong> — {item.label}
                        </span>
                      </Link>
                    ) : (
                      <div className="flex items-center gap-3 rounded-lg border border-amber-200/70 dark:border-amber-800/50 bg-white/60 dark:bg-neutral-900/40 px-3 py-2.5 text-sm font-medium text-amber-800 dark:text-amber-200">
                        <AlertTriangle className="h-4 w-4 flex-shrink-0 text-amber-500 dark:text-amber-400" aria-hidden />
                        <span>
                          <strong>{actionLabel(item)}</strong> — {item.label}
                        </span>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

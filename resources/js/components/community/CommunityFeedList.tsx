"use client"

import { Link, router } from "@inertiajs/react"
import ReportDialog from "@/components/community/ReportDialog"
import { Lock, Megaphone, MessageSquare, Pin, Heart } from "lucide-react"

export type CommunityFeedItem = {
  id: number
  slug: string
  type: string
  title: string
  body: string
  category?: string | null
  is_pinned: boolean
  is_locked: boolean
  replies_count?: number
  published_at?: string | null
  author: { id: number; name: string } | null
  cover_image?: string | null
  attachment_url?: string | null
  attachment_name?: string | null
}

type Props = {
  items: CommunityFeedItem[]
  emptyLabel: string
  reportReasons: Record<string, string>
  canModerate?: boolean
}

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("")
}

function formatWhen(iso: string | null | undefined) {
  if (!iso) return ""
  try {
    return new Date(iso).toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
    })
  } catch {
    return iso
  }
}

export default function CommunityFeedList({
  items,
  emptyLabel,
  reportReasons,
  canModerate = false,
}: Props) {
  if (items.length === 0) {
    return (
      <div className="rounded-2xl border border-dashed border-purple-200 bg-white px-4 py-14 text-center shadow-sm dark:border-purple-500/25 dark:bg-[#111827]">
        {emptyLabel.toLowerCase().includes("announce") ? (
          <Megaphone className="mx-auto mb-3 h-10 w-10 text-purple-300 dark:text-purple-500/60" />
        ) : (
          <MessageSquare className="mx-auto mb-3 h-10 w-10 text-purple-300 dark:text-purple-500/60" />
        )}
        <p className="font-semibold text-slate-800 dark:text-slate-100">No {emptyLabel} yet</p>
        <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">
          Be the first to post and get the conversation started.
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {items.map((item) => {
        const TypeIcon = item.type === "announcement" ? Megaphone : MessageSquare
        const href = route("community.contents.show", item.slug)
        const authorName = item.author?.name ?? "Member"

        return (
          <article
            key={item.id}
            className="overflow-hidden rounded-2xl border border-purple-100/80 bg-white shadow-sm shadow-purple-500/5 transition hover:shadow-md dark:border-purple-500/20 dark:bg-[#111827]"
          >
            {/* Header — Facebook post style */}
            <div className="flex items-start gap-3 px-4 pt-4 sm:px-5 sm:pt-5">
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-sm font-bold text-white shadow-sm ring-2 ring-white dark:ring-[#111827]">
                {initials(authorName) || "?"}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-[15px] font-bold text-slate-900 dark:text-white">{authorName}</p>
                  <span className="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                    <TypeIcon className="h-2.5 w-2.5" />
                    {item.type}
                  </span>
                  {item.category && (
                    <span className="rounded-full border border-purple-100 bg-purple-50 px-2 py-0.5 text-[10px] font-bold text-purple-700 dark:border-purple-500/25 dark:bg-purple-500/15 dark:text-purple-200">
                      {item.category}
                    </span>
                  )}
                  {item.is_pinned && (
                    <span className="inline-flex items-center gap-0.5 rounded-full bg-purple-50 px-2 py-0.5 text-[10px] font-bold text-purple-700 dark:bg-purple-500/15 dark:text-purple-200">
                      <Pin className="h-2.5 w-2.5" /> Pinned
                    </span>
                  )}
                  {item.is_locked && (
                    <span className="inline-flex items-center gap-0.5 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-200">
                      <Lock className="h-2.5 w-2.5" /> Locked
                    </span>
                  )}
                </div>
                <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                  {formatWhen(item.published_at)}
                  {typeof item.replies_count === "number"
                    ? `${item.published_at ? " · " : ""}${item.replies_count} ${item.replies_count === 1 ? "reply" : "replies"}`
                    : ""}
                </p>
              </div>
            </div>

            {/* Title + body */}
            <div className="px-4 pt-3 sm:px-5">
              <Link
                href={href}
                className="block text-lg font-bold leading-snug text-slate-900 hover:text-purple-700 dark:text-white dark:hover:text-purple-300"
              >
                {item.title}
              </Link>
              {item.body.trim() && (
                <p className="mt-1.5 whitespace-pre-wrap text-[15px] leading-relaxed text-slate-700 dark:text-slate-200">
                  {item.body.length > 280 ? `${item.body.slice(0, 280).trimEnd()}…` : item.body}
                </p>
              )}
            </div>

            {/* Post image — full-bleed feed style */}
            {item.cover_image && (
              <Link href={href} className="mt-3 block bg-slate-950/5 dark:bg-black/40">
                <img
                  src={item.cover_image}
                  alt=""
                  className="mx-auto max-h-[min(60vh,520px)] w-full object-contain"
                  loading="lazy"
                />
              </Link>
            )}

            {/* Engagement / actions */}
            <div className="mt-1 flex flex-wrap items-center gap-1 border-t border-purple-50 px-2 py-2 dark:border-purple-500/15 sm:px-3">
              <Link
                href={href}
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-purple-50 hover:text-purple-700 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
              >
                <MessageSquare className="h-4 w-4" />
                {typeof item.replies_count === "number" ? item.replies_count : ""} Comment
              </Link>
              <Link
                href={href}
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-purple-50 hover:text-purple-700 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
              >
                <Heart className="h-4 w-4" />
                View
              </Link>
              <div className="ml-auto flex flex-wrap items-center gap-1">
                <ReportDialog
                  reportableType="CommunityContent"
                  reportableId={item.id}
                  reportReasons={reportReasons}
                />
                {canModerate && (
                  <>
                    <button
                      type="button"
                      className="cursor-pointer rounded-full px-3 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-white/5 dark:hover:text-slate-200"
                      onClick={() =>
                        router.post(route("community.contents.moderate", item.slug), {
                          action: "lock",
                          reason: "Moderator lock",
                        })
                      }
                    >
                      Lock
                    </button>
                    <button
                      type="button"
                      className="cursor-pointer rounded-full px-3 py-2 text-sm font-semibold text-slate-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                      onClick={() =>
                        router.post(route("community.contents.moderate", item.slug), {
                          action: "hide",
                          reason: "Hidden by moderator",
                        })
                      }
                    >
                      Hide
                    </button>
                  </>
                )}
              </div>
            </div>
          </article>
        )
      })}
    </div>
  )
}

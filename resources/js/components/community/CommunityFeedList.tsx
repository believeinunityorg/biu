"use client"

import { Link, router } from "@inertiajs/react"
import ReportDialog from "@/components/community/ReportDialog"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Badge } from "@/components/ui/badge"
import { Lock, Megaphone, MessageSquare, Pin } from "lucide-react"

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
  author: { id: number; name: string } | null
  cover_image?: string | null
}

type Props = {
  items: CommunityFeedItem[]
  emptyLabel: string
  reportReasons: Record<string, string>
  canModerate?: boolean
}

export default function CommunityFeedList({
  items,
  emptyLabel,
  reportReasons,
  canModerate = false,
}: Props) {
  if (items.length === 0) {
    return (
      <Card className="border-dashed border-gray-300 bg-white shadow-none dark:border-white/15 dark:bg-[#111827]">
        <CardContent className="flex flex-col items-center py-14 text-center">
          {emptyLabel.toLowerCase().includes("announce") ? (
            <Megaphone className="mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" />
          ) : (
            <MessageSquare className="mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" />
          )}
          <p className="font-medium text-gray-700 dark:text-gray-200">No {emptyLabel} yet</p>
          <p className="mt-1 max-w-sm text-sm text-muted-foreground">
            Be the first to post and get the conversation started.
          </p>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-4">
      {items.map((item) => (
        <Card
          key={item.id}
          className="border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-white/10 dark:bg-[#111827]"
        >
          <CardHeader className="pb-2">
            <div className="flex flex-wrap items-center gap-2">
              {item.is_pinned && (
                <Badge variant="secondary" className="gap-1">
                  <Pin className="h-3 w-3" /> Pinned
                </Badge>
              )}
              {item.is_locked && (
                <Badge variant="outline" className="gap-1">
                  <Lock className="h-3 w-3" /> Locked
                </Badge>
              )}
              {item.category && <Badge variant="outline">{item.category}</Badge>}
            </div>
            <CardTitle className="text-lg sm:text-xl">
              <Link
                href={route("community.contents.show", item.slug)}
                className="hover:text-purple-600 hover:underline dark:hover:text-purple-300"
              >
                {item.title}
              </Link>
            </CardTitle>
            <CardDescription>
              {item.author?.name ?? "Member"}
              {typeof item.replies_count === "number" ? ` · ${item.replies_count} replies` : ""}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {item.cover_image && (
              <img src={item.cover_image} alt="" className="mb-3 max-h-48 w-full rounded-lg object-cover" />
            )}
            <p className="line-clamp-3 text-sm leading-relaxed text-muted-foreground">{item.body}</p>
            <div className="mt-4 flex flex-wrap gap-2">
              <Button asChild size="sm" variant="outline" className="cursor-pointer">
                <Link href={route("community.contents.show", item.slug)}>Open</Link>
              </Button>
              <ReportDialog
                reportableType="CommunityContent"
                reportableId={item.id}
                reportReasons={reportReasons}
              />
              {canModerate && (
                <>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="cursor-pointer"
                    onClick={() =>
                      router.post(route("community.contents.moderate", item.slug), {
                        action: "lock",
                        reason: "Moderator lock",
                      })
                    }
                  >
                    Lock
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="cursor-pointer"
                    onClick={() =>
                      router.post(route("community.contents.moderate", item.slug), {
                        action: "hide",
                        reason: "Hidden by moderator",
                      })
                    }
                  >
                    Hide
                  </Button>
                </>
              )}
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

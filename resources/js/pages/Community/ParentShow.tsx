"use client"

import { Head, Link, router } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import CommunityComposer from "@/components/community/CommunityComposer"
import CommunityFeedList, { type CommunityFeedItem } from "@/components/community/CommunityFeedList"
import { Button } from "@/components/frontend/ui/button"
import { ArrowLeft, Megaphone, MessageSquare } from "lucide-react"

type Props = {
  parent: {
    type: string
    id: number
    name: string
    kind: "organization" | "alliance"
  }
  tab: string
  discussions: CommunityFeedItem[]
  announcements: CommunityFeedItem[]
  canCreateDiscussion: boolean
  canCreateAnnouncement: boolean
  canModerate: boolean
  backUrl: string
  reportReasons: Record<string, string>
  categories: string[]
}

export default function ParentShow({
  parent,
  tab,
  discussions,
  announcements,
  canCreateDiscussion,
  canCreateAnnouncement,
  canModerate,
  backUrl,
  reportReasons,
  categories,
}: Props) {
  const setTab = (key: string) => {
    router.get(
      route("community.parent.show", { parentType: parent.type, parentId: parent.id }),
      { tab: key },
      { preserveState: true, preserveScroll: true },
    )
  }

  const items = tab === "announcements" ? announcements : discussions

  return (
    <FrontendLayout>
      <Head title={`${parent.name} — Community`} />
      <div className="min-h-screen bg-gray-50 text-gray-900 dark:bg-[#0a0f1a] dark:text-white">
        <div className="container mx-auto px-4 py-8 pb-16">
          <Link
            href={backUrl}
            className="mb-4 inline-flex cursor-pointer items-center text-sm text-purple-600 hover:underline dark:text-purple-300"
          >
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to {parent.name}
          </Link>

          <div className="mb-6">
            <p className="text-sm font-medium uppercase tracking-wide text-purple-600 dark:text-purple-300">
              {parent.kind === "alliance" ? "Unity Impact Alliance community" : "Organization community"}
            </p>
            <h1 className="mt-1 text-3xl font-bold">{parent.name}</h1>
            <p className="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-400">
              Official announcements and community discussions — same framework used by Groups.
            </p>
          </div>

          <div className="mb-6 flex gap-2 border-b border-gray-200 dark:border-white/10">
            <Button
              type="button"
              variant="ghost"
              className={`cursor-pointer rounded-none border-b-2 px-4 ${
                tab === "discussion"
                  ? "border-purple-600 text-purple-600"
                  : "border-transparent text-gray-500"
              }`}
              onClick={() => setTab("discussion")}
            >
              <MessageSquare className="mr-2 h-4 w-4" />
              Discussion
            </Button>
            <Button
              type="button"
              variant="ghost"
              className={`cursor-pointer rounded-none border-b-2 px-4 ${
                tab === "announcements"
                  ? "border-purple-600 text-purple-600"
                  : "border-transparent text-gray-500"
              }`}
              onClick={() => setTab("announcements")}
            >
              <Megaphone className="mr-2 h-4 w-4" />
              Announcements
            </Button>
          </div>

          <div className="space-y-4">
            <CommunityComposer
              parentType={parent.type}
              parentId={parent.id}
              type={tab === "announcements" ? "announcement" : "discussion"}
              canCreate={tab === "announcements" ? canCreateAnnouncement : canCreateDiscussion}
              categories={categories}
            />
            <CommunityFeedList
              items={items}
              emptyLabel={tab === "announcements" ? "announcements" : "discussions"}
              reportReasons={reportReasons}
              canModerate={canModerate}
            />
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}

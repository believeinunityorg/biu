"use client"

import { Link } from "@inertiajs/react"

export type GroupDirectoryCardData = {
  id: number
  name: string
  slug: string
  category: string | null
  cover_image: string | null
  icon_image: string | null
  visibility: string
  members_count: number
  url: string
  parent_name?: string | null
}

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("")
}

export default function GroupDirectoryCard({ group }: { group: GroupDirectoryCardData }) {
  const isPublic = group.visibility === "public" || !group.visibility

  return (
    <Link
      href={group.url}
      className="block h-full cursor-pointer overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-purple-200 hover:shadow-md hover:shadow-purple-500/10 dark:border-purple-500/20 dark:bg-[#0d1424] dark:hover:border-purple-500/40"
    >
      <div className="relative h-20 w-full overflow-hidden sm:h-24">
        {group.cover_image ? (
          <img src={group.cover_image} alt="" className="h-full w-full object-cover" />
        ) : (
          <div className="h-full w-full bg-gradient-to-r from-purple-600 via-blue-600 to-indigo-600" />
        )}
        <div className="absolute inset-0 bg-gradient-to-t from-black/25 to-transparent" />
      </div>

      <div className="relative px-3 pb-3 pt-0">
        <div className="-mt-6 mb-2 flex h-12 w-12 items-center justify-center overflow-hidden rounded-xl border-[3px] border-white bg-gradient-to-br from-purple-600 to-blue-600 shadow dark:border-[#0d1424]">
          {group.icon_image ? (
            <img src={group.icon_image} alt="" className="h-full w-full object-cover" />
          ) : (
            <span className="text-xs font-bold text-white">{initials(group.name) || "G"}</span>
          )}
        </div>
        <p className="truncate text-[15px] font-bold leading-tight text-slate-900 dark:text-white">
          {group.name}
        </p>
        <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
          {isPublic ? "Public" : group.visibility === "hidden" ? "Hidden" : "Private"} group
          {" · "}
          {group.members_count.toLocaleString()}{" "}
          {group.members_count === 1 ? "member" : "members"}
        </p>
        {group.parent_name && (
          <p className="mt-0.5 truncate text-[11px] text-slate-400">{group.parent_name}</p>
        )}
        {group.category && (
          <span className="mt-2 inline-block rounded-full bg-gradient-to-r from-purple-100 to-blue-100 px-2 py-0.5 text-[10px] font-semibold text-purple-800 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-200">
            {group.category}
          </span>
        )}
      </div>
    </Link>
  )
}

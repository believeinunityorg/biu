"use client"

import { useMemo, useState } from "react"
import { Head, Link, router } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import ReportDialog from "@/components/community/ReportDialog"
import {
  ArrowRight,
  Eye,
  EyeOff,
  MoreHorizontal,
  Pin,
  Plus,
  Search,
  Sparkles,
  Star,
  Users,
  UsersRound,
} from "lucide-react"

type GroupCard = {
  id: number
  name: string
  slug: string
  description: string | null
  category: string | null
  cover_image: string | null
  is_featured: boolean
  is_pinned: boolean
  is_hidden_on_parent: boolean
  members_count: number
  url: string
  parent_name?: string | null
}

type Props = {
  title: string
  parent: { type: string; id: number; name: string } | null
  canCreate: boolean
  createUrl: string | null
  canManageParent?: boolean
  groups: GroupCard[]
  reportReasons?: Record<string, string>
}

type FilterKey = "all" | "pinned" | "featured" | "hidden"

function updateParentControls(
  slug: string,
  group: GroupCard,
  patch: Partial<Pick<GroupCard, "is_pinned" | "is_featured" | "is_hidden_on_parent">>,
) {
  router.post(
    route("groups.parent-controls", slug),
    {
      is_pinned: patch.is_pinned ?? group.is_pinned,
      is_featured: patch.is_featured ?? group.is_featured,
      is_hidden_on_parent: patch.is_hidden_on_parent ?? group.is_hidden_on_parent,
    },
    { preserveScroll: true },
  )
}

export default function GroupIndex({
  title,
  parent,
  canCreate,
  createUrl,
  canManageParent,
  groups,
  reportReasons = {},
}: Props) {
  const [query, setQuery] = useState("")
  const [filter, setFilter] = useState<FilterKey>("all")

  const stats = useMemo(
    () => ({
      total: groups.length,
      pinned: groups.filter((g) => g.is_pinned).length,
      featured: groups.filter((g) => g.is_featured).length,
      hidden: groups.filter((g) => g.is_hidden_on_parent).length,
      members: groups.reduce((sum, g) => sum + (g.members_count || 0), 0),
    }),
    [groups],
  )

  const categories = useMemo(() => {
    const set = new Set<string>()
    groups.forEach((g) => {
      if (g.category) set.add(g.category)
    })
    return Array.from(set).sort()
  }, [groups])

  const [category, setCategory] = useState<string>("all")

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    return groups.filter((g) => {
      if (filter === "pinned" && !g.is_pinned) return false
      if (filter === "featured" && !g.is_featured) return false
      if (filter === "hidden" && !g.is_hidden_on_parent) return false
      if (category !== "all" && g.category !== category) return false
      if (!q) return true
      return (
        g.name.toLowerCase().includes(q) ||
        (g.description ?? "").toLowerCase().includes(q) ||
        (g.category ?? "").toLowerCase().includes(q)
      )
    })
  }, [groups, query, filter, category])

  const filterChips: { key: FilterKey; label: string; count: number }[] = [
    { key: "all", label: "All", count: stats.total },
    { key: "pinned", label: "Pinned", count: stats.pinned },
    { key: "featured", label: "Featured", count: stats.featured },
    ...(canManageParent ? [{ key: "hidden" as const, label: "Hidden", count: stats.hidden }] : []),
  ]

  return (
    <AppLayout breadcrumbs={[{ title: "Community Groups", href: "/organization/groups" }]}>
      <Head title={title} />

      <div className="flex w-full flex-1 flex-col gap-6 p-4 sm:p-6">
        {/* Header */}
        <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm">
          <div className="absolute inset-0 bg-gradient-to-r from-purple-600/10 via-blue-600/10 to-transparent dark:from-purple-600/20 dark:via-blue-600/15" />
          <div className="relative flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-7">
            <div className="min-w-0 space-y-2">
              <div className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-sm">
                <Sparkles className="h-3.5 w-3.5" />
                Community Groups
              </div>
              <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">{title}</h1>
              <p className="max-w-2xl text-sm text-muted-foreground sm:text-base">
                {parent
                  ? `Groups under ${parent.name}. Followers and members can create groups instantly — no approval required.`
                  : "Groups you created or joined across organizations and alliances."}
              </p>
            </div>
            {canCreate && createUrl && (
              <Button
                asChild
                size="lg"
                className="shrink-0 cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md hover:from-purple-700 hover:to-blue-700"
              >
                <Link href={createUrl}>
                  <Plus className="mr-2 h-5 w-5" />
                  Create Group
                </Link>
              </Button>
            )}
          </div>

          {/* Stats */}
          <div className="relative grid grid-cols-2 gap-px border-t border-border/60 bg-border/40 sm:grid-cols-4">
            {[
              { label: "Groups", value: stats.total, icon: UsersRound },
              { label: "Members", value: stats.members, icon: Users },
              { label: "Pinned", value: stats.pinned, icon: Pin },
              { label: "Featured", value: stats.featured, icon: Star },
            ].map((stat) => (
              <div key={stat.label} className="flex items-center gap-3 bg-card px-4 py-3.5 sm:px-5">
                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-purple-600/15 to-blue-600/15 text-purple-700 dark:text-purple-300">
                  <stat.icon className="h-4 w-4" />
                </div>
                <div>
                  <p className="text-lg font-bold tabular-nums leading-none">{stat.value}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">{stat.label}</p>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Toolbar */}
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex flex-wrap gap-2">
            {filterChips.map((chip) => (
              <button
                key={chip.key}
                type="button"
                onClick={() => setFilter(chip.key)}
                className={`cursor-pointer rounded-full px-3.5 py-1.5 text-sm font-medium transition ${
                  filter === chip.key
                    ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm"
                    : "bg-muted/70 text-muted-foreground hover:bg-muted hover:text-foreground"
                }`}
              >
                {chip.label}
                <span className="ml-1.5 tabular-nums opacity-80">({chip.count})</span>
              </button>
            ))}
          </div>

          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            {categories.length > 0 && (
              <select
                value={category}
                onChange={(e) => setCategory(e.target.value)}
                className="h-10 cursor-pointer rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value="all">All categories</option>
                {categories.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            )}
            <div className="relative w-full sm:w-72">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search groups…"
                className="h-10 pl-9"
              />
            </div>
          </div>
        </div>

        {/* Grid / empty */}
        {filtered.length === 0 ? (
          <Card className="border-dashed">
            <CardContent className="flex flex-col items-center justify-center gap-4 px-6 py-20 text-center">
              <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600/15 to-blue-600/15">
                <UsersRound className="h-8 w-8 text-purple-700 dark:text-purple-300" />
              </div>
              <div className="space-y-1">
                <h2 className="text-lg font-semibold">
                  {groups.length === 0 ? "No groups yet" : "No groups match your filters"}
                </h2>
                <p className="max-w-md text-sm text-muted-foreground">
                  {groups.length === 0
                    ? "Start a Bible study, volunteer team, support circle, or interest group and grow your community."
                    : "Try a different search or clear your filters."}
                </p>
              </div>
              {groups.length === 0 && canCreate && createUrl ? (
                <Button asChild className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                  <Link href={createUrl}>
                    <Plus className="mr-2 h-4 w-4" />
                    Create your first group
                  </Link>
                </Button>
              ) : groups.length > 0 ? (
                <Button
                  variant="outline"
                  className="cursor-pointer"
                  onClick={() => {
                    setQuery("")
                    setFilter("all")
                    setCategory("all")
                  }}
                >
                  Clear filters
                </Button>
              ) : null}
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            {filtered.map((group) => (
              <article
                key={group.id}
                className="group/card flex flex-col overflow-hidden rounded-2xl border border-border/70 bg-card text-card-foreground shadow-md transition hover:-translate-y-0.5 hover:shadow-lg dark:border-gray-700"
              >
                {/* Top: edge-to-edge image only (no card padding) */}
                <div className="relative m-0 h-44 w-full shrink-0 overflow-hidden p-0">
                  {group.cover_image ? (
                    <img
                      src={group.cover_image}
                      alt={group.name}
                      className="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover/card:scale-105"
                    />
                  ) : (
                    <div className="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-purple-600 to-blue-600">
                      <UsersRound className="h-12 w-12 text-white/90" />
                    </div>
                  )}
                </div>

                {/* Bottom: information */}
                <div className="flex flex-1 flex-col gap-3 p-4">
                  <div className="flex flex-wrap gap-1.5">
                    {group.is_pinned && (
                      <Badge variant="secondary" className="gap-1">
                        <Pin className="h-3 w-3" /> Pinned
                      </Badge>
                    )}
                    {group.is_featured && (
                      <Badge variant="secondary" className="gap-1">
                        <Star className="h-3 w-3" /> Featured
                      </Badge>
                    )}
                    {group.is_hidden_on_parent && (
                      <Badge variant="outline" className="gap-1">
                        <EyeOff className="h-3 w-3" /> Hidden
                      </Badge>
                    )}
                    {group.category && <Badge variant="outline">{group.category}</Badge>}
                  </div>

                  <div className="min-w-0 space-y-1">
                    <Link
                      href={group.url}
                      className="line-clamp-1 text-lg font-semibold tracking-tight text-foreground hover:text-purple-700 dark:hover:text-purple-300"
                    >
                      {group.name}
                    </Link>
                    <p className="line-clamp-2 min-h-[2.5rem] text-sm text-muted-foreground">
                      {group.description || "No description yet."}
                    </p>
                    {group.parent_name && !parent && (
                      <p className="truncate text-xs text-muted-foreground">Under {group.parent_name}</p>
                    )}
                  </div>

                  <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Users className="h-3.5 w-3.5" />
                    {group.members_count} {group.members_count === 1 ? "member" : "members"}
                  </p>

                  <div className="mt-auto flex items-center justify-between gap-2 border-t border-border/60 pt-3">
                    <Button asChild size="sm" className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                      <Link href={group.url}>
                        Open
                        <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                      </Link>
                    </Button>

                    {canManageParent && parent ? (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button size="sm" variant="outline" className="cursor-pointer px-2">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Manage group</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                          <DropdownMenuItem
                            className="cursor-pointer"
                            onClick={() =>
                              updateParentControls(group.slug, group, { is_pinned: !group.is_pinned })
                            }
                          >
                            <Pin className="mr-2 h-4 w-4" />
                            {group.is_pinned ? "Unpin from page" : "Pin on page"}
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="cursor-pointer"
                            onClick={() =>
                              updateParentControls(group.slug, group, { is_featured: !group.is_featured })
                            }
                          >
                            <Star className="mr-2 h-4 w-4" />
                            {group.is_featured ? "Remove featured" : "Feature group"}
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            className="cursor-pointer"
                            onClick={() =>
                              updateParentControls(group.slug, group, {
                                is_hidden_on_parent: !group.is_hidden_on_parent,
                              })
                            }
                          >
                            {group.is_hidden_on_parent ? (
                              <>
                                <Eye className="mr-2 h-4 w-4" />
                                Show on org page
                              </>
                            ) : (
                              <>
                                <EyeOff className="mr-2 h-4 w-4" />
                                Hide from org page
                              </>
                            )}
                          </DropdownMenuItem>
                          {Object.keys(reportReasons).length > 0 && (
                            <>
                              <DropdownMenuSeparator />
                              <div className="px-2 py-1.5">
                                <ReportDialog
                                  reportableType="Group"
                                  reportableId={group.id}
                                  reportReasons={reportReasons}
                                  triggerLabel="Report to BIU"
                                  className="h-8 w-full justify-start px-2"
                                />
                              </div>
                            </>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    ) : Object.keys(reportReasons).length > 0 ? (
                      <ReportDialog
                        reportableType="Group"
                        reportableId={group.id}
                        reportReasons={reportReasons}
                        triggerLabel="Report"
                      />
                    ) : null}
                  </div>
                </div>
              </article>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  )
}

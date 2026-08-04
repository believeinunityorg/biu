import { Head, Link, router } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ChevronDown, ChevronRight } from 'lucide-react'

type TreeMember = {
  id: number
  full_name: string
  photo_url?: string | null
  birth_year?: number | null
  death_year?: number | null
  generation?: number | null
  status: string
  branch?: string | null
  branch_id?: number | null
  children_ids: number[]
  is_claimed: boolean
}

type TreeBranch = {
  id: number
  name: string
  head: TreeMember | null
  members: TreeMember[]
  members_count: number
}

type Props = {
  organization: { id: number; name: string }
  tree: {
    founders: {
      grandfather: TreeMember | null
      grandmother: TreeMember | null
    } | null
    branches: TreeBranch[]
    members: TreeMember[]
  }
  filters: { branch_id: number | null }
  branches: { id: number; name: string }[]
}

function MemberCard({ member, compact = false }: { member: TreeMember; compact?: boolean }) {
  return (
    <div className={`rounded-xl border border-border bg-card ${compact ? 'px-3 py-2' : 'px-4 py-3'} shadow-sm`}>
      <div className="flex items-center gap-3">
        <div className={`${compact ? 'h-8 w-8' : 'h-11 w-11'} overflow-hidden rounded-full ${fr.avatar}`}>
          {member.photo_url ? (
            <img src={member.photo_url} alt="" className="h-full w-full object-cover" />
          ) : (
            <div className={`flex h-full w-full items-center justify-center text-xs font-semibold ${fr.text}`}>
              {member.full_name.slice(0, 1)}
            </div>
          )}
        </div>
        <div>
          <div className="text-sm font-semibold text-foreground">{member.full_name}</div>
          <div className="text-xs text-muted-foreground">
            {member.birth_year || member.death_year
              ? `${member.birth_year ?? '?'} – ${member.death_year ?? 'Present'}`
              : member.branch || 'Family member'}
          </div>
        </div>
      </div>
    </div>
  )
}

export default function Tree({ organization, tree, filters, branches }: Props) {
  const [expanded, setExpanded] = useState<Record<number, boolean>>(() =>
    Object.fromEntries((tree.branches ?? []).map((b) => [b.id, true])),
  )

  const memberMap = useMemo(() => {
    const map = new Map<number, TreeMember>()
    for (const m of tree.members ?? []) map.set(m.id, m)
    return map
  }, [tree.members])

  const setBranchFilter = (value: string) => {
    router.get(
      '/organization/family-reunion/tree',
      value === 'all' ? {} : { branch_id: value },
      { preserveState: true, replace: true },
    )
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Family Tree', href: '/organization/family-reunion/tree' },
      ]}
    >
      <Head title="Family Tree" />
      <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <Card className="border-border shadow-sm">
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle>Family Tree</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  Starts with the founding couple, expands into branches, and links parents to children.
                </p>
              </div>
              <Select
                value={filters.branch_id ? String(filters.branch_id) : 'all'}
                onValueChange={setBranchFilter}
              >
                <SelectTrigger className="w-[220px]">
                  <SelectValue placeholder="Filter by branch" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All branches</SelectItem>
                  {branches.map((b) => (
                    <SelectItem key={b.id} value={String(b.id)}>
                      {b.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </CardHeader>
            <CardContent className="space-y-8">
              {!tree.founders?.grandfather && !tree.founders?.grandmother ? (
                <div className="rounded-xl border border-dashed border-border p-8 text-center">
                  <p className="text-sm text-muted-foreground">Add a founding couple to start the tree.</p>
                  <Button asChild className={`mt-4 ${fr.btn}`}>
                    <Link href="/organization/family-reunion/founders">Add Founding Couple</Link>
                  </Button>
                </div>
              ) : (
                <>
                  <div className="flex flex-wrap items-center justify-center gap-4">
                    {tree.founders?.grandfather && <MemberCard member={tree.founders.grandfather} />}
                    {tree.founders?.grandmother && <MemberCard member={tree.founders.grandmother} />}
                  </div>

                  <div className="mx-auto h-8 w-px bg-slate-300" />

                  <div className="grid gap-4">
                    {(tree.branches ?? []).map((branch) => {
                      const open = expanded[branch.id] !== false
                      return (
                        <div key={branch.id} className="rounded-2xl border border-border bg-muted/70 p-4">
                          <button
                            type="button"
                            className="flex w-full items-center justify-between gap-3 text-left"
                            onClick={() => setExpanded((prev) => ({ ...prev, [branch.id]: !open }))}
                          >
                            <div className="flex items-center gap-2">
                              {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                              <div>
                                <div className="font-semibold text-foreground">{branch.name}</div>
                                <div className="text-xs text-muted-foreground">{branch.members_count} members</div>
                              </div>
                            </div>
                            <Badge variant="secondary">{branch.members_count}</Badge>
                          </button>

                          {open && (
                            <div className="mt-4 space-y-3 border-t border-border pt-4">
                              {branch.head && (
                                <div>
                                  <div className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Branch head
                                  </div>
                                  <MemberCard member={branch.head} compact />
                                </div>
                              )}
                              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {branch.members
                                  .filter((m) => !branch.head || m.id !== branch.head.id)
                                  .map((member) => (
                                    <div key={member.id} className="space-y-2">
                                      <MemberCard member={member} compact />
                                      {member.children_ids?.length > 0 && (
                                        <div className="ml-4 space-y-1 border-l border-border pl-3">
                                          {member.children_ids.map((cid) => {
                                            const child = memberMap.get(cid)
                                            if (!child) return null
                                            return (
                                              <div key={cid} className="text-xs text-muted-foreground">
                                                → {child.full_name}
                                              </div>
                                            )
                                          })}
                                        </div>
                                      )}
                                    </div>
                                  ))}
                              </div>
                            </div>
                          )}
                        </div>
                      )
                    })}
                  </div>
                </>
              )}
            </CardContent>
          </Card>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}

import { Link, useForm } from '@inertiajs/react'
import { FormEvent } from 'react'
import { Button } from '@/components/frontend/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/frontend/ui/card'
import { Input } from '@/components/frontend/ui/input'
import { Label } from '@/components/frontend/ui/label'
import { Checkbox } from '@/components/frontend/ui/checkbox'
import { TextArea } from '@/components/ui/textarea'
import InputError from '@/components/input-error'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/frontend/ui/select'
import { GitBranch, Network } from 'lucide-react'

export type FamilyReunionProfileData = {
  is_family_reunion: boolean
  community_organization_type_id?: number | null
  community_organization_type_slug?: string | null
  community_organization_type_other?: string | null
  family_name?: string | null
  family_motto?: string | null
  family_history?: string | null
  reunion_established?: string | null
  reunion_frequency?: string | null
  reunion_location?: string | null
  tree_visibility?: string | null
  allow_members_add_children?: boolean
  allow_members_invite_relatives?: boolean
  require_member_approval?: boolean
  allow_branch_administrators?: boolean
  grandfather_name?: string
  grandmother_name?: string
  branches?: Array<{
    id: number
    name: string
    admin_name?: string | null
    admin_email?: string | null
    members_count?: number
  }>
}

type Props = {
  data: {
    family_name: string
    family_motto: string
    family_history: string
    reunion_established: string
    reunion_frequency: string
    reunion_location: string
    tree_visibility: string
    allow_members_add_children: boolean
    allow_members_invite_relatives: boolean
    require_member_approval: boolean
    allow_branch_administrators: boolean
    grandfather_name: string
    grandmother_name: string
  }
  setData: (key: string, value: string | boolean) => void
  errors: Record<string, string>
  branches: FamilyReunionProfileData['branches']
  canManageBranches?: boolean
}

export function FamilyReunionProfileSection({
  data,
  setData,
  errors,
  branches = [],
  canManageBranches = true,
}: Props) {
  const branchForm = useForm({
    name: '',
    admin_name: '',
    admin_email: '',
  })

  const submitBranch = (e: FormEvent) => {
    e.preventDefault()
    branchForm.post('/organization/family-reunion/branches', {
      preserveScroll: true,
      onSuccess: () => branchForm.reset(),
    })
  }

  return (
    <Card className="border-border shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-lg">
          <Network className="h-5 w-5 text-blue-600 dark:text-blue-400" />
          Family Reunion
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          Profile extension for Family Reunion organizations. Members are connected through family
          relationships. You continue using all standard BIU features — Announcements, Discussion
          Board, Events, Members, Donations, Marketplace, Media, and Groups.
        </p>
      </CardHeader>
      <CardContent className="space-y-8">
        {/* Family Information */}
        <section className="space-y-4">
          <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Family Information
          </h3>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="family_name">Family Name</Label>
              <Input
                id="family_name"
                className="mt-1.5"
                value={data.family_name}
                onChange={(e) => setData('family_name', e.target.value)}
                placeholder="e.g. Matthews Family"
              />
              <InputError message={errors.family_name} className="mt-1" />
            </div>
            <div>
              <Label htmlFor="family_motto">Family Motto (Optional)</Label>
              <Input
                id="family_motto"
                className="mt-1.5"
                value={data.family_motto}
                onChange={(e) => setData('family_motto', e.target.value)}
                placeholder="Optional motto"
              />
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="family_history">Family History</Label>
              <TextArea
                id="family_history"
                className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                rows={4}
                value={data.family_history}
                onChange={(e) => setData('family_history', e.target.value)}
                placeholder="Share the story of your family reunion…"
              />
              <InputError message={errors.family_history} className="mt-1" />
            </div>
            <div>
              <Label htmlFor="reunion_established">Reunion Established</Label>
              <Input
                id="reunion_established"
                type="date"
                className="mt-1.5"
                value={data.reunion_established}
                onChange={(e) => setData('reunion_established', e.target.value)}
              />
            </div>
            <div>
              <Label>Reunion Frequency</Label>
              <Select
                value={data.reunion_frequency || 'annual'}
                onValueChange={(v) => setData('reunion_frequency', v)}
              >
                <SelectTrigger className="mt-1.5">
                  <SelectValue placeholder="Select frequency" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="annual">Annual</SelectItem>
                  <SelectItem value="biennial">Every 2 years</SelectItem>
                  <SelectItem value="triennial">Every 3 years</SelectItem>
                  <SelectItem value="occasional">Occasional</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="sm:col-span-2">
              <Label htmlFor="reunion_location">Reunion Location</Label>
              <Input
                id="reunion_location"
                className="mt-1.5"
                value={data.reunion_location}
                onChange={(e) => setData('reunion_location', e.target.value)}
                placeholder="City, State"
              />
            </div>
          </div>
        </section>

        {/* Founding Family */}
        <section className="space-y-4">
          <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Founding Family
          </h3>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="grandfather_name">Great-Grandfather *</Label>
              <Input
                id="grandfather_name"
                className="mt-1.5"
                value={data.grandfather_name}
                onChange={(e) => setData('grandfather_name', e.target.value)}
                placeholder="e.g. James Matthews"
              />
              <InputError message={errors.grandfather_name} className="mt-1" />
            </div>
            <div>
              <Label htmlFor="grandmother_name">Great-Grandmother *</Label>
              <Input
                id="grandmother_name"
                className="mt-1.5"
                value={data.grandmother_name}
                onChange={(e) => setData('grandmother_name', e.target.value)}
                placeholder="e.g. Mary Matthews"
              />
              <InputError message={errors.grandmother_name} className="mt-1" />
            </div>
          </div>
        </section>

        {/* Family Branches */}
        <section className="space-y-4">
          <div className="flex items-center gap-2">
            <GitBranch className="h-4 w-4 text-blue-600 dark:text-blue-400" />
            <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
              Family Branches
            </h3>
          </div>

          <form
            onSubmit={submitBranch}
            className="grid grid-cols-1 gap-3 rounded-xl border border-border bg-muted/20 p-4 sm:grid-cols-3"
          >
            {!canManageBranches && (
              <p className="sm:col-span-3 text-sm text-amber-700 dark:text-amber-300">
                Save Changes first to enable Family Reunion, then you can add branches here.
              </p>
            )}
            <div>
              <Label htmlFor="branch_name">Branch Name</Label>
              <Input
                id="branch_name"
                className="mt-1.5"
                value={branchForm.data.name}
                onChange={(e) => branchForm.setData('name', e.target.value)}
                placeholder="e.g. John Matthews Branch"
                disabled={!canManageBranches}
              />
              {branchForm.errors.name && (
                <p className="mt-1 text-sm text-red-600">{branchForm.errors.name}</p>
              )}
            </div>
            <div>
              <Label htmlFor="branch_admin_name">Branch Administrator</Label>
              <Input
                id="branch_admin_name"
                className="mt-1.5"
                value={branchForm.data.admin_name}
                onChange={(e) => branchForm.setData('admin_name', e.target.value)}
                placeholder="Administrator name"
                disabled={!canManageBranches}
              />
            </div>
            <div>
              <Label htmlFor="branch_admin_email">Email (Optional)</Label>
              <Input
                id="branch_admin_email"
                type="email"
                className="mt-1.5"
                value={branchForm.data.admin_email}
                onChange={(e) => branchForm.setData('admin_email', e.target.value)}
                placeholder="admin@example.com"
                disabled={!canManageBranches}
              />
            </div>
            <div className="sm:col-span-3">
              <Button
                type="submit"
                variant="outline"
                size="sm"
                disabled={!canManageBranches || branchForm.processing || !branchForm.data.name.trim()}
              >
                {branchForm.processing ? 'Adding…' : '+ Add Branch'}
              </Button>
            </div>
          </form>

          <div className="overflow-x-auto rounded-xl border border-border">
            <table className="w-full min-w-[520px] text-left text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="px-3 py-2 font-medium">Branch Name</th>
                  <th className="px-3 py-2 font-medium">Branch Administrator</th>
                  <th className="px-3 py-2 font-medium">Email</th>
                </tr>
              </thead>
              <tbody>
                {branches.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="px-3 py-6 text-center text-muted-foreground">
                      No branches yet. Add a branch above.
                    </td>
                  </tr>
                ) : (
                  branches.map((branch) => (
                    <tr key={branch.id} className="border-b border-border last:border-0">
                      <td className="px-3 py-2.5 font-medium text-foreground">{branch.name}</td>
                      <td className="px-3 py-2.5 text-muted-foreground">{branch.admin_name || '—'}</td>
                      <td className="px-3 py-2.5 text-muted-foreground">{branch.admin_email || '—'}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </section>

        {/* Family Privacy */}
        <section className="space-y-4">
          <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Family Privacy
          </h3>
          <div>
            <Label>Who can view the Family Tree?</Label>
            <Select
              value={data.tree_visibility || 'family_members_only'}
              onValueChange={(v) => setData('tree_visibility', v)}
            >
              <SelectTrigger className="mt-1.5 max-w-md">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="public">Public</SelectItem>
                <SelectItem value="family_members_only">Family Members Only</SelectItem>
                <SelectItem value="branch_members_only">Branch Members Only</SelectItem>
                <SelectItem value="administrators_only">Administrators Only</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </section>

        {/* Family Settings */}
        <section className="space-y-4">
          <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
            Family Settings
          </h3>
          <div className="space-y-3">
            {(
              [
                ['allow_members_add_children', 'Allow members to add children'],
                ['allow_members_invite_relatives', 'Allow members to invite relatives'],
                ['require_member_approval', 'Require approval before new family members appear'],
                ['allow_branch_administrators', 'Allow Branch Administrators'],
              ] as const
            ).map(([key, label]) => (
              <label key={key} className="flex cursor-pointer items-center gap-2 text-sm text-foreground">
                <Checkbox
                  checked={Boolean(data[key])}
                  onCheckedChange={(checked) => setData(key, checked === true)}
                />
                {label}
              </label>
            ))}
          </div>
        </section>

        <div className="rounded-lg border border-blue-200 bg-blue-50/60 px-4 py-3 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-100">
          This is a profile extension only. Family tree tools are available in the Family Reunion hub;
          all other BIU features remain available as usual.
        </div>

        <div className="flex flex-wrap gap-2">
          <Button asChild type="button" variant="outline" size="sm">
            <Link href="/organization/family-reunion">Open Family Reunion Hub</Link>
          </Button>
          <Button asChild type="button" variant="outline" size="sm">
            <Link href="/organization/family-reunion/tree">View Family Tree</Link>
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

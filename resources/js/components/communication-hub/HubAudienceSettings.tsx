import { useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

export type HubSettingsForm = {
  announcement_visibility: string
  discussion_visibility: string
  allow_followers_to_post: boolean
  allow_members_to_post: boolean
  require_approval: boolean
  allow_attachments: boolean
  allow_mentions: boolean
  enable_reactions: boolean
  enable_comments: boolean
  enable_reporting: boolean
  auto_archive_days: number | null
  visibility_audiences?: Record<string, string>
}

type Props = {
  settings: HubSettingsForm
  organizationName: string
  className?: string
}

export default function HubAudienceSettings({ settings, organizationName, className }: Props) {
  const audiences = settings.visibility_audiences ?? {
    public: 'Everyone (public)',
    followers: 'Followers & members',
    members: 'Members only',
    staff: 'Staff only',
  }

  const form = useForm({
    announcement_visibility: settings.announcement_visibility ?? 'public',
    discussion_visibility: settings.discussion_visibility ?? 'public',
    allow_followers_to_post: settings.allow_followers_to_post,
    allow_members_to_post: settings.allow_members_to_post,
    require_approval: settings.require_approval,
    allow_attachments: settings.allow_attachments,
    allow_mentions: settings.allow_mentions,
    enable_reactions: settings.enable_reactions,
    enable_comments: settings.enable_comments,
    enable_reporting: settings.enable_reporting,
    auto_archive_days: settings.auto_archive_days ?? '',
  })

  const toggles: Array<{
    key: keyof Omit<typeof form.data, 'auto_archive_days' | 'announcement_visibility' | 'discussion_visibility'>
    label: string
    help: string
  }> = [
    { key: 'allow_followers_to_post', label: 'Allow Followers to Post', help: 'Followers can start discussions on the Discussion Board.' },
    { key: 'allow_members_to_post', label: 'Allow Members to Post', help: 'Verified members can start discussions on the Discussion Board.' },
    { key: 'require_approval', label: 'Require Approval', help: 'New discussions need moderator approval before appearing.' },
    { key: 'allow_attachments', label: 'Allow Attachments', help: 'Users can attach files to discussions and replies.' },
    { key: 'allow_mentions', label: 'Allow Mentions', help: 'Users can mention others with @.' },
    { key: 'enable_reactions', label: 'Enable Reactions', help: 'Emoji reactions on discussions and replies.' },
    { key: 'enable_comments', label: 'Enable Comments', help: 'Followers and members can comment on announcements when the post allows it.' },
    { key: 'enable_reporting', label: 'Enable Reporting', help: 'Users can report discussions and replies.' },
  ]

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        form.transform((data) => ({
          ...data,
          auto_archive_days: data.auto_archive_days === '' ? null : Number(data.auto_archive_days),
        }))
        form.put(route('org.communication-hub.settings.update'), { preserveScroll: true })
      }}
      className={cn(ch.card, 'space-y-6 p-5 sm:p-6', className)}
    >
      <div>
        <h2 className="text-lg font-semibold text-foreground">Board access for {organizationName}</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Control who can view Announcements and the Discussion Board, and who can post as a supporter.
        </p>
      </div>

      <section className="space-y-4">
        <div>
          <h3 className="text-sm font-semibold text-foreground">Who can view</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Organizations choose the audience for Announcements and Discussions separately.
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="hub_announcement_visibility">Announcements</Label>
            <select
              id="hub_announcement_visibility"
              className={cn('flex h-10 w-full rounded-lg border border-input bg-background px-3 text-sm', ch.focus)}
              value={form.data.announcement_visibility}
              onChange={(e) => form.setData('announcement_visibility', e.target.value)}
            >
              {Object.entries(audiences).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">Who can see official organization announcements.</p>
          </div>

          <div className="space-y-2">
            <Label htmlFor="hub_discussion_visibility">Discussion Board</Label>
            <select
              id="hub_discussion_visibility"
              className={cn('flex h-10 w-full rounded-lg border border-input bg-background px-3 text-sm', ch.focus)}
              value={form.data.discussion_visibility}
              onChange={(e) => form.setData('discussion_visibility', e.target.value)}
            >
              {Object.entries(audiences).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">Who can open and read the Discussion Board.</p>
          </div>
        </div>
      </section>

      <section className="space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-foreground">Who can post</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Only the organization posts Announcements. Supporters post on the Discussion Board when allowed below.
          </p>
        </div>

        {toggles.map((item) => (
          <div key={item.key} className="flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
            <div>
              <Label htmlFor={`hub_${item.key}`} className="text-sm font-medium text-foreground">
                {item.label}
              </Label>
              <p className="mt-0.5 text-xs text-muted-foreground">{item.help}</p>
            </div>
            <Switch
              id={`hub_${item.key}`}
              checked={Boolean(form.data[item.key])}
              onCheckedChange={(v) => form.setData(item.key, v)}
            />
          </div>
        ))}
      </section>

      <div className="space-y-2">
        <Label htmlFor="hub_auto_archive_days">Auto Archive Days</Label>
        <Input
          id="hub_auto_archive_days"
          type="number"
          min={1}
          placeholder="Leave blank to disable"
          value={form.data.auto_archive_days}
          onChange={(e) => form.setData('auto_archive_days', e.target.value)}
          className={cn('max-w-xs rounded-lg', ch.focus)}
        />
        <p className="text-xs text-muted-foreground">Automatically archive announcements after this many days.</p>
      </div>

      <div className="flex justify-end pt-2">
        <Button type="submit" className={ch.btn} disabled={form.processing}>
          {form.processing ? 'Saving…' : 'Save access settings'}
        </Button>
      </div>
    </form>
  )
}

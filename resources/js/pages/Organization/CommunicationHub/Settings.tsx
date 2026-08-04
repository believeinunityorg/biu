import { Head, useForm } from '@inertiajs/react'
import SettingsLayout from '@/layouts/settings/layout'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Settings = {
  allow_followers_to_post: boolean
  allow_members_to_post: boolean
  require_approval: boolean
  allow_attachments: boolean
  allow_mentions: boolean
  enable_reactions: boolean
  enable_comments: boolean
  enable_reporting: boolean
  auto_archive_days: number | null
}

type Props = {
  organization: { id: number; name: string }
  settings: Settings
}

export default function CommunicationSettings({ organization, settings }: Props) {
  const form = useForm({
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

  const toggles: Array<{ key: keyof Omit<typeof form.data, 'auto_archive_days'>; label: string; help: string }> = [
    { key: 'allow_followers_to_post', label: 'Allow Followers to Post', help: 'Followers can start discussions.' },
    { key: 'allow_members_to_post', label: 'Allow Members to Post', help: 'Members can start discussions.' },
    { key: 'require_approval', label: 'Require Approval', help: 'New discussions need moderator approval before appearing.' },
    { key: 'allow_attachments', label: 'Allow Attachments', help: 'Users can attach files to discussions and replies.' },
    { key: 'allow_mentions', label: 'Allow Mentions', help: 'Users can mention others with @.' },
    { key: 'enable_reactions', label: 'Enable Reactions', help: 'Emoji reactions on discussions and replies.' },
    { key: 'enable_comments', label: 'Enable Comments', help: 'Comments on announcements when allowed per post.' },
    { key: 'enable_reporting', label: 'Enable Reporting', help: 'Users can report discussions and replies.' },
  ]

  return (
    <SettingsLayout activeTab="communication-hub" pageTitle="Communication Hub" pageSubtitle={`Discussion and announcement settings for ${organization.name}`}>
      <Head title="Communication Hub Settings" />

      <form
        onSubmit={(e) => {
          e.preventDefault()
          form.transform((data) => ({
            ...data,
            auto_archive_days: data.auto_archive_days === '' ? null : Number(data.auto_archive_days),
          }))
          form.put(route('org.communication-hub.settings.update'))
        }}
        className={cn(ch.card, 'space-y-5 p-5 sm:p-6')}
      >
        {toggles.map((item) => (
          <div key={item.key} className="flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
            <div>
              <Label htmlFor={item.key} className="text-sm font-medium text-foreground">
                {item.label}
              </Label>
              <p className="mt-0.5 text-xs text-muted-foreground">{item.help}</p>
            </div>
            <Switch
              id={item.key}
              checked={Boolean(form.data[item.key])}
              onCheckedChange={(v) => form.setData(item.key, v)}
            />
          </div>
        ))}

        <div className="space-y-2">
          <Label htmlFor="auto_archive_days">Auto Archive Days</Label>
          <Input
            id="auto_archive_days"
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
            {form.processing ? 'Saving…' : 'Save Settings'}
          </Button>
        </div>
      </form>
    </SettingsLayout>
  )
}

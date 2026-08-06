import { cn } from '@/lib/utils'
import { ch } from '@/pages/Organization/CommunicationHub/theme'

export type HubTab = 'announcements' | 'discussions' | 'settings'

type Props = {
  value: HubTab
  onChange: (tab: HubTab) => void
  announcementsLabel?: string
  discussionsLabel?: string
  settingsLabel?: string
  showSettings?: boolean
  className?: string
}

export default function HubSegmentedTabs({
  value,
  onChange,
  announcementsLabel = 'Announcements',
  discussionsLabel = 'Discussions',
  settingsLabel = 'Access settings',
  showSettings = false,
  className,
}: Props) {
  const tabs = [
    { key: 'announcements' as const, label: announcementsLabel },
    { key: 'discussions' as const, label: discussionsLabel },
    ...(showSettings ? [{ key: 'settings' as const, label: settingsLabel }] : []),
  ]

  return (
    <div className={cn(ch.segment, className)} role="tablist" aria-label="Announcements and Discussion sections">
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          role="tab"
          aria-selected={value === tab.key}
          onClick={() => onChange(tab.key)}
          className={cn(ch.segmentBtn, value === tab.key ? ch.segmentActive : ch.segmentIdle)}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}

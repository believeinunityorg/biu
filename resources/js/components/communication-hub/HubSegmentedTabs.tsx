import { cn } from '@/lib/utils'
import { ch } from '@/pages/Organization/CommunicationHub/theme'

export type HubTab = 'announcements' | 'discussions'

type Props = {
  value: HubTab
  onChange: (tab: HubTab) => void
  announcementsLabel?: string
  discussionsLabel?: string
  className?: string
}

export default function HubSegmentedTabs({
  value,
  onChange,
  announcementsLabel = 'Announcements',
  discussionsLabel = 'Discussions',
  className,
}: Props) {
  return (
    <div className={cn(ch.segment, className)} role="tablist" aria-label="Communication Hub sections">
      {(
        [
          { key: 'announcements', label: announcementsLabel },
          { key: 'discussions', label: discussionsLabel },
        ] as const
      ).map((tab) => (
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

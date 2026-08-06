import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import DiscussionEditor from '@/components/communication-hub/DiscussionEditor'
import { type HubCategory } from '@/components/communication-hub/types'
import { hubRoute, type HubContext } from '@/lib/communication-hub-routes'

type Props = {
  open: boolean
  onOpenChange: (open: boolean) => void
  organizationName: string
  categories: HubCategory[]
  /** Community org public slug, or manage-mode omit / null */
  hubContext: HubContext
}

export default function StartDiscussionModal({
  open,
  onOpenChange,
  organizationName,
  categories,
  hubContext,
}: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>Start a Discussion</DialogTitle>
          <DialogDescription>
            Ask a question or share something with {organizationName}.
          </DialogDescription>
        </DialogHeader>

        <DiscussionEditor
          action={hubRoute('discussions.store', hubContext)}
          categories={categories}
          submitLabel="Post Discussion"
          variant="modal"
          onCancel={() => onOpenChange(false)}
          onSuccess={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  )
}

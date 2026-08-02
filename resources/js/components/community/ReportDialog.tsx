"use client"

import { useState } from "react"
import { useForm } from "@inertiajs/react"
import { Button } from "@/components/frontend/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/frontend/ui/dialog"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Flag } from "lucide-react"

type Props = {
  reportableType: "Group" | "CommunityContent" | "CommunityReply"
  reportableId: number
  reportReasons: Record<string, string>
  triggerLabel?: string
  className?: string
}

const fieldClass =
  "mt-1.5 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm outline-none transition-colors placeholder:text-gray-500 focus-visible:border-purple-500 focus-visible:ring-2 focus-visible:ring-purple-500/30 dark:border-white/15 dark:bg-[#0a0f1a] dark:text-white dark:placeholder:text-gray-500 dark:focus-visible:border-purple-400"

export default function ReportDialog({
  reportableType,
  reportableId,
  reportReasons,
  triggerLabel = "Report",
  className,
}: Props) {
  const [open, setOpen] = useState(false)
  const { data, setData, post, processing, reset, errors } = useForm({
    reportable_type: reportableType,
    reportable_id: reportableId,
    reason: "spam",
    notes: "",
  })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    post(route("community.reports.store"), {
      preserveScroll: true,
      onSuccess: () => {
        reset("notes")
        setOpen(false)
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={`cursor-pointer gap-1 border-gray-300 bg-white dark:border-white/20 dark:bg-white/10 ${className ?? ""}`}
        >
          <Flag className="h-4 w-4" />
          {triggerLabel}
        </Button>
      </DialogTrigger>
      <DialogContent className="border-gray-200 bg-white text-gray-900 dark:border-white/10 dark:bg-[#111827] dark:text-white sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="text-gray-900 dark:text-white">Report to BIU moderation</DialogTitle>
          <DialogDescription className="text-gray-600 dark:text-gray-400">
            Reports are reviewed by BIU moderators. Content is never permanently deleted.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <Label htmlFor="report-reason" className="text-gray-800 dark:text-gray-200">
              Reason
            </Label>
            <select
              id="report-reason"
              className={fieldClass}
              value={data.reason}
              onChange={(e) => setData("reason", e.target.value)}
            >
              {Object.entries(reportReasons).map(([value, label]) => (
                <option key={value} value={value} className="bg-white text-gray-900 dark:bg-[#0a0f1a] dark:text-white">
                  {label}
                </option>
              ))}
            </select>
            {errors.reason && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.reason}</p>}
          </div>
          <div>
            <Label htmlFor="report-notes" className="text-gray-800 dark:text-gray-200">
              Additional details (optional)
            </Label>
            <Textarea
              id="report-notes"
              className={fieldClass}
              value={data.notes}
              onChange={(e) => setData("notes", e.target.value)}
              rows={3}
              placeholder="Share any context that helps moderators review this…"
            />
            {errors.notes && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.notes}</p>}
          </div>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              className="cursor-pointer border-gray-300 dark:border-white/20 dark:bg-white/5 dark:hover:bg-white/10"
              onClick={() => setOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={processing}
              className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
            >
              {processing ? "Submitting…" : "Submit report"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

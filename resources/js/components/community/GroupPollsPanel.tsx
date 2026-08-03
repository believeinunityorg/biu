"use client"

import { FormEvent, useState } from "react"
import { router, useForm } from "@inertiajs/react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/frontend/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import { AlertTriangle, BarChart3, Check, Pencil, Plus, Trash2, X } from "lucide-react"

export type GroupPollItem = {
  id: number
  question: string
  allow_multiple: boolean
  closes_at: string | null
  closed_at: string | null
  is_closed: boolean
  created_at: string | null
  total_votes: number
  viewer_option_ids: number[]
  viewer_has_voted: boolean
  can_manage: boolean
  creator: { id: number; name: string } | null
  options: {
    id: number
    label: string
    votes_count: number
    percent: number
  }[]
}

type Props = {
  groupSlug: string
  polls: GroupPollItem[]
  canCreatePoll: boolean
  canVotePolls: boolean
}

export default function GroupPollsPanel({ groupSlug, polls, canCreatePoll, canVotePolls }: Props) {
  const [showComposer, setShowComposer] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm<{
    question: string
    allow_multiple: boolean
    closes_at: string
    options: string[]
  }>({
    question: "",
    allow_multiple: false,
    closes_at: "",
    options: ["", ""],
  })

  const submitPoll = (e: FormEvent) => {
    e.preventDefault()
    post(route("groups.polls.store", groupSlug), {
      preserveScroll: true,
      onSuccess: () => {
        reset()
        setData("options", ["", ""])
        setShowComposer(false)
      },
    })
  }

  return (
    <div className="space-y-4">
      {!showComposer ? (
        <div className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
          <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                <BarChart3 className="h-5 w-5" />
              </div>
              <div>
                <p className="text-[15px] font-bold text-slate-900 dark:text-white">Polls</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">Ask the group and see how members vote</p>
              </div>
            </div>
            {canCreatePoll && (
              <Button
                type="button"
                className="cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 font-semibold text-white"
                onClick={() => setShowComposer(true)}
              >
                <Plus className="mr-1.5 h-4 w-4" />
                Create poll
              </Button>
            )}
          </div>
        </div>
      ) : (
        canCreatePoll && (
          <form
            onSubmit={submitPoll}
            className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]"
          >
            <div className="flex items-center justify-between border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
              <p className="text-[15px] font-bold text-slate-900 dark:text-white">Create poll</p>
              <button
                type="button"
                className="cursor-pointer text-sm font-semibold text-purple-700 hover:underline dark:text-purple-300"
                onClick={() => setShowComposer(false)}
              >
                Cancel
              </button>
            </div>

            <div className="space-y-4 p-4">
              <Input
                className="h-11 rounded-xl border-0 border-transparent bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 text-[17px] font-semibold text-slate-900 shadow-none placeholder:font-normal placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
                value={data.question}
                onChange={(e) => setData("question", e.target.value)}
                placeholder="Ask a question…"
                required
              />
              {errors.question && <p className="text-sm text-red-600">{errors.question}</p>}

              <div className="space-y-2">
                {data.options.map((option, index) => (
                  <div key={index} className="flex items-center gap-2">
                    <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-purple-300 dark:border-purple-400/50" />
                    <Input
                      className="h-10 rounded-full border-0 border-transparent bg-gradient-to-r from-purple-50 to-blue-50 px-4 text-[15px] text-slate-900 shadow-none placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
                      value={option}
                      onChange={(e) => {
                        const next = [...data.options]
                        next[index] = e.target.value
                        setData("options", next)
                      }}
                      placeholder={`Option ${index + 1}`}
                      required
                    />
                    {data.options.length > 2 && (
                      <Button
                        type="button"
                        variant="outline"
                        className="h-9 w-9 shrink-0 cursor-pointer rounded-full border-purple-200 p-0"
                        onClick={() =>
                          setData(
                            "options",
                            data.options.filter((_, i) => i !== index),
                          )
                        }
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    )}
                  </div>
                ))}
                {data.options.length < 6 && (
                  <button
                    type="button"
                    className="flex w-full cursor-pointer items-center gap-2 rounded-full border border-dashed border-purple-200 px-4 py-2.5 text-sm font-semibold text-purple-700 hover:bg-purple-50 dark:border-purple-500/30 dark:text-purple-300 dark:hover:bg-purple-500/10"
                    onClick={() => setData("options", [...data.options, ""])}
                  >
                    <Plus className="h-4 w-4" />
                    Add option
                  </button>
                )}
                {errors.options && <p className="text-sm text-red-600">{errors.options}</p>}
              </div>

              <div className="space-y-2 rounded-xl border border-purple-100 p-3 dark:border-purple-500/20">
                <label className="flex cursor-pointer items-center justify-between gap-3 text-sm font-medium">
                  <span>Allow multiple answers</span>
                  <input
                    type="checkbox"
                    className="h-4 w-4 cursor-pointer accent-purple-600"
                    checked={data.allow_multiple}
                    onChange={(e) => setData("allow_multiple", e.target.checked)}
                  />
                </label>
                <div>
                  <Label className="text-xs text-slate-500">Close date (optional)</Label>
                  <Input
                    type="datetime-local"
                    className="mt-1 rounded-xl border-0 border-transparent bg-gradient-to-r from-purple-50 to-blue-50 text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:focus-visible:ring-purple-500/30"
                    value={data.closes_at}
                    onChange={(e) => setData("closes_at", e.target.value)}
                  />
                  {errors.closes_at && <p className="mt-1 text-sm text-red-600">{errors.closes_at}</p>}
                </div>
              </div>

              <Button
                type="submit"
                disabled={processing || !data.question.trim()}
                className="h-10 w-full cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 text-[15px] font-bold text-white"
              >
                {processing ? "Posting…" : "Post poll"}
              </Button>
            </div>
          </form>
        )
      )}

      {polls.length === 0 ? (
        <div className="rounded-xl border border-dashed border-purple-200 bg-white px-4 py-10 text-center dark:border-purple-500/30 dark:bg-[#111827]">
          <BarChart3 className="mx-auto h-8 w-8 text-purple-400" />
          <p className="mt-2 text-sm text-slate-500">No polls yet.</p>
        </div>
      ) : (
        polls.map((poll) => (
          <PollCard
            key={poll.id}
            poll={poll}
            groupSlug={groupSlug}
            canVote={canVotePolls && !poll.is_closed}
          />
        ))
      )}
    </div>
  )
}

function PollCard({
  poll,
  groupSlug,
  canVote,
}: {
  poll: GroupPollItem
  groupSlug: string
  canVote: boolean
}) {
  const [selected, setSelected] = useState<number[]>(poll.viewer_option_ids)
  const [submitting, setSubmitting] = useState(false)
  const [editing, setEditing] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const showResults = !editing && (poll.viewer_has_voted || poll.is_closed || !canVote)

  const toggleOption = (optionId: number) => {
    if (poll.allow_multiple) {
      setSelected((prev) =>
        prev.includes(optionId) ? prev.filter((id) => id !== optionId) : [...prev, optionId],
      )
      return
    }
    setSelected([optionId])
  }

  const submitVote = () => {
    if (selected.length === 0) {
      return
    }
    setSubmitting(true)
    router.post(
      route("groups.polls.vote", [groupSlug, poll.id]),
      { option_ids: selected },
      {
        preserveScroll: true,
        onFinish: () => {
          setSubmitting(false)
          setEditing(false)
        },
      },
    )
  }

  const confirmDelete = () => {
    setDeleting(true)
    router.delete(route("groups.polls.destroy", [groupSlug, poll.id]), {
      preserveScroll: true,
      onFinish: () => {
        setDeleting(false)
        setDeleteOpen(false)
      },
    })
  }

  return (
    <Card className="overflow-hidden border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
      <CardHeader className="space-y-2 pb-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <CardTitle className="text-[17px] font-bold leading-snug text-slate-900 dark:text-white">
            {poll.question}
          </CardTitle>
          <div className="flex flex-wrap gap-1.5">
            {poll.is_closed ? (
              <Badge variant="secondary" className="rounded-full font-medium">
                Closed
              </Badge>
            ) : (
              <Badge className="rounded-full border-0 bg-gradient-to-r from-purple-600 to-blue-600 font-medium text-white">
                Open
              </Badge>
            )}
            {poll.allow_multiple && (
              <Badge variant="outline" className="rounded-full border-purple-200 text-purple-700 dark:border-purple-500/30 dark:text-purple-300">
                Multi-select
              </Badge>
            )}
          </div>
        </div>
        <CardDescription className="text-[13px] text-slate-500 dark:text-slate-400">
          {poll.creator?.name ? `By ${poll.creator.name}` : "Poll"}
          {" · "}
          {poll.total_votes} {poll.total_votes === 1 ? "vote" : "votes"}
          {poll.closes_at && !poll.is_closed
            ? ` · Closes ${new Date(poll.closes_at).toLocaleString()}`
            : null}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {showResults
          ? poll.options.map((option) => {
              const mine = poll.viewer_option_ids.includes(option.id)
              return (
                <div key={option.id} className="space-y-1.5">
                  <div className="flex items-center justify-between gap-3 text-sm">
                    <span className={mine ? "font-semibold text-purple-700 dark:text-purple-300" : "text-slate-800 dark:text-slate-200"}>
                      {option.label}
                      {mine ? " · your vote" : ""}
                    </span>
                    <span className="tabular-nums text-[13px] text-slate-500">
                      {option.percent}% ({option.votes_count})
                    </span>
                  </div>
                  <div className="h-2.5 overflow-hidden rounded-full bg-gradient-to-r from-purple-100 to-blue-100 dark:from-purple-500/20 dark:to-blue-500/20">
                    <div
                      className="h-full rounded-full bg-gradient-to-r from-purple-600 to-blue-600"
                      style={{ width: `${option.percent}%` }}
                    />
                  </div>
                </div>
              )
            })
          : poll.options.map((option) => (
              <label
                key={option.id}
                className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3.5 py-3 text-sm transition-colors ${
                  selected.includes(option.id)
                    ? "border-purple-500 bg-gradient-to-r from-purple-50 to-blue-50 dark:border-purple-400 dark:from-purple-500/15 dark:to-blue-500/15"
                    : "border-purple-100 hover:bg-purple-50/50 dark:border-purple-500/20 dark:hover:bg-purple-500/10"
                }`}
              >
                <input
                  type={poll.allow_multiple ? "checkbox" : "radio"}
                  name={`poll-${poll.id}`}
                  className="cursor-pointer accent-purple-600"
                  checked={selected.includes(option.id)}
                  onChange={() => toggleOption(option.id)}
                />
                <span className="font-medium text-slate-800 dark:text-slate-100">{option.label}</span>
              </label>
            ))}

        <div className="flex flex-wrap items-center justify-end gap-1.5 border-t border-purple-50 pt-3 dark:border-purple-500/15">
          {poll.can_manage && (
            <button
              type="button"
              className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-2.5 text-[13px] font-semibold text-red-600/80 transition-colors hover:bg-red-50 hover:text-red-600 dark:text-red-400/80 dark:hover:bg-red-500/10 dark:hover:text-red-400"
              onClick={() => setDeleteOpen(true)}
            >
              <Trash2 className="h-3.5 w-3.5" />
              Delete
            </button>
          )}

          {poll.can_manage && !poll.is_closed && (
            <button
              type="button"
              className="inline-flex h-9 cursor-pointer items-center rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-purple-50 hover:text-purple-800 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
              onClick={() =>
                router.post(route("groups.polls.close", [groupSlug, poll.id]), {}, { preserveScroll: true })
              }
            >
              Close poll
            </button>
          )}

          {canVote && poll.viewer_has_voted && !poll.is_closed && !editing && (
            <button
              type="button"
              className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-purple-50 hover:text-purple-800 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
              onClick={() => {
                setSelected(poll.viewer_option_ids)
                setEditing(true)
              }}
            >
              <Pencil className="h-3.5 w-3.5" />
              Change vote
            </button>
          )}

          {editing && (
            <button
              type="button"
              className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10"
              onClick={() => setEditing(false)}
            >
              <X className="h-3.5 w-3.5" />
              Cancel
            </button>
          )}

          {canVote && !showResults && (
            <Button
              type="button"
              disabled={submitting || selected.length === 0}
              className="h-9 cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 px-4 text-[13px] font-bold text-white shadow-sm shadow-purple-500/20 hover:from-purple-700 hover:to-blue-700 disabled:opacity-50"
              onClick={submitVote}
            >
              <Check className="mr-1.5 h-3.5 w-3.5" />
              {submitting ? "Voting…" : editing || poll.viewer_has_voted ? "Update vote" : "Submit vote"}
            </Button>
          )}
        </div>
      </CardContent>

      <Dialog open={deleteOpen} onOpenChange={(open) => !deleting && setDeleteOpen(open)}>
        <DialogContent className="max-w-[420px] gap-0 overflow-hidden rounded-2xl border-purple-100 p-0 shadow-xl shadow-purple-500/10 dark:border-purple-500/25 dark:bg-[#111827]">
          <div className="bg-gradient-to-r from-purple-600 to-blue-600 px-5 py-4">
            <div className="flex items-center gap-3">
              <div className="flex h-11 w-11 items-center justify-center rounded-full bg-white/20 text-white">
                <AlertTriangle className="h-5 w-5" />
              </div>
              <DialogHeader className="space-y-1 text-left">
                <DialogTitle className="text-[17px] font-bold text-white">Delete poll?</DialogTitle>
                <DialogDescription className="text-sm text-white/85">
                  This can’t be undone.
                </DialogDescription>
              </DialogHeader>
            </div>
          </div>

          <div className="space-y-4 px-5 py-4">
            <p className="text-[15px] leading-relaxed text-slate-600 dark:text-slate-300">
              You’re about to permanently remove this poll and all of its votes from the group.
            </p>
            <div className="rounded-xl border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-3 dark:border-purple-500/20 dark:from-purple-500/10 dark:to-blue-500/10">
              <p className="text-xs font-semibold uppercase tracking-wide text-purple-600 dark:text-purple-300">
                Poll
              </p>
              <p className="mt-1 line-clamp-3 text-sm font-semibold text-slate-900 dark:text-white">
                {poll.question}
              </p>
              <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                {poll.total_votes} {poll.total_votes === 1 ? "vote" : "votes"}
                {poll.options.length > 0 ? ` · ${poll.options.length} options` : ""}
              </p>
            </div>

            <DialogFooter className="gap-2 sm:justify-end sm:space-x-0">
              <button
                type="button"
                disabled={deleting}
                className="inline-flex h-10 cursor-pointer items-center justify-center rounded-xl px-4 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100 disabled:opacity-60 dark:text-slate-300 dark:hover:bg-white/10"
                onClick={() => setDeleteOpen(false)}
              >
                Keep poll
              </button>
              <Button
                type="button"
                disabled={deleting}
                className="h-10 cursor-pointer rounded-xl bg-red-600 px-4 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-60"
                onClick={confirmDelete}
              >
                <Trash2 className="mr-1.5 h-4 w-4" />
                {deleting ? "Deleting…" : "Delete poll"}
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>
    </Card>
  )
}

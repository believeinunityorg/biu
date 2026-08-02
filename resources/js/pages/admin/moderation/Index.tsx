"use client"

import { Head, router } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

type ReportRow = {
  id: number
  reason: string
  reason_label: string
  notes: string | null
  status: string
  created_at: string | null
  summary: string
  reportable_type: string
  reportable_id: number
  reporter: { id: number; name: string; email: string } | null
}

type Props = {
  reports: {
    data: ReportRow[]
    links: unknown
    meta?: unknown
  }
  filters: { status: string }
  reportReasons: Record<string, string>
}

export default function ModerationIndex({ reports, filters }: Props) {
  const act = (id: number, status: string, moderation_action?: string) => {
    router.post(route("admin.moderation.update", id), {
      status,
      moderation_action: moderation_action ?? null,
      reason: "Reviewed by BIU moderator",
    })
  }

  return (
    <AppLayout breadcrumbs={[{ title: "Moderation queue", href: "/admin/moderation" }]}>
      <Head title="Moderation queue" />
      <div className="space-y-6 p-4 sm:p-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold">BIU moderation queue</h1>
            <p className="text-sm text-muted-foreground">
              Review community reports. Prefer hide / lock / restore — never hard-delete content.
            </p>
          </div>
          <Select
            value={filters.status}
            onValueChange={(value) =>
              router.get(route("admin.moderation.index"), { status: value }, { preserveState: true })
            }
          >
            <SelectTrigger className="w-40 cursor-pointer">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="open">Open</SelectItem>
              <SelectItem value="reviewed">Reviewed</SelectItem>
              <SelectItem value="actioned">Actioned</SelectItem>
              <SelectItem value="dismissed">Dismissed</SelectItem>
              <SelectItem value="all">All</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-3">
          {reports.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                No reports in this filter.
              </CardContent>
            </Card>
          ) : (
            reports.data.map((report) => (
              <Card key={report.id}>
                <CardHeader className="pb-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge>{report.reason_label}</Badge>
                    <Badge variant="outline">{report.status}</Badge>
                    <Badge variant="secondary">{report.reportable_type}</Badge>
                  </div>
                  <CardTitle className="text-base">{report.summary}</CardTitle>
                  <p className="text-xs text-muted-foreground">
                    Reported by {report.reporter?.name ?? "Unknown"} · {report.created_at}
                  </p>
                </CardHeader>
                <CardContent className="space-y-3">
                  {report.notes && <p className="text-sm">{report.notes}</p>}
                  {report.status === "open" && (
                    <div className="flex flex-wrap gap-2">
                      <Button
                        size="sm"
                        className="cursor-pointer"
                        onClick={() => act(report.id, "actioned", "hide")}
                      >
                        Hide content
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="cursor-pointer"
                        onClick={() => act(report.id, "actioned", "lock")}
                      >
                        Lock discussion
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="cursor-pointer"
                        onClick={() => act(report.id, "actioned", "hide_group")}
                      >
                        Hide group on parent
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="cursor-pointer"
                        onClick={() => act(report.id, "actioned", "suspend_member")}
                      >
                        Suspend posting (7d)
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="cursor-pointer"
                        onClick={() => act(report.id, "dismissed")}
                      >
                        Dismiss
                      </Button>
                    </div>
                  )}
                </CardContent>
              </Card>
            ))
          )}
        </div>
      </div>
    </AppLayout>
  )
}

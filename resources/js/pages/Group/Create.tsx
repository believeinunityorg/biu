"use client"

import { FormEvent, useState } from "react"
import { Head, Link, useForm } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { ArrowLeft, UsersRound } from "lucide-react"

type Props = {
  parent: { type: string; id: number; name: string }
  categories: string[]
}

export default function GroupCreate({ parent, categories }: Props) {
  const { data, setData, post, processing, errors } = useForm<{
    parent_type: string
    parent_id: number
    name: string
    description: string
    category: string
    cover_image: File | null
  }>({
    parent_type: parent.type,
    parent_id: parent.id,
    name: "",
    description: "",
    category: "",
    cover_image: null,
  })
  const [preview, setPreview] = useState<string | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post(route("groups.store"), { forceFormData: true })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: "Community Groups", href: "/organization/groups" },
        { title: "Create Group", href: "/groups/create" },
      ]}
    >
      <Head title="Create Group" />
      <div className="w-full space-y-6 p-4 sm:p-6">
        <Link
          href="/organization/groups"
          className="inline-flex cursor-pointer items-center text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="mr-2 h-4 w-4" />
          Back to groups
        </Link>

        <Card>
          <CardHeader>
            <div className="mb-2 flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white">
              <UsersRound className="h-6 w-6" />
            </div>
            <CardTitle className="text-2xl">Create a Community Group</CardTitle>
            <CardDescription>
              Under {parent.name}. No organization approval required — you become the Group Administrator.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={submit} className="space-y-5">
              <div>
                <Label htmlFor="name">Group Name *</Label>
                <Input
                  id="name"
                  className="mt-1.5"
                  value={data.name}
                  onChange={(e) => setData("name", e.target.value)}
                  required
                />
                {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
              </div>
              <div>
                <Label htmlFor="description">Description *</Label>
                <Textarea
                  id="description"
                  className="mt-1.5"
                  rows={4}
                  value={data.description}
                  onChange={(e) => setData("description", e.target.value)}
                  required
                />
                {errors.description && <p className="mt-1 text-sm text-red-600">{errors.description}</p>}
              </div>
              <div>
                <Label htmlFor="category">Category (optional)</Label>
                <select
                  id="category"
                  className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  value={data.category}
                  onChange={(e) => setData("category", e.target.value)}
                >
                  <option value="">Select a category</option>
                  {categories.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <Label htmlFor="cover_image">Cover Image *</Label>
                <Input
                  id="cover_image"
                  type="file"
                  accept="image/*"
                  className="mt-1.5 cursor-pointer"
                  onChange={(e) => {
                    const file = e.target.files?.[0] ?? null
                    setData("cover_image", file)
                    setPreview(file ? URL.createObjectURL(file) : null)
                  }}
                  required
                />
                {preview && (
                  <img src={preview} alt="Cover preview" className="mt-3 h-40 w-full rounded-lg object-cover" />
                )}
                {errors.cover_image && <p className="mt-1 text-sm text-red-600">{errors.cover_image}</p>}
              </div>
              <div className="flex flex-wrap justify-end gap-3 pt-2">
                <Button type="button" variant="outline" className="cursor-pointer" asChild>
                  <Link href="/organization/groups">Cancel</Link>
                </Button>
                <Button
                  type="submit"
                  disabled={processing}
                  className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 font-semibold text-white"
                >
                  {processing ? "Creating…" : "Create Group"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}

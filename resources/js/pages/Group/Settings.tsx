"use client"

import { FormEvent, useState } from "react"
import { Head, Link, useForm } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { ArrowLeft } from "lucide-react"

type Props = {
  group: {
    id: number
    name: string
    slug: string
    description: string | null
    category: string | null
    cover_image: string | null
  }
  categories: string[]
}

export default function GroupSettings({ group, categories }: Props) {
  const { data, setData, post, processing, errors } = useForm<{
    name: string
    description: string
    category: string
    cover_image: File | null
  }>({
    name: group.name,
    description: group.description ?? "",
    category: group.category ?? "",
    cover_image: null,
  })
  const [preview, setPreview] = useState<string | null>(group.cover_image)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post(route("groups.settings.update", group.slug), { forceFormData: true })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: "Community Groups", href: "/organization/groups" },
        { title: group.name, href: route("groups.show", group.slug) },
        { title: "Settings", href: route("groups.settings", group.slug) },
      ]}
    >
      <Head title={`Settings · ${group.name}`} />
      <div className="w-full space-y-6 p-4 sm:p-6">
        <Link
          href={route("groups.show", group.slug)}
          className="inline-flex cursor-pointer items-center text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="mr-2 h-4 w-4" />
          Back to group
        </Link>
        <Card>
          <CardHeader>
            <CardTitle>Group settings</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={submit} className="space-y-4">
              <div>
                <Label htmlFor="name">Name</Label>
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
                <Label htmlFor="description">Description</Label>
                <Textarea
                  id="description"
                  className="mt-1.5"
                  rows={4}
                  value={data.description}
                  onChange={(e) => setData("description", e.target.value)}
                  required
                />
              </div>
              <div>
                <Label htmlFor="category">Category</Label>
                <select
                  id="category"
                  className="mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
                  value={data.category}
                  onChange={(e) => setData("category", e.target.value)}
                >
                  <option value="">Select</option>
                  {categories.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <Label htmlFor="cover_image">Cover image</Label>
                <Input
                  id="cover_image"
                  type="file"
                  accept="image/*"
                  className="mt-1.5 cursor-pointer"
                  onChange={(e) => {
                    const file = e.target.files?.[0] ?? null
                    setData("cover_image", file)
                    if (file) setPreview(URL.createObjectURL(file))
                  }}
                />
                {preview && <img src={preview} alt="" className="mt-3 h-40 w-full rounded-lg object-cover" />}
              </div>
              <div className="flex flex-wrap justify-end gap-3 pt-2">
                <Button type="button" variant="outline" className="cursor-pointer" asChild>
                  <Link href={route("groups.show", group.slug)}>Cancel</Link>
                </Button>
                <Button
                  type="submit"
                  disabled={processing}
                  className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600"
                >
                  {processing ? "Saving…" : "Save settings"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}

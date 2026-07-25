"use client"

import { Head, useForm, usePage } from "@inertiajs/react"
import { useState } from "react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Gift, Loader2 } from "lucide-react"
import toast from "react-hot-toast"

interface Recipient {
  id: number
  name: string
  slug: string | null
  image: string | null
}

interface GiftOccasion {
  id: number
  occasion: string
  icon: string | null
  category: string | null
}

interface SenderBalances {
  purchased_believe_points: number
  gifted_believe_points: number
}

interface BirthdayGiftPageProps {
  recipient: Recipient
  senderBalances: SenderBalances
  giftOccasions: GiftOccasion[]
}

const PRESETS = [10, 25, 50] as const

export default function BirthdayGiftPage() {
  const { recipient, senderBalances, giftOccasions } = usePage().props as unknown as BirthdayGiftPageProps
  const [preset, setPreset] = useState<number | "custom">(10)
  const defaultOccasionId = giftOccasions[0]?.id ?? 0

  const { data, setData, post, processing, errors, reset } = useForm({
    amount: 10,
    gift_occasion_id: defaultOccasionId,
    message: "",
  })

  const applyPreset = (v: number | "custom") => {
    setPreset(v)
    if (v !== "custom") {
      setData("amount", v)
    }
  }

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    post(`/supporters/gift/${recipient.id}`, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success("Gift sent!")
        reset("amount", "message")
        setData("gift_occasion_id", defaultOccasionId)
      },
      onError: () => {
        toast.error("Could not send gift. Check your balance and try again.")
      },
    })
  }

  const purchased = senderBalances.purchased_believe_points
  const gifted = senderBalances.gifted_believe_points

  return (
    <FrontendLayout>
      <Head title="Send gift" />
      <div className="max-w-lg mx-auto py-10 px-4">
        <Card className="border-2 border-violet-500/30 dark:border-violet-400/30 shadow-xl dark:bg-gray-900/80">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-xl dark:text-white">
              <Gift className="h-6 w-6 text-amber-500" />
              Send Gift
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-3 mb-6">
              {recipient.image ? (
                <img
                  src={recipient.image}
                  alt=""
                  className="h-14 w-14 rounded-full object-cover border-2 border-violet-500/40"
                />
              ) : (
                <div className="h-14 w-14 rounded-full bg-muted flex items-center justify-center text-lg font-semibold">
                  {(recipient.name || "?")[0]}
                </div>
              )}
              <div>
                <p className="text-sm text-muted-foreground">Recipient</p>
                <p className="font-semibold text-lg dark:text-white">{recipient.name}</p>
              </div>
            </div>

            <form onSubmit={submit} className="space-y-5">
              <div>
                <Label className="dark:text-gray-200">Occasion</Label>
                <Select value={String(data.gift_occasion_id)} onValueChange={(value) => setData("gift_occasion_id", Number(value))}>
                  <SelectTrigger className="mt-2 dark:bg-gray-800">
                    <SelectValue placeholder="Choose an occasion" />
                  </SelectTrigger>
                  <SelectContent>
                    {giftOccasions.map((occasion) => (
                      <SelectItem key={occasion.id} value={String(occasion.id)}>
                        {occasion.icon ? `${occasion.icon} ` : ""}{occasion.occasion}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.gift_occasion_id && <p className="text-sm text-destructive mt-1">{errors.gift_occasion_id}</p>}
              </div>

              <div>
                <Label className="dark:text-gray-200">Amount (Believe Points)</Label>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-2">
                  {PRESETS.map((n) => (
                    <Button
                      key={n}
                      type="button"
                      variant={preset === n ? "default" : "outline"}
                      className={preset === n ? "ring-2 ring-primary" : ""}
                      onClick={() => applyPreset(n)}
                    >
                      {n}
                    </Button>
                  ))}
                  <Button
                    type="button"
                    variant={preset === "custom" ? "default" : "outline"}
                    onClick={() => applyPreset("custom")}
                  >
                    Custom
                  </Button>
                </div>
                {preset === "custom" && (
                  <Input
                    type="number"
                    step="0.01"
                    min={0.01}
                    className="mt-2 dark:bg-gray-800"
                    value={data.amount}
                    onChange={(e) => setData("amount", parseFloat(e.target.value) || 0)}
                  />
                )}
                {errors.amount && <p className="text-sm text-destructive mt-1">{errors.amount}</p>}
              </div>

              <div>
                <Label className="dark:text-gray-200">Message (optional)</Label>
                <Textarea
                  className="mt-2 dark:bg-gray-800"
                  placeholder="Write a short note"
                  value={data.message}
                  onChange={(e) => setData("message", e.target.value)}
                  maxLength={500}
                />
                {errors.message && <p className="text-sm text-destructive mt-1">{errors.message}</p>}
              </div>

              <div className="flex flex-col gap-2 text-sm border rounded-lg p-3 bg-amber-500/5 border-amber-500/30">
                <div className="flex items-center gap-2 text-amber-700 dark:text-amber-300">
                  <Gift className="h-4 w-4" />
                  <span>
                    Purchased balance: <strong>{purchased.toFixed(2)}</strong>
                  </span>
                </div>
                <p className="text-xs text-muted-foreground">
                  BP moves to Holding until they Accept / Collect, then to their Gifted BP wallet (not re-giftable).
                </p>
                {gifted > 0 && (
                  <span className="text-xs text-muted-foreground">
                    Your Gifted BP balance cannot be re-sent.
                  </span>
                )}
              </div>

              <Button
                type="submit"
                className="w-full bg-gradient-to-r from-blue-600 to-violet-600"
                disabled={processing || data.amount <= 0 || purchased < data.amount}
              >
                {processing ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    Sending…
                  </>
                ) : (
                  "Send gift & hold BP"
                )}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </FrontendLayout>
  )
}

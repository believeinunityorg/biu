import { Card, CardContent, CardHeader, CardTitle } from '@/components/frontend/ui/card'
import { Input } from '@/components/frontend/ui/input'
import { Label } from '@/components/frontend/ui/label'
import { TextArea } from '@/components/ui/textarea'
import InputError from '@/components/input-error'
import { Church } from 'lucide-react'

export type ChurchProfileData = {
  is_church: boolean
  denomination?: string | null
  senior_pastor_name?: string | null
  service_times?: string | null
  ministries?: string | null
  worship_location?: string | null
}

type Props = {
  data: {
    denomination: string
    senior_pastor_name: string
    service_times: string
    ministries: string
    worship_location: string
  }
  setData: (key: string, value: string) => void
  errors: Record<string, string>
}

export function ChurchProfileSection({ data, setData, errors }: Props) {
  return (
    <Card className="border-border shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-lg">
          <Church className="h-5 w-5 text-blue-600 dark:text-blue-400" />
          Church / Religious
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          Profile extension for Church / Religious organizations. These fields are unique to this
          organization type — you continue using the standard BIU profile and all existing features.
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="denomination">Denomination</Label>
            <Input
              id="denomination"
              className="mt-1.5"
              value={data.denomination}
              onChange={(e) => setData('denomination', e.target.value)}
              placeholder="e.g. Baptist, Methodist, Non-denominational"
            />
            <InputError message={errors.denomination} className="mt-1" />
          </div>
          <div>
            <Label htmlFor="senior_pastor_name">Senior Pastor / Faith Leader</Label>
            <Input
              id="senior_pastor_name"
              className="mt-1.5"
              value={data.senior_pastor_name}
              onChange={(e) => setData('senior_pastor_name', e.target.value)}
              placeholder="Name of senior pastor or faith leader"
            />
            <InputError message={errors.senior_pastor_name} className="mt-1" />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="worship_location">Worship Location</Label>
            <Input
              id="worship_location"
              className="mt-1.5"
              value={data.worship_location}
              onChange={(e) => setData('worship_location', e.target.value)}
              placeholder="Main campus or worship address"
            />
            <InputError message={errors.worship_location} className="mt-1" />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="service_times">Service Times</Label>
            <TextArea
              id="service_times"
              className="mt-1.5 min-h-[88px]"
              value={data.service_times}
              onChange={(e) => setData('service_times', e.target.value)}
              placeholder="e.g. Sunday 9:00 AM & 11:00 AM; Wednesday Bible Study 7:00 PM"
            />
            <InputError message={errors.service_times} className="mt-1" />
          </div>
          <div className="sm:col-span-2">
            <Label htmlFor="ministries">Ministries</Label>
            <TextArea
              id="ministries"
              className="mt-1.5 min-h-[88px]"
              value={data.ministries}
              onChange={(e) => setData('ministries', e.target.value)}
              placeholder="e.g. Youth, Outreach, Music, Small Groups"
            />
            <InputError message={errors.ministries} className="mt-1" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

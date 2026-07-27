"use client"

import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  Shield,
  CheckCircle,
  AlertCircle,
  Calendar,
  Clock,
  Users,
  MapPin,
  Coins,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Separator } from "@/components/ui/separator"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Link, useForm, usePage } from "@inertiajs/react"
import { type FormEventHandler } from "react"
import { Badge } from "@/components/ui/badge"
import BrpParticipationHint from "@/components/brp/BrpParticipationHint"
import { courseEnrollmentBrpModule } from "@/lib/brp-participation"
import { enrollmentBillingCycleSuffix } from "@/lib/enrollment-billing-cycle"

interface Course {
  id: number
  name: string
  slug: string
  description: string
  type?: string
  pricing_type: "free" | "paid"
  course_fee: number | null
  enrollment_billing_cycle?: string | null
  start_date: string
  end_date?: string
  start_time: string
  session_duration_minutes: number
  format: string
  max_participants: number
  enrolled: number
  formatted_price: string
  formatted_duration: string
  formatted_format: string
  image_url?: string
  meeting_link?: string
  topic: {
    name: string
  }
  organization: {
    name: string
  }
}

interface FeeBreakdown {
  course_fee: number
  platform_fee: number
  platform_fee_percentage: number
  total_bp: number
  refundable_bp: number
}

interface Props {
  course: Course
  feeBreakdown?: FeeBreakdown | null
}

export default function FrontendCourseEnroll({ course, feeBreakdown }: Props) {
  const page = usePage()
  const auth = (page.props as any).auth
  const currentBalance = parseFloat(auth?.user?.purchased_believe_points) || Math.max(0, (parseFloat(auth?.user?.believe_points) || 0) - (parseFloat(auth?.user?.gifted_believe_points) || 0))

  const { data, setData, post, processing, errors } = useForm({
    terms_accepted: false,
  })

  const submit: FormEventHandler = (e) => {
    e.preventDefault()
    post(`/courses/${course.slug}/enroll`)
  }

  const listingFee =
    feeBreakdown?.course_fee ??
    (course.pricing_type === "paid" && course.course_fee ? parseFloat(course.course_fee.toString()) || 0 : 0)
  const platformFee = feeBreakdown?.platform_fee ?? 0
  const pointsRequired = feeBreakdown?.total_bp ?? listingFee + platformFee
  const hasEnoughPoints = currentBalance >= pointsRequired
  const enrollmentBrpModule = courseEnrollmentBrpModule(course)
  const billingSuffix = enrollmentBillingCycleSuffix(course.enrollment_billing_cycle ?? "one_time")
  const isMonthlyBilling = (course.enrollment_billing_cycle ?? "one_time") === "monthly"

  const availableSpots = course.max_participants - course.enrolled

  return (
    <FrontendLayout>
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 py-8">
        <div className="container mx-auto px-4 sm:px-6 lg:px-8">
          <div className="max-w-6xl mx-auto">
            <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="mb-8">
              <Link href={`/courses/${course.slug}`}>
                <Button variant="ghost" className="mb-4">
                  <ArrowLeft className="mr-2 h-4 w-4" />
                  Back to Course
                </Button>
              </Link>
              <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">Enroll in {course.name}</h1>
              <p className="text-gray-600 dark:text-gray-400">
                Paid Connection Hub listings are purchased with Believe Points (BP) only.
              </p>
            </motion.div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
              <div className="lg:col-span-2">
                <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
                  <Card className="shadow-lg">
                    <CardHeader>
                      <CardTitle className="flex items-center gap-2">
                        <Coins className="h-5 w-5 text-yellow-600" />
                        Enrollment Details
                      </CardTitle>
                    </CardHeader>
                    <CardContent>
                      <form onSubmit={submit} className="space-y-6">
                        <div className="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                          <h3 className="font-semibold text-gray-900 dark:text-white mb-2">Course Summary</h3>
                          <div className="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                            <div className="flex justify-between">
                              <span>Course:</span>
                              <span className="font-medium">{course.name}</span>
                            </div>
                            <div className="flex justify-between">
                              <span>Price:</span>
                              <span className="font-medium text-green-600">
                                {course.formatted_price}
                                {billingSuffix}
                              </span>
                            </div>
                            <div className="flex justify-between">
                              <span>Duration:</span>
                              <span className="font-medium">{course.formatted_duration}</span>
                            </div>
                            <div className="flex justify-between">
                              <span>Format:</span>
                              <span className="font-medium">{course.formatted_format}</span>
                            </div>
                          </div>
                        </div>

                        {course.pricing_type === "paid" && (
                          <div className="space-y-4">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Believe Points payment</h3>

                            <div className="rounded-lg border border-primary/20 bg-primary/5 p-4 space-y-2 text-sm">
                              <div className="flex justify-between">
                                <span>Listing fee</span>
                                <span className="font-medium">{listingFee.toFixed(2)} BP</span>
                              </div>
                              {platformFee > 0 && feeBreakdown && (
                                <>
                                  <div className="flex justify-between text-muted-foreground">
                                    <span>Platform fee ({feeBreakdown.platform_fee_percentage}%, added)</span>
                                    <span>+ {platformFee.toFixed(2)} BP (non-refundable)</span>
                                  </div>
                                  <div className="flex justify-between border-t border-primary/10 pt-2">
                                    <span>If host cancels, you receive back</span>
                                    <span className="font-medium text-green-700 dark:text-green-400">
                                      {feeBreakdown.refundable_bp.toFixed(2)} BP
                                    </span>
                                  </div>
                                </>
                              )}
                              <div className="flex justify-between border-t border-primary/10 pt-2 font-semibold">
                                <span>{isMonthlyBilling ? "Total due each month" : "Total due now"}</span>
                                <span>
                                  {pointsRequired.toFixed(2)} BP{billingSuffix}
                                </span>
                              </div>
                            </div>

                            <div className="flex items-center gap-3 p-4 border-2 border-primary rounded-lg bg-primary/10">
                              <Coins className="h-5 w-5 text-yellow-600" />
                              <div className="flex-1">
                                <div className="font-semibold flex items-center gap-2">
                                  Pay with Believe Points
                                  {!hasEnoughPoints && (
                                    <Badge variant="destructive" className="text-xs">
                                      Insufficient
                                    </Badge>
                                  )}
                                </div>
                                <div className="text-sm text-muted-foreground">
                                  Your purchased balance: {currentBalance.toFixed(2)} BP
                                  {hasEnoughPoints && (
                                    <span className="text-green-600 ml-2">
                                      (You&apos;ll have {(currentBalance - pointsRequired).toFixed(2)} purchased BP remaining)
                                    </span>
                                  )}
                                </div>
                              </div>
                            </div>

                            {!hasEnoughPoints && (
                              <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertDescription>
                                  You need {pointsRequired.toFixed(2)} purchased BP but only have {currentBalance.toFixed(2)} BP.
                                  Gift BP can only be used for gift cards.
                                </AlertDescription>
                              </Alert>
                            )}

                            <Alert>
                              <Shield className="h-4 w-4" />
                              <AlertDescription>
                                Connection Hub purchases use Believe Points only. If the host cancels the listing, your refundable BP
                                is returned to your available balance. Platform fees are never refunded.
                              </AlertDescription>
                            </Alert>
                          </div>
                        )}

                        <div className="space-y-4">
                          <Separator />
                          <div className="flex items-start space-x-3">
                            <Checkbox
                              id="terms"
                              checked={data.terms_accepted}
                              onCheckedChange={(checked) => setData("terms_accepted", checked as boolean)}
                            />
                            <div className="grid gap-1.5 leading-none">
                              <Label htmlFor="terms" className="text-sm font-medium leading-none">
                                I accept the terms and conditions
                              </Label>
                              <p className="text-xs text-muted-foreground">
                                By enrolling, you agree to our Terms of Service and Privacy Policy.
                              </p>
                            </div>
                          </div>
                          {errors.terms_accepted && <p className="text-sm text-red-600">{errors.terms_accepted}</p>}
                        </div>

                        {enrollmentBrpModule && (
                          <BrpParticipationHint module={enrollmentBrpModule} variant="alert" />
                        )}

                        <Button
                          type="submit"
                          className="w-full bg-green-600 hover:bg-green-700"
                          size="lg"
                          disabled={
                            processing ||
                            !data.terms_accepted ||
                            (course.pricing_type === "paid" && !hasEnoughPoints)
                          }
                        >
                          {processing ? (
                            "Processing..."
                          ) : course.pricing_type === "paid" ? (
                            <>
                              <Coins className="mr-2 h-4 w-4" />
                              Pay {pointsRequired.toFixed(2)} BP{billingSuffix} & Enroll
                            </>
                          ) : (
                            <>
                              <CheckCircle className="mr-2 h-4 w-4" />
                              Enroll for Free
                            </>
                          )}
                        </Button>
                      </form>
                    </CardContent>
                  </Card>
                </motion.div>
              </div>

              <div className="lg:col-span-1">
                <motion.div
                  initial={{ opacity: 0, x: 20 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: 0.2 }}
                  className="sticky top-8 space-y-6"
                >
                  <Card className="shadow-lg">
                    <CardContent className="p-6">
                      {course.image_url && (
                        <img
                          src={course.image_url || "/placeholder.svg"}
                          alt={course.name}
                          className="w-full h-32 object-cover rounded-lg mb-4"
                        />
                      )}
                      <h3 className="font-bold text-lg text-gray-900 dark:text-white mb-2">{course.name}</h3>
                      <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">by {course.organization.name}</p>
                      <div className="space-y-3">
                        <div className="flex items-center gap-2 text-sm">
                          <Calendar className="h-4 w-4 text-blue-600" />
                          <span>
                            {new Date(course.start_date).toLocaleDateString()} at {course.start_time}
                          </span>
                        </div>
                        <div className="flex items-center gap-2 text-sm">
                          <Clock className="h-4 w-4 text-blue-600" />
                          <span>{course.formatted_duration}</span>
                        </div>
                        <div className="flex items-center gap-2 text-sm">
                          <MapPin className="h-4 w-4 text-blue-600" />
                          <span>{course.formatted_format}</span>
                        </div>
                        <div className="flex items-center gap-2 text-sm">
                          <Users className="h-4 w-4 text-blue-600" />
                          <span>{availableSpots} spots remaining</span>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                </motion.div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}

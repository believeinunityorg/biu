"use client"

import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { motion } from "framer-motion"
import { Send, MessageCircle, HelpCircle, Users, CheckCircle2, ArrowRight } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/frontend/ui/select"
import { Link, useForm } from "@inertiajs/react"
import { useState } from "react"
import { PageHead } from "@/components/frontend/PageHead"
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField"

interface ContactPageProps {
  seo?: { title: string; description?: string }
  hero?: {
    badge_text?: string
    title?: string
    description?: string
  }
  contactMethods?: Array<{
    title?: string
    description?: string
    contact?: string
    action?: string
    color?: string
    bgColor?: string
    iconColor?: string
  }>
  faqItems?: Array<{
    question?: string
    answer?: string
  }>
  officeHours?: {
    day_range?: string
    hours?: string
    saturday_day?: string
    saturday_hours?: string
    sunday_day?: string
    sunday_status?: string
  }
  cta?: {
    title?: string
    description?: string
    button1_text?: string
    button1_link?: string
    button2_text?: string
    button2_link?: string
  }
}

// Contact is form-only. Technical help: support@believeinunity.org

const defaultFaqItems = [
  {
    question: "How do I know my donation is secure?",
    answer:
      "We use industry-standard encryption and partner with trusted payment processors like Stripe to ensure your donation is completely secure.",
  },
  {
    question: "Can I get a tax receipt for my donation?",
    answer:
      "Yes! All donations through Believe in Unity are tax-deductible, and you'll receive an official receipt via email immediately after your donation.",
  },
  {
    question: "How do I track the impact of my donation?",
    answer:
      "You can log into your account to see detailed reports on how your donations are being used and the impact they're creating.",
  },
  {
    question: "How are organizations verified?",
    answer:
      "We have a rigorous verification process that includes checking legal status, financial transparency, and impact measurement capabilities.",
  },
]


export default function ContactPage({
  seo,
  hero,
  contactMethods = [],
  faqItems = [],
  officeHours,
  cta
}: ContactPageProps) {
  const { data, setData, post, processing, errors, wasSuccessful } = useForm({
    first_name: '',
    last_name: '',
    email: '',
    subject: '',
    message: '',
    cf_turnstile_response: '',
  })

  const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    post('/contact', {
      preserveScroll: true,
      onSuccess: () => {
        // Form will show success message via wasSuccessful
      },
    })
  }

    return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Contact Us"} description={seo?.description} />
      <div className="min-h-screen bg-gradient-to-br from-gray-50 via-white to-blue-50/30 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950">
        {/* Hero Section */}
        <section className="relative overflow-hidden bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 dark:from-gray-900 dark:via-gray-800 dark:to-gray-900 py-20 md:py-28">
          <div className="absolute inset-0 bg-grid-white/10 [mask-image:linear-gradient(0deg,white,rgba(255,255,255,0.6))]"></div>
          <div className="container mx-auto px-4 relative z-10">
            <motion.div
                initial={{ opacity: 0, y: 30 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.8 }}
                className="text-center max-w-4xl mx-auto"
            >
              {hero?.badge_text && (
                <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 mb-6">
                  <MessageCircle className="h-4 w-4 text-white" />
                  <span className="text-sm font-medium text-white">{hero.badge_text}</span>
                </div>
              )}
              <h1 className="text-4xl md:text-5xl lg:text-6xl font-bold text-white mb-6 leading-tight">
                {hero?.title || "Get in Touch"}
              </h1>
              <p className="text-xl md:text-2xl text-blue-100 mb-8 max-w-2xl mx-auto leading-relaxed">
                {hero?.description || "Have questions? We're here to help. Reach out to us and we'll get back to you as soon as possible."}
                </p>
            </motion.div>
            </div>
        </section>

        <div className="container mx-auto px-4 py-16 md:py-20">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 lg:gap-12">
            {/* Contact Form */}
            <div className="lg:col-span-2">
              <motion.div
                initial={{ opacity: 0, y: 30 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.8 }}
              >
                <Card className="bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 shadow-xl">
                  <CardHeader className="pb-6 border-b border-gray-200 dark:border-gray-700">
                    <div className="flex items-center gap-3 mb-2">
                      <div className="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                        <Send className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                      </div>
                      <div>
                        <CardTitle className="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white">
                          Send us a Message
                        </CardTitle>
                        <CardDescription className="text-gray-600 dark:text-gray-300 mt-1">
                        Fill out the form below and we'll get back to you within 24 hours
                    </CardDescription>
                      </div>
                    </div>
                    </CardHeader>
                  <CardContent className="pt-6">
                    {wasSuccessful ? (
                      <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        className="text-center py-12"
                      >
                        <div className="inline-flex items-center justify-center w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full mb-4">
                          <CheckCircle2 className="h-8 w-8 text-green-600 dark:text-green-400" />
                        </div>
                        <h3 className="text-2xl font-bold text-gray-900 dark:text-white mb-2">
                          Message Sent Successfully!
                        </h3>
                        <p className="text-gray-600 dark:text-gray-300 mb-6">
                          Thank you for contacting us. We'll get back to you within 24 hours.
                        </p>
                        <Button
                          onClick={() => window.location.reload()}
                          variant="outline"
                          className="bg-white dark:bg-gray-800"
                        >
                          Send Another Message
                        </Button>
                      </motion.div>
                    ) : (
                      <form onSubmit={handleSubmit} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                          <div className="space-y-2">
                            <Label htmlFor="first-name" className="text-sm font-semibold text-gray-900 dark:text-white">
                              First Name <span className="text-red-500">*</span>
                            </Label>
                        <Input
                            id="first-name"
                              value={data.first_name}
                              onChange={(e) => setData('first_name', e.target.value)}
                            placeholder="John"
                            required
                              className="h-11 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        />
                            {errors.first_name && (
                              <p className="text-sm text-red-600 dark:text-red-400">{errors.first_name}</p>
                            )}
                        </div>
                          <div className="space-y-2">
                            <Label htmlFor="last-name" className="text-sm font-semibold text-gray-900 dark:text-white">
                              Last Name <span className="text-red-500">*</span>
                            </Label>
                        <Input
                            id="last-name"
                              value={data.last_name}
                              onChange={(e) => setData('last_name', e.target.value)}
                            placeholder="Doe"
                            required
                              className="h-11 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        />
                            {errors.last_name && (
                              <p className="text-sm text-red-600 dark:text-red-400">{errors.last_name}</p>
                            )}
                        </div>
                    </div>

                        <div className="space-y-2">
                          <Label htmlFor="email" className="text-sm font-semibold text-gray-900 dark:text-white">
                            Email Address <span className="text-red-500">*</span>
                          </Label>
                        <Input
                        id="email"
                        type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        placeholder="john@example.com"
                        required
                            className="h-11 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        />
                          {errors.email && (
                            <p className="text-sm text-red-600 dark:text-red-400">{errors.email}</p>
                          )}
                    </div>

                        <div className="space-y-2">
                          <Label htmlFor="subject" className="text-sm font-semibold text-gray-900 dark:text-white">
                            Subject <span className="text-red-500">*</span>
                          </Label>
                          <Select value={data.subject} onValueChange={(value) => setData('subject', value)}>
                            <SelectTrigger className="h-11 bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <SelectValue placeholder="Select a subject" />
                        </SelectTrigger>
                        <SelectContent className="bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700">
                            <SelectItem value="general">General Inquiry</SelectItem>
                            <SelectItem value="donation">Donation Support</SelectItem>
                            <SelectItem value="organization">Organization Registration</SelectItem>
                            <SelectItem value="technical">Technical Support</SelectItem>
                            <SelectItem value="partnership">Partnership Opportunities</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                        </Select>
                          {errors.subject && (
                            <p className="text-sm text-red-600 dark:text-red-400">{errors.subject}</p>
                          )}
                    </div>

                        <div className="space-y-2">
                          <Label htmlFor="message" className="text-sm font-semibold text-gray-900 dark:text-white">
                            Message <span className="text-red-500">*</span>
                          </Label>
                        <Textarea
                        id="message"
                            value={data.message}
                            onChange={(e) => setData('message', e.target.value)}
                        placeholder="Tell us how we can help you..."
                            className="min-h-[180px] bg-white dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none"
                        required
                        />
                          {errors.message && (
                            <p className="text-sm text-red-600 dark:text-red-400">{errors.message}</p>
                          )}
                    </div>

                        <TurnstileField
                          onToken={(token) => setData('cf_turnstile_response', token)}
                          error={errors.cf_turnstile_response}
                        />

                        <Button
                          type="submit"
                          size="lg"
                          disabled={processing || turnstileBlocksSubmit}
                          className="w-full h-12 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold shadow-lg hover:shadow-xl transition-all duration-200"
                        >
                          {processing ? (
                            <>
                              <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-white mr-2"></div>
                              Sending...
                            </>
                          ) : (
                            <>
                        <Send className="mr-2 h-5 w-5" />
                        Send Message
                            </>
                          )}
                    </Button>
                      </form>
                    )}
                    </CardContent>
                </Card>
                </motion.div>
            </div>

            {/* Sidebar: form-only contact + technical help */}
            <div className="space-y-6">
                <motion.div
                  initial={{ opacity: 0, x: 30 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ duration: 0.8, delay: 0.2 }}
                >
                  <Card className="bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 shadow-lg">
                    <CardHeader className="pb-4">
                      <CardTitle className="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <MessageCircle className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        How to reach us
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                      <p className="text-sm text-gray-600 dark:text-gray-300">
                        We respond to all inquiries through the contact form on this page. Please submit your message below and we&apos;ll get back to you as soon as possible.
                      </p>
                      <div className="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <p className="text-sm font-semibold text-gray-900 dark:text-white mb-1">Technical help</p>
                        <a
                          href="mailto:support@believeinunity.org"
                          className="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline"
                        >
                          support@believeinunity.org
                        </a>
                      </div>
                    </CardContent>
                  </Card>
                </motion.div>
            </div>
            </div>

            {/* FAQ Section */}
            <motion.div
            initial={{ opacity: 0, y: 30 }}
            whileInView={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8 }}
            viewport={{ once: true }}
            className="mt-20 md:mt-24"
            >
            <div className="text-center mb-12">
              <h2 className="text-3xl md:text-4xl font-bold text-gray-900 dark:text-white mb-4">
                Frequently Asked Questions
              </h2>
              <p className="text-xl text-gray-600 dark:text-gray-300 max-w-2xl mx-auto">
                Quick answers to common questions about our platform and services
              </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-6xl mx-auto">
              {(faqItems.length > 0 ? faqItems : defaultFaqItems).map((item, index) => (
                <motion.div
                    key={index}
                    initial={{ opacity: 0, y: 20 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, delay: index * 0.1 }}
                    viewport={{ once: true }}
                >
                  <Card className="h-full bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 shadow-lg hover:shadow-xl transition-shadow duration-200">
                    <CardHeader>
                      <CardTitle className="text-lg font-semibold flex items-start gap-3 text-gray-900 dark:text-white">
                        <div className="p-1.5 bg-blue-100 dark:bg-blue-900/30 rounded-lg mt-0.5">
                          <HelpCircle className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                        </div>
                        <span>{item.question}</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                      <p className="text-gray-600 dark:text-gray-300 leading-relaxed">{item.answer}</p>
                    </CardContent>
                    </Card>
                </motion.div>
                ))}
            </div>
            </motion.div>

            {/* CTA Section */}
            <motion.div
            initial={{ opacity: 0, y: 30 }}
            whileInView={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8 }}
            viewport={{ once: true }}
            className="mt-20 md:mt-24"
            >
            <Card className="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 text-white border-0 shadow-2xl overflow-hidden relative">
              <div className="absolute inset-0 bg-grid-white/10 [mask-image:linear-gradient(0deg,white,rgba(255,255,255,0.6))]"></div>
              <CardContent className="py-16 md:py-20 relative z-10">
                <div className="text-center max-w-3xl mx-auto">
                  <div className="inline-flex items-center justify-center w-16 h-16 bg-white/20 backdrop-blur-sm rounded-full mb-6">
                    <Users className="h-8 w-8 text-white" />
                  </div>
                  <h3 className="text-3xl md:text-4xl font-bold mb-4">
                    {cta?.title || "Ready to Make a Difference?"}
                  </h3>
                  <p className="text-lg md:text-xl mb-8 opacity-95 leading-relaxed">
                    {cta?.description || "Join thousands of supporters making an impact. Start your journey today and help create positive change in our communities."}
                </p>
                <div className="flex flex-col sm:flex-row gap-4 justify-center">
                    {cta?.button1_text && cta?.button1_link && (
                      <Button
                        size="lg"
                        asChild
                        className="bg-white text-blue-600 hover:bg-gray-100 font-semibold shadow-lg hover:shadow-xl transition-all duration-200 h-12 px-8"
                      >
                        <Link href={cta.button1_link}>
                          {cta.button1_text}
                          <ArrowRight className="ml-2 h-5 w-5" />
                        </Link>
                      </Button>
                    )}
                    {cta?.button2_text && cta?.button2_link && (
                      <Button
                        size="lg"
                        variant="outline"
                        asChild
                        className="border-2 border-white text-white hover:bg-white hover:text-blue-600 bg-transparent font-semibold shadow-lg hover:shadow-xl transition-all duration-200 h-12 px-8"
                      >
                        <Link href={cta.button2_link}>
                          {cta.button2_text}
                          <ArrowRight className="ml-2 h-5 w-5" />
                        </Link>
                      </Button>
                    )}
                    {!cta && (
                      <>
                        <Button
                          size="lg"
                          asChild
                          className="bg-white text-blue-600 hover:bg-gray-100 font-semibold shadow-lg hover:shadow-xl transition-all duration-200 h-12 px-8"
                        >
                          <Link href="/donate">
                    Start Donating
                            <ArrowRight className="ml-2 h-5 w-5" />
                          </Link>
                    </Button>
                    <Button
                    size="lg"
                    variant="outline"
                          asChild
                          className="border-2 border-white text-white hover:bg-white hover:text-blue-600 bg-transparent font-semibold shadow-lg hover:shadow-xl transition-all duration-200 h-12 px-8"
                    >
                          <Link href="/organizations">
                    Browse Organizations
                            <ArrowRight className="ml-2 h-5 w-5" />
                          </Link>
                    </Button>
                      </>
                    )}
                  </div>
                </div>
                </CardContent>
            </Card>
            </motion.div>
        </div>
        </div>
    </FrontendLayout>
  )
}

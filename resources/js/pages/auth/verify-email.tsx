"use client"
import { Button } from "@/components/ui/button"
import type React from "react"
import { motion } from "framer-motion"
import { Send, CheckCircle, Mail, LoaderCircle } from "lucide-react"
import { Link, useForm, usePage } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"

interface PageProps {
  status?: string
  auth?: {
    user?: {
      id: number
      name?: string
      email?: string
    } | null
  }
  [key: string]: unknown
}

export default function VerifyEmail({ status }: { status?: string }) {
  const { post, processing } = useForm({})
  const pageProps = usePage<PageProps>().props
  const auth = pageProps.auth || (pageProps as PageProps).auth
  const userEmail = auth?.user?.email || "your email address"

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    // Use relative URL to ensure we stay on the current domain (main app, not livestock)
    // This prevents CORS issues when route() resolves to wrong domain
    post("/email/verification-notification")
  }

  return (
    <FrontendLayout>
      <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-50 via-purple-50 to-blue-50 p-4 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950">
        <motion.div
          initial={{ opacity: 0, scale: 0.95 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ duration: 0.5, ease: "easeOut" }}
          className="w-full max-w-lg"
        >
          <div className="rounded-2xl border border-slate-200 bg-white p-8 shadow-xl dark:border-gray-700 dark:bg-gray-800 md:p-10">
            <motion.div
              initial={{ opacity: 0, scale: 0.8 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ delay: 0.2, type: "spring", stiffness: 200 }}
              className="mb-6 flex justify-center"
            >
              <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600 shadow-lg">
                <CheckCircle className="h-10 w-10 text-white" />
              </div>
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.3 }}
              className="mb-2 text-center"
            >
              <h1 className="mb-2 text-3xl font-bold text-slate-900 dark:text-white md:text-4xl">
                Verify Your Email
              </h1>
              <p className="text-lg text-slate-600 dark:text-gray-300">
                Please check your email to complete verification
              </p>
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.4 }}
              className="mt-8 mb-6"
            >
              <div className="rounded-xl border border-slate-200 bg-slate-50 p-5 dark:border-gray-600 dark:bg-gray-700/50">
                <div className="flex items-start gap-4">
                  <div className="mt-1 shrink-0">
                    <Send className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                  </div>
                  <div className="flex-1">
                    <p className="text-sm leading-relaxed text-slate-700 dark:text-gray-200">
                      We've sent a verification link to{" "}
                      <span className="font-semibold text-purple-700 dark:text-purple-300">
                        {userEmail}
                      </span>
                      . Please check your inbox and click the link to verify your account.
                    </p>
                  </div>
                </div>
              </div>
            </motion.div>

            {status === "verification-link-sent" && (
              <motion.div
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: "auto" }}
                transition={{ delay: 0.5 }}
                className="mb-6"
              >
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40">
                  <div className="flex items-center gap-3">
                    <CheckCircle className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <p className="text-sm text-emerald-800 dark:text-emerald-200">
                      A new verification link has been sent to your email address.
                    </p>
                  </div>
                </div>
              </motion.div>
            )}

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.5 }}
              className="mb-6"
            >
              <form onSubmit={submit}>
                <Button
                  type="submit"
                  disabled={processing}
                  className="w-full cursor-pointer rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 py-6 text-lg font-semibold text-white shadow-lg transition-all duration-300 hover:from-purple-700 hover:to-blue-700 hover:shadow-xl disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {processing ? (
                    <>
                      <LoaderCircle className="mr-2 h-5 w-5 animate-spin" />
                      Sending...
                    </>
                  ) : (
                    <>
                      <Send className="mr-2 h-5 w-5" />
                      Resend Verification Email
                    </>
                  )}
                </Button>
              </form>
            </motion.div>

            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.6 }}
              className="border-t border-slate-200 pt-4 text-center dark:border-gray-700"
            >
              <p className="text-sm text-slate-500 dark:text-gray-400">
                Questions about email verification?{" "}
                <Link
                  href={route("contact")}
                  className="cursor-pointer text-purple-600 underline transition-colors hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300"
                >
                  Contact our support team
                </Link>
              </p>
            </motion.div>

            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              transition={{ delay: 0.7 }}
              className="mt-4 text-center"
            >
              <Link
                href={route("logout.main")}
                method="post"
                as="button"
                className="cursor-pointer text-sm text-slate-500 underline transition-colors hover:text-slate-700 dark:text-gray-400 dark:hover:text-gray-300"
              >
                Log Out
              </Link>
            </motion.div>
          </div>
        </motion.div>
      </div>
    </FrontendLayout>
  )
}

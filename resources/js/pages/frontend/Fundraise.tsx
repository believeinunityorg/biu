"use client";

/**
 * Option 3 — Branded Funnel Page (Public)
 * Step 1: Explain (30 sec) → Step 2: Qualify (form) → Step 3: Redirect to Wefunder
 * Captures leads; builds founder pipeline; Wefunder is the rails, not the front door.
 */
import FrontendLayout from "@/layouts/frontend/frontend-layout";
import { PageHead } from "@/components/frontend/PageHead";
import { Button } from "@/components/frontend/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card";
import { Input } from "@/components/frontend/ui/input";
import { Label } from "@/components/frontend/ui/label";
import { Textarea } from "@/components/frontend/ui/textarea";
import { useForm } from "@inertiajs/react";
import { motion } from "framer-motion";
import { ArrowRight, Sparkles, FileText, Send } from "lucide-react";
import InputError from "@/components/input-error";
import TurnstileField, { useTurnstileGate } from "@/components/TurnstileField";

const WEFUNDER_URL = "https://wefunder.com/raise?utm_source=believeinunity&utm_medium=partner&utm_campaign=qualified_founders";

interface FundraiseProps {
  seo?: { title: string; description?: string };
  wefunderUrl?: string;
}

export default function FundraisePage({ seo, wefunderUrl = WEFUNDER_URL }: FundraiseProps) {
  const { data, setData, post, processing, errors } = useForm({
    name: "",
    company: "",
    email: "",
    project_summary: "",
    cf_turnstile_response: "",
  });

  const { turnstileBlocksSubmit } = useTurnstileGate(data.cf_turnstile_response);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post("/fundraise", { preserveScroll: false });
  };

  return (
    <FrontendLayout>
      <PageHead
        title={seo?.title ?? "Raise Capital"}
        description={seo?.description ?? "Raise capital through community-powered crowdfunding."}
      />
      <div className="min-h-screen bg-gradient-to-b from-slate-50 to-white dark:from-gray-900 dark:to-gray-800 py-12 px-4 sm:px-6 lg:px-8">
        <div className="max-w-2xl mx-auto">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5 }}
            className="space-y-10"
          >
            {/* Step 1 – Explain (30 seconds) */}
            <section className="text-center">
              <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 mb-4">
                <Sparkles className="w-6 h-6" />
              </div>
              <h1 className="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white mb-3">
                Raise capital through community-powered crowdfunding.
              </h1>
              <p className="text-lg text-gray-600 dark:text-gray-300 max-w-xl mx-auto">
                Share a few details below (we capture leads and build our founder pipeline), then continue to the Wefunder application.
              </p>
            </section>

            {/* Step 2 – Qualify them (short form: Name, Company, Email, Project summary) */}
            <Card className="border border-gray-200 dark:border-gray-700 shadow-xl overflow-hidden">
              <CardHeader className="bg-gradient-to-r from-emerald-500/10 to-teal-500/10 dark:from-emerald-900/20 dark:to-teal-900/20 border-b border-gray-200 dark:border-gray-700">
                <div className="flex items-center gap-2 text-emerald-700 dark:text-emerald-400">
                  <FileText className="w-5 h-5" />
                  <span className="text-sm font-medium">Step 2 — Qualify</span>
                </div>
                <CardTitle className="text-xl text-gray-900 dark:text-white mt-1">
                  Short form
                </CardTitle>
                <p className="text-sm text-gray-600 dark:text-gray-300 mt-1">
                  Name, Company, Email, Project summary. We save this so we can follow up and own the relationship; then you continue to Wefunder.
                </p>
              </CardHeader>
              <CardContent className="p-6 sm:p-8">
                <form onSubmit={submit} className="space-y-5">
                  <div>
                    <Label htmlFor="name" className="text-gray-900 dark:text-white font-medium">
                      Name <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="name"
                      type="text"
                      placeholder="Your full name"
                      value={data.name}
                      onChange={(e) => setData("name", e.target.value)}
                      className="mt-2 bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white"
                      required
                    />
                    <InputError message={errors.name} className="mt-1" />
                  </div>
                  <div>
                    <Label htmlFor="company" className="text-gray-900 dark:text-white font-medium">
                      Company <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="company"
                      type="text"
                      placeholder="Company or project name"
                      value={data.company}
                      onChange={(e) => setData("company", e.target.value)}
                      className="mt-2 bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white"
                      required
                    />
                    <InputError message={errors.company} className="mt-1" />
                  </div>
                  <div>
                    <Label htmlFor="email" className="text-gray-900 dark:text-white font-medium">
                      Email <span className="text-red-500">*</span>
                    </Label>
                    <Input
                      id="email"
                      type="email"
                      placeholder="you@example.com"
                      value={data.email}
                      onChange={(e) => setData("email", e.target.value)}
                      className="mt-2 bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white"
                      required
                    />
                    <InputError message={errors.email} className="mt-1" />
                  </div>
                  <div>
                    <Label htmlFor="project_summary" className="text-gray-900 dark:text-white font-medium">
                      Project summary <span className="text-red-500">*</span>
                    </Label>
                    <Textarea
                      id="project_summary"
                      placeholder="Brief description of your project or raise (what you're building, how much you're raising, why it matters)"
                      value={data.project_summary}
                      onChange={(e) => setData("project_summary", e.target.value)}
                      rows={4}
                      className="mt-2 bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white resize-none"
                      required
                    />
                    <InputError message={errors.project_summary} className="mt-1" />
                  </div>

                  <TurnstileField
                    onToken={(token) => setData("cf_turnstile_response", token)}
                    error={errors.cf_turnstile_response}
                  />

                  {/* Step 3 – Then redirect to Wefunder */}
                  <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
                      After you submit, we save your info and you’ll be taken to the Wefunder application to complete your raise. Wefunder is our rails — this page is your front door.
                    </p>
                    <Button
                      type="submit"
                      className="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2"
                      disabled={processing || turnstileBlocksSubmit}
                    >
                      {processing ? (
                        <>
                          <Send className="w-5 h-5 animate-pulse" />
                          Sending…
                        </>
                      ) : (
                        <>
                          Continue to Wefunder Application
                          <ArrowRight className="w-5 h-5" />
                        </>
                      )}
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>

            <p className="text-center text-sm text-gray-500 dark:text-gray-400">
              By continuing, you agree to our{" "}
              <a href="/terms-of-service" className="text-emerald-600 dark:text-emerald-400 hover:underline">
                Terms of Service
              </a>{" "}
              and{" "}
              <a href="/privacy-policy" className="text-emerald-600 dark:text-emerald-400 hover:underline">
                Privacy Policy
              </a>
              .
            </p>
          </motion.div>
        </div>
      </div>
    </FrontendLayout>
  );
}

// resources/js/Pages/PrivacyPolicy.tsx
import React from 'react';
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { PageHead } from "@/components/frontend/PageHead";

const PrivacyPolicy = () => {
    return (
        <FrontendLayout>
            <PageHead title="Privacy Policy" description="Learn how we collect, use, and protect your personal information. Your privacy matters to us." />
            <div className="min-h-screen bg-gray-50 dark:bg-gray-900 py-12 px-4 sm:px-6 lg:px-8">
                <div className="max-w-4xl mx-auto">
                    {/* Header */}
                    <div className="text-center mb-12">
                        <h1 className="text-4xl font-bold text-gray-900 dark:text-white mb-4">
                            Privacy Policy
                        </h1>
                        <p className="text-lg text-gray-600 dark:text-gray-300">
                            Last updated: {new Date().toLocaleDateString()}
                        </p>
                    </div>

                    {/* Content */}
                    <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-8">
                        {/* Introduction */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Introduction
                            </h2>
                            <p className="text-gray-600 dark:text-gray-300 leading-relaxed">
                                At Believe In Unity (501c3ers), we are committed to protecting your privacy and ensuring
                                the security of your personal information. This Privacy Policy explains
                                how we collect, use, store, and protect your data when you use our services at{" "}
                                <a href="https://believeinunity.org" className="text-blue-600 hover:underline dark:text-blue-400">
                                    believeinunity.org
                                </a>
                                .
                            </p>
                        </section>

                        {/* Data Collection */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                What Data We Collect
                            </h2>
                            <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                                <ul className="list-disc list-inside space-y-2 text-gray-600 dark:text-gray-300">
                                    <li>Phone numbers for verification purposes</li>
                                    <li>Email addresses for communication</li>
                                    <li>Name and contact information</li>
                                    <li>Organization details (if applicable)</li>
                                    <li>Facebook Page connection and related data (only if you connect a page—e.g. page list, Page-published content you choose to view, and publish actions)</li>
                                    <li>Gmail / Google Contacts connection data (only if you connect Gmail for Email Invite—e.g. contact names and emails you choose to sync, OAuth tokens)</li>
                                    <li>YouTube / Google account connection data (only if you connect YouTube—e.g. channel metadata, OAuth tokens, uploaded video references)</li>
                                    <li>IP addresses and browser information</li>
                                    <li>Usage data and analytics</li>
                                </ul>
                            </div>
                        </section>

                        {/* Why We Collect Data */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Why We Collect Your Data
                            </h2>
                            <div className="space-y-4">
                                <div className="flex items-start">
                                    <div className="flex-shrink-0 h-6 w-6 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center mt-1">
                                        <span className="text-blue-600 dark:text-blue-300 text-sm font-bold">1</span>
                                    </div>
                                    <div className="ml-4">
                                        <h3 className="font-medium text-gray-900 dark:text-white">
                                            Phone Verification (OTP)
                                        </h3>
                                        <p className="text-gray-600 dark:text-gray-300 mt-1">
                                            We collect phone numbers to send One-Time Passwords (OTP) for
                                            account verification and security purposes.
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-start">
                                    <div className="flex-shrink-0 h-6 w-6 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center mt-1">
                                        <span className="text-blue-600 dark:text-blue-300 text-sm font-bold">2</span>
                                    </div>
                                    <div className="ml-4">
                                        <h3 className="font-medium text-gray-900 dark:text-white">
                                            Service Provision
                                        </h3>
                                        <p className="text-gray-600 dark:text-gray-300 mt-1">
                                            To provide and maintain our services, communicate with you,
                                            and improve user experience.
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-start">
                                    <div className="flex-shrink-0 h-6 w-6 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center mt-1">
                                        <span className="text-blue-600 dark:text-blue-300 text-sm font-bold">3</span>
                                    </div>
                                    <div className="ml-4">
                                        <h3 className="font-medium text-gray-900 dark:text-white">
                                            Security & Compliance
                                        </h3>
                                        <p className="text-gray-600 dark:text-gray-300 mt-1">
                                            To protect against fraud, ensure platform security, and
                                            comply with legal obligations.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* Facebook Page Connection */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Connecting Your Facebook Page
                            </h2>

                            <div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-4 border border-blue-200 dark:border-blue-800">
                                <h3 className="font-semibold text-blue-800 dark:text-blue-300 mb-2">
                                    Important: Facebook is not used for user login
                                </h3>
                                <p className="text-blue-700 dark:text-blue-400 text-sm leading-relaxed">
                                    Believe In Unity does <strong>not</strong> use Facebook as our user authentication system.
                                    Users create and sign into their Believe In Unity accounts using our own login system
                                    (for example, email and password, and related verification). Facebook Login is used{" "}
                                    <strong>only</strong> to authorize and connect a Facebook Page after the user is already
                                    signed into our application. Connecting Facebook does not create a Believe In Unity account
                                    and is not required to register or log in.
                                </p>
                            </div>

                            <p className="text-gray-600 dark:text-gray-300 leading-relaxed mb-4">
                                If you choose to connect a Facebook Page (typically from organization or integration settings
                                after you are logged in), we request certain permissions from Meta (Facebook) so you can manage
                                that Page and view insights inside Believe In Unity. We use these permissions only as described
                                below. We do not post to your Page without your explicit permission.
                            </p>

                            <div className="space-y-4 mb-4">
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        How Facebook Page connection works
                                    </h3>
                                    <ul className="list-disc list-inside space-y-2 text-gray-600 dark:text-gray-300 text-sm">
                                        <li>You must already be signed into your Believe In Unity account.</li>
                                        <li>You start the Facebook Page connection from our app (for example, Facebook Integration / connect flow).</li>
                                        <li>Meta shows a Facebook Login / authorization screen so you can grant Page permissions to our app.</li>
                                        <li>You select which Facebook Page(s) you manage and want to connect.</li>
                                        <li>We store connection credentials (such as Page access tokens) and related Page metadata needed to provide the features you requested.</li>
                                        <li>You can disconnect the Page at any time; after disconnect we stop accessing that Page on your behalf.</li>
                                    </ul>
                                </div>
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        Data we may receive or store when you connect a Page
                                    </h3>
                                    <ul className="list-disc list-inside space-y-2 text-gray-600 dark:text-gray-300 text-sm">
                                        <li>Identifiers and names of Facebook Pages you manage (so you can choose which Page to connect)</li>
                                        <li>Page access tokens and related OAuth / connection metadata</li>
                                        <li>Content for posts you create and publish through our app (stored in our database so you can manage those posts in BIU)</li>
                                    </ul>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm mt-3">
                                        We use this data only to provide Facebook Page features inside Believe In Unity. We do not
                                        sell Facebook user or Page data. We do not use it for advertising unrelated to the service
                                        you requested.
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-4">
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        Pages list (pages_show_list)
                                    </h3>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm">
                                        We request access to list the Facebook Pages you manage so you can choose which page to connect to our service. We use this only to display your pages and let you select one to connect. We do not access or use your page list for any other purpose.
                                    </p>
                                </div>
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        Managing posts (pages_manage_posts)
                                    </h3>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm">
                                        We use this permission to create and publish posts on your connected Facebook Page only when you explicitly take action in our app (for example, when you write a post and tap &quot;Publish&quot;). We do not post to your page without your permission. Every post is published only after you confirm it. You remain in full control of what is published. We list in our app only posts created through BIU; we do not read your Page&apos;s full Facebook feed, likes, comments, or insights.
                                    </p>
                                </div>
                            </div>
                            <p className="text-gray-600 dark:text-gray-300 text-sm mt-4">
                                You can disconnect your Facebook Page at any time from your account or integration settings
                                (for example,{" "}
                                <a href="/facebook/connect" className="text-blue-600 hover:underline dark:text-blue-400">
                                    /facebook/connect
                                </a>
                                ). After disconnection, we stop accessing that page and do not post on your behalf.
                                You can also remove our app&apos;s access in Facebook: Settings → Apps and Websites. For full deletion
                                steps, see our{" "}
                                <a href="/data-deletion" className="text-blue-600 hover:underline dark:text-blue-400">
                                    data deletion instructions
                                </a>
                                .
                            </p>
                        </section>

                        {/* Gmail / Google Contacts (Email Invite) */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Connecting Gmail for Email Invites (Google OAuth)
                            </h2>
                            <p className="text-gray-600 dark:text-gray-300 leading-relaxed mb-4">
                                If your nonprofit chooses to connect Google under <strong>Email Invite</strong>, we use Google OAuth only to import Google Contacts so you can send invitation emails you initiate. We do not sell Google user data. We do not read Gmail inbox messages. We do not use Google contact data for advertising or unrelated purposes.
                            </p>
                            <div className="space-y-4">
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        View your contacts (contacts.readonly)
                                    </h3>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm">
                                        We import contact names and email addresses from Google Contacts so you can invite supporters to join your organization on Believe In Unity. Imported contacts are stored only for your organization&apos;s invite workflow. We do not request Gmail inbox access.
                                    </p>
                                </div>
                            </div>
                            <p className="text-gray-600 dark:text-gray-300 text-sm mt-4">
                                OAuth tokens are stored securely and used only to refresh access for these features. You can disconnect Gmail at any time under <strong>Email Invite</strong>. We delete the connection and stored tokens from our database. See our{" "}
                                <a href="/data-deletion" className="text-blue-600 hover:underline dark:text-blue-400">
                                    data deletion instructions
                                </a>{" "}
                                for full steps. You can also remove our app&apos;s access at{" "}
                                <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline dark:text-blue-400">
                                    myaccount.google.com/permissions
                                </a>
                                .
                            </p>
                            <div className="mt-6 rounded-lg border border-purple-500/20 bg-purple-500/5 p-4 dark:border-purple-400/25 dark:bg-purple-500/10">
                                <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                    Google Workspace Limited Use &amp; AI
                                </h3>
                                <p className="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-3">
                                    The use of raw or derived user data received from Workspace APIs will adhere to the{" "}
                                    <a
                                        href="https://developers.google.com/workspace/workspace-api-user-data-developer-policy"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        Google User Data Policy
                                    </a>
                                    , including the Limited Use requirements.
                                </p>
                                <p className="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-3">
                                    Believe In Unity does <strong>not</strong> transfer Google Contacts or other Google Workspace API user data (raw, aggregated, anonymized, or derived) to any third-party AI/ML service. We do <strong>not</strong> use that data to create, train, or improve foundational or generalized machine learning or artificial intelligence models.
                                </p>
                                <p className="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                                    Separately, the product offers optional AI features that are <strong>not</strong> connected to Google Workspace or Contacts data—for example drafting tools and AI Media Studio. Those features may use{" "}
                                    <strong>OpenAI Platform API (pay-as-you-go / usage-based billing)</strong> and/or{" "}
                                    <strong>fal.ai API (usage-based / pay-as-you-go credits)</strong> with user-supplied or app-generated content only. Google Workspace / Contacts data is never included in those AI requests. We do not use ChatGPT consumer Free/Plus/Pro plans or fal.ai free consumer tiers for these integrations—only API usage billing.
                                </p>
                            </div>
                        </section>

                        {/* YouTube / Google Connection */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Connecting Your YouTube Channel (Google OAuth)
                            </h2>
                            <p className="text-gray-600 dark:text-gray-300 leading-relaxed mb-4">
                                If you choose to connect a YouTube channel, we use Google OAuth to access your channel only as described below. We do not sell Google user data. We use it solely to provide Believe In Unity features you request.
                            </p>
                            <div className="space-y-4">
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        View channel and videos (youtube.readonly)
                                    </h3>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm">
                                        We read your YouTube channel name, channel URL, and public video metadata so your content can appear in Unity Videos and related dashboards. We do not modify or delete your existing YouTube content with this scope.
                                    </p>
                                </div>
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        Manage YouTube account (youtube.force-ssl)
                                    </h3>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm">
                                        We use this scope to manage live streams and channel features you start from Believe In Unity, such as Go YouTube Live and related Unity Meet workflows. We only act when you explicitly start or publish from the app.
                                    </p>
                                </div>
                                <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                    <h3 className="font-semibold text-gray-900 dark:text-white mb-2">
                                        Upload recordings (youtube.upload)
                                    </h3>
                                    <p className="text-gray-600 dark:text-gray-300 text-sm">
                                        When you explicitly publish a Unity Meet or livestream recording to YouTube, we upload that video to your connected channel using the title, description, and privacy setting you choose. We never upload without your action in the app.
                                    </p>
                                </div>
                            </div>
                            <p className="text-gray-600 dark:text-gray-300 text-sm mt-4">
                                OAuth tokens are stored securely and used only to refresh access for these features. You can disconnect YouTube at any time under Integrations → YouTube or Profile → Integrations. We revoke tokens on disconnect and stop accessing your channel. See our{" "}
                                <a href="/data-deletion" className="text-blue-600 hover:underline dark:text-blue-400">
                                    data deletion instructions
                                </a>{" "}
                                for full steps.
                            </p>
                        </section>

                        {/* Data Storage & Protection */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                How We Store and Protect Your Data
                            </h2>
                            <div className="grid md:grid-cols-2 gap-6">
                                <div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-6">
                                    <h3 className="font-semibold text-green-800 dark:text-green-300 mb-2">
                                        🔒 Encryption
                                    </h3>
                                    <p className="text-green-700 dark:text-green-400 text-sm">
                                        All sensitive data is encrypted both in transit (SSL/TLS) and at rest.
                                    </p>
                                </div>

                                <div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-6">
                                    <h3 className="font-semibold text-green-800 dark:text-green-300 mb-2">
                                        🛡️ Secure Servers
                                    </h3>
                                    <p className="text-green-700 dark:text-green-400 text-sm">
                                        Data is stored on secure, monitored servers with regular security updates.
                                    </p>
                                </div>

                                <div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-6">
                                    <h3 className="font-semibold text-green-800 dark:text-green-300 mb-2">
                                        📊 Access Controls
                                    </h3>
                                    <p className="text-green-700 dark:text-green-400 text-sm">
                                        Strict access controls ensure only authorized personnel can access your data.
                                    </p>
                                </div>

                                <div className="bg-green-50 dark:bg-green-900/20 rounded-lg p-6">
                                    <h3 className="font-semibold text-green-800 dark:text-green-300 mb-2">
                                        🔄 Regular Audits
                                    </h3>
                                    <p className="text-green-700 dark:text-green-400 text-sm">
                                        Regular security audits and vulnerability assessments are conducted.
                                    </p>
                                </div>
                            </div>
                        </section>

                        {/* User Rights */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Your Rights & Data Control
                            </h2>
                            <div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6">
                                <h3 className="font-semibold text-blue-800 dark:text-blue-300 mb-3">
                                    Request Data Deletion or Exercise Your Rights
                                </h3>
                                <p className="text-blue-700 dark:text-blue-400 mb-4">
                                    You have the right to:
                                </p>
                                <ul className="list-disc list-inside space-y-2 text-blue-700 dark:text-blue-400">
                                    <li>Request deletion of your personal data</li>
                                    <li>Access the data we hold about you</li>
                                    <li>Correct inaccurate data</li>
                                    <li>Object to data processing</li>
                                    <li>Request data portability</li>
                                </ul>
                                <div className="mt-4 p-4 bg-white dark:bg-gray-700 rounded-lg">
                                    <p className="text-gray-600 dark:text-gray-300">
                                        To delete your data, follow our step-by-step instructions:
                                    </p>
                                    <a
                                        href="/data-deletion"
                                        className="mt-2 inline-block text-lg font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                    >
                                        Data deletion instructions →
                                    </a>
                                    <p className="text-gray-600 dark:text-gray-300 mt-4">
                                        Or contact us at:
                                    </p>
                                    <p className="text-lg font-semibold text-gray-900 dark:text-white mt-2">
                                        support@believeinunity.org
                                    </p>
                                </div>
                            </div>
                        </section>

                        {/* Contact Information */}
                        <section className="mb-8">
                            <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">
                                Contact Information
                            </h2>
                            <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                                <div className="space-y-3">
                                    <div>
                                        <h3 className="font-medium text-gray-900 dark:text-white">
                                            Privacy Team
                                        </h3>
                                        <p className="text-gray-600 dark:text-gray-300">
                                            Email: support@believeinunity.org
                                        </p>
                                    </div>
                                    <div>
                                        <h3 className="font-medium text-gray-900 dark:text-white">
                                            General Support
                                        </h3>
                                        <p className="text-gray-600 dark:text-gray-300">
                                            Email: support@believeinunity.org
                                        </p>
                                    </div>
                                    <div>
                                        <h3 className="font-medium text-gray-900 dark:text-white">
                                            Response Time
                                        </h3>
                                        <p className="text-gray-600 dark:text-gray-300">
                                            We typically respond to privacy-related inquiries within 48 hours.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        {/* Consent Notice */}
                        <section className="mt-12 p-6 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800">
                            <h3 className="text-lg font-semibold text-yellow-800 dark:text-yellow-300 mb-2">
                                Consent & Agreement
                            </h3>
                            <p className="text-yellow-700 dark:text-yellow-400">
                                By using our services and providing your phone number for verification,
                                you consent to the collection and use of your information as described
                                in this Privacy Policy.
                            </p>
                        </section>
                    </div>

                    {/* Footer Links */}
                    <div className="mt-8 text-center">
                        <div className="flex justify-center space-x-6">
                            <a
                                href="/terms-of-service"
                                className="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
                            >
                                Terms of Service
                            </a>
                            <a
                                href="/data-deletion"
                                className="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
                            >
                                Data Deletion
                            </a>
                            <a
                                href="/privacy-policy"
                                className="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors font-semibold"
                            >
                                Privacy Policy
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </FrontendLayout>
    );
};

export default PrivacyPolicy;

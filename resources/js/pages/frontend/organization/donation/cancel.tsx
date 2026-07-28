import { motion } from "framer-motion"
import { XCircleIcon, HomeIcon, RefreshCcwIcon, InfoIcon, PhoneIcon, MailIcon, ReceiptTextIcon } from "lucide-react"
import { Link } from "@inertiajs/react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Separator } from "@/components/ui/separator"

export default function DonationCancelPage() {
    // Placeholder data - in a real app, this might come from query params
    const cancellationDetails = {
        reason: "Payment processing failed due to insufficient funds.",
        transactionId: "TXN-123456789", // Could be null if transaction never started
        contactEmail: "support@believeinunity.org",
        contactPhone: "+1 (800) 123-4567",
    }

    const containerVariants = {
        hidden: { opacity: 0, y: 50 },
        visible: {
            opacity: 1,
            y: 0,
            transition: {
                type: "spring" as const,
                damping: 15,
                stiffness: 100,
                staggerChildren: 0.1,
                delayChildren: 0.2,
            },
        },
    }

    const itemVariants = {
        hidden: { opacity: 0, y: 20 },
        visible: { opacity: 1, y: 0 },
    }

    const iconVariants = {
        hidden: { scale: 0, rotate: 180, opacity: 0 },
        visible: {
            scale: 1,
            rotate: 0,
            opacity: 1,
            transition: {
                type: "spring" as const,
                stiffness: 260,
                damping: 20,
                duration: 0.8,
            },
        },
    }

    return (
        <div className="flex min-h-[calc(100vh-64px)] flex-col items-center justify-center bg-gradient-to-br from-red-50 to-rose-100 px-4 py-12 dark:from-red-950 dark:to-rose-950">
            <motion.div
                variants={containerVariants}
                initial="hidden"
                animate="visible"
                className="w-full max-w-3xl rounded-2xl bg-white p-8 text-center shadow-xl dark:bg-gray-900 md:p-12 border border-gray-100 dark:border-gray-800"
            >
                <motion.div variants={iconVariants} className="mb-6 flex justify-center">
                    <XCircleIcon className="h-28 w-28 text-red-500 md:h-36 md:w-36" />
                </motion.div>
                <motion.h1
                    variants={itemVariants}
                    className="mb-4 text-4xl font-extrabold text-gray-900 dark:text-gray-50 md:text-5xl leading-tight"
                >
                    Donation Cancelled
                </motion.h1>
                <motion.p
                    variants={itemVariants}
                    className="mb-8 text-xl text-gray-600 dark:text-gray-400 md:text-2xl max-w-prose mx-auto"
                >
                    Your donation could not be processed at this time.
                </motion.p>

                <motion.div variants={itemVariants} className="mb-8">
                    <Card className="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm">
                        <CardHeader className="pb-4">
                            <CardTitle className="text-2xl font-bold text-gray-800 dark:text-gray-100 flex items-center justify-center">
                                <InfoIcon className="mr-2 h-6 w-6 text-red-600 dark:text-red-400" />
                                Cancellation Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-left text-gray-700 dark:text-gray-300 p-6">
                            <div className="flex items-start">
                                <InfoIcon className="mr-3 h-5 w-5 text-red-600 dark:text-red-400 mt-1" />
                                <span>
                                    Reason:{" "}
                                    <span className="font-medium text-gray-900 dark:text-gray-50">{cancellationDetails.reason}</span>
                                </span>
                            </div>
                            {cancellationDetails.transactionId && (
                                <div className="flex items-center">
                                    <ReceiptTextIcon className="mr-3 h-5 w-5 text-purple-600 dark:text-purple-400" />
                                    <span>
                                        Transaction ID:{" "}
                                        <span className="font-medium text-gray-900 dark:text-gray-50">
                                            {cancellationDetails.transactionId}
                                        </span>
                                    </span>
                                </div>
                            )}
                            <Separator className="my-2 bg-gray-200 dark:bg-gray-700" />
                            <p className="text-center text-gray-600 dark:text-gray-400 italic">
                                Please review the details and try again, or contact our support team.
                            </p>
                            <div className="flex flex-col sm:flex-row justify-center gap-4 mt-4">
                                {cancellationDetails.contactEmail && (
                                    <Link
                                        href={`mailto:${cancellationDetails.contactEmail}`}
                                        className="text-blue-600 hover:underline dark:text-blue-400 flex items-center justify-center"
                                    >
                                        <MailIcon className="mr-2 h-4 w-4" />
                                        {cancellationDetails.contactEmail}
                                    </Link>
                                )}
                                {cancellationDetails.contactPhone && (
                                    <Link
                                        href={`tel:${cancellationDetails.contactPhone.replace(/\s/g, "")}`}
                                        className="text-blue-600 hover:underline dark:text-blue-400 flex items-center justify-center"
                                    >
                                        <PhoneIcon className="mr-2 h-4 w-4" />
                                        {cancellationDetails.contactPhone}
                                    </Link>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>

                <Separator className="my-8 bg-gray-200 dark:bg-gray-700" />

                <div className="flex flex-col gap-6 md:flex-row md:justify-center">
                    <motion.div variants={itemVariants}>
                        <Link
                            href="/donate"
                            className="flex flex-col items-center justify-center p-4 rounded-lg
              text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-50
              transition-colors duration-200 group"
                        >
                            <RefreshCcwIcon className="h-8 w-8 mb-2 group-hover:scale-110 transition-transform duration-220" />
                            <span className="text-lg font-medium">Try Again</span>
                        </Link>
                    </motion.div>
                    <motion.div variants={itemVariants}>
                        <Link
                            href="/"
                            className="flex flex-col items-center justify-center p-4 rounded-lg
              text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-50
              transition-colors duration-200 group"
                        >
                            <HomeIcon className="h-8 w-8 mb-2 group-hover:scale-110 transition-transform duration-220" />
                            <span className="text-lg font-medium">Go to Home</span>
                        </Link>
                    </motion.div>
                </div>
            </motion.div>
        </div>
    )
}

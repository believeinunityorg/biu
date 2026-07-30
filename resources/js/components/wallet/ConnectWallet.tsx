import { motion } from 'framer-motion'
import { Wallet, RefreshCw, Building2, Sparkles, Shield, Zap } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useEffect, useState } from 'react'

interface ConnectWalletProps {
    isLoading: boolean
    organizationName?: string
    /** Organization / Care Alliance accounts use org info; supporters use personal info. */
    isOrganizationAccount?: boolean
    onConnect: () => void
}

export function ConnectWallet({
    isLoading,
    organizationName,
    isOrganizationAccount = false,
    onConnect
}: ConnectWalletProps) {
    const [particles, setParticles] = useState<Array<{ id: number; x: number; y: number; delay: number }>>([])

    useEffect(() => {
        // Generate floating particles
        const newParticles = Array.from({ length: 12 }, (_, i) => ({
            id: i,
            x: Math.random() * 100,
            y: Math.random() * 100,
            delay: Math.random() * 2,
        }))
        setParticles(newParticles)
    }, [])

    return (
        <div className="flex-1 flex flex-col items-center justify-center p-4 space-y-3 relative z-10 overflow-hidden max-h-full">
            {/* Animated Background Particles - Reduced */}
            <div className="absolute inset-0 overflow-hidden pointer-events-none">
                {particles.slice(0, 8).map((particle) => (
                    <motion.div
                        key={particle.id}
                        className="absolute w-1.5 h-1.5 rounded-full bg-gradient-to-r from-purple-400/15 to-blue-400/15 blur-sm"
                        style={{
                            left: `${particle.x}%`,
                            top: `${particle.y}%`,
                        }}
                        animate={{
                            y: [0, -20, 0],
                            x: [0, Math.sin(particle.id) * 15, 0],
                            opacity: [0.2, 0.4, 0.2],
                            scale: [1, 1.1, 1],
                        }}
                        transition={{
                            duration: 3 + particle.delay,
                            repeat: Infinity,
                            ease: "easeInOut",
                            delay: particle.delay,
                        }}
                    />
                ))}
            </div>

            {/* Animated Gradient Orbs - Smaller */}
            <motion.div
                className="absolute top-1/4 left-1/4 w-32 h-32 bg-purple-500/8 rounded-full blur-2xl"
                animate={{
                    scale: [1, 1.2, 1],
                    opacity: [0.2, 0.4, 0.2],
                }}
                transition={{
                    duration: 4,
                    repeat: Infinity,
                    ease: "easeInOut",
                }}
            />
            <motion.div
                className="absolute bottom-1/4 right-1/4 w-32 h-32 bg-blue-500/8 rounded-full blur-2xl"
                animate={{
                    scale: [1, 1.3, 1],
                    opacity: [0.2, 0.4, 0.2],
                }}
                transition={{
                    duration: 5,
                    repeat: Infinity,
                    ease: "easeInOut",
                    delay: 1,
                }}
            />

            {/* Main Content - Compact */}
            <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -10 }}
                transition={{ duration: 0.3 }}
                className="text-center space-y-3 w-full max-w-sm relative z-10"
            >
                {/* Animated Wallet Icon - Centered */}
                <motion.div
                    className="mx-auto relative flex items-center justify-center"
                    initial={{ scale: 0 }}
                    animate={{ scale: 1 }}
                    transition={{
                        type: "spring",
                        stiffness: 200,
                        damping: 15,
                        delay: 0.1,
                    }}
                >
                    {/* Glowing Ring - Smaller */}
                    <motion.div
                        className="absolute inset-0 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 opacity-15 blur-lg"
                        animate={{
                            scale: [1, 1.15, 1],
                            opacity: [0.15, 0.3, 0.15],
                        }}
                        transition={{
                            duration: 2,
                            repeat: Infinity,
                            ease: "easeInOut",
                        }}
                    />
                    
                    {/* Icon Container - Smaller, Centered */}
                    <motion.div
                        className="relative w-16 h-16 bg-gradient-to-br from-purple-600 via-purple-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg mx-auto"
                        whileHover={{ scale: 1.05 }}
                        transition={{ type: "spring", stiffness: 300 }}
                    >
                        <Wallet className="h-8 w-8 text-white mx-auto" />
                        
                        {/* Sparkle Effects - Smaller */}
                        <motion.div
                            className="absolute -top-1 -right-1"
                            animate={{
                                rotate: [0, 360],
                                scale: [1, 1.1, 1],
                            }}
                            transition={{
                                duration: 3,
                                repeat: Infinity,
                                ease: "linear",
                            }}
                        >
                            <Sparkles className="h-3 w-3 text-yellow-400" />
                        </motion.div>
                    </motion.div>
                </motion.div>

                {/* Title and Description - Compact */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.2 }}
                >
                    <h3 className="text-xl font-bold mb-1.5 bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent">
                        Connect Your Wallet
                    </h3>
                    <p className="text-xs text-muted-foreground max-w-xs mx-auto leading-relaxed">
                        Connect Believe Cash to manage funds and transactions.
                    </p>
                </motion.div>

                {/* Organization Info - Compact */}
                {organizationName && (
                    <motion.div
                        initial={{ opacity: 0, y: 5 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.3 }}
                        className="p-2.5 bg-gradient-to-r from-purple-50/50 to-blue-50/50 dark:from-purple-950/20 dark:to-blue-950/20 rounded-lg border border-purple-200/50 dark:border-purple-800/50 backdrop-blur-sm"
                    >
                        <div className="flex items-center gap-2">
                            <Building2 className="h-4 w-4 text-purple-600 dark:text-purple-400" />
                            <div className="text-left">
                                <p className="text-[10px] text-muted-foreground">Organization</p>
                                <p className="text-xs font-semibold text-foreground">{organizationName}</p>
                            </div>
                        </div>
                    </motion.div>
                )}

                {/* Features Icons - Compact */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 0.4 }}
                    className="flex items-center justify-center gap-4 py-2"
                >
                    {[
                        { icon: Shield, label: 'Secure' },
                        { icon: Zap, label: 'Fast' },
                        { icon: Wallet, label: 'Easy' },
                    ].map((feature, index) => (
                        <motion.div
                            key={feature.label}
                            className="flex flex-col items-center gap-1"
                            initial={{ opacity: 0, y: 5 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.5 + index * 0.05 }}
                            whileHover={{ scale: 1.1 }}
                        >
                            <div className="w-8 h-8 rounded-full bg-gradient-to-br from-purple-500/20 to-blue-500/20 flex items-center justify-center">
                                <feature.icon className="h-4 w-4 text-purple-600 dark:text-purple-400" />
                            </div>
                            <span className="text-[10px] text-muted-foreground">{feature.label}</span>
                        </motion.div>
                    ))}
                </motion.div>

                {/* Connect Button - Compact */}
                <motion.div
                    initial={{ opacity: 0, y: 5 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.6 }}
                    className="space-y-1.5"
                >
                    <Button
                        onClick={onConnect}
                        disabled={isLoading}
                        className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white shadow-lg shadow-purple-500/50 hover:shadow-xl hover:shadow-purple-500/50 transition-all duration-300 relative overflow-hidden group"
                        size="default"
                    >
                        {/* Button Shine Effect */}
                        <motion.div
                            className="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent"
                            animate={{
                                x: ['-100%', '100%'],
                            }}
                            transition={{
                                duration: 2,
                                repeat: Infinity,
                                repeatDelay: 1,
                                ease: "linear",
                            }}
                        />
                        
                        <span className="relative flex items-center justify-center text-sm">
                            {isLoading ? (
                                <>
                                    <RefreshCw className="h-3.5 w-3.5 mr-2 animate-spin" />
                                    Connecting...
                                </>
                            ) : (
                                <>
                                    <Wallet className="h-3.5 w-3.5 mr-2 group-hover:rotate-12 transition-transform" />
                                    Connect Wallet
                                </>
                            )}
                        </span>
                    </Button>
                    
                    <p className="text-[10px] text-muted-foreground">
                        {isOrganizationAccount
                            ? 'Your organization information will be used to create your Believe Cash account'
                            : 'Your personal information will be used to create your Believe Cash account'}
                    </p>
                </motion.div>
            </motion.div>
        </div>
    )
}


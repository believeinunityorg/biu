"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Camera, Loader2, Mic, Video } from "lucide-react"
import UnityMeetVideoLogoOverlay from "@/components/meeting/UnityMeetVideoLogoOverlay"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

/**
 * Feature Policy for the VDO.Ninja iframe.
 * Do not attach origins to display-capture/camera — invalid syntax breaks Firefox and Edge.
 */
export const VDO_IFRAME_ALLOW =
  "camera *; microphone *; display-capture *; fullscreen *; autoplay *; clipboard-write *; encrypted-media; gyroscope; picture-in-picture"

const VDO_READY_ACTIONS = new Set([
  "director-connected",
  "guest-connected",
  "scene-connected",
  "joining-room",
])

type MediaAccessState = "checking" | "prompt" | "granted" | "denied" | "unsupported"

function normalizeMeetingSrc(url: string): string {
  try {
    const parsed = new URL(url)
    if (parsed.protocol === "http:") {
      parsed.protocol = "https:"
    }
    if (!parsed.searchParams.has("iframe")) {
      parsed.searchParams.set("iframe", "")
    }
    return parsed.toString()
  } catch {
    return url
  }
}

type PermissionProbe = "granted" | "prompt" | "denied" | "unknown"

async function querySinglePermission(name: "camera" | "microphone"): Promise<PermissionProbe> {
  if (typeof navigator === "undefined" || !navigator.permissions?.query) {
    return "unknown"
  }

  try {
    const result = await navigator.permissions.query({ name: name as PermissionName })
    if (result.state === "granted" || result.state === "denied" || result.state === "prompt") {
      return result.state
    }
    return "unknown"
  } catch {
    return "unknown"
  }
}

/**
 * Unity Meet: camera and mic are independent — either is enough to enter.
 * Audio-only calls still require the microphone.
 */
async function queryMediaPermissionState(audioOnly = false): Promise<PermissionProbe> {
  const microphone = await querySinglePermission("microphone")

  if (audioOnly) {
    if (microphone === "denied") {
      return "denied"
    }
    if (microphone === "granted") {
      return "granted"
    }
    return microphone === "unknown" ? "unknown" : "prompt"
  }

  const camera = await querySinglePermission("camera")

  // Block the meeting only when both devices are hard-denied.
  if (camera === "denied" && microphone === "denied") {
    return "denied"
  }

  // Join with whichever device is already allowed; VDO can prompt for the other.
  if (camera === "granted" || microphone === "granted") {
    return "granted"
  }

  if (camera === "unknown" && microphone === "unknown") {
    return "unknown"
  }

  return "prompt"
}

async function tryGetUserMedia(constraints: MediaStreamConstraints): Promise<boolean> {
  if (typeof navigator === "undefined" || !navigator.mediaDevices?.getUserMedia) {
    return false
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia(constraints)
    stream.getTracks().forEach((track) => track.stop())
    return true
  } catch {
    return false
  }
}

/**
 * Request AV independently so a missing/denied camera does not block mic (and vice versa).
 */
async function requestMeetingMedia(audioOnly = false): Promise<boolean> {
  if (audioOnly) {
    return tryGetUserMedia({ audio: true })
  }

  // Prefer a single browser prompt when both can be granted together.
  if (await tryGetUserMedia({ video: true, audio: true })) {
    return true
  }

  // Fall back independently — missing/denied camera must not block mic (and vice versa).
  if (await tryGetUserMedia({ audio: true })) {
    return true
  }

  return tryGetUserMedia({ video: true })
}

function closeVdoSession(frameWindow: Window | null | undefined): void {
  if (!frameWindow) {
    return
  }

  try {
    frameWindow.postMessage({ close: "estop" }, "*")
  } catch {
    // iframe may already be gone
  }
}

function isVdoReadyMessage(data: unknown): boolean {
  if (!data || typeof data !== "object") {
    return false
  }

  const payload = data as { action?: string; value?: unknown }
  if (!payload.action) {
    return false
  }

  if (VDO_READY_ACTIONS.has(payload.action)) {
    return true
  }

  if (payload.action === "push-connection" && payload.value === true) {
    return true
  }

  if (payload.action === "view-connection" && payload.value === true) {
    return true
  }

  return false
}

type VdoMeetingIframeProps = {
  src: string
  title?: string
  className?: string
  allow?: string
  showLogoOverlay?: boolean
  /** When false, tear down the iframe immediately (avoids VDO reconnect / Refresh UI). */
  active?: boolean
  /** Audio-only chat calls — microphone permission only, no camera. */
  audioOnly?: boolean
}

export default function VdoMeetingIframe({
  src,
  title = "Meeting",
  className = "absolute inset-0 z-[1] h-full w-full border-0 bg-black",
  allow = VDO_IFRAME_ALLOW,
  showLogoOverlay = true,
  active = true,
  audioOnly = false,
}: VdoMeetingIframeProps) {
  const meetingSrc = useMemo(() => normalizeMeetingSrc(src), [src])
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const [mediaAccess, setMediaAccess] = useState<MediaAccessState>("checking")
  const [isRequestingAccess, setIsRequestingAccess] = useState(false)
  const [iframeSrc, setIframeSrc] = useState<string | null>(null)
  const [vdoSessionReady, setVdoSessionReady] = useState(false)
  const [insecureContext, setInsecureContext] = useState(false)

  useEffect(() => {
    setInsecureContext(typeof window !== "undefined" && !window.isSecureContext)
  }, [])

  useEffect(() => {
    let cancelled = false

    const run = async () => {
      if (typeof window === "undefined") {
        return
      }

      if (!window.isSecureContext) {
        if (!cancelled) {
          setMediaAccess("unsupported")
        }
        return
      }

      if (!navigator.mediaDevices?.getUserMedia) {
        if (!cancelled) {
          setMediaAccess("unsupported")
        }
        return
      }

      if (!cancelled) {
        setMediaAccess("checking")
      }

      const permissionState = await queryMediaPermissionState(audioOnly)
      if (cancelled) {
        return
      }

      if (permissionState === "granted") {
        setMediaAccess("granted")
        return
      }

      if (permissionState === "denied") {
        setMediaAccess("denied")
        return
      }

      setMediaAccess("prompt")
    }

    void run()

    return () => {
      cancelled = true
    }
  }, [meetingSrc, audioOnly])

  useEffect(() => {
    if (active) {
      return
    }

    closeVdoSession(iframeRef.current?.contentWindow ?? null)
    setIframeSrc(null)
    setVdoSessionReady(false)
  }, [active])

  useEffect(() => {
    setVdoSessionReady(false)
    setIframeSrc(null)

    if (mediaAccess !== "granted" || !active) {
      return
    }

    const deferId = window.setTimeout(() => setIframeSrc(meetingSrc), 0)
    return () => window.clearTimeout(deferId)
  }, [mediaAccess, meetingSrc, active])

  useEffect(() => {
    if (!iframeSrc || !active) {
      return
    }

    const onMessage = (event: MessageEvent) => {
      if (!event.origin.includes("vdo.ninja")) {
        return
      }

      const frameWindow = iframeRef.current?.contentWindow
      if (frameWindow && event.source !== frameWindow) {
        return
      }

      if (isVdoReadyMessage(event.data)) {
        setVdoSessionReady(true)
      }
    }

    window.addEventListener("message", onMessage)
    return () => window.removeEventListener("message", onMessage)
  }, [iframeSrc, active])

  const handleAllowAccess = useCallback(async () => {
    setIsRequestingAccess(true)
    const allowed = await requestMeetingMedia(audioOnly)
    setIsRequestingAccess(false)

    if (allowed) {
      setMediaAccess("granted")
      return
    }

    const permissionState = await queryMediaPermissionState(audioOnly)
    setMediaAccess(permissionState === "denied" ? "denied" : "prompt")
  }, [audioOnly])

  const handleRetryPermission = useCallback(() => {
    setMediaAccess("prompt")
    void handleAllowAccess()
  }, [handleAllowAccess])

  const showMeeting = active && mediaAccess === "granted" && vdoSessionReady

  if (!active) {
    return <div className="absolute inset-0 min-h-0 min-w-0 bg-black" aria-hidden />
  }

  return (
    <div className="absolute inset-0 min-h-0 min-w-0">
      {insecureContext && (
        <div className="pointer-events-none absolute inset-x-0 top-0 z-30 p-3">
          <Alert className="pointer-events-auto border-amber-500/50 bg-amber-950/90 text-amber-50">
            <AlertDescription className="text-sm">
              Camera and microphone require a secure connection (HTTPS). Reload this page using{" "}
              <span className="font-medium">https://</span> in the address bar.
            </AlertDescription>
          </Alert>
        </div>
      )}

      {!showMeeting && (
        <div
          className="absolute inset-0 z-20 flex flex-col items-center justify-center gap-4 bg-black px-6 text-center"
          aria-live="polite"
          aria-busy={mediaAccess === "checking" || isRequestingAccess || (mediaAccess === "granted" && !vdoSessionReady)}
        >
          {mediaAccess === "checking" || isRequestingAccess ? (
            <>
              <Loader2 className="h-10 w-10 animate-spin text-purple-400" aria-hidden />
              <p className="text-sm font-medium text-white/90">
                {isRequestingAccess
                  ? audioOnly
                    ? "Waiting for microphone access…"
                    : "Waiting for camera or microphone access…"
                  : "Checking access…"}
              </p>
            </>
          ) : mediaAccess === "prompt" ? (
            <>
              <div className="flex items-center gap-3 text-purple-300">
                {!audioOnly ? <Camera className="h-8 w-8" aria-hidden /> : null}
                <Mic className="h-8 w-8" aria-hidden />
              </div>
              <div className="space-y-2 max-w-sm">
                <p className="text-base font-semibold text-white">
                  {audioOnly ? "Microphone required" : "Camera or microphone"}
                </p>
                <p className="text-sm text-white/70">
                  {audioOnly
                    ? "Allow microphone access to join the audio call."
                    : "Allow access to at least one device to join. You can use audio without a camera, or video without a mic."}
                </p>
              </div>
              <Button
                type="button"
                onClick={() => void handleAllowAccess()}
                className="bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
              >
                {!audioOnly ? <Video className="mr-2 h-4 w-4" /> : <Mic className="mr-2 h-4 w-4" />}
                {audioOnly ? "Allow microphone" : "Allow camera or microphone"}
              </Button>
            </>
          ) : mediaAccess === "denied" ? (
            <>
              {!audioOnly ? <Camera className="h-10 w-10 text-amber-400" aria-hidden /> : <Mic className="h-10 w-10 text-amber-400" aria-hidden />}
              <div className="space-y-2 max-w-sm">
                <p className="text-base font-semibold text-white">Access blocked</p>
                <p className="text-sm text-white/70">
                  {audioOnly
                    ? "Enable microphone for this site in your browser settings, then try again."
                    : "Enable at least a camera or microphone for this site in your browser settings, then try again."}
                </p>
              </div>
              <Button type="button" variant="outline" className="border-white/20 text-white hover:bg-white/10" onClick={handleRetryPermission}>
                Try again
              </Button>
            </>
          ) : mediaAccess === "unsupported" ? (
            <>
              <p className="text-sm font-medium text-white/90 max-w-sm">
                Camera and microphone are not available in this browser or connection.
              </p>
            </>
          ) : (
            <>
              <Loader2 className="h-10 w-10 animate-spin text-purple-400" aria-hidden />
              <p className="text-sm font-medium text-white/90">Connecting to meeting…</p>
              <p className="text-xs text-white/60 max-w-xs">
                If your browser asks for camera or microphone access, choose Allow for the devices you want to use.
              </p>
            </>
          )}
        </div>
      )}

      {iframeSrc ? (
        <iframe
          ref={iframeRef}
          src={iframeSrc}
          title={title}
          className={`${className} transition-opacity duration-300 ${showMeeting ? "opacity-100" : "opacity-0 pointer-events-none"}`}
          allow={allow}
          allowFullScreen
        />
      ) : null}
      {showLogoOverlay && showMeeting ? <UnityMeetVideoLogoOverlay /> : null}
    </div>
  )
}

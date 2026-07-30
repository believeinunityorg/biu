/**
 * Placeholder image for VDO.Ninja `&avatar=` (tile when camera is off).
 * Must be an absolute URL on our domain — VDO loads it cross-origin from vdo.ninja.
 * Do not use ui-avatars.com (duplicate CORS * headers break Chromium).
 */
export function vdoUiAvatarUrl(displayName: string): string {
  const name = displayName.trim() || "Guest"
  const origin = typeof window !== "undefined" ? window.location.origin : ""
  return `${origin}/meet/vdo-avatar?name=${encodeURIComponent(name)}&size=256`
}

/** VDO preset: name overlay on each video tile (see Ninja docs → &showlabels). */
export const VDO_SHOW_LABELS_STYLE = "zoom"

/**
 * One horizontal row for the auto grid — e.g. 2 participants = two tiles in one row.
 * See Ninja docs → &rows
 */
export const VDO_GROUP_ROWS_SINGLE = "1"

/** Guest label font size as % (Ninja docs → &fontsize). */
export const VDO_LABEL_FONT_PCT = "82"

/**
 * Meet-style grid labels + single row + showall.
 * `nocontrols` hides VDO’s **video** control bar (play / progress on tiles), not the main **user** bar (mic, hang up, etc.).
 * `clock=false` disables the optional wall-clock overlay (VDO &clock).
 */
export function applyVdoGroupRoomPresentation(url: URL): void {
  url.searchParams.set("showlabels", VDO_SHOW_LABELS_STYLE)
  url.searchParams.set("rows", VDO_GROUP_ROWS_SINGLE)
  url.searchParams.set("fontsize", VDO_LABEL_FONT_PCT)
  url.searchParams.set("nocontrols", "")
  url.searchParams.set("clock", "false")
  if (!url.searchParams.has("showall")) {
    url.searchParams.set("showall", "")
  }
}

/**
 * Strip VDO.Ninja chrome so BIU owns participant list and meeting actions.
 * Video transport only — guest list, menu, and settings stay in our UI.
 */
export function applyVdoMinimalHostUi(url: URL): void {
  applyVdoGroupRoomPresentation(url)
  url.searchParams.set("showlist", "0")
  url.searchParams.set("hidemenu", "")
  url.searchParams.set("hideheader", "")
  url.searchParams.set("nosettings", "")
  url.searchParams.set("nohangupbutton", "")
  if (!url.searchParams.has("noheader")) {
    url.searchParams.set("noheader", "")
  }
  applyVdoHostScreenShareForYoutube(url)
}

/**
 * Keep screen share on the same MediaMTX/WHIP path the AWS worker pulls for YouTube.
 *
 * VDO defaults to screensharetype=3 (second video track) or type=2 (new stream id / _ss path).
 * The Fargate worker only remuxes the host `push` path → YouTube goes blank when the camera
 * is stopped and screen is shared. Type 1 replaces the webcam track in-place on the same push.
 */
export function applyVdoHostScreenShareForYoutube(url: URL): void {
  if (!url.searchParams.has("push")) {
    return
  }
  // Replace webcam with screen on the same connection / same WHIP publish id.
  url.searchParams.set("screensharetype", "1")
  url.searchParams.delete("screenshareid")
  url.searchParams.set("smallshare", "")
  url.searchParams.set("screensharecontenthint", "detail")
  if (!url.searchParams.has("ssb")) {
    url.searchParams.set("ssb", "")
  }
}

/** Bust VDO sticky session when starting a new meeting round. */
export function applyVdoMeetingSession(url: URL, sessionKey: number): void {
  if (sessionKey > 0) {
    url.searchParams.set("sess", String(sessionKey))
    url.searchParams.set("clearstorage", "")
  }
}

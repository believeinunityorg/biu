<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unity Meet — Stream Canvas (do not close while live)</title>
<style>
  html,body{margin:0;background:#000;color:#9aa;font:13px/1.4 system-ui,sans-serif;overflow:hidden}
  #c{display:block;width:100vw;height:100vh;object-fit:contain;background:#000}
  #hud{position:fixed;left:8px;top:8px;z-index:2;background:rgba(0,0,0,.55);
       padding:6px 10px;border-radius:6px;pointer-events:none;max-width:80vw}
  .ok{color:#4ade80}.warn{color:#fbbf24}.err{color:#f87171}
</style>
</head>
<body>
<canvas id="c" width="1280" height="720"></canvas>
<div id="hud">canvas mixer starting…</div>
<script>
/*
 * Unity Meet participant canvas mixer — MVP (approved scope, 2026-05-17).
 *
 * Fixed up to 6 seats. Each meeting seat publishes its camera to MediaMTX at
 * <streamPath>_s<n> (Laravel sets &mediamtx=&push= on the participant URLs).
 * This page subscribes to all 6 seats via MediaMTX-native WHEP (same-origin
 * to the bridge — no cross-origin VDO.Ninja iframe involved), draws only live
 * seats into one canvas (full-bleed when alone; no empty "seat N" placeholders),
 * mixes their audio, and WHIP-publishes the single combined stream back to
 * MediaMTX at <streamPath> — the path the bridge -> FFmpeg worker -> YouTube
 * pipeline already pulls.
 */
const CFG = @json($cfg);
// CFG = { whepBase, whipUrl, seatPaths:[6], width, height, fps }

const cv  = document.getElementById('c');
const ctx = cv.getContext('2d', { alpha:false });
cv.width = CFG.width; cv.height = CFG.height;
const hud = document.getElementById('hud');
function say(msg, cls){ hud.innerHTML = '<span class="'+(cls||'')+'">'+msg+'</span>'; }

// Dynamic layout among live seats only (see layoutForCount / liveCellRect).
const GAP = 6;

// One <video> per seat, fed by a WHEP subscription to MediaMTX.
const seats = CFG.seatPaths.map((path, i) => {
  const v = document.createElement('video');
  v.muted = true; v.autoplay = true; v.playsInline = true;
  return { i, path, video:v, live:false, pc:null, audioTrack:null };
});

// ---- WHEP subscribe (MediaMTX native: POST SDP offer to /<path>/whep) ----
async function whepSubscribe(seat){
  try {
    const pc = new RTCPeerConnection({ iceServers:[{urls:'stun:stun.l.google.com:19302'}] });
    seat.pc = pc;
    pc.addTransceiver('video', { direction:'recvonly' });
    pc.addTransceiver('audio', { direction:'recvonly' });
    const ms = new MediaStream();
    pc.ontrack = (e) => {
      ms.addTrack(e.track);
      seat.video.srcObject = ms;
      seat.video.play().catch(()=>{});
      if (e.track.kind === 'video') seat.live = true;
      if (e.track.kind === 'audio') { seat.audioTrack = e.track; addSeatAudio(seat); }
    };
    pc.onconnectionstatechange = () => {
      if (['failed','disconnected','closed'].includes(pc.connectionState)) {
        seat.live = false;
        setTimeout(() => { if (seat.pc===pc) { try{pc.close()}catch(e){}; whepSubscribe(seat); } }, 4000);
      }
    };
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    const res = await fetch(CFG.whepBase + '/' + seat.path + '/whep', {
      method:'POST', headers:{'Content-Type':'application/sdp'}, body: offer.sdp
    });
    if (!res.ok) { // empty seat (nobody publishing) — retry slowly
      try{pc.close()}catch(e){}; seat.pc=null;
      setTimeout(() => whepSubscribe(seat), 5000); return;
    }
    await pc.setRemoteDescription({ type:'answer', sdp: await res.text() });
  } catch (err) {
    setTimeout(() => whepSubscribe(seat), 5000);
  }
}

// ---- Audio mix (WebAudio -> single destination track) ----
const actx = new (window.AudioContext||window.webkitAudioContext)();
const mixDest = actx.createMediaStreamDestination();
// Always-active silent source so the destination track is guaranteed to emit
// packets from the moment the WHIP offer is made — without this, no audio
// flows until a seat audio source connects, and MediaMTX never sees an audio
// track on the canvas publish. That made the bridge transcode (-c:a aac)
// have no audio input and the _aac path the worker pulls never became ready.
const silence = actx.createConstantSource(); silence.offset.value = 0;
const silenceGain = actx.createGain(); silenceGain.gain.value = 0; // inaudible
silence.connect(silenceGain).connect(mixDest);
silence.start();
function addSeatAudio(seat){
  try {
    const src = actx.createMediaStreamSource(new MediaStream([seat.audioTrack]));
    src.connect(mixDest);
  } catch(e){}
}

// ---- Draw loop: only live seats; full-bleed when alone (no empty "seat N" tiles) ----
function drawCover(v, r){
  const vw=v.videoWidth, vh=v.videoHeight; if(!vw||!vh) return false;
  const s=Math.max(r.w/vw, r.h/vh), dw=vw*s, dh=vh*s;
  ctx.save(); ctx.beginPath(); ctx.rect(r.x,r.y,r.w,r.h); ctx.clip();
  ctx.drawImage(v, r.x+(r.w-dw)/2, r.y+(r.h-dh)/2, dw, dh); ctx.restore();
  return true;
}

/** Grid geometry for N live tiles — fills the canvas; empty seats are omitted. */
function layoutForCount(n){
  if (n <= 0) return { cols: 1, rows: 1 };
  if (n === 1) return { cols: 1, rows: 1 };
  if (n === 2) return { cols: 2, rows: 1 };
  if (n <= 4) return { cols: 2, rows: 2 };
  return { cols: 3, rows: 2 };
}

function liveCellRect(index, count){
  const { cols, rows } = layoutForCount(count);
  const gap = count === 1 ? 0 : GAP;
  const cellW = (CFG.width  - gap*(cols+1)) / cols;
  const cellH = (CFG.height - gap*(rows+1)) / rows;
  const r = Math.floor(index / cols);
  const c = index % cols;
  return {
    x: gap + c * (cellW + gap),
    y: gap + r * (cellH + gap),
    w: cellW,
    h: cellH,
  };
}

let liveCount = 0;
function frame(){
  ctx.fillStyle = '#000';
  ctx.fillRect(0, 0, cv.width, cv.height);

  const live = seats.filter((s) => s.live && s.video.readyState >= 2);
  liveCount = 0;
  for (let i = 0; i < live.length; i++) {
    if (drawCover(live[i].video, liveCellRect(i, live.length))) {
      liveCount++;
    }
  }
}
// setInterval — NOT requestAnimationFrame. The mixer runs in a hidden 1x1
// off-screen iframe; Chrome throttles rAF to ~1 Hz (or 0) for hidden iframes,
// so captureStream() produced near-zero video frames during WHIP negotiation,
// the bridge runOnReady fired on the first arriving (audio) track and locked
// in audio-only output. setInterval is not subject to that throttling.
setInterval(frame, Math.max(16, Math.round(1000 / CFG.fps)));

// ---- WHIP publish the combined canvas+audio to <streamPath> ----
let publishing=false;
async function whipPublish(){
  if (publishing) return; publishing=true;
  const canvasStream = cv.captureStream(CFG.fps);
  const out = new MediaStream();
  canvasStream.getVideoTracks().forEach(t=>out.addTrack(t));
  mixDest.stream.getAudioTracks().forEach(t=>out.addTrack(t));

  const pc = new RTCPeerConnection({ iceServers:[{urls:'stun:stun.l.google.com:19302'}] });
  out.getTracks().forEach(t => pc.addTrack(t, out));
  pc.onconnectionstatechange = () => {
    if (['failed','disconnected','closed'].includes(pc.connectionState)) {
      publishing=false; say('publish dropped — reconnecting','err');
      setTimeout(whipPublish, 3000);
    }
  };
  try {
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    const res = await fetch(CFG.whipUrl, {
      method:'POST', headers:{'Content-Type':'application/sdp'}, body: offer.sdp
    });
    if (!res.ok) { publishing=false; say('WHIP publish failed ('+res.status+') — retrying','err');
                   setTimeout(whipPublish, 4000); return; }
    await pc.setRemoteDescription({ type:'answer', sdp: await res.text() });
    say('LIVE — publishing combined canvas. Keep this tab open.','ok');
  } catch(err){ publishing=false; say('publish error: '+err.message,'err');
                setTimeout(whipPublish, 4000); }
}

// ---- boot ----
say('subscribing to '+seats.length+' seats…','warn');
seats.forEach(whepSubscribe);
// Kick the canvas immediately with one frame so captureStream has data the
// instant WHIP negotiates (before setInterval's first tick). setInterval
// (above) drives the steady draw loop — rAF is throttled in hidden iframes.
frame();
// give seats a moment to connect, then start publishing the canvas
setTimeout(() => { actx.resume().catch(()=>{}); whipPublish();
  setInterval(()=>{ if(publishing) say('LIVE — '+liveCount+' participant(s) on canvas. Keep tab open.','ok'); }, 5000);
}, 4000);
</script>
</body>
</html>

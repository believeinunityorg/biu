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
 * Fixed 3x2 grid. Each meeting seat publishes its camera to MediaMTX at
 * <streamPath>_s<n> (Laravel sets &mediamtx=&push= on the participant URLs).
 * This page subscribes to all 6 seats via MediaMTX-native WHEP (same-origin
 * to the bridge — no cross-origin VDO.Ninja iframe involved), draws them into
 * one canvas, mixes their audio, and WHIP-publishes the single combined
 * stream back to MediaMTX at <streamPath> — the exact path the existing
 * bridge -> FFmpeg worker -> YouTube pipeline already pulls. No downstream
 * changes. No active-speaker / overlays / branding (MVP scope limits).
 */
const CFG = @json($cfg);
// CFG = { whepBase, whipUrl, seatPaths:[6], width, height, fps }

const cv  = document.getElementById('c');
const ctx = cv.getContext('2d', { alpha:false });
cv.width = CFG.width; cv.height = CFG.height;
const hud = document.getElementById('hud');
function say(msg, cls){ hud.innerHTML = '<span class="'+(cls||'')+'">'+msg+'</span>'; }

// 3 columns x 2 rows
const COLS = 3, ROWS = 2, GAP = 6;
const cellW = (CFG.width  - GAP*(COLS+1)) / COLS;
const cellH = (CFG.height - GAP*(ROWS+1)) / ROWS;
function cellRect(i){ const r=Math.floor(i/COLS), c=i%COLS;
  return { x: GAP + c*(cellW+GAP), y: GAP + r*(cellH+GAP), w: cellW, h: cellH }; }

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

// ---- Draw loop: 3x2 grid, cover-fit each seat, placeholder if empty ----
function drawCover(v, r){
  const vw=v.videoWidth, vh=v.videoHeight; if(!vw||!vh) return false;
  const s=Math.max(r.w/vw, r.h/vh), dw=vw*s, dh=vh*s;
  ctx.save(); ctx.beginPath(); ctx.rect(r.x,r.y,r.w,r.h); ctx.clip();
  ctx.drawImage(v, r.x+(r.w-dw)/2, r.y+(r.h-dh)/2, dw, dh); ctx.restore();
  return true;
}
let liveCount = 0;
function frame(){
  ctx.fillStyle='#000'; ctx.fillRect(0,0,cv.width,cv.height);
  let n=0;
  for (const seat of seats){
    const r = cellRect(seat.i);
    const drawn = (seat.live && seat.video.readyState>=2) ? drawCover(seat.video, r) : false;
    if (drawn) n++;
    else { ctx.fillStyle='#0c0c0c'; ctx.fillRect(r.x,r.y,r.w,r.h);
           ctx.fillStyle='#333'; ctx.font='14px system-ui'; ctx.textAlign='center';
           ctx.fillText('seat '+(seat.i+1), r.x+r.w/2, r.y+r.h/2); }
  }
  liveCount=n;
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

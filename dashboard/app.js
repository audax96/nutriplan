"use strict";
const $ = (id) => document.getElementById(id);
const fmt = (n) => (n == null ? "–" : Number(n).toLocaleString());
const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();

function card(label, value, sub, cls, bar) {
  const s = cls ? `<div class="status ${cls}"><span class="dot"></span>${value}</div>` : `<div class="value">${value}</div>`;
  const b = bar != null ? `<div class="bar ${bar.cls || ""}"><i style="width:${Math.min(100, Math.max(0, bar.pct))}%"></i></div>` : "";
  return `<div class="card"><div class="label">${label}</div>${s}${sub ? `<div class="sub">${sub}</div>` : ""}${b}</div>`;
}
function dur(s) {
  if (s == null) return "–";
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
  return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
}
const level = (pct, warn, bad) => (pct >= bad ? "bad" : pct >= warn ? "warn" : "");

function chart(canvas, series, opts = {}) {
  const dpr = window.devicePixelRatio || 1;
  const w = canvas.clientWidth, h = canvas.clientHeight || 110;
  canvas.width = w * dpr; canvas.height = h * dpr;
  const ctx = canvas.getContext("2d");
  ctx.scale(dpr, dpr); ctx.clearRect(0, 0, w, h);
  const all = series.flatMap((s) => s.data.filter((v) => v != null));
  if (all.length < 2) { ctx.fillStyle = css("--muted"); ctx.fillText("Collecting data…", 8, 20); return; }
  const max = Math.max(...all, opts.minMax || 1) * 1.1;
  ctx.strokeStyle = css("--line"); ctx.lineWidth = 1;
  for (let i = 0; i <= 2; i++) { const y = 6 + ((h - 18) * i) / 2; ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke(); }
  ctx.fillStyle = css("--muted"); ctx.font = "11px sans-serif";
  ctx.fillText(Math.round(max), 2, 16); ctx.fillText("0", 2, h - 8);
  series.forEach((s) => {
    ctx.strokeStyle = css(s.color); ctx.lineWidth = 2; ctx.beginPath();
    let started = false;
    s.data.forEach((v, i) => {
      if (v == null) { started = false; return; }
      const x = (i / (s.data.length - 1)) * (w - 4) + 2, y = h - 12 - (v / max) * (h - 24);
      started ? ctx.lineTo(x, y) : ctx.moveTo(x, y); started = true;
    });
    ctx.stroke();
  });
  let lx = 40;
  series.forEach((s) => { ctx.fillStyle = css(s.color); ctx.fillRect(lx, h - 9, 10, 3); ctx.fillStyle = css("--muted"); ctx.fillText(s.name, lx + 14, h - 4); lx += 20 + ctx.measureText(s.name).width + 14; });
}

function render(d) {
  if (d.collecting) { $("updated").textContent = "First collection running…"; return; }
  const age = Math.round(Date.now() / 1000 - d.ts);
  $("updated").textContent = `Updated ${age < 5 ? "just now" : age + " s ago"}, refreshes every 10 s` + (d.collector_error ? ` · collector error: ${d.collector_error}` : "");

  const c = d.checks, m = d.mac, cl = d.claude;
  const svc = (label, r, extra) => card(label, r.ok ? "Up" : "Down", r.ok ? `${extra || ""}${r.ms != null ? r.ms + " ms" : ""}` : (r.error || (r.status ? "HTTP " + r.status : "No response")), r.ok ? "ok" : "bad");
  $("checks").innerHTML =
    svc("API on the Mac (localhost)", c.local_api) +
    svc("API through the tunnel", c.public_api) +
    svc("Web app (Cloudflare)", c.web_app) +
    card("cloudflared process", c.tunnel.process_running ? "Running" : "Not found", c.tunnel.process_running ? "" : "Run: sudo launchctl kickstart -k system/com.cloudflare.cloudflared", c.tunnel.process_running ? "ok" : "bad");

  const ci = d.containers;
  $("containers").innerHTML = !ci.docker_ok
    ? card("Docker", "Not reachable", "Open Docker Desktop and wait for Engine running", "bad")
    : ci.items.map((x) => {
        const up = x.state === "running" && (!x.health || x.health === "healthy");
        const pct = x.mem_limit_mib ? (100 * x.mem_mib) / x.mem_limit_mib : 0;
        return card(x.service, x.state + (x.health ? ` (${x.health})` : ""), `CPU ${x.cpu}% · ${x.mem_mib} / ${x.mem_limit_mib} MiB<br>${x.status || ""}`, up ? "ok" : "bad", { pct, cls: level(pct, 70, 90) });
      }).join("") || card("Containers", "None found", "Run docker compose up -d", "bad");

  const loadPct = (100 * m.load1) / m.cores, diskUsed = 100 * (1 - m.disk_free_gb / m.disk_total_gb);
  $("mac").innerHTML =
    card("Load (1 / 5 / 15 min)", `${m.load1}`, `${m.load5} / ${m.load15} on ${m.cores} cores`, null, { pct: loadPct, cls: level(loadPct, 70, 100) }) +
    card("Memory in use", m.mem_used_pct != null ? m.mem_used_pct + "%" : "–", m.mem_total_gb ? `of ${m.mem_total_gb} GB` : "", null, m.mem_used_pct != null ? { pct: m.mem_used_pct, cls: level(m.mem_used_pct, 80, 92) } : null) +
    card("Disk free", `${m.disk_free_gb} GB`, `of ${m.disk_total_gb} GB`, null, { pct: diskUsed, cls: level(diskUsed, 80, 92) }) +
    card("Uptime", dur(m.uptime_s), "Since last restart");

  $("claude").innerHTML =
    card("Claude calls", fmt(cl.calls_24h), "plan and scan calls") +
    card("Tokens in / out", `${fmt(cl.tokens_in_24h)} / ${fmt(cl.tokens_out_24h)}`, "") +
    card("Cut off (max_tokens)", fmt(cl.truncated_24h), "should be 0", null, null) +
    card("Errors in API logs", fmt(cl.errors_24h), "", null);

  const hs = d.history;
  chart($("c_ms"), [{ name: "API via tunnel", data: hs.map((p) => p.api_ms), color: "--accent" }], { minMax: 200 });
  chart($("c_load"), [{ name: "load", data: hs.map((p) => p.load), color: "--accent" }, { name: "container CPU %", data: hs.map((p) => p.cpu), color: "--accent2" }], { minMax: 2 });

  const bad = [c.local_api, c.public_api, c.web_app].filter((r) => !r.ok).length + (c.tunnel.process_running ? 0 : 1) + (ci.docker_ok ? 0 : 1);
  const o = $("overall");
  o.className = "pill " + (bad ? "bad" : "ok");
  o.textContent = bad ? `${bad} problem${bad > 1 ? "s" : ""}` : "All systems up";
  document.title = (bad ? "⚠ " : "✓ ") + "NutriPlan status";
}

async function tick() {
  try {
    const r = await fetch("status.json", { cache: "no-store" });
    render(await r.json());
  } catch (e) {
    $("updated").textContent = "Dashboard server not reachable. Is collector.py running?";
    $("overall").className = "pill bad"; $("overall").textContent = "Collector offline";
  }
}
tick(); setInterval(tick, 10000);
window.addEventListener("resize", tick);

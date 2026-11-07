#!/usr/bin/env python3
"""NutriPlan status dashboard: collector + tiny web server (standard library only).

Run from anywhere:  python3 ~/Projects/nutriplan/dashboard/collector.py
Opens on http://127.0.0.1:8080  (bound to localhost only, never exposed to the tunnel)
"""
import json, os, re, shutil, subprocess, threading, time, urllib.request, urllib.error
from collections import deque
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT = os.path.dirname(HERE)  # ~/Projects/nutriplan
LOCAL_URL = os.environ.get("LOCAL_URL", "http://localhost:8000/api/health")
API_URL = os.environ.get("PUBLIC_API_URL", "https://api.nutriplan.stream/api/health")
APP_URL = os.environ.get("PUBLIC_APP_URL", "https://app.nutriplan.stream")
PORT = int(os.environ.get("DASH_PORT", "8080"))
INTERVAL = 10          # seconds between collections
HISTORY_POINTS = 360   # 1 hour at 10 s

history = deque(maxlen=HISTORY_POINTS)
state = {"data": {"collecting": True}}
lock = threading.Lock()
slow_cache = {"t": 0, "val": None}


def run(cmd, timeout=20):
    try:
        r = subprocess.run(cmd, cwd=PROJECT, capture_output=True, text=True, timeout=timeout)
        return r.returncode, r.stdout
    except Exception as e:  # noqa
        return 1, str(e)


def probe(url, expect=None, timeout=8):
    t0 = time.time()
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "nutriplan-dashboard"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            body = r.read(200000).decode("utf-8", "replace")
            ok = r.status == 200 and (expect is None or expect in body)
            return {"ok": ok, "status": r.status, "ms": round((time.time() - t0) * 1000)}
    except urllib.error.HTTPError as e:
        return {"ok": False, "status": e.code, "ms": round((time.time() - t0) * 1000)}
    except Exception as e:  # noqa
        return {"ok": False, "status": 0, "ms": None, "error": str(e)[:80]}


def to_mib(s):
    m = re.match(r"([\d.]+)\s*([KMGT]?i?B)", s.strip())
    if not m:
        return 0.0
    v, u = float(m.group(1)), m.group(2).upper()
    return v * {"B": 1 / 1048576, "KIB": 1 / 1024, "MIB": 1, "GIB": 1024, "TIB": 1048576}.get(u, 1)


def containers():
    rc, out = run(["docker", "compose", "ps", "-a", "--format", "json"])
    items = []
    if rc == 0:
        for line in out.strip().splitlines():
            try:
                items.append(json.loads(line))
            except Exception:
                pass
    stats = {}
    rc2, out2 = run(["docker", "stats", "--no-stream", "--format", "{{json .}}"], timeout=25)
    if rc2 == 0:
        for line in out2.strip().splitlines():
            try:
                j = json.loads(line)
                stats[j.get("Name")] = j
            except Exception:
                pass
    res = []
    for c in items:
        name = c.get("Name") or c.get("Service")
        st = stats.get(name, {})
        mem_used, _, mem_lim = (st.get("MemUsage") or "").partition("/")
        res.append({
            "service": c.get("Service"), "name": name,
            "state": c.get("State"), "health": c.get("Health") or "",
            "status": c.get("Status"),
            "cpu": float((st.get("CPUPerc") or "0%").strip("%") or 0),
            "mem_mib": round(to_mib(mem_used), 1), "mem_limit_mib": round(to_mib(mem_lim), 1),
        })
    return {"docker_ok": rc == 0, "items": res}


def claude_usage():
    """Parse 'Claude call: stop=... in=... out=...' lines from the last 24 h of API logs."""
    now = time.time()
    if slow_cache["val"] is not None and now - slow_cache["t"] < 60:
        return slow_cache["val"]
    rc, out = run(["docker", "compose", "logs", "api", "--since", "24h", "--no-log-prefix"], timeout=40)
    calls = tin = tout = trunc = errors = 0
    if rc == 0:
        for line in out.splitlines():
            m = re.search(r"Claude call: stop=(\S+) in=(\d+) out=(\d+)", line)
            if m:
                calls += 1
                tin += int(m.group(2))
                tout += int(m.group(3))
                if m.group(1) == "max_tokens":
                    trunc += 1
            elif re.search(r"llm_|Fatal|Parse error|Uncaught|SQLSTATE", line):
                errors += 1
    val = {"calls_24h": calls, "tokens_in_24h": tin, "tokens_out_24h": tout,
           "truncated_24h": trunc, "errors_24h": errors}
    slow_cache.update(t=now, val=val)
    return val


def mac():
    load1, load5, load15 = os.getloadavg()
    cores = os.cpu_count() or 1
    du = shutil.disk_usage("/")
    mem_total = mem_avail = None
    try:
        total = int(subprocess.check_output(["sysctl", "-n", "hw.memsize"], text=True).strip())
        vm = subprocess.check_output(["vm_stat"], text=True)
        page = int(re.search(r"page size of (\d+)", vm).group(1))
        pages = {k: int(v) for k, v in re.findall(r"Pages (free|inactive|speculative):\s+(\d+)", vm)}
        avail = sum(pages.values()) * page
        mem_total, mem_avail = total, avail
    except Exception:
        pass
    up = None
    try:
        out = subprocess.check_output(["sysctl", "-n", "kern.boottime"], text=True)
        up = int(time.time() - int(re.search(r"sec = (\d+)", out).group(1)))
    except Exception:
        pass
    return {"load1": round(load1, 2), "load5": round(load5, 2), "load15": round(load15, 2), "cores": cores,
            "disk_total_gb": round(du.total / 1e9, 1), "disk_free_gb": round(du.free / 1e9, 1),
            "mem_total_gb": round(mem_total / 1e9, 1) if mem_total else None,
            "mem_used_pct": round(100 * (1 - mem_avail / mem_total), 1) if mem_total else None,
            "uptime_s": up}


def tunnel():
    rc, out = run(["pgrep", "-fl", "cloudflared"])
    return {"process_running": rc == 0 and "cloudflared" in out}


def collect():
    started = time.time()
    local = probe(LOCAL_URL, '"ok"')
    api = probe(API_URL, '"ok"')
    app = probe(APP_URL, "<base href=\"")
    if app["ok"]:  # the raw-template trap we hit earlier
        try:
            html = urllib.request.urlopen(APP_URL, timeout=8).read(50000).decode("utf-8", "replace")
            if "$FLUTTER_BASE_HREF" in html:
                app["ok"] = False
                app["error"] = "raw template deployed (wrong folder)"
        except Exception:
            pass
    data = {
        "ts": int(started),
        "checks": {"local_api": local, "public_api": api, "web_app": app, "tunnel": tunnel()},
        "containers": containers(),
        "mac": mac(),
        "claude": claude_usage(),
    }
    cpu_total = sum(c["cpu"] for c in data["containers"]["items"])
    history.append({"t": data["ts"], "load": data["mac"]["load1"], "cpu": round(cpu_total, 1),
                    "api_ms": api["ms"], "api_ok": 1 if api["ok"] else 0, "local_ok": 1 if local["ok"] else 0})
    data["history"] = list(history)
    with lock:
        state["data"] = data


def loop():
    while True:
        try:
            collect()
        except Exception as e:  # noqa
            with lock:
                state["data"] = {**state["data"], "collector_error": str(e)[:200]}
        time.sleep(INTERVAL)


MIME = {".html": "text/html", ".css": "text/css", ".js": "application/javascript"}


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        path = self.path.split("?")[0]
        if path == "/status.json":
            with lock:
                body = json.dumps(state["data"]).encode()
            ctype = "application/json"
        else:
            name = "index.html" if path == "/" else path.lstrip("/")
            full = os.path.realpath(os.path.join(HERE, name))
            if not full.startswith(HERE + os.sep) or not os.path.isfile(full) or full.endswith(".py"):
                self.send_error(404)
                return
            with open(full, "rb") as f:
                body = f.read()
            ctype = MIME.get(os.path.splitext(full)[1], "application/octet-stream")
        self.send_response(200)
        self.send_header("Content-Type", ctype + "; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    threading.Thread(target=loop, daemon=True).start()
    print(f"NutriPlan dashboard on http://127.0.0.1:{PORT}  (Ctrl+C to stop)")
    ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()

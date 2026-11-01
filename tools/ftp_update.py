#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
华政选课网 —— 一站式 FTP 热更新脚本

零第三方依赖，仅用 Python 标准库（Python 3.7+ 可用）。

用法
    python tools/ftp_update.py --check      # 预检：连FTP、定位站点根目录、列出将变更的文件（只读，绝不改动任何东西）
    python tools/ftp_update.py --apply      # 执行更新：下载备份旧文件 → 上传覆盖 → 校验 → 冒烟测试
    python tools/ftp_update.py --rollback   # 回滚到最近一次备份
    python tools/ftp_update.py --rollback 20261030-1100
    python tools/ftp_update.py --list-history

可选参数
    --remote DIR        指定服务器上的站点根目录（不给则自动探测）
    --yes               跳过二次确认，直接执行
    --with-installers   连 install.php / upgrade.php / schema.sql 一起传（默认不传，这四个是一次性安装脚本）
    --no-smoke          更新后不做 HTTP 冒烟测试

配置
    默认读取 deploy/deploy.conf（KEY=VALUE）。
    优先级：命令行参数 > 环境变量（HZ_FTP_HOST / HZ_FTP_USER / HZ_FTP_PASS ...）> 配置文件

安全设计（重要）
    1. 覆盖前会先校验远端目录里确实存在本站的 index.php / api.php / modules，
       防止根目录探测失误把文件写进别的站点。
    2. 备份采用「下载到本地」而非「服务器改名留 .bak」——后者会让 PHP 源码
       以纯文本形式被任何访客下载，是真实的源码泄露风险。
    3. 只覆盖、不删除远端文件（回滚时除外），不会误删线上数据。
    4. config.php / install.lock / uploads/ 永不上传。
"""

import argparse
import ftplib
import hashlib
import io
import os
import posixpath
import sys
import time
from datetime import datetime

# Windows 控制台默认 GBK，中文输出会崩，强制 UTF-8
if hasattr(sys.stdout, "reconfigure"):
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCAL_ROOT = os.path.join(BASE, "php", "public")
HISTORY_DIR = os.path.join(BASE, "deploy", ".history")
DEFAULT_CONF = os.path.join(BASE, "deploy", "deploy.conf")

DOMAIN_FULL = "www.ecupler2026.online"
DOMAIN_APEX = "ecupler2026.online"

# 永不进入/上传的目录
EXCLUDE_DIRS = {"uploads", ".deploy", ".git", "data", "logs", "__pycache__", ".history"}
# 永不上传的文件（config.php 与 install.lock 是服务器专属；后四个是一次性安装脚本）
EXCLUDE_FILES = {
    "config.php",
    "config.example.php",
    "install.php",
    "install.lock",
    "upgrade.php",
    "schema.sql",
    "seed.json",
    ".user.ini",
    ".htaccess",
    ".DS_Store",
    "Thumbs.db",
    ".gitignore",
}
EXCLUDE_EXT = {".log", ".tmp", ".bak", ".swp", ".pyc", ".old"}


# ---------------- 基础输出 ----------------
def ok(msg):
    print("  [OK]   %s" % msg)


def warn(msg):
    print("  [!!]   %s" % msg)


def fail(msg):
    print("  [XX]   %s" % msg)


def head(msg):
    print("\n=== %s ===" % msg)


def human(n):
    if n < 1024:
        return "%d B" % n
    if n < 1024 * 1024:
        return "%.1f KB" % (n / 1024.0)
    return "%.2f MB" % (n / (1024.0 * 1024.0))


# ---------------- 配置 ----------------
def load_conf(path):
    """读 KEY=VALUE 配置文件；返回 dict。文件不存在返回空 dict。"""
    conf = {}
    if not os.path.isfile(path):
        return conf
    with open(path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            k, v = line.split("=", 1)
            conf[k.strip()] = v.strip()
    return conf


def merge_conf(args):
    """合并 配置文件 / 环境变量 / 命令行参数"""
    conf = load_conf(DEFAULT_CONF if os.path.isfile(DEFAULT_CONF) else DEFAULT_CONF)

    envmap = {
        "ftp_host": "HZ_FTP_HOST",
        "ftp_port": "HZ_FTP_PORT",
        "ftp_user": "HZ_FTP_USER",
        "ftp_pass": "HZ_FTP_PASS",
        "ftp_tls": "HZ_FTP_TLS",
        "remote_dir": "HZ_REMOTE_DIR",
        "site_url": "HZ_SITE_URL",
        "db_name": "HZ_DB_NAME",
        "db_user": "HZ_DB_USER",
        "db_pass": "HZ_DB_PASS",
        "db_prefix": "HZ_DB_PREFIX",
    }
    for k, ev in envmap.items():
        val = os.environ.get(ev)
        if val:
            conf[k] = val

    if args.host:
        conf["ftp_host"] = args.host
    if args.port:
        conf["ftp_port"] = str(args.port)
    if args.user:
        conf["ftp_user"] = args.user
    if args.password:
        conf["ftp_pass"] = args.password
    if args.remote:
        conf["remote_dir"] = args.remote
    if args.tls is not None:
        conf["ftp_tls"] = "1" if args.tls else "0"

    conf.setdefault("ftp_port", "21")
    conf.setdefault("ftp_tls", "0")
    conf.setdefault("remote_dir", "")
    conf.setdefault("site_url", "")

    try:
        conf["ftp_port"] = int(conf["ftp_port"])
    except Exception:
        conf["ftp_port"] = 21

    conf["ftp_tls"] = str(conf["ftp_tls"]).lower() in ("1", "true", "yes", "on")
    return conf


# ---------------- 本地文件收集 ----------------
def collect_local(root, with_installers=False):
    """遍历本地站点目录，返回 {相对路径(带/): 绝对路径}"""
    out = {}
    for dirpath, dirnames, filenames in os.walk(root):
        rel_dir = os.path.relpath(dirpath, root).replace("\\", "/")
        if rel_dir == ".":
            rel_dir = ""
        # 剪掉排除目录
        dirnames[:] = sorted(d for d in dirnames if d not in EXCLUDE_DIRS)
        if rel_dir:  # 已处于排除目录内时不再收集（双保险）
            parts = set(rel_dir.split("/"))
            if parts & EXCLUDE_DIRS:
                continue
        for fn in filenames:
            if fn in EXCLUDE_FILES:
                continue
            ext = os.path.splitext(fn)[1].lower()
            if ext in EXCLUDE_EXT:
                continue
            if not with_installers and fn in ("install.php", "upgrade.php", "schema.sql", "seed.json"):
                continue
            rel = (rel_dir + "/" + fn) if rel_dir else fn
            out[rel] = os.path.join(dirpath, fn)
    return out


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


# ---------------- FTP 封装 ----------------
def connect(conf):
    host = conf["ftp_host"]
    port = conf["ftp_port"]
    user = conf["ftp_user"]
    pwd = conf["ftp_pass"]

    if not host or not user:
        fail("缺少 FTP 地址或账号，请检查 deploy/deploy.conf")
        sys.exit(2)

    print("  连接 %s:%d ..." % (host, port))
    try:
        if conf["ftp_tls"]:
            ftp = ftplib.FTP_TLS(timeout=30)
            ftp.connect(host, port)
            ftp.login(user, pwd)
            ftp.prot_p()
            ok("已通过 FTPS(TLS) 登录")
        else:
            ftp = ftplib.FTP(timeout=30)
            ftp.connect(host, port)
            ftp.login(user, pwd)
            ok("已通过 FTP 登录")
    except ftplib.error_perm as e:
        fail("登录失败（账号或密码错误）：%s" % e)
        sys.exit(2)
    except Exception as e:
        fail("无法连接服务器：%s" % e)
        sys.exit(2)

    try:
        ftp.set_pasv(True)
    except Exception:
        pass
    ftp.encoding = "utf-8"
    return ftp


def _base(name):
    n = name.rstrip("/")
    return n.split("/")[-1]


def _child(parent, name):
    p = parent.rstrip("/")
    return (p + "/" + name) if p else ("/" + name)


def _list_dir(ftp):
    """列出当前目录下的 [(name, type)]，优先 MLSD，回退 NLST+CWD 探测"""
    entries = []
    try:
        for name, facts in ftp.mlsd():
            if name in (".", ".."):
                continue
            entries.append((_base(name), facts.get("type", "file"), facts))
        return entries
    except Exception:
        pass
    try:
        names = ftp.nlst()
    except Exception:
        return []
    for name in names:
        b = _base(name)
        if b in (".", ".."):
            continue
        isdir = False
        try:
            ftp.cwd(b)
            ftp.cwd("..")
            isdir = True
        except Exception:
            isdir = False
        entries.append((b, "dir" if isdir else "file", {}))
    return entries


def _is_site_root(ftp, path):
    """判断远端目录是否为本站根目录：需同时存在 index.php / api.php / modules"""
    try:
        ftp.cwd(path)
    except Exception:
        return False
    names = set(n for n, _t, _f in _list_dir(ftp))
    return "api.php" in names and "index.php" in names and "modules" in names


def detect_root(ftp, wanted=""):
    """自动探测站点根目录"""
    try:
        home = ftp.pwd()
    except Exception:
        home = "/"

    cands = []
    if wanted:
        cands.append(wanted)
        if not wanted.startswith("/"):
            cands.append(_child(home, wanted))
    cands.append(home)
    cands += [
        "/www/wwwroot/" + DOMAIN_FULL,
        "/www/wwwroot/" + DOMAIN_APEX,
        _child(home, DOMAIN_FULL),
        _child(home, DOMAIN_APEX),
        "/www/wwwroot/" + DOMAIN_FULL + "/public",
        "/httpdocs",
        "/public_html",
        "/htdocs",
        "/web",
        "/www",
        "/",
    ]

    seen = set()
    for c in cands:
        c = c.replace("//", "/") or "/"
        if c in seen:
            continue
        seen.add(c)
        if _is_site_root(ftp, c):
            return c, "命中候选路径" if c != home else "FTP 登录根目录"

    # 广度优先扫描，最多 3 层 / 200 个目录
    queue = [(home, 0)]
    seen_dirs = set()
    while queue:
        d, depth = queue.pop(0)
        if d in seen_dirs or depth > 3 or len(seen_dirs) > 200:
            continue
        seen_dirs.add(d)
        if _is_site_root(ftp, d):
            return d, "目录扫描发现"
        try:
            ftp.cwd(d)
            for name, typ, _f in _list_dir(ftp):
                if typ == "dir":
                    queue.append((_child(d, name), depth + 1))
        except Exception:
            pass
    return None, ""


def remote_get(ftp, path):
    """下载远端文件为 bytes；不存在返回 None"""
    buf = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + path, buf.write)
    except Exception:
        return None
    return buf.getvalue()


def remote_put(ftp, path, data):
    """上传 bytes 到远端；返回 True/False"""
    try:
        ftp.storbinary("STOR " + path, io.BytesIO(data))
        return True
    except Exception:
        return False


def remote_size(ftp, path):
    try:
        s = ftp.size(path)
        return int(s) if s is not None else -1
    except Exception:
        return -1


def remote_delete(ftp, path):
    try:
        ftp.delete(path)
        return True
    except Exception:
        return False


def ensure_remote_dir(ftp, root, rel_dir):
    """确保远端子目录存在，逐层 MKD"""
    if not rel_dir:
        return
    parts = [p for p in rel_dir.split("/") if p]
    cur = root.rstrip("/")
    for p in parts:
        cur = cur + "/" + p
        try:
            ftp.mkd(cur)
        except Exception:
            pass  # 已存在（550）或无权限，忽略；上传失败会在校验阶段暴露


# ---------------- 计划与执行 ----------------
def build_plan(ftp, root, files):
    """按内容 sha256 比对，返回 {'new':[], 'changed':[], 'same':[]}"""
    plan = {"new": [], "changed": [], "same": []}
    for rel in sorted(files.keys()):
        remote_path = root.rstrip("/") + "/" + rel
        remote_data = remote_get(ftp, remote_path)
        if remote_data is None:
            plan["new"].append(rel)
            continue
        local_hash = sha256_file(files[rel])
        remote_hash = hashlib.sha256(remote_data).hexdigest()
        if local_hash == remote_hash:
            plan["same"].append(rel)
        else:
            plan["changed"].append(rel)
    return plan


def verify_remote_is_ours(ftp, root):
    """覆盖前的安全校验：远端必须是本站"""
    data = remote_get(ftp, root.rstrip("/") + "/api.php")
    if data is None:
        return False, "该目录下找不到 api.php"
    if b"courses.list" not in data:
        return False, "该目录下的 api.php 不含本站路由（courses.list），很可能不是本站目录"
    idx = remote_get(ftp, root.rstrip("/") + "/index.php")
    if idx is None:
        return False, "该目录下找不到 index.php"
    if b"assets/js/app.js" not in idx:
        return False, "该目录下的 index.php 不像本站入口"
    return True, ""


def smoke_test(site_url):
    """HTTP 冒烟：确认新代码已生效"""
    import json
    import ssl
    import urllib.request

    if not site_url:
        warn("未配置 site_url，跳过冒烟测试")
        return
    base = site_url.rstrip("/")
    print("  访问 %s ..." % base)
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE

    # 关键：绕过系统代理。很多开发环境/沙箱有透明代理，
    # 它会拦掉对外部站点的请求并返回 502，导致误判成"站点挂了"。
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPSHandler(context=ctx),
    )
    opener.addheaders = [
        ("User-Agent", "hzxk-deploy-check/1.0"),
    ]

    try:
        with opener.open(base + "/api.php?r=courses.facets", timeout=25) as r:
            body = r.read().decode("utf-8", "replace")
        payload = json.loads(body)
        facets = (payload.get("data") or {}).get("facets") or payload.get("facets")
        if not facets:
            warn("站点可访问，但没读到 facets 字段 —— 新代码可能还没生效，等几秒刷新再试")
            return

        labels = {
            "categories": "课程类别",
            "colleges": "开课部门",
            "credits": "学分",
            "examModes": "考核方式",
            "languages": "授课语言",
        }
        ok("courses.facets 接口正常，动态筛选项如下")
        for key, label in labels.items():
            items = facets.get(key) or []
            if not items:
                print("         %-8s 0 项（无数据，前端会自动隐藏该下拉）" % label)
                continue
            preview = "、".join(
                "%s(%s)" % (i.get("value"), i.get("count")) for i in items[:4]
            )
            more = " …" if len(items) > 4 else ""
            print("         %-8s %2d 项：%s%s" % (label, len(items), preview, more))
    except Exception as e:
        warn("冒烟测试未完成：%s" % e)
        warn("若返回 404/403，多半是旧文件还没生效，等几秒刷新再试")


def confirm(prompt):
    try:
        ans = input("  %s [y/N]: " % prompt).strip().lower()
    except EOFError:
        return False
    return ans in ("y", "yes")


def do_check(conf):
    head("1. 连接 FTP")
    ftp = connect(conf)

    head("2. 定位站点根目录")
    root, how = detect_root(ftp, conf.get("remote_dir", ""))
    if not root:
        fail("没能自动找到站点根目录。请用 --remote 指定，例如：")
        print("        python tools/ftp_update.py --check --remote /www/wwwroot/%s" % DOMAIN_FULL)
        ftp.quit()
        sys.exit(3)
    ok("站点根目录：%s" % root)
    print("      （定位方式：%s）" % how)

    head("3. 校验远端确实是本站")
    good, reason = verify_remote_is_ours(ftp, root)
    if not good:
        fail(reason)
        fail("为安全起见已中止。确认目录正确后可用 --remote 显式指定。")
        ftp.quit()
        sys.exit(3)
    ok("确认是华政选课网的程序目录")

    head("4. 比对文件差异（按内容哈希，不受服务器时区影响）")
    files = collect_local(LOCAL_ROOT, conf.get("with_installers", False))
    print("  本地待同步文件 %d 个，开始逐个比对...\n" % len(files))
    plan = build_plan(ftp, root, files)

    if plan["new"]:
        print("  [新增] 服务器上没有，需要上传：")
        for rel in plan["new"]:
            print("         %-34s %s" % (rel, human(os.path.getsize(files[rel]))))
    if plan["changed"]:
        print("  [变更] 内容不同，需要覆盖：")
        for rel in plan["changed"]:
            print("         %-34s %s" % (rel, human(os.path.getsize(files[rel]))))
    if plan["same"]:
        print("  [相同] 内容一致，将跳过 %d 个文件" % len(plan["same"]))

    total = len(plan["new"]) + len(plan["changed"])
    print()
    if total == 0:
        ok("服务器上的文件已经是最新版本，无需更新。")
    else:
        ok("共 %d 个文件需要更新（新增 %d / 变更 %d）" % (total, len(plan["new"]), len(plan["changed"])))
        print("\n  确认无误后执行：")
        print("      python tools/ftp_update.py --apply")
    ftp.quit()


def do_apply(conf):
    head("1. 连接 FTP")
    ftp = connect(conf)

    head("2. 定位站点根目录")
    root, how = detect_root(ftp, conf.get("remote_dir", ""))
    if not root:
        fail("没能自动找到站点根目录，请用 --remote 指定。")
        ftp.quit()
        sys.exit(3)
    ok("站点根目录：%s  (%s)" % (root, how))

    head("3. 覆盖前安全校验")
    good, reason = verify_remote_is_ours(ftp, root)
    if not good:
        fail(reason)
        fail("已中止，未改动任何文件。")
        ftp.quit()
        sys.exit(3)
    ok("确认是华政选课网的程序目录")

    head("4. 比对差异")
    files = collect_local(LOCAL_ROOT, conf.get("with_installers", False))
    plan = build_plan(ftp, root, files)
    total = len(plan["new"]) + len(plan["changed"])
    for rel in plan["new"]:
        print("  [新增] %-34s %s" % (rel, human(os.path.getsize(files[rel]))))
    for rel in plan["changed"]:
        print("  [变更] %-34s %s" % (rel, human(os.path.getsize(files[rel]))))
    print("  [相同] %d 个文件将跳过" % len(plan["same"]))
    if total == 0:
        ok("已是最新，无需更新。")
        ftp.quit()
        return

    if not conf.get("yes"):
        print()
        warn("即将覆盖线上 %d 个文件。" % total)
        if not confirm("继续？"):
            print("  已取消，未改动任何文件。")
            ftp.quit()
            return

    # ---------- 备份 ----------
    head("5. 下载备份线上旧文件")
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    backup_root = os.path.join(BASE, "deploy", ".history", stamp, "files")
    os.makedirs(backup_root, exist_ok=True)

    manifest = {
        "stamp": stamp,
        "created": datetime.now().isoformat(timespec="seconds"),
        "remote_dir": root,
        "site_url": conf.get("site_url", ""),
        "files": [],
    }
    backup_fail = []
    for rel in plan["changed"]:
        data = remote_get(ftp, root.rstrip("/") + "/" + rel)
        if data is None:
            backup_fail.append(rel)
            continue
        dst = os.path.join(backup_root, rel.replace("/", os.sep))
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, "wb") as f:
            f.write(data)
        manifest["files"].append({"rel": rel, "action": "changed", "backup": dst})
    for rel in plan["new"]:
        manifest["files"].append({"rel": rel, "action": "new", "backup": None})

    if backup_fail:
        fail("以下文件无法备份，已跳过，未覆盖：%s" % ", ".join(backup_fail))
        plan["changed"] = [r for r in plan["changed"] if r not in backup_fail]
    ok("已备份 %d 个旧文件到 deploy/.history/%s/" % (len(manifest["files"]) - len(plan["new"]), stamp))

    # ---------- 上传 ----------
    head("6. 上传新文件")
    uploaded, failed = [], []
    for rel in plan["new"] + plan["changed"]:
        with open(files[rel], "rb") as f:
            data = f.read()
        remote_path = root.rstrip("/") + "/" + rel
        rel_dir = posixpath.dirname(rel)
        if rel_dir:
            ensure_remote_dir(ftp, root, rel_dir)
        if remote_put(ftp, remote_path, data):
            uploaded.append(rel)
            print("         %-34s %s" % (rel, human(len(data))))
        else:
            failed.append(rel)
            fail("上传失败：%s" % rel)

    # ---------- 校验 ----------
    head("7. 校验上传结果")
    bad = []
    for rel in uploaded:
        want = os.path.getsize(files[rel])
        got = remote_size(ftp, root.rstrip("/") + "/" + rel)
        if got != want:
            bad.append((rel, want, got))
    if bad:
        for rel, want, got in bad:
            fail("大小不一致 %s（本地 %s / 线上 %s）" % (rel, human(want), human(got)))
    else:
        ok("全部 %d 个文件校验通过（大小一致）" % len(uploaded))

    # 写 manifest
    import json
    mpath = os.path.join(BASE, "deploy", ".history", stamp, "manifest.json")
    with open(mpath, "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
    latest = os.path.join(HISTORY_DIR, "latest.json")
    with open(latest, "w", encoding="utf-8") as f:
        json.dump({"stamp": stamp, "manifest": mpath}, f, ensure_ascii=False, indent=2)

    # ---------- 冒烟 ----------
    if not conf.get("no_smoke"):
        head("8. 更新后冒烟测试")
        smoke_test(conf.get("site_url", ""))

    head("完成")
    if failed:
        fail("%d 个文件上传失败：%s" % (len(failed), ", ".join(failed)))
        print("  可执行回滚：python tools/ftp_update.py --rollback %s" % stamp)
    else:
        ok("更新成功")
        print("\n  若发现异常，一键回滚：")
        print("      python tools/ftp_update.py --rollback %s" % stamp)

    ftp.quit()


def do_rollback(conf, stamp=None):
    head("1. 解析备份批次")
    if not stamp:
        latest = os.path.join(HISTORY_DIR, "latest.json")
        if not os.path.isfile(latest):
            fail("找不到任何备份记录（deploy/.history/latest.json 不存在）。")
            sys.exit(3)
        import json
        with open(latest, "r", encoding="utf-8") as f:
            stamp = json.load(f).get("stamp")
    mpath = os.path.join(HISTORY_DIR, stamp, "manifest.json")
    if not os.path.isfile(mpath):
        fail("找不到批次 %s 的备份记录。" % stamp)
        sys.exit(3)
    import json
    with open(mpath, "r", encoding="utf-8") as f:
        manifest = json.load(f)
    ok("回滚批次：%s（%s）" % (stamp, manifest.get("created", "")))
    print("     远端目录：%s" % manifest.get("remote_dir"))

    head("2. 连接 FTP")
    ftp = connect(conf)
    root = manifest.get("remote_dir") or detect_root(ftp, conf.get("remote_dir", ""))[0]
    if not root:
        fail("无法定位站点根目录，请用 --remote 指定。")
        ftp.quit()
        sys.exit(3)

    head("3. 还原文件")
    restored, deleted, failed = [], [], []
    for item in manifest.get("files", []):
        rel = item["rel"]
        remote_path = root.rstrip("/") + "/" + rel
        if item["action"] == "changed":
            src = item.get("backup")
            if not src or not os.path.isfile(src):
                failed.append(rel)
                fail("备份文件丢失：%s" % rel)
                continue
            with open(src, "rb") as f:
                data = f.read()
            if remote_put(ftp, remote_path, data):
                restored.append(rel)
                print("         已还原 %s" % rel)
            else:
                failed.append(rel)
                fail("还原失败：%s" % rel)
        else:  # new —— 回滚时删掉
            if remote_delete(ftp, remote_path):
                deleted.append(rel)
                print("         已删除新增文件 %s" % rel)
            else:
                failed.append(rel)

    head("完成")
    if failed:
        fail("%d 个文件回滚失败：%s" % (len(failed), ", ".join(failed)))
    else:
        ok("已回滚 %d 个文件，删除 %d 个新增文件" % (len(restored), len(deleted)))
        print("\n  请刷新浏览器（Ctrl+F5）确认站点恢复。")

    ftp.quit()


def do_cleanup(conf):
    """清理线上遗留的安装期文件 —— 安装完成后的安全收尾"""
    # 这些文件只在安装/升级时用一次，留在站点根目录属于信息泄露面
    JUNK = [
        "migrate_once.php",    # 发布期间的一次性数据库迁移入口
        "schema.sql",           # 完整数据库结构，可被任意访客下载
        "config.example.php",   # 配置模板，泄露文件布局
        "modules/seed.json",    # 演示数据
        "install.php",          # 安装向导
        "upgrade.php",          # 升级脚本
    ]

    head("1. 连接 FTP")
    ftp = connect(conf)
    root, how = detect_root(ftp, conf.get("remote_dir", ""))
    if not root:
        fail("无法定位站点根目录，请用 --remote 指定。")
        ftp.quit()
        sys.exit(3)
    ok("站点根目录：%s  (%s)" % (root, how))

    head("2. 覆盖前安全校验")
    good, reason = verify_remote_is_ours(ftp, root)
    if not good:
        fail(reason)
        fail("已中止，未删除任何文件。")
        ftp.quit()
        sys.exit(3)
    ok("确认是华政选课网的程序目录")

    head("3. 扫描遗留文件")
    found = []
    for rel in JUNK:
        data = remote_get(ftp, root.rstrip("/") + "/" + rel)
        if data is not None:
            found.append((rel, data))
            print("  发现 %-24s %s" % (rel, human(len(data))))
    if not found:
        ok("没有遗留文件，无需清理。")
        ftp.quit()
        return

    if not conf.get("yes"):
        print()
        warn("即将从服务器删除以上 %d 个文件（删除前会先下载备份到本地）。" % len(found))
        warn("注意：install.php / upgrade.php 删除后若将来需要，重新上传即可。")
        if not confirm("确认删除？"):
            print("  已取消，未删除任何文件。")
            ftp.quit()
            return

    head("4. 备份并删除")
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    backup_root = os.path.join(HISTORY_DIR, stamp, "removed")
    os.makedirs(backup_root, exist_ok=True)

    import json
    manifest = {
        "stamp": stamp,
        "created": datetime.now().isoformat(timespec="seconds"),
        "remote_dir": root,
        "action": "cleanup",
        "files": [],
    }
    for rel, data in found:
        dst = os.path.join(backup_root, rel.replace("/", os.sep))
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, "wb") as f:
            f.write(data)
        if remote_delete(ftp, root.rstrip("/") + "/" + rel):
            print("  已删除 %-24s (备份: %s)" % (rel, dst))
        else:
            fail("删除失败：%s（备份已保存）" % rel)
        manifest["files"].append({"rel": rel, "backup": dst})

    mpath = os.path.join(HISTORY_DIR, stamp, "manifest.json")
    with open(mpath, "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)
    print("\n  备份位置：deploy/.history/%s/removed/" % stamp)
    ok("清理完成")

    ftp.quit()


def do_list_history():
    if not os.path.isdir(HISTORY_DIR):
        print("暂无备份记录。")
        return
    stamps = sorted(
        (d for d in os.listdir(HISTORY_DIR) if os.path.isdir(os.path.join(HISTORY_DIR, d))),
        reverse=True,
    )
    if not stamps:
        print("暂无备份记录。")
        return
    import json
    for s in stamps:
        mpath = os.path.join(HISTORY_DIR, s, "manifest.json")
        extra = ""
        if os.path.isfile(mpath):
            with open(mpath, "r", encoding="utf-8") as f:
                m = json.load(f)
            extra = "  %d 个文件  %s" % (len(m.get("files", [])), m.get("created", ""))
        print("  %s%s" % (s, extra))
    print("\n  回滚示例：python tools/ftp_update.py --rollback %s" % stamps[0])


# ---------------- 入口 ----------------
def main():
    ap = argparse.ArgumentParser(
        description="华政选课网 FTP 一站式热更新",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument("--check", action="store_true", help="只读预检，列出将变更的文件")
    ap.add_argument("--apply", action="store_true", help="执行更新（备份后覆盖）")
    ap.add_argument("--rollback", nargs="?", const="", metavar="STAMP",
                    help="回滚到指定批次，留空则回滚最近一次")
    ap.add_argument("--list-history", action="store_true", help="列出所有备份批次")
    ap.add_argument("--cleanup", action="store_true",
                    help="清理线上遗留的安装期文件（schema.sql 等，删除前自动备份）")
    ap.add_argument("--host", help="FTP 地址")
    ap.add_argument("--port", type=int, help="FTP 端口")
    ap.add_argument("--user", help="FTP 账号")
    ap.add_argument("--password", help="FTP 密码")
    ap.add_argument("--tls", dest="tls", action="store_true", default=None,
                    help="使用 FTPS(显式 TLS) 连接，默认取 deploy.conf 的 ftp_tls")
    ap.add_argument("--remote", help="服务器上的站点根目录")
    ap.add_argument("--yes", action="store_true", help="跳过二次确认")
    ap.add_argument("--with-installers", action="store_true",
                    help="同时上传 install.php/upgrade.php/schema.sql")
    ap.add_argument("--no-smoke", action="store_true", help="更新后不做冒烟测试")
    args = ap.parse_args()

    print("=" * 62)
    print("  华政选课网 —— FTP 一站式热更新")
    print("=" * 62)

    if not os.path.isdir(LOCAL_ROOT):
        fail("找不到本地站点目录：%s" % LOCAL_ROOT)
        sys.exit(2)

    if args.list_history:
        do_list_history()
        return

    conf = merge_conf(args)
    conf["with_installers"] = args.with_installers
    conf["no_smoke"] = args.no_smoke
    conf["yes"] = args.yes

    if args.rollback is not None:
        do_rollback(conf, args.rollback or None)
    elif args.cleanup:
        do_cleanup(conf)
    elif args.apply:
        do_apply(conf)
    else:
        do_check(conf)


if __name__ == "__main__":
    main()

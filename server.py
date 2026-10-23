#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
昊宸工作室网站服务器
- 静态托管：index.html / login.html / register.html / img/
- 注册登录 API：/api/register /api/login /api/logout /api/me
- 数据库：SQLite（data.db），密码使用 PBKDF2-SHA256 加盐哈希存储

启动：python server.py
访问：http://127.0.0.1:8000
"""
import hashlib
import hmac
import json
import os
import re
import sqlite3
import time
import uuid
from contextlib import closing
from http import cookies
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

BASE = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE, "data.db")
HOST, PORT = "127.0.0.1", 8000

EMAIL_RE = re.compile(r"^[\w.+-]+@[\w-]+(\.[\w-]+)+$")
SESSION_COOKIE = "hc_session"
DAY = 86400


def db():
    return sqlite3.connect(DB_PATH)


def query(sql, args=(), commit=False, one=False):
    with closing(db()) as conn:
        cur = conn.execute(sql, args)
        rows = cur.fetchall()
        if commit:
            conn.commit()
        return (rows[0] if rows else None) if one else rows


def init_db():
    with closing(db()) as conn:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS users(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                pw_hash TEXT NOT NULL,
                salt TEXT NOT NULL,
                created_at REAL NOT NULL
            );
            CREATE TABLE IF NOT EXISTS sessions(
                token TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                expires_at REAL NOT NULL
            );
            CREATE TABLE IF NOT EXISTS posts(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at REAL NOT NULL
            );
            CREATE TABLE IF NOT EXISTS likes(
                user_id INTEGER NOT NULL,
                post_id INTEGER NOT NULL,
                UNIQUE(user_id, post_id)
            );
            CREATE TABLE IF NOT EXISTS follows(
                follower_id INTEGER NOT NULL,
                followee_id INTEGER NOT NULL,
                UNIQUE(follower_id, followee_id)
            );
            """
        )
        try:
            conn.execute("ALTER TABLE users ADD COLUMN bio TEXT DEFAULT ''")
        except sqlite3.OperationalError:
            pass  # 列已存在


def hash_pw(password, salt_hex):
    return hashlib.pbkdf2_hmac(
        "sha256", password.encode("utf-8"), bytes.fromhex(salt_hex), 100_000
    ).hex()


def create_session(user_id, remember):
    """签发会话令牌。remember=True 时有效期 30 天，否则 1 天。"""
    token = uuid.uuid4().hex + uuid.uuid4().hex
    ttl = 30 * DAY if remember else DAY
    query(
        "INSERT INTO sessions(token, user_id, expires_at) VALUES(?, ?, ?)",
        (token, user_id, time.time() + ttl),
        commit=True,
    )
    return token, ttl


def destroy_session(token):
    query("DELETE FROM sessions WHERE token = ?", (token,), commit=True)


def current_user(cookie_header):
    """根据 Cookie 解析当前登录用户，过期会话自动清理。"""
    if not cookie_header:
        return None
    jar = cookies.SimpleCookie()
    try:
        jar.load(cookie_header)
    except Exception:
        return None
    if SESSION_COOKIE not in jar:
        return None
    token = jar[SESSION_COOKIE].value
    row = query(
        "SELECT s.expires_at, u.id, u.email, u.name "
        "FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?",
        (token,),
        one=True,
    )
    if not row:
        return None
    expires_at, uid, email, name = row
    if expires_at < time.time():
        destroy_session(token)
        return None
    return {"id": uid, "email": email, "name": name, "token": token}


def build_cookie(token, ttl=None):
    """ttl=None 表示浏览器会话 Cookie，关闭浏览器即失效。"""
    c = "%s=%s; Path=/; HttpOnly; SameSite=Lax" % (SESSION_COOKIE, token)
    if ttl is not None:
        c += "; Max-Age=%d" % ttl
    return c


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=BASE, **kwargs)

    # ---------- 工具 ----------
    def send_json(self, obj, status=200, set_cookie=None):
        body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        if set_cookie:
            self.send_header("Set-Cookie", set_cookie)
        self.end_headers()
        self.wfile.write(body)

    def read_json(self):
        try:
            n = int(self.headers.get("Content-Length") or 0)
            data = json.loads(self.rfile.read(n).decode("utf-8") or "{}")
            return data if isinstance(data, dict) else {}
        except Exception:
            return {}

    def log_message(self, fmt, *args):
        pass  # 静态请求不打印日志，保持控制台清爽

    # ---------- API 路由 ----------
    def do_GET(self):
        parsed = urlparse(self.path)
        path = parsed.path
        if path == "/api/me":
            user = current_user(self.headers.get("Cookie"))
            if not user:
                return self.send_json({"error": "未登录"}, 401)
            return self.send_json(
                {"user": {"id": user["id"], "email": user["email"], "name": user["name"]}}
            )
        if path == "/api/users/search":
            return self.handle_users_search(parse_qs(parsed.query))
        if path == "/api/user":
            viewer = current_user(self.headers.get("Cookie"))
            return self.handle_user_detail(parse_qs(parsed.query), viewer)
        if path == "/api/posts":
            viewer = current_user(self.headers.get("Cookie"))
            return self.handle_posts_list(viewer)
        return super().do_GET()

    def do_POST(self):
        path = urlparse(self.path).path
        if path == "/api/register":
            return self.handle_register()
        if path == "/api/login":
            return self.handle_login()
        if path == "/api/logout":
            return self.handle_logout()
        if path in ("/api/profile", "/api/follow", "/api/posts",
                    "/api/posts/delete", "/api/posts/like"):
            user = current_user(self.headers.get("Cookie"))
            if not user:
                return self.send_json({"error": "请先登录"}, 401)
            if path == "/api/profile":
                return self.handle_profile(user)
            if path == "/api/follow":
                return self.handle_follow(user)
            if path == "/api/posts":
                return self.handle_post_create(user)
            if path == "/api/posts/delete":
                return self.handle_post_delete(user)
            if path == "/api/posts/like":
                return self.handle_post_like(user)
        return self.send_json({"error": "接口不存在"}, 404)

    # ---------- 注册 ----------
    def handle_register(self):
        data = self.read_json()
        email = str(data.get("email", "")).strip().lower()
        name = str(data.get("name", "")).strip()
        password = str(data.get("password", ""))

        if not EMAIL_RE.match(email):
            return self.send_json({"error": "邮箱格式不正确"}, 400)
        if not 2 <= len(name) <= 20:
            return self.send_json({"error": "昵称需为 2-20 个字符"}, 400)
        if len(password) < 6:
            return self.send_json({"error": "密码至少 6 位"}, 400)

        salt = os.urandom(16).hex()
        pw_hash = hash_pw(password, salt)
        try:
            with closing(db()) as conn:
                conn.execute(
                    "INSERT INTO users(email, name, pw_hash, salt, created_at) "
                    "VALUES(?, ?, ?, ?, ?)",
                    (email, name, pw_hash, salt, time.time()),
                )
                conn.commit()
        except sqlite3.IntegrityError:
            return self.send_json({"error": "该邮箱已被注册"}, 409)

        row = query("SELECT id FROM users WHERE email = ?", (email,), one=True)
        token, ttl = create_session(row[0], True)  # 注册后自动登录（30 天）
        print("[注册] %s -> %s" % (email, name))
        return self.send_json(
            {"ok": True, "user": {"id": row[0], "email": email, "name": name}},
            set_cookie=build_cookie(token, ttl),
        )

    # ---------- 登录 ----------
    def handle_login(self):
        data = self.read_json()
        email = str(data.get("email", "")).strip().lower()
        password = str(data.get("password", ""))
        remember = bool(data.get("remember"))
        if not email or not password:
            return self.send_json({"error": "请输入邮箱和密码"}, 400)

        row = query(
            "SELECT id, name, pw_hash, salt FROM users WHERE email = ?",
            (email,),
            one=True,
        )
        if not row:
            return self.send_json({"error": "邮箱或密码错误"}, 401)
        uid, name, pw_hash, salt = row
        if not hmac.compare_digest(hash_pw(password, salt), pw_hash):
            return self.send_json({"error": "邮箱或密码错误"}, 401)

        token, ttl = create_session(uid, remember)
        # 勾选“记住我”→ 30 天有效；否则仅当前浏览器会话
        cookie = build_cookie(token, 30 * DAY) if remember else build_cookie(token)
        print("[登录] %s%s" % (email, "（记住我）" if remember else ""))
        return self.send_json(
            {"ok": True, "user": {"id": uid, "email": email, "name": name}},
            set_cookie=cookie,
        )

    # ---------- 退出 ----------
    def handle_logout(self):
        user = current_user(self.headers.get("Cookie"))
        if user:
            destroy_session(user["token"])
        return self.send_json({"ok": True}, set_cookie=build_cookie("", 0))

    # ---------- 编辑资料 ----------
    def handle_profile(self, user):
        data = self.read_json()
        name = str(data.get("name", "")).strip()
        bio = str(data.get("bio", "")).strip()
        if not 2 <= len(name) <= 20:
            return self.send_json({"error": "昵称需为 2-20 个字符"}, 400)
        if len(bio) > 200:
            return self.send_json({"error": "简介不能超过 200 个字符"}, 400)
        query(
            "UPDATE users SET name = ?, bio = ? WHERE id = ?",
            (name, bio, user["id"]),
            commit=True,
        )
        return self.send_json(
            {"ok": True,
             "user": {"id": user["id"], "email": user["email"], "name": name, "bio": bio}}
        )

    # ---------- 搜索用户 ----------
    def handle_users_search(self, qs):
        q = (qs.get("q", [""])[0] or "").strip()
        if not q:
            return self.send_json({"users": []})
        like = "%" + q + "%"
        rows = query(
            "SELECT u.id, u.name, u.email, u.bio, "
            "(SELECT COUNT(*) FROM follows f WHERE f.followee_id = u.id) "
            "FROM users u WHERE u.name LIKE ? OR u.email LIKE ? "
            "ORDER BY u.id LIMIT 20",
            (like, like),
        )
        users = [
            {"id": r[0], "name": r[1], "email": r[2], "bio": r[3] or "", "followers": r[4]}
            for r in rows
        ]
        return self.send_json({"users": users})

    # ---------- 用户主页 ----------
    def handle_user_detail(self, qs, viewer):
        try:
            uid = int(qs.get("id", [""])[0])
        except (TypeError, ValueError):
            return self.send_json({"error": "用户不存在"}, 404)
        row = query(
            "SELECT id, name, email, bio, created_at FROM users WHERE id = ?",
            (uid,), one=True,
        )
        if not row:
            return self.send_json({"error": "用户不存在"}, 404)
        posts = query(
            "SELECT p.id, p.title, p.content, p.created_at, "
            "(SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) "
            "FROM posts p WHERE p.user_id = ? ORDER BY p.created_at DESC, p.id DESC",
            (uid,),
        )
        followers = query(
            "SELECT COUNT(*) FROM follows WHERE followee_id = ?", (uid,), one=True
        )[0]
        following = query(
            "SELECT COUNT(*) FROM follows WHERE follower_id = ?", (uid,), one=True
        )[0]
        is_following = False
        if viewer and viewer["id"] != uid:
            is_following = bool(query(
                "SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?",
                (viewer["id"], uid), one=True,
            ))
        return self.send_json({
            "user": {"id": row[0], "name": row[1], "email": row[2],
                     "bio": row[3] or "", "created_at": row[4]},
            "posts": [
                {"id": p[0], "title": p[1], "content": p[2],
                 "created_at": p[3], "like_count": p[4]}
                for p in posts
            ],
            "followers": followers,
            "following": following,
            "is_following": is_following,
            "is_self": bool(viewer and viewer["id"] == uid),
        })

    # ---------- 关注/取关 ----------
    def handle_follow(self, user):
        data = self.read_json()
        try:
            fid = int(data.get("user_id"))
        except (TypeError, ValueError):
            return self.send_json({"error": "用户不存在"}, 404)
        if fid == user["id"]:
            return self.send_json({"error": "不能关注自己"}, 400)
        if not query("SELECT 1 FROM users WHERE id = ?", (fid,), one=True):
            return self.send_json({"error": "用户不存在"}, 404)
        row = query(
            "SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?",
            (user["id"], fid), one=True,
        )
        if row:
            query(
                "DELETE FROM follows WHERE follower_id = ? AND followee_id = ?",
                (user["id"], fid), commit=True,
            )
            following = False
        else:
            query(
                "INSERT INTO follows(follower_id, followee_id) VALUES(?, ?)",
                (user["id"], fid), commit=True,
            )
            following = True
        return self.send_json({"ok": True, "following": following})

    # ---------- 帖子列表 ----------
    def handle_posts_list(self, viewer):
        rows = query(
            "SELECT p.id, p.title, p.content, p.created_at, u.id, u.name, "
            "(SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) "
            "FROM posts p JOIN users u ON u.id = p.user_id "
            "ORDER BY p.created_at DESC, p.id DESC"
        )
        vid = viewer["id"] if viewer else None
        liked_ids = set()
        if vid:
            liked_ids = set(
                r[0] for r in query("SELECT post_id FROM likes WHERE user_id = ?", (vid,))
            )
        posts = [
            {"id": r[0], "title": r[1], "content": r[2], "created_at": r[3],
             "author_id": r[4], "author_name": r[5], "like_count": r[6],
             "liked": r[0] in liked_ids, "is_own": bool(vid and r[4] == vid)}
            for r in rows
        ]
        return self.send_json({"posts": posts})

    # ---------- 发帖 ----------
    def handle_post_create(self, user):
        data = self.read_json()
        title = str(data.get("title", "")).strip()
        content = str(data.get("content", "")).strip()
        if not 1 <= len(title) <= 80:
            return self.send_json({"error": "标题需为 1-80 个字符"}, 400)
        if not 1 <= len(content) <= 2000:
            return self.send_json({"error": "内容需为 1-2000 个字符"}, 400)
        with closing(db()) as conn:
            cur = conn.execute(
                "INSERT INTO posts(user_id, title, content, created_at) VALUES(?, ?, ?, ?)",
                (user["id"], title, content, time.time()),
            )
            conn.commit()
            pid = cur.lastrowid
        return self.send_json({"ok": True, "id": pid})

    # ---------- 删帖 ----------
    def handle_post_delete(self, user):
        data = self.read_json()
        try:
            pid = int(data.get("id"))
        except (TypeError, ValueError):
            return self.send_json({"error": "帖子不存在"}, 404)
        row = query("SELECT user_id FROM posts WHERE id = ?", (pid,), one=True)
        if not row:
            return self.send_json({"error": "帖子不存在"}, 404)
        if row[0] != user["id"]:
            return self.send_json({"error": "只能删除自己的帖子"}, 403)
        query("DELETE FROM posts WHERE id = ?", (pid,), commit=True)
        query("DELETE FROM likes WHERE post_id = ?", (pid,), commit=True)
        return self.send_json({"ok": True})

    # ---------- 点赞/取消 ----------
    def handle_post_like(self, user):
        data = self.read_json()
        try:
            pid = int(data.get("id"))
        except (TypeError, ValueError):
            return self.send_json({"error": "帖子不存在"}, 404)
        if not query("SELECT 1 FROM posts WHERE id = ?", (pid,), one=True):
            return self.send_json({"error": "帖子不存在"}, 404)
        row = query(
            "SELECT 1 FROM likes WHERE user_id = ? AND post_id = ?",
            (user["id"], pid), one=True,
        )
        if row:
            query(
                "DELETE FROM likes WHERE user_id = ? AND post_id = ?",
                (user["id"], pid), commit=True,
            )
            liked = False
        else:
            query(
                "INSERT INTO likes(user_id, post_id) VALUES(?, ?)",
                (user["id"], pid), commit=True,
            )
            liked = True
        count = query(
            "SELECT COUNT(*) FROM likes WHERE post_id = ?", (pid,), one=True
        )[0]
        return self.send_json({"ok": True, "liked": liked, "like_count": count})


if __name__ == "__main__":
    init_db()
    print("=" * 46)
    print("  昊宸工作室网站已启动（SQLite 已就绪）")
    print("  首页   http://127.0.0.1:%d" % PORT)
    print("  登录   http://127.0.0.1:%d/login.html" % PORT)
    print("  注册   http://127.0.0.1:%d/register.html" % PORT)
    print("  按 Ctrl+C 停止服务")
    print("=" * 46)
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()

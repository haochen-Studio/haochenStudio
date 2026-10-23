<?php
// ================================================================
// 昊宸工作室 单文件后端（PHP + MySQL，适配 InfinityFree）
//
// 数据库配置：在 InfinityFree 控制面板（MySQL Databases）查看并填写
// ================================================================
define('DB_HOST', 'sql207.infinityfree.com'); // MySQL 主机名（控制面板查看）
define('DB_NAME', 'if0_42273521_haochen');     // 数据库名
define('DB_USER', 'if0_42273521');             // 数据库用户名
define('DB_PASS', 'Ao9LAdL41EnH');            // 数据库密码（vPanel 账号密码）
// 用此邮箱注册的账号自动成为根管理员；其他用户可由根管理员通过 admin_update_user 的 is_admin 字段授权
define('ADMIN_EMAIL', 'haochen-gongzuoshi@qq.com');
// ================================================================

define('SESSION_COOKIE', 'hc_session');
define('DAY', 86400);
define('EMAIL_RE', '/^[\w.+-]+@[\w-]+(\.[\w-]+)+$/');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------- 数据库 ----------
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            )
        );
    }
    return $pdo;
}

function q($sql, $args = array()) {
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function q1($sql, $args = array()) {
    $rows = q($sql, $args);
    return $rows ? $rows[0] : null;
}

// ---------- 工具 ----------
function send_json($obj, $status = 200, $set_cookie = null) {
    http_response_code($status);
    if ($set_cookie !== null) {
        header('Set-Cookie: ' . $set_cookie);
    }
    echo json_encode($obj, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : array();
}

function build_cookie($token, $ttl = null) {
    $c = SESSION_COOKIE . '=' . $token . '; Path=/; HttpOnly; SameSite=Lax';
    if ($ttl !== null) {
        $c .= '; Max-Age=' . $ttl;
    }
    return $c;
}

// ---------- 会话 ----------
function create_session($user_id, $remember) {
    $token = bin2hex(random_bytes(32));
    $ttl = $remember ? 30 * DAY : DAY;
    $stmt = db()->prepare('INSERT INTO sessions(token, user_id, expires_at) VALUES(?, ?, ?)');
    $stmt->execute(array($token, $user_id, microtime(true) + $ttl));
    return array($token, $ttl);
}

function destroy_session($token) {
    $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute(array($token));
}

function current_user() {
    if (empty($_COOKIE[SESSION_COOKIE])) {
        return null;
    }
    $token = $_COOKIE[SESSION_COOKIE];
    $row = q1(
        'SELECT s.expires_at, u.id, u.email, u.name
         FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?',
        array($token)
    );
    if (!$row) {
        return null;
    }
    if ((float)$row['expires_at'] < microtime(true)) {
        destroy_session($token);
        return null;
    }
    return array(
        'id' => (int)$row['id'],
        'email' => $row['email'],
        'name' => $row['name'],
        'token' => $token,
    );
}

function require_login() {
    $user = current_user();
    if (!$user) {
        send_json(array('error' => '请先登录'), 401);
    }
    return $user;
}

// ---------- 管理员 ----------
function is_admin_email($email) {
    return strtolower(trim(strval($email))) === strtolower(ADMIN_EMAIL);
}

// 管理员 = 根管理员（email === ADMIN_EMAIL）或被授权管理员（users.is_admin = 1）
function is_admin($userId) {
    $row = q1('SELECT email, is_admin FROM users WHERE id = ?', array((int)$userId));
    if (!$row) {
        return false;
    }
    return is_admin_email($row['email']) || (int)$row['is_admin'] === 1;
}

function require_admin() {
    $user = require_login();
    if (!is_admin($user['id'])) {
        send_json(array('error' => '无权限'), 403);
    }
    return $user;
}

// ---------- 结构迁移（幂等、非破坏性） ----------
function column_exists($table, $col) {
    $row = q1(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        array($table, $col)
    );
    return $row && (int)$row['c'] > 0;
}

function table_exists($table) {
    $row = q1(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        array($table)
    );
    return $row && (int)$row['c'] > 0;
}

function run_migrations() {
    if (table_exists('users')) {
        $user_cols = array(
            'verify_type' => "ALTER TABLE users ADD COLUMN verify_type VARCHAR(10) NOT NULL DEFAULT ''",
            'verify_title' => "ALTER TABLE users ADD COLUMN verify_title VARCHAR(50) NOT NULL DEFAULT ''",
            'coins' => 'ALTER TABLE users ADD COLUMN coins INT NOT NULL DEFAULT 0',
            'banned' => 'ALTER TABLE users ADD COLUMN banned TINYINT NOT NULL DEFAULT 0',
            'followers_delta' => 'ALTER TABLE users ADD COLUMN followers_delta INT NOT NULL DEFAULT 0',
            'is_admin' => 'ALTER TABLE users ADD COLUMN is_admin TINYINT NOT NULL DEFAULT 0',
        );
        foreach ($user_cols as $col => $sql) {
            if (!column_exists('users', $col)) {
                db()->exec($sql);
            }
        }
    }
    $tables = array(
        "CREATE TABLE IF NOT EXISTS announcement(
            id INT PRIMARY KEY,
            content TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS verify_requests(
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(10) NOT NULL,
            title VARCHAR(50) NOT NULL DEFAULT '',
            reason VARCHAR(200) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at DOUBLE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS messages(
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_id INT NOT NULL,
            to_id INT NOT NULL,
            content VARCHAR(500) NOT NULL,
            created_at DOUBLE NOT NULL,
            INDEX idx_pair (from_id, to_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS achievements(
            id INT PRIMARY KEY,
            name VARCHAR(30) NOT NULL,
            description VARCHAR(100) NOT NULL,
            coins INT NOT NULL DEFAULT 0,
            criteria_key VARCHAR(30) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS user_achievements(
            user_id INT NOT NULL,
            achievement_id INT NOT NULL,
            awarded_at DOUBLE NOT NULL,
            UNIQUE KEY uq_ua (user_id, achievement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS article_hidden(
            id INT PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS articles_custom(
            id INT AUTO_INCREMENT PRIMARY KEY,
            cat VARCHAR(20) NOT NULL,
            title VARCHAR(100) NOT NULL,
            body TEXT,
            date VARCHAR(10) NOT NULL DEFAULT '',
            created_at DOUBLE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS replies(
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            user_id INT NOT NULL,
            content VARCHAR(200) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(post_id),
            INDEX(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS msg_reads(
            user_id INT PRIMARY KEY,
            last_read DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS captchas(
            token CHAR(32) PRIMARY KEY,
            answer VARCHAR(8) NOT NULL,
            expires DOUBLE NOT NULL,
            INDEX idx_expires (expires)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );
    foreach ($tables as $sql) {
        db()->exec($sql);
    }
    // 种子成就（INSERT IGNORE，重复运行不报错）
    $seed = array(
        array(1, '初露锋芒', '发布第一条帖子', 10, 'first_post'),
        array(2, '笔耕不辍', '累计发布10条帖子', 30, 'post_10'),
        array(3, '备受认可', '累计获得10个赞', 20, 'likes_10'),
        array(4, '人气新星', '粉丝达到10', 20, 'followers_10'),
        array(5, '认证达人', '通过V认证', 15, 'verified'),
        array(6, '社交达人', '首次关注他人', 5, 'first_follow'),
        array(7, '打破沉默', '发送第一条私聊', 5, 'first_chat'),
    );
    $stmt = db()->prepare('INSERT IGNORE INTO achievements(id, name, description, coins, criteria_key) VALUES(?, ?, ?, ?, ?)');
    foreach ($seed as $a) {
        $stmt->execute($a);
    }
}

// ---------- 成就自动发放 ----------
function award_achievement($userId, $criteriaKey) {
    try {
        $a = q1('SELECT id, coins FROM achievements WHERE criteria_key = ?', array($criteriaKey));
        if (!$a) {
            return;
        }
        $stmt = db()->prepare('INSERT IGNORE INTO user_achievements(user_id, achievement_id, awarded_at) VALUES(?, ?, ?)');
        $stmt->execute(array($userId, (int)$a['id'], microtime(true)));
        if ($stmt->rowCount() > 0) {
            $stmt = db()->prepare('UPDATE users SET coins = coins + ? WHERE id = ?');
            $stmt->execute(array((int)$a['coins'], $userId));
        }
    } catch (PDOException $e) {
        // 成就为辅助功能，失败不影响主流程
    }
}

// ---------- 初始化建表 ----------
function handle_install() {
    $tables = array(
        'users' => "CREATE TABLE IF NOT EXISTS users(
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            name VARCHAR(255) NOT NULL,
            pw_hash VARCHAR(255) NOT NULL,
            salt VARCHAR(64) NOT NULL DEFAULT '',
            bio TEXT,
            verify_type VARCHAR(10) NOT NULL DEFAULT '',
            verify_title VARCHAR(50) NOT NULL DEFAULT '',
            coins INT NOT NULL DEFAULT 0,
            banned TINYINT NOT NULL DEFAULT 0,
            followers_delta INT NOT NULL DEFAULT 0,
            created_at DOUBLE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'sessions' => "CREATE TABLE IF NOT EXISTS sessions(
            token VARCHAR(128) PRIMARY KEY,
            user_id INT NOT NULL,
            expires_at DOUBLE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'posts' => "CREATE TABLE IF NOT EXISTS posts(
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            created_at DOUBLE NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'likes' => "CREATE TABLE IF NOT EXISTS likes(
            user_id INT NOT NULL,
            post_id INT NOT NULL,
            UNIQUE KEY uq_like (user_id, post_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'follows' => "CREATE TABLE IF NOT EXISTS follows(
            follower_id INT NOT NULL,
            followee_id INT NOT NULL,
            UNIQUE KEY uq_follow (follower_id, followee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    );
    $created = array();
    foreach ($tables as $name => $sql) {
        db()->exec($sql);
        $created[] = $name;
    }
    run_migrations(); // 新表 + 新增列 + 种子成就（幂等）
    send_json(array('ok' => true, 'tables' => $created, 'migrated' => true));
}

// ---------- 结构迁移入口（幂等，可重复调用） ----------
function handle_migrate() {
    run_migrations();
    send_json(array('ok' => true, 'migrated' => true));
}

// ---------- 人机验证（图形验证码） ----------
function captcha_png($code) {
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $w = 120;
    $h = 44;
    $im = @imagecreatetruecolor($w, $h);
    if (!$im) {
        return null;
    }
    $bg = imagecolorallocate($im, 245, 247, 250);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    // 干扰点
    for ($i = 0; $i < 90; $i++) {
        $c = imagecolorallocate($im, random_int(120, 210), random_int(120, 210), random_int(120, 210));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }
    // 干扰线
    for ($i = 0; $i < 3; $i++) {
        $c = imagecolorallocate($im, random_int(150, 205), random_int(150, 205), random_int(150, 205));
        imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }
    // 字符
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $c = imagecolorallocate($im, random_int(20, 110), random_int(20, 110), random_int(20, 110));
        $x = 12 + $i * 26 + random_int(-2, 2);
        $y = random_int(12, 20);
        imagestring($im, 5, $x, $y, $code[$i], $c);
    }
    ob_start();
    imagepng($im);
    $raw = ob_get_clean();
    imagedestroy($im);
    return 'data:image/png;base64,' . base64_encode($raw);
}

function captcha_svg($code) {
    $w = 120;
    $h = 44;
    $out = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
    $out .= '<rect width="100%" height="100%" fill="#f5f7fa"/>';
    for ($i = 0; $i < 3; $i++) {
        $out .= '<line x1="' . random_int(0, $w) . '" y1="' . random_int(0, $h) . '" x2="' . random_int(0, $w) . '" y2="' . random_int(0, $h)
            . '" stroke="#c9d2de" stroke-width="1"/>';
    }
    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $x = 20 + $i * 26;
        $y = 31 + random_int(-3, 3);
        $rot = random_int(-16, 16);
        $col = sprintf('#%02x%02x%02x', random_int(20, 110), random_int(20, 110), random_int(20, 110));
        $out .= '<text x="' . $x . '" y="' . $y . '" font-family="monospace" font-size="23" font-weight="700" fill="' . $col
            . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')">' . htmlspecialchars($code[$i]) . '</text>';
    }
    $out .= '</svg>';
    return $out;
}

function handle_captcha() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXY23456789';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $token = bin2hex(random_bytes(16));
    $now = microtime(true);
    // 顺手清理过期验证码
    $del = db()->prepare('DELETE FROM captchas WHERE expires < ?');
    $del->execute(array($now));
    $stmt = db()->prepare('INSERT INTO captchas(token, answer, expires) VALUES(?, ?, ?)');
    $stmt->execute(array($token, strtoupper($code), $now + 300));

    $image = captcha_png($code);
    $svg = $image ? null : captcha_svg($code);
    send_json(array('ok' => true, 'token' => $token, 'image' => $image, 'svg' => $svg));
}

// 校验验证码：token 一次性使用，5 分钟有效
function check_captcha($data) {
    $token = trim(strval(isset($data['captcha_token']) ? $data['captcha_token'] : ''));
    $answer = strtoupper(trim(strval(isset($data['captcha_answer']) ? $data['captcha_answer'] : '')));
    if ($token === '' || $answer === '') {
        send_json(array('error' => '请填写图形验证码'), 400);
    }
    $row = q1('SELECT answer, expires FROM captchas WHERE token = ?', array($token));
    if (!$row) {
        send_json(array('error' => '验证码已失效，请刷新后重试'), 400);
    }
    q('DELETE FROM captchas WHERE token = ?', array($token)); // 一次性
    if ((float)$row['expires'] < microtime(true)) {
        send_json(array('error' => '验证码已过期，请刷新后重试'), 400);
    }
    if (strtoupper(trim($row['answer'])) !== $answer) {
        send_json(array('error' => '验证码错误，请重新输入'), 400);
    }
}

// ---------- 注册 ----------
function handle_register() {
    $data = read_json();
    check_captcha($data);
    $email = strtolower(trim(strval(isset($data['email']) ? $data['email'] : '')));
    $name = trim(strval(isset($data['name']) ? $data['name'] : ''));
    $password = strval(isset($data['password']) ? $data['password'] : '');

    if (!preg_match(EMAIL_RE, $email)) {
        send_json(array('error' => '邮箱格式不正确'), 400);
    }
    $name_len = mb_strlen($name, 'UTF-8');
    if ($name_len < 2 || $name_len > 20) {
        send_json(array('error' => '昵称需为 2-20 个字符'), 400);
    }
    if (strlen($password) < 6) {
        send_json(array('error' => '密码至少 6 位'), 400);
    }

    $pw_hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = db()->prepare(
            "INSERT INTO users(email, name, pw_hash, salt, bio, created_at) VALUES(?, ?, ?, '', '', ?)"
        );
        $stmt->execute(array($email, $name, $pw_hash, microtime(true)));
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            send_json(array('error' => '该邮箱已被注册'), 409);
        }
        throw $e;
    }

    $uid = (int)db()->lastInsertId();
    list($token, $ttl) = create_session($uid, true); // 注册后自动登录（30 天）
    send_json(
        array('ok' => true, 'user' => array('id' => $uid, 'email' => $email, 'name' => $name)),
        200,
        build_cookie($token, $ttl)
    );
}

// ---------- 登录 ----------
function handle_login() {
    $data = read_json();
    check_captcha($data);
    $email = strtolower(trim(strval(isset($data['email']) ? $data['email'] : '')));
    $password = strval(isset($data['password']) ? $data['password'] : '');
    $remember = !empty($data['remember']);
    if ($email === '' || $password === '') {
        send_json(array('error' => '请输入邮箱和密码'), 400);
    }

    $row = q1('SELECT id, name, pw_hash, banned FROM users WHERE email = ?', array($email));
    if (!$row || !password_verify($password, $row['pw_hash'])) {
        send_json(array('error' => '邮箱或密码错误'), 401);
    }
    if ((int)$row['banned'] === 1) {
        send_json(array('error' => '账号已被封禁，如有疑问请联系管理员'), 403);
    }
    $uid = (int)$row['id'];

    list($token, ) = create_session($uid, $remember);
    // 勾选“记住我”→ 30 天有效；否则仅当前浏览器会话
    $cookie = $remember ? build_cookie($token, 30 * DAY) : build_cookie($token);
    send_json(
        array('ok' => true, 'user' => array('id' => $uid, 'email' => $email, 'name' => $row['name'])),
        200,
        $cookie
    );
}

// ---------- 退出 ----------
function handle_logout() {
    $user = current_user();
    if ($user) {
        destroy_session($user['token']);
    }
    send_json(array('ok' => true), 200, build_cookie('', 0));
}

// ---------- 当前用户 ----------
function handle_me() {
    $user = current_user();
    if (!$user) {
        send_json(array('error' => '未登录'), 401);
    }
    $extra = q1('SELECT verify_type, verify_title, coins FROM users WHERE id = ?', array($user['id']));
    send_json(array('user' => array(
        'id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'is_admin' => is_admin($user['id']) ? 1 : 0,
        'verify_type' => $extra ? $extra['verify_type'] : '',
        'verify_title' => $extra ? $extra['verify_title'] : '',
        'coins' => $extra ? (int)$extra['coins'] : 0,
    )));
}

// ---------- 编辑资料 ----------
function handle_profile($user) {
    $data = read_json();
    $name = trim(strval(isset($data['name']) ? $data['name'] : ''));
    $bio = trim(strval(isset($data['bio']) ? $data['bio'] : ''));
    $name_len = mb_strlen($name, 'UTF-8');
    if ($name_len < 2 || $name_len > 20) {
        send_json(array('error' => '昵称需为 2-20 个字符'), 400);
    }
    if (mb_strlen($bio, 'UTF-8') > 200) {
        send_json(array('error' => '简介不能超过 200 个字符'), 400);
    }
    $stmt = db()->prepare('UPDATE users SET name = ?, bio = ? WHERE id = ?');
    $stmt->execute(array($name, $bio, $user['id']));
    send_json(array(
        'ok' => true,
        'user' => array('id' => $user['id'], 'email' => $user['email'], 'name' => $name, 'bio' => $bio),
    ));
}

// ---------- 搜索用户 ----------
function handle_users_search() {
    $kw = trim(strval(isset($_GET['q']) ? $_GET['q'] : ''));
    if ($kw === '') {
        send_json(array('users' => array()));
    }
    $like = '%' . $kw . '%';
    $rows = q(
        'SELECT u.id, u.name, u.email, u.bio, u.verify_type, u.verify_title,
         (SELECT COUNT(*) FROM follows f WHERE f.followee_id = u.id) + u.followers_delta AS followers
         FROM users u WHERE u.name LIKE ? OR u.email LIKE ?
         ORDER BY u.id LIMIT 20',
        array($like, $like)
    );
    $users = array();
    foreach ($rows as $r) {
        $users[] = array(
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'email' => $r['email'],
            'bio' => $r['bio'] !== null ? $r['bio'] : '',
            'verify_type' => $r['verify_type'],
            'verify_title' => $r['verify_title'],
            'followers' => (int)$r['followers'],
        );
    }
    send_json(array('users' => $users));
}

// ---------- 用户主页 ----------
function handle_user_detail() {
    $viewer = current_user();
    $uid = intval(isset($_GET['id']) ? $_GET['id'] : 0);
    if ($uid <= 0) {
        send_json(array('error' => '用户不存在'), 404);
    }
    $row = q1('SELECT id, name, email, bio, created_at, verify_type, verify_title, followers_delta FROM users WHERE id = ?', array($uid));
    if (!$row) {
        send_json(array('error' => '用户不存在'), 404);
    }
    $rows = q(
        'SELECT p.id, p.title, p.content, p.created_at,
         (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count
         FROM posts p WHERE p.user_id = ? ORDER BY p.created_at DESC, p.id DESC',
        array($uid)
    );
    $posts = array();
    foreach ($rows as $p) {
        $posts[] = array(
            'id' => (int)$p['id'],
            'title' => $p['title'],
            'content' => $p['content'],
            'created_at' => (float)$p['created_at'],
            'like_count' => (int)$p['like_count'],
        );
    }
    $followers = (int)q1('SELECT COUNT(*) AS c FROM follows WHERE followee_id = ?', array($uid))['c']
        + (int)$row['followers_delta'];
    $following = (int)q1('SELECT COUNT(*) AS c FROM follows WHERE follower_id = ?', array($uid))['c'];
    $is_following = false;
    if ($viewer && $viewer['id'] !== $uid) {
        $is_following = (bool)q1(
            'SELECT 1 AS x FROM follows WHERE follower_id = ? AND followee_id = ?',
            array($viewer['id'], $uid)
        );
    }
    send_json(array(
        'user' => array(
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'bio' => $row['bio'] !== null ? $row['bio'] : '',
            'verify_type' => $row['verify_type'],
            'verify_title' => $row['verify_title'],
            'created_at' => (float)$row['created_at'],
        ),
        'posts' => $posts,
        'followers' => $followers,
        'following' => $following,
        'is_following' => $is_following,
        'is_self' => (bool)($viewer && $viewer['id'] === $uid),
    ));
}

// ---------- 关注/取关 ----------
function handle_follow($user) {
    $data = read_json();
    $fid = intval(isset($data['user_id']) ? $data['user_id'] : 0);
    if ($fid <= 0) {
        send_json(array('error' => '用户不存在'), 404);
    }
    if ($fid === $user['id']) {
        send_json(array('error' => '不能关注自己'), 400);
    }
    if (!q1('SELECT 1 AS x FROM users WHERE id = ?', array($fid))) {
        send_json(array('error' => '用户不存在'), 404);
    }
    $row = q1(
        'SELECT 1 AS x FROM follows WHERE follower_id = ? AND followee_id = ?',
        array($user['id'], $fid)
    );
    if ($row) {
        $stmt = db()->prepare('DELETE FROM follows WHERE follower_id = ? AND followee_id = ?');
        $stmt->execute(array($user['id'], $fid));
        $following = false;
    } else {
        $stmt = db()->prepare('INSERT INTO follows(follower_id, followee_id) VALUES(?, ?)');
        $stmt->execute(array($user['id'], $fid));
        $following = true;
        // 成就：被关注者真实粉丝数 >= 10；关注者首次关注他人
        $real = (int)q1('SELECT COUNT(*) AS c FROM follows WHERE followee_id = ?', array($fid))['c'];
        if ($real >= 10) {
            award_achievement($fid, 'followers_10');
        }
        $mine = (int)q1('SELECT COUNT(*) AS c FROM follows WHERE follower_id = ?', array($user['id']))['c'];
        if ($mine === 1) {
            award_achievement($user['id'], 'first_follow');
        }
    }
    send_json(array('ok' => true, 'following' => $following));
}

// ---------- 帖子列表 ----------
function handle_posts_list() {
    $viewer = current_user();
    $rows = q(
        'SELECT p.id, p.title, p.content, p.created_at, u.id AS author_id, u.name AS author_name,
         u.verify_type, u.verify_title,
         (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
         (SELECT COUNT(*) FROM replies rp WHERE rp.post_id = p.id) AS reply_count
         FROM posts p JOIN users u ON u.id = p.user_id
         WHERE u.banned = 0
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $vid = $viewer ? $viewer['id'] : 0;
    $liked_ids = array();
    if ($vid) {
        foreach (q('SELECT post_id FROM likes WHERE user_id = ?', array($vid)) as $r) {
            $liked_ids[(int)$r['post_id']] = true;
        }
    }
    $posts = array();
    foreach ($rows as $r) {
        $pid = (int)$r['id'];
        $posts[] = array(
            'id' => $pid,
            'title' => $r['title'],
            'content' => $r['content'],
            'created_at' => (float)$r['created_at'],
            'author_id' => (int)$r['author_id'],
            'author_name' => $r['author_name'],
            'verify_type' => $r['verify_type'],
            'verify_title' => $r['verify_title'],
            'like_count' => (int)$r['like_count'],
            'reply_count' => (int)$r['reply_count'],
            'liked' => isset($liked_ids[$pid]),
            'is_own' => (bool)($vid && (int)$r['author_id'] === $vid),
        );
    }
    send_json(array('posts' => $posts));
}

// ---------- 发帖 ----------
function handle_post_create($user) {
    $data = read_json();
    $title = trim(strval(isset($data['title']) ? $data['title'] : ''));
    $content = trim(strval(isset($data['content']) ? $data['content'] : ''));
    $title_len = mb_strlen($title, 'UTF-8');
    if ($title_len < 1 || $title_len > 80) {
        send_json(array('error' => '标题需为 1-80 个字符'), 400);
    }
    $content_len = mb_strlen($content, 'UTF-8');
    if ($content_len < 1 || $content_len > 2000) {
        send_json(array('error' => '内容需为 1-2000 个字符'), 400);
    }
    // 双限流（管理员豁免）：当日（MySQL 本地日期）最多 10 条；距上一条帖子不足 5 分钟不可再发
    if (!is_admin($user['id'])) {
        $today = (int)q1(
            'SELECT COUNT(*) AS c FROM posts WHERE user_id = ? AND created_at >= UNIX_TIMESTAMP(CURDATE())',
            array($user['id'])
        )['c'];
        if ($today >= 10) {
            send_json(array('error' => '今日发帖次数已用完（每天最多10条），明天再来吧'), 429);
        }
        $last = q1(
            'SELECT created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            array($user['id'])
        );
        if ($last && (microtime(true) - (float)$last['created_at']) < 300) {
            send_json(array('error' => '发帖太频繁，5分钟内只能发布一次，请稍后再试'), 429);
        }
    }
    $stmt = db()->prepare('INSERT INTO posts(user_id, title, content, created_at) VALUES(?, ?, ?, ?)');
    $stmt->execute(array($user['id'], $title, $content, microtime(true)));
    $pid = (int)db()->lastInsertId();
    // 成就：第一条帖子 / 累计 10 条帖子
    $pc = (int)q1('SELECT COUNT(*) AS c FROM posts WHERE user_id = ?', array($user['id']))['c'];
    if ($pc === 1) {
        award_achievement($user['id'], 'first_post');
    }
    if ($pc >= 10) {
        award_achievement($user['id'], 'post_10');
    }
    send_json(array('ok' => true, 'id' => $pid));
}

// ---------- 删帖 ----------
function handle_post_delete($user) {
    $data = read_json();
    $pid = intval(isset($data['id']) ? $data['id'] : 0);
    if ($pid <= 0) {
        send_json(array('error' => '帖子不存在'), 404);
    }
    $row = q1('SELECT user_id FROM posts WHERE id = ?', array($pid));
    if (!$row) {
        send_json(array('error' => '帖子不存在'), 404);
    }
    if ((int)$row['user_id'] !== $user['id'] && !is_admin($user['id'])) {
        send_json(array('error' => '只能删除自己的帖子'), 403);
    }
    $stmt = db()->prepare('DELETE FROM posts WHERE id = ?');
    $stmt->execute(array($pid));
    $stmt = db()->prepare('DELETE FROM likes WHERE post_id = ?');
    $stmt->execute(array($pid));
    send_json(array('ok' => true));
}

// ---------- 点赞/取消 ----------
function handle_post_like($user) {
    $data = read_json();
    $pid = intval(isset($data['id']) ? $data['id'] : 0);
    if ($pid <= 0) {
        send_json(array('error' => '帖子不存在'), 404);
    }
    $post = q1('SELECT user_id FROM posts WHERE id = ?', array($pid));
    if (!$post) {
        send_json(array('error' => '帖子不存在'), 404);
    }
    $row = q1(
        'SELECT 1 AS x FROM likes WHERE user_id = ? AND post_id = ?',
        array($user['id'], $pid)
    );
    if ($row) {
        $stmt = db()->prepare('DELETE FROM likes WHERE user_id = ? AND post_id = ?');
        $stmt->execute(array($user['id'], $pid));
        $liked = false;
    } else {
        $stmt = db()->prepare('INSERT INTO likes(user_id, post_id) VALUES(?, ?)');
        $stmt->execute(array($user['id'], $pid));
        $liked = true;
        // 成就：作者总获赞数 >= 10
        $author_id = (int)$post['user_id'];
        $total = (int)q1(
            'SELECT COUNT(*) AS c FROM likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id = ?',
            array($author_id)
        )['c'];
        if ($total >= 10) {
            award_achievement($author_id, 'likes_10');
        }
    }
    $count = (int)q1('SELECT COUNT(*) AS c FROM likes WHERE post_id = ?', array($pid))['c'];
    send_json(array('ok' => true, 'liked' => $liked, 'like_count' => $count));
}

// ---------- 回复 ----------
function handle_reply_create($user) {
    $data = read_json();
    $pid = intval(isset($data['post_id']) ? $data['post_id'] : 0);
    $content = trim(strval(isset($data['content']) ? $data['content'] : ''));
    if (!q1('SELECT 1 AS x FROM posts WHERE id = ?', array($pid))) {
        send_json(array('error' => '帖子不存在'), 404);
    }
    $len = mb_strlen($content, 'UTF-8');
    if ($len < 1 || $len > 200) {
        send_json(array('error' => '回复内容需为1-200个字'), 400);
    }
    $stmt = db()->prepare('INSERT INTO replies(post_id, user_id, content) VALUES(?, ?, ?)');
    $stmt->execute(array($pid, $user['id'], $content));
    $rid = (int)db()->lastInsertId();
    $row = q1(
        'SELECT r.id, r.post_id, r.user_id, r.content, r.created_at, u.name AS user_name
         FROM replies r JOIN users u ON u.id = r.user_id WHERE r.id = ?',
        array($rid)
    );
    send_json(array('ok' => true, 'reply' => array(
        'id' => (int)$row['id'],
        'post_id' => (int)$row['post_id'],
        'user_id' => (int)$row['user_id'],
        'user_name' => $row['user_name'],
        'content' => $row['content'],
        'created_at' => strval($row['created_at']),
    )));
}

function handle_replies_list() {
    $viewer = current_user();
    $pid = intval(isset($_GET['post_id']) ? $_GET['post_id'] : 0);
    $rows = q(
        'SELECT r.id, r.post_id, r.user_id, r.content, r.created_at,
         u.name AS user_name, u.verify_type, u.verify_title
         FROM replies r JOIN users u ON u.id = r.user_id
         WHERE r.post_id = ? AND u.banned = 0
         ORDER BY r.id ASC',
        array($pid)
    );
    $vid = $viewer ? $viewer['id'] : 0;
    $admin = $vid ? is_admin($vid) : false;
    $replies = array();
    foreach ($rows as $r) {
        $replies[] = array(
            'id' => (int)$r['id'],
            'post_id' => (int)$r['post_id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'verify_type' => $r['verify_type'],
            'verify_title' => $r['verify_title'],
            'content' => $r['content'],
            'created_at' => strval($r['created_at']),
            'can_delete' => ($vid && ((int)$r['user_id'] === $vid || $admin)) ? 1 : 0,
        );
    }
    send_json(array('replies' => $replies));
}

function handle_reply_delete($user) {
    $data = read_json();
    $id = intval(isset($data['id']) ? $data['id'] : 0);
    $row = q1('SELECT id, user_id FROM replies WHERE id = ?', array($id));
    if (!$row) {
        send_json(array('error' => '回复不存在'), 404);
    }
    if ((int)$row['user_id'] !== $user['id'] && !is_admin($user['id'])) {
        send_json(array('error' => '只能删除自己的回复'), 403);
    }
    $stmt = db()->prepare('DELETE FROM replies WHERE id = ?');
    $stmt->execute(array($id));
    send_json(array('ok' => true));
}

// ---------- 公告 ----------
function handle_announcement() {
    $row = q1('SELECT content FROM announcement WHERE id = 1');
    send_json(array('content' => $row && $row['content'] !== null ? $row['content'] : ''));
}

function handle_admin_announcement($admin) {
    $data = read_json();
    $content = strval(isset($data['content']) ? $data['content'] : '');
    $stmt = db()->prepare('INSERT INTO announcement(id, content) VALUES(1, ?) ON DUPLICATE KEY UPDATE content = VALUES(content)');
    $stmt->execute(array($content));
    send_json(array('ok' => true));
}

// ---------- V 认证申请 ----------
function handle_verify_apply($user) {
    $data = read_json();
    $type = strval(isset($data['type']) ? $data['type'] : '');
    $title = trim(strval(isset($data['title']) ? $data['title'] : ''));
    $reason = trim(strval(isset($data['reason']) ? $data['reason'] : ''));
    if (!in_array($type, array('blue', 'white', 'yellow'), true)) {
        send_json(array('error' => '认证类型无效'), 400);
    }
    $tl = mb_strlen($title, 'UTF-8');
    if ($tl < 1 || $tl > 20) {
        send_json(array('error' => '认证称号需为 1-20 个字符'), 400);
    }
    if (mb_strlen($reason, 'UTF-8') > 200) {
        send_json(array('error' => '申请理由不能超过 200 个字符'), 400);
    }
    if (q1("SELECT 1 AS x FROM verify_requests WHERE user_id = ? AND status = 'pending'", array($user['id']))) {
        send_json(array('error' => '已有待审核的申请，请耐心等待'), 400);
    }
    $u = q1('SELECT verify_type FROM users WHERE id = ?', array($user['id']));
    if ($u && $u['verify_type'] !== '') {
        send_json(array('error' => '你已通过认证'), 400);
    }
    $stmt = db()->prepare('INSERT INTO verify_requests(user_id, type, title, reason, status, created_at) VALUES(?, ?, ?, ?, \'pending\', ?)');
    $stmt->execute(array($user['id'], $type, $title, $reason, microtime(true)));
    send_json(array('ok' => true));
}

function handle_verify_status($user) {
    $u = q1('SELECT verify_type, verify_title FROM users WHERE id = ?', array($user['id']));
    if ($u && $u['verify_type'] !== '') {
        send_json(array('status' => 'approved', 'verify_type' => $u['verify_type'], 'verify_title' => $u['verify_title']));
    }
    if (q1("SELECT 1 AS x FROM verify_requests WHERE user_id = ? AND status = 'pending'", array($user['id']))) {
        send_json(array('status' => 'pending', 'verify_type' => '', 'verify_title' => ''));
    }
    send_json(array('status' => 'none', 'verify_type' => '', 'verify_title' => ''));
}

function handle_verify_requests($admin) {
    $rows = q(
        'SELECT r.id, r.user_id, u.name AS user_name, r.type, r.title, r.reason, r.status, r.created_at
         FROM verify_requests r JOIN users u ON u.id = r.user_id
         ORDER BY (r.status = \'pending\') DESC, r.id DESC'
    );
    $list = array();
    foreach ($rows as $r) {
        $list[] = array(
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'],
            'type' => $r['type'],
            'title' => $r['title'],
            'reason' => $r['reason'],
            'status' => $r['status'],
            'created_at' => (float)$r['created_at'],
        );
    }
    send_json(array('requests' => $list));
}

function handle_verify_review($admin) {
    $data = read_json();
    $id = intval(isset($data['id']) ? $data['id'] : 0);
    $approve = !empty($data['approve']);
    $req = q1(
        'SELECT r.id, r.user_id, r.type, r.title, r.status, u.email AS user_email
         FROM verify_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?',
        array($id)
    );
    if (!$req) {
        send_json(array('error' => '申请不存在'), 404);
    }
    if ($req['status'] !== 'pending') {
        send_json(array('error' => '该申请已处理'), 400);
    }
    if (is_admin_email($req['user_email'])) {
        send_json(array('error' => '不能修改管理员账号'), 403);
    }
    $stmt = db()->prepare('UPDATE verify_requests SET status = ? WHERE id = ?');
    $stmt->execute(array($approve ? 'approved' : 'rejected', $id));
    if ($approve) {
        $stmt = db()->prepare('UPDATE users SET verify_type = ?, verify_title = ? WHERE id = ?');
        $stmt->execute(array($req['type'], $req['title'], (int)$req['user_id']));
        award_achievement((int)$req['user_id'], 'verified');
    }
    send_json(array('ok' => true));
}

// ---------- 会员中心 ----------
function handle_member_center($user) {
    $uid = $user['id'];
    $u = q1('SELECT coins, verify_type, verify_title, followers_delta FROM users WHERE id = ?', array($uid));
    $posts = (int)q1('SELECT COUNT(*) AS c FROM posts WHERE user_id = ?', array($uid))['c'];
    $likes = (int)q1(
        'SELECT COUNT(*) AS c FROM likes l JOIN posts p ON p.id = l.post_id WHERE p.user_id = ?',
        array($uid)
    )['c'];
    $followers = (int)q1('SELECT COUNT(*) AS c FROM follows WHERE followee_id = ?', array($uid))['c']
        + ($u ? (int)$u['followers_delta'] : 0);
    $rows = q(
        'SELECT a.id, a.name, a.description, a.coins, ua.awarded_at
         FROM achievements a
         LEFT JOIN user_achievements ua ON ua.achievement_id = a.id AND ua.user_id = ?
         ORDER BY a.id',
        array($uid)
    );
    $achievements = array();
    foreach ($rows as $r) {
        $achievements[] = array(
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'coins' => (int)$r['coins'],
            'awarded' => $r['awarded_at'] !== null ? 1 : 0,
            'awarded_at' => $r['awarded_at'] !== null ? (float)$r['awarded_at'] : null,
        );
    }
    send_json(array(
        'coins' => $u ? (int)$u['coins'] : 0,
        'verify_type' => $u ? $u['verify_type'] : '',
        'verify_title' => $u ? $u['verify_title'] : '',
        'stats' => array(
            'posts' => $posts,
            'likes_received' => $likes,
            'followers' => $followers,
        ),
        'achievements' => $achievements,
    ));
}

// ---------- 私聊 ----------
function handle_conversations($user) {
    $uid = $user['id'];
    $rows = q(
        'SELECT CASE WHEN m.from_id = ? THEN m.to_id ELSE m.from_id END AS peer_id,
         MAX(m.created_at) AS last_time
         FROM messages m WHERE m.from_id = ? OR m.to_id = ?
         GROUP BY peer_id ORDER BY last_time DESC',
        array($uid, $uid, $uid)
    );
    $conversations = array();
    foreach ($rows as $r) {
        $peer_id = (int)$r['peer_id'];
        $peer = q1('SELECT name, verify_type, verify_title FROM users WHERE id = ?', array($peer_id));
        if (!$peer) {
            continue;
        }
        $last = q1(
            'SELECT content, created_at FROM messages
             WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)
             ORDER BY created_at DESC, id DESC LIMIT 1',
            array($uid, $peer_id, $peer_id, $uid)
        );
        $conversations[] = array(
            'peer_id' => $peer_id,
            'peer_name' => $peer['name'],
            'verify_type' => $peer['verify_type'],
            'verify_title' => $peer['verify_title'],
            'last_message' => $last ? $last['content'] : '',
            'last_time' => $last ? (float)$last['created_at'] : (float)$r['last_time'],
        );
    }
    send_json(array('conversations' => $conversations));
}

function handle_chat($user) {
    $uid = $user['id'];
    $peer = intval(isset($_GET['user_id']) ? $_GET['user_id'] : 0);
    if ($peer <= 0 || !q1('SELECT 1 AS x FROM users WHERE id = ?', array($peer))) {
        send_json(array('error' => '用户不存在'), 404);
    }
    $rows = q(
        'SELECT id, from_id, content, created_at FROM messages
         WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)
         ORDER BY created_at ASC, id ASC',
        array($uid, $peer, $peer, $uid)
    );
    $messages = array();
    foreach ($rows as $r) {
        $messages[] = array(
            'id' => (int)$r['id'],
            'from_me' => (int)$r['from_id'] === $uid ? 1 : 0,
            'content' => $r['content'],
            'created_at' => (float)$r['created_at'],
        );
    }
    send_json(array('messages' => $messages));
}

function handle_send_message($user) {
    $data = read_json();
    $to = intval(isset($data['to_id']) ? $data['to_id'] : 0);
    $content = trim(strval(isset($data['content']) ? $data['content'] : ''));
    if ($to <= 0 || !q1('SELECT 1 AS x FROM users WHERE id = ?', array($to))) {
        send_json(array('error' => '用户不存在'), 404);
    }
    if ($to === $user['id']) {
        send_json(array('error' => '不能给自己发消息'), 400);
    }
    $len = mb_strlen($content, 'UTF-8');
    if ($len < 1 || $len > 500) {
        send_json(array('error' => '消息需为 1-500 个字符'), 400);
    }
    $stmt = db()->prepare('INSERT INTO messages(from_id, to_id, content, created_at) VALUES(?, ?, ?, ?)');
    $stmt->execute(array($user['id'], $to, $content, microtime(true)));
    // 成就：发送第一条私聊
    $cnt = (int)q1('SELECT COUNT(*) AS c FROM messages WHERE from_id = ?', array($user['id']))['c'];
    if ($cnt === 1) {
        award_achievement($user['id'], 'first_chat');
    }
    send_json(array('ok' => true));
}

// ---------- 未读消息 ----------
function handle_unread_count($user) {
    $c = (int)q1(
        "SELECT COUNT(*) AS c FROM messages m
         LEFT JOIN msg_reads mr ON mr.user_id = ?
         WHERE m.to_id = ? AND m.created_at > UNIX_TIMESTAMP(COALESCE(mr.last_read, '1970-01-01 00:00:00'))",
        array($user['id'], $user['id'])
    )['c'];
    send_json(array('count' => $c));
}

function handle_mark_read($user) {
    $stmt = db()->prepare(
        'INSERT INTO msg_reads(user_id, last_read) VALUES(?, NOW())
         ON DUPLICATE KEY UPDATE last_read = NOW()'
    );
    $stmt->execute(array($user['id']));
    send_json(array('ok' => true));
}

// ---------- 管理后台：用户 ----------
function handle_admin_users($admin) {
    $rows = q(
        'SELECT u.id, u.name, u.email, u.bio, u.coins, u.followers_delta,
         u.verify_type, u.verify_title, u.banned, u.is_admin, u.created_at,
         (SELECT COUNT(*) FROM follows f WHERE f.followee_id = u.id) AS followers_real
         FROM users u ORDER BY u.id'
    );
    $users = array();
    foreach ($rows as $r) {
        $real = (int)$r['followers_real'];
        $users[] = array(
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'email' => $r['email'],
            'bio' => $r['bio'] !== null ? $r['bio'] : '',
            'coins' => (int)$r['coins'],
            'followers' => $real + (int)$r['followers_delta'],
            'followers_real' => $real,
            'verify_type' => $r['verify_type'],
            'verify_title' => $r['verify_title'],
            'banned' => (int)$r['banned'],
            'is_admin' => (int)$r['is_admin'],
            'created_at' => (float)$r['created_at'],
        );
    }
    send_json(array('users' => $users));
}

function handle_admin_update_user($admin) {
    $data = read_json();
    $id = intval(isset($data['id']) ? $data['id'] : 0);
    $target = q1('SELECT id, email FROM users WHERE id = ?', array($id));
    if (!$target) {
        send_json(array('error' => '用户不存在'), 404);
    }
    if (is_admin_email($target['email'])) {
        send_json(array('error' => '不能修改管理员账号'), 403);
    }
    $sets = array();
    $args = array();
    if (isset($data['name'])) {
        $name = trim(strval($data['name']));
        $nl = mb_strlen($name, 'UTF-8');
        if ($nl < 1 || $nl > 20) {
            send_json(array('error' => '昵称需为 1-20 个字符'), 400);
        }
        $sets[] = 'name = ?';
        $args[] = $name;
    }
    if (isset($data['bio'])) {
        $bio = trim(strval($data['bio']));
        if (mb_strlen($bio, 'UTF-8') > 200) {
            send_json(array('error' => '简介不能超过 200 个字符'), 400);
        }
        $sets[] = 'bio = ?';
        $args[] = $bio;
    }
    if (isset($data['verify_type'])) {
        $vt = strval($data['verify_type']);
        if (!in_array($vt, array('', 'blue', 'white', 'yellow'), true)) {
            send_json(array('error' => '认证类型无效'), 400);
        }
        $sets[] = 'verify_type = ?';
        $args[] = $vt;
    }
    if (isset($data['verify_title'])) {
        $vtt = trim(strval($data['verify_title']));
        if (mb_strlen($vtt, 'UTF-8') > 50) {
            send_json(array('error' => '认证称号不能超过 50 个字符'), 400);
        }
        $sets[] = 'verify_title = ?';
        $args[] = $vtt;
    }
    if (isset($data['banned'])) {
        $sets[] = 'banned = ?';
        $args[] = !empty($data['banned']) ? 1 : 0;
    }
    if (isset($data['coins'])) {
        $sets[] = 'coins = ?';
        $args[] = intval($data['coins']);
    }
    if (isset($data['followers'])) {
        $real = (int)q1('SELECT COUNT(*) AS c FROM follows WHERE followee_id = ?', array($id))['c'];
        $sets[] = 'followers_delta = ?';
        $args[] = intval($data['followers']) - $real;
    }
    if (isset($data['is_admin'])) {
        $sets[] = 'is_admin = ?';
        $args[] = !empty($data['is_admin']) ? 1 : 0;
    }
    if ($sets) {
        $args[] = $id;
        $stmt = db()->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($args);
    }
    send_json(array('ok' => true));
}

// ---------- 新闻管理 ----------
function handle_news_state() {
    $hidden = array();
    foreach (q('SELECT id FROM article_hidden') as $r) {
        $hidden[] = (int)$r['id'];
    }
    $custom = array();
    foreach (q('SELECT id, cat, title, body, date FROM articles_custom ORDER BY created_at DESC, id DESC') as $r) {
        $custom[] = array(
            'id' => (int)$r['id'],
            'cat' => $r['cat'],
            'title' => $r['title'],
            'body' => $r['body'] !== null ? $r['body'] : '',
            'date' => $r['date'],
        );
    }
    send_json(array('hidden' => $hidden, 'custom' => $custom));
}

function handle_admin_news_hide($admin) {
    $data = read_json();
    $id = intval(isset($data['id']) ? $data['id'] : 0);
    if ($id <= 0) {
        send_json(array('error' => '参数错误'), 400);
    }
    if (!empty($data['hidden'])) {
        $stmt = db()->prepare('INSERT IGNORE INTO article_hidden(id) VALUES(?)');
        $stmt->execute(array($id));
    } else {
        $stmt = db()->prepare('DELETE FROM article_hidden WHERE id = ?');
        $stmt->execute(array($id));
    }
    send_json(array('ok' => true));
}

function handle_admin_news_add($admin) {
    $data = read_json();
    $cat = strval(isset($data['cat']) ? $data['cat'] : '');
    $title = trim(strval(isset($data['title']) ? $data['title'] : ''));
    $body = strval(isset($data['body']) ? $data['body'] : '');
    $date = trim(strval(isset($data['date']) ? $data['date'] : ''));
    if (!in_array($cat, array('industry', 'pic', 'rules'), true)) {
        send_json(array('error' => '分类无效'), 400);
    }
    $tl = mb_strlen($title, 'UTF-8');
    if ($tl < 1 || $tl > 100) {
        send_json(array('error' => '标题需为 1-100 个字符'), 400);
    }
    $stmt = db()->prepare('INSERT INTO articles_custom(cat, title, body, date, created_at) VALUES(?, ?, ?, ?, ?)');
    $stmt->execute(array($cat, $title, $body, $date, microtime(true)));
    send_json(array('ok' => true, 'id' => (int)db()->lastInsertId()));
}

function handle_admin_news_del($admin) {
    $data = read_json();
    $id = intval(isset($data['id']) ? $data['id'] : 0);
    if ($id <= 0) {
        send_json(array('error' => '参数错误'), 400);
    }
    $stmt = db()->prepare('DELETE FROM articles_custom WHERE id = ?');
    $stmt->execute(array($id));
    send_json(array('ok' => true));
}

// ---------- 路由 ----------
$action = isset($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'install':
        handle_install();
        break;
    case 'migrate':
        handle_migrate();
        break;
    case 'register':
        handle_register();
        break;
    case 'login':
        handle_login();
        break;
    case 'captcha':
        handle_captcha();
        break;
    case 'logout':
        handle_logout();
        break;
    case 'me':
        handle_me();
        break;
    case 'profile':
        handle_profile(require_login());
        break;
    case 'users_search':
        handle_users_search();
        break;
    case 'user':
        handle_user_detail();
        break;
    case 'follow':
        handle_follow(require_login());
        break;
    case 'posts':
        handle_posts_list();
        break;
    case 'post_create':
        handle_post_create(require_login());
        break;
    case 'post_delete':
        handle_post_delete(require_login());
        break;
    case 'post_like':
        handle_post_like(require_login());
        break;
    case 'replies':
        handle_replies_list();
        break;
    case 'reply_create':
        handle_reply_create(require_login());
        break;
    case 'reply_delete':
        handle_reply_delete(require_login());
        break;
    case 'announcement':
        handle_announcement();
        break;
    case 'admin_announcement':
        handle_admin_announcement(require_admin());
        break;
    case 'verify_apply':
        handle_verify_apply(require_login());
        break;
    case 'verify_status':
        handle_verify_status(require_login());
        break;
    case 'verify_requests':
        handle_verify_requests(require_admin());
        break;
    case 'verify_review':
        handle_verify_review(require_admin());
        break;
    case 'member_center':
        handle_member_center(require_login());
        break;
    case 'conversations':
        handle_conversations(require_login());
        break;
    case 'chat':
        handle_chat(require_login());
        break;
    case 'send_message':
        handle_send_message(require_login());
        break;
    case 'unread_count':
        handle_unread_count(require_login());
        break;
    case 'mark_read':
        handle_mark_read(require_login());
        break;
    case 'admin_users':
        handle_admin_users(require_admin());
        break;
    case 'admin_update_user':
        handle_admin_update_user(require_admin());
        break;
    case 'news_state':
        handle_news_state();
        break;
    case 'admin_news_hide':
        handle_admin_news_hide(require_admin());
        break;
    case 'admin_news_add':
        handle_admin_news_add(require_admin());
        break;
    case 'admin_news_del':
        handle_admin_news_del(require_admin());
        break;
    default:
        send_json(array('error' => '接口不存在'), 404);
}

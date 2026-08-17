<?php
/**
 * helpdesk/kb.php — عرض مقالة من Mini KB
 * GET: id (article id)
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/helpdesk_helpers.php';

page_guard('helpdesk', 'view');
global $pdo;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'مقالة غير موجودة');
    header('Location: ' . BASE_URL . '/helpdesk/index.php');
    exit;
}

// جلب المقالة
$art = $pdo->prepare("
    SELECT a.*, c.name_ar AS category_name, c.icon AS category_icon, c.color AS category_color, c.slug AS category_slug
    FROM helpdesk_articles a
    LEFT JOIN helpdesk_categories c ON c.id = a.category_id
    WHERE a.id = ? AND a.is_published = 1
");
$art->execute([$id]);
$article = $art->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    flash('error', 'مقالة غير موجودة أو غير منشورة');
    header('Location: ' . BASE_URL . '/helpdesk/index.php');
    exit;
}

// زيادة view_count
$pdo->prepare("UPDATE helpdesk_articles SET view_count = view_count + 1 WHERE id = ?")
    ->execute([$id]);

// مقالات ذات صلة (نفس التصنيف)
$related = [];
if ($article['category_id']) {
    $related = $pdo->prepare("
        SELECT id, title_ar, summary_ar
        FROM helpdesk_articles
        WHERE category_id = ? AND id != ? AND is_published = 1
        ORDER BY view_count DESC LIMIT 4
    ")->execute([$article['category_id'], $id]) ?: [];
    $related = $pdo->prepare("
        SELECT id, title_ar, summary_ar
        FROM helpdesk_articles
        WHERE category_id = ? AND id != ? AND is_published = 1
        ORDER BY view_count DESC LIMIT 4
    ");
    $related->execute([$article['category_id'], $id]);
    $related = $related->fetchAll(PDO::FETCH_ASSOC);
}

// هل المستخدم قيّم هذه المقالة؟ (بسيطة: نخزن في cookie)
$cookie_key = 'kb_voted_' . $id;
$user_vote = $_COOKIE[$cookie_key] ?? null; // 'yes' | 'no' | null

$page_title = $article['title_ar'];
$active_nav = 'helpdesk';
$flash_msgs = get_flash();

// simple markdown-ish renderer (## headings, **bold**, lists)
function render_kb_body(string $body): string {
    $body = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    // headings
    $body = preg_replace('/^## (.+)$/m', '<h3 style="font-size:14px;font-weight:900;margin:16px 0 8px;color:#0f172a">$1</h3>', $body);
    // bold
    $body = preg_replace('/\*\*(.+?)\*\*/', '<strong style="color:#0f172a">$1</strong>', $body);
    // lists (lines starting with "-")
    $body = preg_replace_callback('/(?:^- .+(?:\n|$))+?/m', function($m) {
        $items = preg_replace('/^- (.+)$/m', '<li>$1</li>', $m[0]);
        return '<ul style="margin:8px 0 8px 18px;line-height:1.7">' . $items . '</ul>';
    }, $body);
    // newlines
    $body = nl2br($body);
    return '<div style="font-size:13.5px;line-height:1.7;color:#0f172a">' . $body . '</div>';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= e($article['title_ar']) ?> — قاعدة المعرفة</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary:#4338ca; --primary-light:#eef2ff; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 900px; margin: 0 auto; padding: 16px 20px; }

        .back { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px; margin-bottom:12px; }
        .back:hover { background:#f1f5f9; }

        .article { background:#fff; border:1px solid var(--border); border-radius:14px; padding:28px 32px; margin-bottom:14px; }
        .article .breadcrumb { font-size:11.5px; color:var(--text-3); margin-bottom:10px; }
        .article .breadcrumb a { color:var(--primary); text-decoration:none; font-weight:700; }
        .article h1 { font-size:24px; font-weight:900; color:var(--text-main); line-height:1.4; margin:0 0 12px; }
        .article .meta { display:flex; flex-wrap:wrap; gap:14px; font-size:12px; color:var(--text-3); margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid var(--border); }
        .article .meta i { color:var(--primary); margin-inline-end:4px; }

        .cat-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:6px; font-size:12px; font-weight:800; }

        .summary { background:var(--primary-light); border-inline-start:4px solid var(--primary); padding:14px 18px; border-radius:10px; margin-bottom:18px; font-size:14px; line-height:1.6; color:#1e3a8a; }

        .body { font-size:14px; line-height:1.7; }

        .helpful-box { background:#f0fdf4; border:1px solid #bbf7d0; padding:16px 20px; border-radius:12px; margin-top:24px; text-align:center; }
        .helpful-box h4 { font-size:14px; font-weight:800; color:#166534; margin:0 0 10px; }
        .helpful-btn { padding:10px 22px; border:1.5px solid #cbd5e1; background:#fff; border-radius:8px; cursor:pointer; font-weight:800; font-size:12.5px; color:var(--text-2); font-family:'Tajawal'; margin:0 4px; transition:all 0.15s; }
        .helpful-btn:hover { background:#f1f5f9; }
        .helpful-btn.voted { background:#16a34a; color:#fff; border-color:#16a34a; }
        .helpful-btn.not-voted { background:#dc2626; color:#fff; border-color:#dc2626; }
        .helpful-btn:disabled { opacity:0.5; cursor:not-allowed; }

        .related { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px 22px; }
        .related h3 { font-size:14px; font-weight:900; color:var(--text-2); margin:0 0 10px; padding-bottom:8px; border-bottom:1px solid var(--border); text-transform:uppercase; letter-spacing:0.4px; }
        .related-list { list-style:none; padding:0; margin:0; display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .related-list li a { display:block; padding:10px 12px; border:1.5px solid #e2e8f0; border-radius:8px; text-decoration:none; color:var(--text-main); transition:all 0.15s; }
        .related-list li a:hover { border-color: var(--primary); background:#fafbff; }
        .related-list .rt { font-weight:800; font-size:12.5px; margin-bottom:3px; }
        .related-list .rs { font-size:11px; color:var(--text-3); line-height:1.4; }
        @media (max-width: 640px) { .related-list { grid-template-columns:1fr; } }

        .stats { display:flex; gap:14px; font-size:11.5px; color:var(--text-3); margin-top:8px; }
        .stats span { display:inline-flex; align-items:center; gap:4px; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <a href="<?= BASE_URL ?>/helpdesk/index.php" class="back">
        <i class="fa-solid fa-arrow-right"></i> العودة لنظام التذاكر
    </a>

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="banner" style="background:<?= $fm['type']==='success'?'#dcfce7':'#fee2e2' ?>;border:1px solid <?= $fm['type']==='success'?'#86efac':'#fca5a5' ?>;color:<?= $fm['type']==='success'?'#14532d':'#7f1d1d' ?>;padding:11px 16px;border-radius:8px;margin-bottom:12px;font-weight:700;font-size:12.5px">
            <?= e($fm['message']) ?>
        </div>
    <?php endforeach; ?>

    <article class="article">
        <?php if ($article['category_name']): ?>
            <div class="breadcrumb">
                <a href="<?= BASE_URL ?>/helpdesk/index.php">نظام التذاكر</a>
                <i class="fa-solid fa-chevron-left" style="margin:0 4px;font-size:10px"></i>
                <a href="<?= BASE_URL ?>/helpdesk/new.php?category=<?= (int)$article['category_id'] ?>"><?= e($article['category_name']) ?></a>
            </div>
        <?php endif; ?>

        <h1><?= e($article['title_ar']) ?></h1>

        <div class="meta">
            <?php if ($article['category_name']): ?>
                <span class="cat-pill" style="background:<?= e($article['category_color']) ?>22;color:<?= e($article['category_color']) ?>">
                    <i class="fa-solid <?= e($article['category_icon']) ?>"></i>
                    <?= e($article['category_name']) ?>
                </span>
            <?php endif; ?>
            <span><i class="fa-regular fa-calendar"></i> <?= e(date('Y-m-d', strtotime($article['created_at']))) ?></span>
            <span><i class="fa-solid fa-eye"></i> <?= (int)$article['view_count'] ?> مشاهدة</span>
            <span><i class="fa-solid fa-thumbs-up"></i> <?= (int)$article['helpful_count'] ?> مفيد</span>
        </div>

        <?php if (!empty($article['summary_ar'])): ?>
            <div class="summary"><?= e($article['summary_ar']) ?></div>
        <?php endif; ?>

        <div class="body">
            <?= render_kb_body($article['body_ar']) ?>
        </div>

        <div class="helpful-box" id="helpfulBox">
            <?php if ($user_vote): ?>
                <h4>
                    <i class="fa-solid fa-<?= $user_vote==='yes'?'thumbs-up':'thumbs-down' ?>"></i>
                    شكراً على تقييمك!
                </h4>
                <p style="font-size:12px;color:#166534;margin:0">تم تسجيل تقييمك لهذه المقالة.</p>
            <?php else: ?>
                <h4>هل كانت هذه المقالة مفيدة؟</h4>
                <button class="helpful-btn" data-vote="yes" onclick="voteHelpful(<?= (int)$id ?>, 'yes')">
                    <i class="fa-solid fa-thumbs-up"></i> نعم، مفيدة
                </button>
                <button class="helpful-btn" data-vote="no" onclick="voteHelpful(<?= (int)$id ?>, 'no')">
                    <i class="fa-solid fa-thumbs-down"></i> لا، غير مفيدة
                </button>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($related): ?>
    <div class="related">
        <h3><i class="fa-solid fa-bookmark" style="color:var(--primary);margin-inline-end:6px"></i> مقالات ذات صلة</h3>
        <ul class="related-list">
            <?php foreach ($related as $r): ?>
                <li>
                    <a href="<?= BASE_URL ?>/helpdesk/kb.php?id=<?= (int)$r['id'] ?>">
                        <div class="rt"><?= e($r['title_ar']) ?></div>
                        <?php if (!empty($r['summary_ar'])): ?>
                            <div class="rs"><?= e(mb_substr($r['summary_ar'], 0, 80, 'UTF-8')) ?></div>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

</div>
</main>
</div>

<script>
async function voteHelpful(id, vote) {
    const btns = document.querySelectorAll('.helpful-btn');
    btns.forEach(b => b.disabled = true);

    try {
        const fd = new FormData();
        fd.append('article_id', id);
        fd.append('vote', vote);
        const r = await fetch('<?= BASE_URL ?>/api/helpdesk_kb_feedback.php', {
            method: 'POST', body: fd, credentials: 'same-origin'
        });
        const data = await r.json();
        if (data.ok) {
            // Set cookie for 90 days
            document.cookie = `kb_voted_${id}=${vote}; path=/; max-age=${90*24*60*60}`;
            // Replace box with thank-you
            const box = document.getElementById('helpfulBox');
            box.innerHTML = `
                <h4><i class="fa-solid fa-${vote==='yes'?'thumbs-up':'thumbs-down'}"></i> شكراً على تقييمك!</h4>
                <p style="font-size:12px;color:#166534;margin:0">تم تسجيل تقييمك لهذه المقالة.</p>
            `;
        } else {
            alert(data.error || 'فشل التسجيل');
            btns.forEach(b => b.disabled = false);
        }
    } catch (e) {
        alert('خطأ: ' + e.message);
        btns.forEach(b => b.disabled = false);
    }
}
</script>
</body>
</html>

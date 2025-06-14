<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ユーザーのポイントを取得
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT points FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userPoints = $stmt->fetchColumn();

// 人気タグを取得（タグの使用頻度トップ10）
$tagCounts = [];
$stmt = $pdo->query("SELECT tags FROM images WHERE tags IS NOT NULL AND tags != ''");
while ($row = $stmt->fetch()) {
    // タグを「, 」で分割してカウント
    $tags = array_filter(array_map('trim', explode(', ', $row['tags'])));
    foreach ($tags as $tag) {
        if (!isset($tagCounts[$tag])) {
            $tagCounts[$tag] = 0;
        }
        $tagCounts[$tag]++;
    }
}
arsort($tagCounts);
$popularTags = array_slice($tagCounts, 0, 10);

// 検索処理
$search = $_GET['search'] ?? '';
$images = [];
$sortByLikes = false;
// 検索クエリをスペースで分割
$searchTerms = array_filter(array_map('trim', explode(' ', $search)));

// 検索条件を解析
$userIdsInclude = [];
$userIdsExclude = [];
$tagsInclude = [];
$tagsExclude = [];

foreach ($searchTerms as $term) {
    if (strpos($term, 'user_id:') === 0) {
        $userId = substr($term, strlen('user_id:'));
        if (strpos($userId, '-') === 0) {
            $userIdsExclude[] = substr($userId, 1); // -を除去
        } else {
            $userIdsInclude[] = $userId;
        }
    } elseif ($term === 'sort:likes') {
        $sortByLikes = true;
    } else {
        if (strpos($term, '-') === 0) {
            $tagsExclude[] = substr($term, 1); // -を除去
        } else {
            $tagsInclude[] = $term;
        }
    }
}

// SQLクエリを構築
$sql = "SELECT id, filename, likes, tags FROM images WHERE 1=1"; // tagsカラムも取得
$params = [];

// 投稿者（user_id）の条件
if (!empty($userIdsInclude)) {
    $placeholders = implode(',', array_fill(0, count($userIdsInclude), '?'));
    $sql .= " AND user_id IN ($placeholders)";
    $params = array_merge($params, $userIdsInclude);
}
if (!empty($userIdsExclude)) {
    $placeholders = implode(',', array_fill(0, count($userIdsExclude), '?'));
    $sql .= " AND user_id NOT IN ($placeholders)";
    $params = array_merge($params, $userIdsExclude);
}

// タグの条件（含む）
// 各タグが `tags` カラムに含まれるかをチェック
foreach ($tagsInclude as $tagInclude) {
    $sql .= " AND FIND_IN_SET(?, REPLACE(tags, ', ', ','))"; // 「, 」を「,」に変換してからFIND_IN_SET
    $params[] = $tagInclude;
}

// タグの条件（除外）
foreach ($tagsExclude as $tagExclude) {
    $sql .= " AND NOT FIND_IN_SET(?, REPLACE(tags, ', ', ','))"; // 「, 」を「,」に変換してからFIND_IN_SET
    $params[] = $tagExclude;
}

// ソート条件
$sql .= $sortByLikes ? " ORDER BY likes DESC, created_at DESC" : " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$images = $stmt->fetchAll();

// 検索結果の表示用メッセージ
$searchDisplay = '';
if ($search) {
    $parts = [];
    if (!empty($userIdsInclude)) {
        $parts[] = "投稿者: " . htmlspecialchars(implode(', ', $userIdsInclude));
    }
    if (!empty($userIdsExclude)) {
        $parts[] = "投稿者除外: " . htmlspecialchars(implode(', ', $userIdsExclude));
    }
    if (!empty($tagsInclude)) {
        $parts[] = "タグ: " . htmlspecialchars(implode(', ', $tagsInclude));
    }
    if (!empty($tagsExclude)) {
        $parts[] = "タグ除外: " . htmlspecialchars(implode(', ', $tagsExclude));
    }
    $searchDisplay = implode(' の ', $parts);
    if ($sortByLikes) {
        $searchDisplay .= " (いいね順)";
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ホーム</title>
    <link rel="stylesheet" href="Rx93style.css">
</head>
<body>
    <div class="container">
        <div class="header-container">
            <div class="nav-links">
                <a href="dashboard.php">Main</a>
                <a href="mypage.php">My Page</a>
                <a href="mylike.php">My Like</a>
                <a href="multi_upload.php">Uploads</a>
                <a href="create_emoji.php">Create Emoji</a>
                <a href="create_emoji.php">Create Emoji</a>
                <a href="edit_emoji.php">Edit Emoji</a>
                <a href="login.php">Logout</a>
            </div>
        </div>
        <form class="search-form" method="GET">
                    <input type="text" name="search" placeholder="Search">
                    <button type="submit">Search</button>
                </form>
        
        <div class="layout">
            <div class="sidebar">
                <h3>人気タグ</h3>
                <ul class="tag-list">
                    <?php foreach ($popularTags as $tag => $count): ?>
                        <li><a href="?search=<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?> (<?php echo $count; ?>)</a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="main-content">
                <h3><?php echo $search ? '検索結果: ' . $searchDisplay : ''; ?></h3>
                <div id="image-list">
                    <?php
                    $userId = $_SESSION['user_id'];
                    foreach ($images as $image) {
                        echo '<div class="image-container">';
                        echo '<a href="image_detail.php?id=' . $image['id'] . '" target="_blank">';
                        echo '<img src="Uploads/' . htmlspecialchars($image['filename']) . '" alt="投稿画像">';
                        echo '</a>';
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

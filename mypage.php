<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// ユーザーのポイントを取得
$stmt = $pdo->prepare("SELECT points FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userPoints = $stmt->fetchColumn();

// フォロワー数を取得
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE followee_id = ?");
$stmt->execute([$userId]);
$followerCount = $stmt->fetchColumn();

// フォローイング数を取得
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = ?");
$stmt->execute([$userId]);
$followingCount = $stmt->fetchColumn();

// 投稿した画像を取得
$stmt = $pdo->prepare("SELECT id, filename, likes FROM images WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$postedImages = $stmt->fetchAll();

// CSRFトークン生成
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>マイページ</title>
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
                <a href="edit_emoji.php">Edit Emoji</a>
                <a href="login.php">Logout</a>
            </div>
        </div>
        <form class="search-form" method="GET" action="dashboard.php">
            <input type="text" name="search" placeholder="Seaerch">
            <button type="submit">Search</button>
        </form>
        <div class="main-content">
            <h3>マイページ</h3>
            <div class="user-stats">
                <p>ポイント: <span class="stat"><?php echo htmlspecialchars($userPoints); ?></span></p>
                <p>フォロワー: <a href="followers.php" class="stat"><?php echo htmlspecialchars($followerCount); ?></a></p>
                <p>フォローイング: <a href="following.php" class="stat"><?php echo htmlspecialchars($followingCount); ?></a></p>
            </div>
            <h3>投稿した画像</h3>
            <div id="image-list">
                <?php if (empty($postedImages)): ?>
                    <p>投稿した画像はありません</p>
                <?php else: ?>
                    <?php foreach ($postedImages as $image): ?>
                        <div class="image-container">
                            <a href="image_detail.php?id=<?php echo $image['id']; ?>" target="_blank">
                                <img src="Uploads/<?php echo htmlspecialchars($image['filename']); ?>" alt="投稿画像">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// フォロワー一覧を取得
$stmt = $pdo->prepare("
    SELECT u.user_id
    FROM users u
    INNER JOIN follows f ON u.user_id = f.follower_id
    WHERE f.followee_id = ?
    ORDER BY u.user_id ASC
");
$stmt->execute([$userId]);
$followers = $stmt->fetchAll();

// ユーザーのポイントを取得
$stmt = $pdo->prepare("SELECT points FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$userPoints = $stmt->fetchColumn();

// CSRFトークン生成
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>フォロワー一覧</title>
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
                <a href="login.php">Logout</a>
                <a href="create_emoji.php">Create Emojis</a>
            </div>
        </div>
        <form class="search-form" method="GET" action="dashboard.php">
            <input type="text" name="search" placeholder="Seaerch">
            <button type="submit">Search</button>
        </form>
        <div class="main-content">
            <h3>フォロワー一覧</h3>
            <?php if (empty($followers)): ?>
                <p>フォロワーはいません</p>
            <?php else: ?>
                <ul class="user-list">
                    <?php foreach ($followers as $follower): ?>
                        <li>
                            <a href="dashboard.php?search=user_id:<?php echo urlencode($follower['user_id']); ?>">
                                <?php echo htmlspecialchars($follower['user_id']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a href="mypage.php" class="back-btn">マイページに戻る</a>
        </div>
    </div>
</body>
</html>
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

// いいねした画像を取得
$stmt = $pdo->prepare("
    SELECT i.id, i.filename, i.likes
    FROM images i
    INNER JOIN likes l ON i.id = l.image_id
    WHERE l.user_id = ?
    ORDER BY l.created_at DESC
");
$stmt->execute([$userId]);
$likedImages = $stmt->fetchAll();
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
        <form class="search-form" method="GET">
                    <input type="text" name="search" placeholder="Seaerch">
                    <button type="submit">Search</button>
                </form>
        
        <div class="main-content">
            <h3>いいねした画像</h3>
            <div id="image-list">
                <?php if (empty($likedImages)): ?>
                    <p>いいねした画像はありません</p>
                <?php else: ?>
                    <?php foreach ($likedImages as $image): ?>
                        <div class="image-container">
                            <a href="image_detail.php?id=<?php echo $image['id']; ?>" target="_blank">
                                <img src="Uploads/<?php echo htmlspecialchars($image['filename']); ?>" alt="いいねした画像">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</body>
</html>
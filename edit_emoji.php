<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// フォルダを取得
$stmt = $pdo->prepare("SELECT id, folder_name FROM emoji_folders WHERE user_id = ?");
$stmt->execute([$userId]);
$folders = $stmt->fetchAll();

// フォルダ名編集
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_folder']) && isset($_POST['folder_id']) && isset($_POST['folder_name'])) {
    $folderId = $_POST['folder_id'];
    $folderName = trim($_POST['folder_name']);
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $folderName)) {
        $error = "フォルダ名は3～20文字の英数字またはアンダースコアのみです";
    } else {
        $stmt = $pdo->prepare("UPDATE emoji_folders SET folder_name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderName, $folderId, $userId]);
        header("Location: edit_emoji.php?success=" . urlencode("フォルダ名を更新しました"));
        exit;
    }
}

// 絵文字編集
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_emoji']) && isset($_POST['emoji_id']) && isset($_POST['emoji_name']) && isset($_FILES['emoji_image'])) {
    $emojiId = $_POST['emoji_id'];
    $emojiName = trim($_POST['emoji_name']);
    $emojiImage = $_FILES['emoji_image'];

    if (!preg_match('/^[a-zA-Z0-9_]{3,10}$/', $emojiName)) {
        $error = "絵文字名は3～10文字の英数字またはアンダースコアのみです";
    } elseif ($emojiImage['size'] > 1024 * 1024) {
        $error = "画像サイズは1MB以下にしてください";
    } else {
        $allowedTypes = ['image/png', 'image/gif'];
        if (!in_array($emojiImage['type'], $allowedTypes)) {
            $error = "画像はPNGまたはGIFのみです";
        } else {
            $imageInfo = getimagesize($emojiImage['tmp_name']);
            if (!$imageInfo || !in_array($imageInfo[0].'x'.$imageInfo[1], ['100x100', '200x200'])) {
                $error = "画像は100x100または200x200ピクセルである必要があります";
            } else {
                $uploadDir = 'Emojis/';
                $filename = uniqid() . '.' . pathinfo($emojiImage['name'], PATHINFO_EXTENSION);
                $uploadPath = $uploadDir . $filename;

                $pdo->beginTransaction();
                try {
                    // 既存のファイルを取得して削除
                    $stmt = $pdo->prepare("SELECT filename FROM emojis WHERE id = ? AND user_id = ?");
                    $stmt->execute([$emojiId, $userId]);
                    $oldFile = $stmt->fetchColumn();
                    if ($oldFile && file_exists($uploadDir . $oldFile)) {
                        unlink($uploadDir . $oldFile);
                    }
                    if (move_uploaded_file($emojiImage['tmp_name'], $uploadPath)) {
                        $stmt = $pdo->prepare("UPDATE emojis SET emoji_name = ?, filename = ? WHERE id = ? AND user_id = ?");
                        $stmt->execute([$emojiName, $filename, $emojiId, $userId]);
                        $pdo->commit();
                        header("Location: edit_emoji.php?success=" . urlencode("絵文字を更新しました"));
                        exit;
                    } else {
                        $error = "画像のアップロードに失敗しました";
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "エラー: " . $e->getMessage();
                }
            }
        }
    }
}

// 絵文字削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_emoji']) && isset($_POST['emoji_id'])) {
    $emojiId = $_POST['delete_emoji'];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT filename FROM emojis WHERE id = ? AND user_id = ?");
        $stmt->execute([$emojiId, $userId]);
        $filename = $stmt->fetchColumn();
        if ($filename && file_exists('Emojis/' . $filename)) {
            unlink('Emojis/' . $filename);
        }
        $stmt = $pdo->prepare("DELETE FROM emojis WHERE id = ? AND user_id = ?");
        $stmt->execute([$emojiId, $userId]);
        $pdo->commit();
        header("Location: edit_emoji.php?success=" . urlencode("絵文字を削除しました"));
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "エラー: " . $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>絵文字編集</title>
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
            <h2>絵文字編集</h2>
            <?php if (isset($error)): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <p class="success"><?php echo htmlspecialchars($_GET['success']); ?></p>
            <?php endif; ?>
            <?php if (empty($folders)): ?>
                <p>フォルダがありません。<a href="create_emoji.php">絵文字を作成</a></p>
            <?php else: ?>
                <?php foreach ($folders as $folder): ?>
                    <div class="folder-section">
                        <h3>フォルダ: <?php echo htmlspecialchars($folder['folder_name']); ?></h3>
                        <form method="POST">
                            <input type="hidden" name="folder_id" value="<?php echo $folder['id']; ?>">
                            <input type="text" name="folder_name" value="<?php echo htmlspecialchars($folder['folder_name']); ?>" required>
                            <button type="submit" name="edit_folder">フォルダ名更新</button>
                        </form>
                        <?php
                        $stmt = $pdo->prepare("SELECT id, emoji_name, filename FROM emojis WHERE folder_id = ?");
                        $stmt->execute([$folder['id']]);
                        $emojis = $stmt->fetchAll();
                        ?>
                        <?php if (empty($emojis)): ?>
                            <p>このフォルダに絵文字はありません</p>
                        <?php else: ?>
                            <div class="emoji-list">
                                <?php foreach ($emojis as $emoji): ?>
                                    <div class="emoji-item">
                                        <img src="Emojis/<?php echo htmlspecialchars($emoji['filename']); ?>" class="emoji" alt="<?php echo htmlspecialchars($emoji['emoji_name']); ?>">
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="emoji_id" value="<?php echo $emoji['id']; ?>">
                                            <input type="text" name="emoji_name" value="<?php echo htmlspecialchars($emoji['emoji_name']); ?>" required>
                                            <input type="file" name="emoji_image" accept="image/png,image/gif">
                                            <button type="submit" name="edit_emoji">更新</button>
                                            <button type="submit" name="delete_emoji" value="<?php echo $emoji['id']; ?>" class="delete-btn">削除</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <a href="mypage.php" class="back-btn">マイページに戻る</a>
        </div>
    </div>
</body>
</html>
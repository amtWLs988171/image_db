<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$cost = 500; // フォルダ作成のコスト

// ユーザーのフォルダを取得
$stmt = $pdo->prepare("SELECT id, folder_name FROM emoji_folders WHERE user_id = ?");
$stmt->execute([$userId]);
$folders = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['folder_name']) && isset($_FILES['emoji_images']) && isset($_POST['emoji_names'])) {
    $folderName = trim($_POST['folder_name']);
    $emojiImages = $_FILES['emoji_images'];
    $emojiNames = $_POST['emoji_names'];
    $folderId = $_POST['folder_id'] ?? null;

    // バリデーション
    if (empty($folderName) && empty($folderId)) {
        $error = "フォルダ名または既存フォルダを選択してください";
    } elseif (count($emojiImages['name']) !== count($emojiNames)) {
        $error = "画像と絵文字名の数が一致しません";
    } else {
        $allowedTypes = ['image/png', 'image/gif'];
        $successCount = 0;
        $errorMessages = [];

        // フォルダ作成（新規の場合）
        if (empty($folderId)) {
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $folderName)) {
                $error = "フォルダ名は3～20文字の英数字またはアンダースコアのみです";
            } else {
                $stmt = $pdo->prepare("SELECT points FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $points = $stmt->fetchColumn();
                if ($points < $cost) {
                    $error = "ポイントが不足しています（必要: $cost ポイント）";
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("INSERT INTO emoji_folders (user_id, folder_name) VALUES (?, ?)");
                        $stmt->execute([$userId, $folderName]);
                        $folderId = $pdo->lastInsertId();
                        $stmt = $pdo->prepare("UPDATE users SET points = points - ? WHERE user_id = ?");
                        $stmt->execute([$cost, $userId]);
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "フォルダ作成エラー: " . $e->getMessage();
                    }
                }
            }
        }

        // 絵文字アップロード
        if (empty($error) && $folderId) {
            $uploadDir = 'Emojis/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            for ($i = 0; $i < count($emojiImages['name']); $i++) {
                $emojiName = trim($emojiNames[$i]);
                if (!preg_match('/^[a-zA-Z0-9_]{3,10}$/', $emojiName)) {
                    $errorMessages[] = "絵文字名「{$emojiName}」は3～10文字の英数字またはアンダースコアのみです";
                    continue;
                }
                if ($emojiImages['size'][$i] > 1024 * 1024) { // 1MB制限
                    $errorMessages[] = "画像「{$emojiImages['name'][$i]}」は1MB以下にしてください";
                    continue;
                }
                if (!in_array($emojiImages['type'][$i], $allowedTypes)) {
                    $errorMessages[] = "画像「{$emojiImages['name'][$i]}」はPNGまたはGIFのみです";
                    continue;
                }

                // 画像サイズチェック
                $imageInfo = getimagesize($emojiImages['tmp_name'][$i]);
                if (!$imageInfo || !in_array($imageInfo[0].'x'.$imageInfo[1], ['100x100', '200x200'])) {
                    $errorMessages[] = "画像「{$emojiImages['name'][$i]}」は100x100または200x200ピクセルである必要があります";
                    continue;
                }

                $filename = uniqid() . '.' . pathinfo($emojiImages['name'][$i], PATHINFO_EXTENSION);
                $uploadPath = $uploadDir . $filename;

                $pdo->beginTransaction();
                try {
                    if (move_uploaded_file($emojiImages['tmp_name'][$i], $uploadPath)) {
                        $stmt = $pdo->prepare("INSERT INTO emojis (folder_id, user_id, emoji_name, filename) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$folderId, $userId, $emojiName, $filename]);
                        $pdo->commit();
                        $successCount++;
                    } else {
                        $errorMessages[] = "画像「{$emojiImages['name'][$i]}」のアップロードに失敗";
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errorMessages[] = "絵文字「{$emojiName}」の保存エラー: " . $e->getMessage();
                }
            }
        }

        if ($successCount > 0) {
            header("Location: create_emoji.php?success=" . urlencode("$successCount 個の絵文字を追加しました"));
            exit;
        } elseif (!empty($errorMessages)) {
            $error = implode('<br>', $errorMessages);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>絵文字作成</title>
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
        <div class="upload-container">
            <h2>絵文字を追加</h2>
            <?php if (isset($error)): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <p class="success"><?php echo htmlspecialchars($_GET['success']); ?></p>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>フォルダを選択または新規作成:</label>
                    <select name="folder_id">
                        <option value="">新規フォルダを作成</option>
                        <?php foreach ($folders as $folder): ?>
                            <option value="<?php echo $folder['id']; ?>">
                                <?php echo htmlspecialchars($folder['folder_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="folder_name" placeholder="新規フォルダ名（作成時のみ）">
                    <p>新規フォルダ作成には<?php echo $cost; ?>ポイントが必要です</p>
                </div>
                <div class="file-input">
                    <label>絵文字画像（PNG/GIF、100x100または200x200、1MB以下）:</label>
                    <input type="file" name="emoji_images[]" id="emoji-input" multiple accept="image/png,image/gif" required>
                </div>
                <div class="preview-container" id="preview-container">
                    <!-- JavaScriptで動的に追加 -->
                </div>
                <button type="submit">追加</button>
            </form>
            <a href="mypage.php" class="back-btn">マイページに戻る</a>
        </div>
    </div>
    <script>
        document.getElementById('emoji-input').addEventListener('change', (e) => {
            const files = e.target.files;
            const previewContainer = document.getElementById('preview-container');
            previewContainer.innerHTML = '';
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                if (!file.type.startsWith('image/')) continue;

                const reader = new FileReader();
                reader.onload = (e) => {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    div.innerHTML = `
                        <img src="${e.target.result}" alt="${file.name}">
                        <input type="text" name="emoji_names[]" placeholder="絵文字名（${file.name}）" required>
                    `;
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>
<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['images'])) {
    $userId = $_SESSION['user_id'];
    $images = $_FILES['images'];
    $tags = $_POST['tags'];

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $successCount = 0;
    $errorMessages = [];

    for ($i = 0; $i < count($images['name']); $i++) {
        if (!in_array($images['type'][$i], $allowedTypes)) {
            $errorMessages[] = "ファイル " . htmlspecialchars($images['name'][$i]) . " は画像ではありません";
            continue;
        }
        $uploadDir = 'Uploads/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filename = uniqid() . '.' . pathinfo($images['name'][$i], PATHINFO_EXTENSION);
        $uploadPath = $uploadDir . $filename;
        if (move_uploaded_file($images['tmp_name'][$i], $uploadPath)) {
            $tagInput = trim($tags[$i]);
            // スペース、全角スペース、コンマ、全角コンマで分割し、空要素を除去
            $tagArray = array_filter(array_map('trim', preg_split('/[,\s　、]+/', $tagInput)));
            // 各タグ内のスペースをアンダースコアに変換（これは元のままで良いか確認）
            // もし「First Gundam」のようなタグをそのまま保存したい場合は、この行を削除
            $tagArray = array_map(function($tag) {
                return str_replace(' ', '_', $tag);
            }, $tagArray);
            // タグを「, 」で結合して保存
            $tagString = implode(', ', $tagArray);
            $stmt = $pdo->prepare("INSERT INTO images (user_id, filename, tags, likes) VALUES (?, ?, ?, 0)");
            if ($stmt->execute([$userId, $filename, $tagString])) {
                $successCount++;
                // ポイント付与: 1画像につき100ポイント
                $stmt = $pdo->prepare("UPDATE users SET points = points + 100 WHERE user_id = ?");
                $stmt->execute([$userId]);
            } else {
                $errorMessages[] = "ファイル " . htmlspecialchars($images['name'][$i]) . " のデータベース保存に失敗";
            }
        } else {
            $errorMessages[] = "ファイル " . htmlspecialchars($images['name'][$i]) . " のアップロードに失敗: コード " . $images['error'][$i];
        }
    }
    if ($successCount > 0) {
        header("Location: dashboard.php?success=" . urlencode($successCount . " 枚の画像がアップロードされました"));
        exit;
    } else {
        $error = implode('<br>', $errorMessages);
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>複数画像アップロード</title>
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
        <div class="upload-container">
            <h2>複数画像をアップロード</h2>
            <?php if (isset($error)): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="file-input">
                    <label>画像を選択（複数可）</label>
                    <input type="file" name="images[]" id="image-input" multiple accept="image/*" required>
                </div>
                <div class="preview-container" id="preview-container">
                    </div>
                <button type="submit">アップロード</button>
            </form>
            <a href="dashboard.php" class="back-btn">ダッシュボードに戻る</a>
        </div>
    </div>
    <script>
        document.getElementById('image-input').addEventListener('change', (e) => {
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
                        <input type="text" name="tags[]" placeholder="タグ（${file.name}） 例: First_Gundam, Gundam">
                    `;
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>

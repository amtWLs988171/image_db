<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json'); // JSONレスポンスを返すことを宣言

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ログインしてください']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['image_id'])) {
    echo json_encode(['success' => false, 'message' => '無効なリクエスト']);
    exit;
}

$userId = $_SESSION['user_id'];
$imageId = $_POST['image_id'];

// トランザクション開始
$pdo->beginTransaction();

try {
    // いいね状態を確認
    $stmt = $pdo->prepare("SELECT 1 FROM likes WHERE user_id = ? AND image_id = ?");
    $stmt->execute([$userId, $imageId]);
    $hasLiked = $stmt->fetch() !== false;

    if ($hasLiked) {
        // いいね解除
        $stmt = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND image_id = ?");
        $stmt->execute([$userId, $imageId]);
        $stmt = $pdo->prepare("UPDATE images SET likes = likes - 1 WHERE id = ?");
        $stmt->execute([$imageId]);
        $newHasLiked = false; // 新しいいいね状態
    } else {
        // いいね追加
        $stmt = $pdo->prepare("INSERT INTO likes (user_id, image_id) VALUES (?, ?)");
        $stmt->execute([$userId, $imageId]);
        $stmt = $pdo->prepare("UPDATE images SET likes = likes + 1 WHERE id = ?");
        $stmt->execute([$imageId]);
        $newHasLiked = true; // 新しいいいね状態
    }

    // 現在のいいね数を取得
    $stmt = $pdo->prepare("SELECT likes FROM images WHERE id = ?");
    $stmt->execute([$imageId]);
    $likes = $stmt->fetchColumn();

    $pdo->commit();

    // JSONレスポンスを準備
    $response = [
        'success' => true,
        'likes' => $likes,
        'hasLiked' => $newHasLiked
    ];
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'エラー: ' . $e->getMessage()]);
    exit;
}
?>

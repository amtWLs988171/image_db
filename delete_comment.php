<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

function json_error($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    json_error('ログインしてください');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('無効なリクエストメソッドです');
}
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    json_error('無効なリクエストです');
}

$currentUserId = $_SESSION['user_id'];
$commentId = $_POST['delete_comment'] ?? null;
$imageId = $_POST['image_id'] ?? null;

if (empty($commentId) || empty($imageId)) {
    json_error('必要な情報が不足しています');
}

try {
    // 画像の所有者とコメントの投稿者を取得
    $stmt = $pdo->prepare("SELECT user_id FROM images WHERE id = ?");
    $stmt->execute([$imageId]);
    $imageOwnerId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $commentOwnerId = $stmt->fetchColumn();

    if (!$commentOwnerId) {
        json_error('コメントが見つかりません');
    }

    // 削除権限を確認（現在のユーザーがコメント投稿者 or 画像所有者）
    if ($currentUserId === $commentOwnerId || $currentUserId === $imageOwnerId) {
        $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);

        echo json_encode(['success' => true, 'comment_id' => $commentId]);
        exit;
    } else {
        json_error('コメントを削除する権限がありません');
    }

} catch (Exception $e) {
    json_error('データベースエラー: ' . $e->getMessage());
}
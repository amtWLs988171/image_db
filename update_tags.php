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

$userId = $_SESSION['user_id'];
$imageId = $_POST['image_id'];
$tags = trim($_POST['tags'] ?? '');
$imageOwnerId = $_POST['image_owner_id'];

if ($userId !== $imageOwnerId) {
    json_error('タグを編集する権限がありません');
}
if (empty($imageId)) {
    json_error('画像IDがありません');
}

// タグの処理
$tagArray = array_filter(array_map('trim', preg_split('/[,\s　、]+/', $tags)));
$tagArray = array_map(fn($tag) => str_replace(' ', '_', $tag), $tagArray);
$tagString = implode(', ', $tagArray);

try {
    $stmt = $pdo->prepare("UPDATE images SET tags = ? WHERE id = ?");
    $stmt->execute([$tagString, $imageId]);

    // 更新後のタグリストのHTMLを生成
    $tagsHtml = '';
    if (!empty($tagArray)) {
        foreach ($tagArray as $tag) {
            $tagsHtml .= '<li><a href="dashboard.php?search=' . urlencode($tag) . '">' . htmlspecialchars($tag) . '</a></li>';
        }
    } else {
        $tagsHtml = '<li>タグがありません</li>';
    }

    echo json_encode([
        'success' => true,
        'tagsHtml' => $tagsHtml
    ]);

} catch (Exception $e) {
    json_error('データベースエラー: ' . $e->getMessage());
}
<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json'); // JSONレスポンスを返す

// エラーレスポンスを返すための関数
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
$comment = trim($_POST['comment'] ?? '');

if (empty($comment)) {
    json_error('コメントを入力してください');
}
if (empty($imageId)) {
    json_error('画像IDがありません');
}

// 全ユーザーの絵文字を取得（コメント描画用）
$stmt_emojis = $pdo->query("
    SELECT e.emoji_name, e.filename
    FROM emojis e
");
$all_emojis = $stmt_emojis->fetchAll(PDO::FETCH_ASSOC);

// 絵文字をコメントに変換する関数
function renderComment($comment, $emojis) {
    $output = htmlspecialchars($comment);
    foreach ($emojis as $emoji) {
        $pattern = '/:'.preg_quote($emoji['emoji_name'], '/').':/';
        $replacement = '<img src="Emojis/'.htmlspecialchars($emoji['filename']).'" class="emoji" alt="'.htmlspecialchars($emoji['emoji_name']).'">';
        $output = preg_replace($pattern, $replacement, $output);
    }
    return $output;
}


$pdo->beginTransaction();
try {
    // コメントをDBに挿入
    $stmt = $pdo->prepare("INSERT INTO comments (image_id, user_id, comment) VALUES (?, ?, ?)");
    $stmt->execute([$imageId, $userId, $comment]);
    $commentId = $pdo->lastInsertId();

    // ポイント付与: コメント1回につき30ポイント（画像ごと）
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE image_id = ? AND user_id = ?");
    $stmt_check->execute([$imageId, $userId]);
    if ($stmt_check->fetchColumn() == 1) { // この投稿が初回コメントの場合
        $stmt_update = $pdo->prepare("UPDATE users SET points = points + 30 WHERE user_id = ?");
        $stmt_update->execute([$userId]);
    }

    $pdo->commit();

    // 成功レスポンスを返す
    echo json_encode([
        'success' => true,
        'comment' => [
            'id' => $commentId,
            'commenter' => htmlspecialchars($userId),
            'html' => renderComment($comment, $all_emojis),
            'image_owner_id' => $_POST['image_owner_id'] // 削除ボタン表示判定用
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    json_error('データベースエラー: ' . $e->getMessage());
}
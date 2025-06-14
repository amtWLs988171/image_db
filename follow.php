<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['followee_id']) || !isset($_POST['csrf_token'])) {
    die("無効なリクエスト");
}

// CSRFトークン検証
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("CSRFトークンが無効です");
}

$userId = $_SESSION['user_id'];
$followeeId = $_POST['followee_id'];

if ($userId === $followeeId) {
    die("自分自身をフォローできません");
}

// トランザクション開始
$pdo->beginTransaction();

try {
    // フォロー状態を確認
    $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?");
    $stmt->execute([$userId, $followeeId]);
    $isFollowing = $stmt->fetch() !== false;

    if ($isFollowing) {
        // フォロー解除
        $stmt = $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND followee_id = ?");
        $stmt->execute([$userId, $followeeId]);
    } else {
        // フォロー追加
        $stmt = $pdo->prepare("INSERT INTO follows (follower_id, followee_id) VALUES (?, ?)");
        $stmt->execute([$userId, $followeeId]);
    }

    $pdo->commit();
    header("Location: " . $_SERVER['HTTP_REFERER'] ?: "dashboard.php");
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    die("エラー: " . $e->getMessage());
}
?>
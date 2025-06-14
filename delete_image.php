<?php
session_start();
require_once 'db_connect.php';

// 로그인하지 않은 사용자는 login.php로 리디렉션
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 削除する画像IDがPOSTパラメータで送信されたか確認
// GETパラメータからPOSTパラメータに変更
if (!isset($_POST['id'])) {
    // エラーメッセージをセッションに保存してリダイレクト
    $_SESSION['error_message'] = "削除する画像のIDが指定されていません。";
    header("Location: mypage.php"); // または image_detail.php?id=...
    exit;
}

// CSRFトークン検証
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_message'] = "無効なリクエストです。";
    header("Location: mypage.php"); // または image_detail.php?id=...
    exit;
}

$imageId = $_POST['id']; // GETからPOSTに変更
$currentUserId = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // イメージ情報 가져오기 (특히 파일명과 소유자 ID)
    $stmt = $pdo->prepare("SELECT user_id, filename FROM images WHERE id = ?");
    $stmt->execute([$imageId]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$image) {
        $_SESSION['error_message'] = "画像が見つかりません。";
        header("Location: mypage.php");
        exit;
    }

    // 현재 로그인한 사용자가 이미지 소유자인지 확인
    if ($image['user_id'] !== $currentUserId) {
        $_SESSION['error_message'] = "この画像を削除する権限がありません。";
        header("Location: mypage.php");
        exit;
    }

    // 1. 서버에서 실제 이미지 파일 삭제
    $filePath = 'Uploads/' . $image['filename'];
    if (file_exists($filePath)) {
        if (!unlink($filePath)) {
            // 파일 삭제 실패 시 롤백 및 오류 메시지
            $pdo->rollBack();
            $_SESSION['error_message'] = "ファイルの削除に失敗しました。";
            header("Location: mypage.php");
            exit;
        }
    } else {
        // 파일이 존재하지 않는 경우 (DB에는 있지만 실제 파일이 없을 때)
        // 일단 DB에서는 삭제하도록 진행하거나, 여기서 오류 처리할 수 있습니다.
        // 여기서는 로그를 남기거나 관리자에게 알리는 등의 처리를 할 수 있습니다.
        error_log("ファイルが見つかりませんでしたが、DBからの削除を試みます: " . $filePath);
    }

    // 2. `likes` 테이블에서 관련 좋아요 삭제
    $stmt = $pdo->prepare("DELETE FROM likes WHERE image_id = ?");
    $stmt->execute([$imageId]);

    // 3. `comments` 테이블에서 관련 댓글 삭제
    $stmt = $pdo->prepare("DELETE FROM comments WHERE image_id = ?");
    $stmt->execute([$imageId]);

    // 4. `images` 테이블에서 이미지 레코드 삭제
    $stmt = $pdo->prepare("DELETE FROM images WHERE id = ?");
    $stmt->execute([$imageId]);

    $pdo->commit();

    // 삭제 성공 후、マイページまたはダッシュボードへリダイレクト
    $_SESSION['success_message'] = "画像を削除しました。";
    header("Location: mypage.php");
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['error_message'] = "データベースエラー: " . $e->getMessage();
    header("Location: mypage.php");
    exit;
} catch (Exception $e) {
    // 기타 예외 처리
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_message'] = "エラーが発生しました: " . $e->getMessage();
    header("Location: mypage.php");
    exit;
}
?>

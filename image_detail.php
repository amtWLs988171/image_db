<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}

$userId = $_SESSION['user_id'];
$imageId = $_GET['id'];

// 画像情報を取得
$stmt = $pdo->prepare("SELECT i.*, u.user_id AS owner FROM images i JOIN users u ON i.user_id = u.user_id WHERE i.id = ?");
$stmt->execute([$imageId]);
$image = $stmt->fetch();
if (!$image) {
    header("Location: dashboard.php");
    exit;
}

// いいね状態を確認
$stmt = $pdo->prepare("SELECT 1 FROM likes WHERE user_id = ? AND image_id = ?");
$stmt->execute([$userId, $imageId]);
$hasLiked = $stmt->fetch() !== false;

// フォロー状態を確認
$stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?");
$stmt->execute([$userId, $image['user_id']]);
$isFollowing = $stmt->fetch() !== false;

// 絵文字を取得（フォルダごと）
$stmt = $pdo->prepare("
    SELECT e.emoji_name, e.filename, f.folder_name
    FROM emojis e
    JOIN emoji_folders f ON e.folder_id = f.id
    WHERE e.user_id = ?
    ORDER BY f.folder_name, e.emoji_name
");
$stmt->execute([$userId]);
$emojis = $stmt->fetchAll();

// コメントを取得
try {
    $stmt = $pdo->prepare("SELECT c.*, u.user_id AS commenter FROM comments c JOIN users u ON c.user_id = u.user_id WHERE c.image_id = ? ORDER BY c.created_at DESC");
    $stmt->execute([$imageId]);
    $comments = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "コメントの取得に失敗しました: " . $e->getMessage();
    $comments = [];
}

// コメント投稿処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "無効なリクエストです";
    } else {
        $comment = trim($_POST['comment']);
        if (!empty($comment)) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO comments (image_id, user_id, comment) VALUES (?, ?, ?)");
                $stmt->execute([$imageId, $userId, $comment]);
                // ポイント付与: コメント1回につき30ポイント（画像ごと）
                $stmt = $pdo->prepare("SELECT 1 FROM comments WHERE image_id = ? AND user_id = ?");
                $stmt->execute([$imageId, $userId]);
                if ($stmt->fetchColumn() == 1) { // 初コメントの場合
                    $stmt = $pdo->prepare("UPDATE users SET points = points + 30 WHERE user_id = ?");
                    $stmt->execute([$userId]);
                }
                $pdo->commit();
                header("Location: image_detail.php?id=$imageId");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "エラー: " . $e->getMessage();
            }
        } else {
            $error = "コメントを入力してください";
        }
    }
}

// タグ編集処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tags']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "無効なリクエストです";
    } elseif ($userId !== $image['user_id']) {
        $error = "タグを編集する権限がありません";
    } else {
        $tags = trim($_POST['tags']);
        // スペース、全角スペース、コンマ、全角コンマで分割し、空要素を除去
        $tagArray = array_filter(array_map('trim', preg_split('/[,\s　、]+/', $tags)));
        // 各タグ内のスペースをアンダースコアに変換（必要であれば）
        $tagArray = array_map(function($tag) {
            return str_replace(' ', '_', $tag);
        }, $tagArray);
        // タグを「, 」で結合して保存
        $tagString = implode(', ', $tagArray);
        try {
            $stmt = $pdo->prepare("UPDATE images SET tags = ? WHERE id = ?");
            $stmt->execute([$tagString, $imageId]);
            header("Location: image_detail.php?id=$imageId");
            exit;
        } catch (Exception $e) {
            $error = "エラー: " . $e->getMessage();
        }
    }
}

// コメント削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "無効なリクエストです";
    } else {
        $commentId = $_POST['delete_comment'];
        $stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        if ($comment && ($comment['user_id'] === $userId || $userId === $image['user_id'])) {
            try {
                $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
                $stmt->execute([$commentId]);
                header("Location: image_detail.php?id=$imageId");
                exit;
            } catch (Exception $e) {
                $error = "エラー: " . $e->getMessage();
            }
        } else {
            $error = "コメントを削除する権限がありません";
        }
    }
}

// CSRFトークン生成
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// 絵文字をコメントに変換
function renderComment($comment, $emojis) {
    $output = htmlspecialchars($comment);
    foreach ($emojis as $emoji) {
        $pattern = '/:'.preg_quote($emoji['emoji_name'], '/').':/';
        $replacement = '<img src="Emojis/'.htmlspecialchars($emoji['filename']).'" class="emoji" alt="'.htmlspecialchars($emoji['emoji_name']).'">';
        $output = preg_replace($pattern, $replacement, $output);
    }
    return $output;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>画像詳細</title>
    <link rel="stylesheet" href="Rx93style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* カスタムスタイル: 絵文字選択肢の表示 */
        .selected-emoji-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            min-height: 100px; /* 絵文字の高さに合わせてスペースを確保 */
            padding: 5px;
            border: 1px dashed #38444d; /* プレビューエリアのボーダー */
            border-radius: 6px;
            background-color: #1a1a1a;
        }
        .selected-emoji-preview img {
            width: 100px; /* プレビュー画像のサイズを100x100に */
            height: 100px;
            vertical-align: middle;
            border-radius: 8px; /* 角丸 */
        }
        /* いいねボタンのスタイル */
        .like-button-container {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
            justify-content: center;
        }
        .like-button {
            background: none;
            border: none;
            color: #8899a6; /* デフォルトのハート色 */
            font-size: 24px; /* ハートアイコンのサイズ */
            cursor: pointer;
            transition: color 0.2s ease, transform 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .like-button.liked .fa-heart {
            color: #e0245e; /* いいね済みはピンク */
        }
        .like-button:hover .fa-heart {
            color: #e0245e; /* ホバー時もピンク */
            transform: scale(1.1);
        }
        .likes-count {
            color: #8899a6;
            font-size: 16px;
        }

        /* 画像管理ボタンのスタイル */
        .manage-image-btn {
            background: none;
            border: none;
            color: #8899a6;
            font-size: 24px;
            cursor: pointer;
            margin-top: 15px;
            transition: color 0.2s ease;
        }
        .manage-image-btn:hover {
            color: #1da1f2; /* Xのブランドカラー */
        }

        /* モーダル関連のスタイル */
        .modal {
            display: none; /* 初期状態では非表示 */
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7); /* 半透明の背景 */
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #1a1a1a;
            margin: auto;
            padding: 30px;
            border: 1px solid #2f3336;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
            position: relative;
            text-align: left;
        }

        .close-button {
            color: #8899a6;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 10px;
            right: 20px;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .close-button:hover,
        .close-button:focus {
            color: #e0e0e0;
            text-decoration: none;
            cursor: pointer;
        }

        .modal-content h3 {
            color: #e0e0e0;
            margin-bottom: 20px;
            text-align: center;
        }

        .modal-content form {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #2f3336;
        }

        .modal-content form:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .modal-content label {
            display: block;
            margin-bottom: 8px;
            color: #bbbbbb;
            font-size: 15px;
        }
        .modal-content input[type="text"] {
            max-width: 100%;
            margin-bottom: 15px;
        }
        .modal-content button {
            width: 100%;
            margin-top: 10px;
        }
    </style>
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
            <input type="text" name="search" placeholder="Search">
            <button type="submit">Search</button>
        </form>
        <div class="detail-container">
            <?php if (isset($error)): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endif; ?>
            <img src="Uploads/<?php echo htmlspecialchars($image['filename']); ?>" alt="画像">
            <p>投稿者: <a href="dashboard.php?search=user_id:<?php echo urlencode($image['owner']); ?>"><?php echo htmlspecialchars($image['owner']); ?></a></p>

            <div class="like-button-container">
                <button type="button" id="like-button" class="like-button <?php echo $hasLiked ? 'liked' : ''; ?>" data-image-id="<?php echo $imageId; ?>">
                    <i class="fas fa-heart"></i> <span id="likes-count"><?php echo $image['likes']; ?></span>
                </button>
            </div>

            <?php if ($userId === $image['user_id']): ?>
                <button type="button" id="manage-image-btn" class="manage-image-btn">
                    <i class="fas fa-ellipsis-h"></i> </button>
            <?php endif; ?>

            <div class="tag-list">
                <?php if (!empty($image['tags'])): ?>
                    <?php
                    // タグを「, 」で分割して表示
                    $displayTags = explode(', ', $image['tags']);
                    foreach ($displayTags as $tag):
                    ?>
                        <li><a href="dashboard.php?search=<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li>タグがありません</li>
                <?php endif; ?>
            </div>
            <div class="comment-section">
                <h3>コメント</h3>
                <form class="comment-form" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <textarea name="comment" id="comment-textarea" placeholder="コメントを入力"></textarea>
                    <select name="emoji_selector" id="emoji-selector">
                        <option value="">絵文字を選択</option>
                        <?php
                        $currentFolder = '';
                        foreach ($emojis as $emoji):
                            if ($emoji['folder_name'] !== $currentFolder):
                                if ($currentFolder !== ''): ?>
                                    </optgroup>
                                <?php endif;
                                $currentFolder = $emoji['folder_name']; ?>
                                <optgroup label="<?php echo htmlspecialchars($emoji['folder_name']); ?>">
                            <?php endif; ?>
                            <option value="<?php echo htmlspecialchars($emoji['emoji_name']); ?>" data-filename="<?php echo htmlspecialchars($emoji['filename']); ?>">
                                <?php echo htmlspecialchars($emoji['emoji_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentFolder !== ''): ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <div id="selected-emoji-preview" class="selected-emoji-preview"></div>
                    <button type="submit">コメント投稿</button>
                </form>
                <div class="comment-list">
                    <?php if (empty($comments)): ?>
                        <p>コメントがありません</p>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item">
                                <p><strong><?php echo htmlspecialchars($comment['commenter']); ?>:</strong> <?php echo renderComment($comment['comment'], $emojis); ?></p>
                                <?php if ($comment['user_id'] === $userId || $userId === $image['user_id']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="delete_comment" value="<?php echo $comment['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <button type="submit" class="comment-delete-btn">削除</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="dashboard.php" class="back-btn">ダッシュボードに戻る</a>
        </div>
    </div>

    <div id="image-manage-modal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h3>画像管理</h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <label for="modal-tags">タグ (コンマ区切り):</label>
                <input type="text" id="modal-tags" name="tags" value="<?php echo htmlspecialchars($image['tags']); ?>">
                <button type="submit">タグを更新</button>
            </form>

            <form method="POST" action="delete_image.php" onsubmit="return confirm('本当にこの画像を削除しますか？');">
                <input type="hidden" name="id" value="<?php echo $imageId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" class="delete-btn">画像を削除</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const emojiSelector = document.getElementById('emoji-selector');
            const commentTextarea = document.getElementById('comment-textarea');
            const selectedEmojiPreview = document.getElementById('selected-emoji-preview');
            const likeButton = document.getElementById('like-button');
            const likesCountSpan = document.getElementById('likes-count');
            const manageImageBtn = document.getElementById('manage-image-btn');
            const imageManageModal = document.getElementById('image-manage-modal');
            const closeModalButton = document.querySelector('.modal .close-button');

            // 絵文字選択のプレビュー機能
            emojiSelector.addEventListener('change', (e) => {
                const selectedOption = e.target.options[e.target.selectedIndex];
                const emojiName = selectedOption.value;
                const emojiFilename = selectedOption.dataset.filename;

                // テキストエリアに絵文字名を追加
                if (emojiName) {
                    commentTextarea.value += `:${emojiName}:`;
                }

                // 選択された絵文字のプレビューを更新
                selectedEmojiPreview.innerHTML = ''; // 既存のプレビューをクリア
                if (emojiFilename) {
                    const img = document.createElement('img');
                    img.src = `Emojis/${emojiFilename}`;
                    img.alt = emojiName;
                    img.className = 'emoji'; // Rx93style.cssの.emojiスタイルを適用
                    selectedEmojiPreview.appendChild(img);
                    const span = document.createElement('span');
                    span.textContent = `:${emojiName}:`;
                    selectedEmojiPreview.appendChild(span);
                }
            });

            // いいねボタンの非同期処理
            if (likeButton) {
                likeButton.addEventListener('click', async () => {
                    const imageId = likeButton.dataset.imageId;
                    const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>'; // PHPからCSRFトークンを取得

                    try {
                        const response = await fetch('like.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `image_id=${imageId}&csrf_token=${csrfToken}`
                        });

                        const data = await response.json();

                        if (data.success) {
                            likesCountSpan.textContent = data.likes;
                            if (data.hasLiked) {
                                likeButton.classList.add('liked');
                            } else {
                                likeButton.classList.remove('liked');
                            }
                        } else {
                            console.error('いいね処理失敗:', data.message);
                            alert('いいね処理に失敗しました: ' + data.message); // エラーメッセージを表示
                        }
                    } catch (error) {
                        console.error('Fetch Error:', error);
                        alert('通信エラーが発生しました。'); // 通信エラーを表示
                    }
                });
            }

            // 画像管理モーダルの表示/非表示
            if (manageImageBtn) {
                manageImageBtn.addEventListener('click', () => {
                    imageManageModal.style.display = 'flex'; // Flexboxで中央寄せ
                });
            }

            if (closeModalButton) {
                closeModalButton.addEventListener('click', () => {
                    imageManageModal.style.display = 'none';
                });
            }

            // モーダルの外側をクリックで閉じる
            window.addEventListener('click', (event) => {
                if (event.target === imageManageModal) {
                    imageManageModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>

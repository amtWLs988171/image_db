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

// 全ユーザーの絵文字を取得（コメント描画用）
$stmt_all_emojis = $pdo->query("
    SELECT e.emoji_name, e.filename
    FROM emojis e
");
$all_emojis = $stmt_all_emojis->fetchAll(PDO::FETCH_ASSOC);

// コメントを取得
$stmt = $pdo->prepare("SELECT c.*, u.user_id AS commenter FROM comments c JOIN users u ON c.user_id = u.user_id WHERE c.image_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$imageId]);
$comments = $stmt->fetchAll();


// --- コメント投稿とタグ更新のPOST処理はここから削除 ---
// 非同期化に伴い、これらの処理は add_comment.php と update_tags.php に移動しました。


// コメント削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment']) && isset($_POST['csrf_token'])) {
    // ... （この部分は変更なし）
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
        /* CSSの変更はなし */
        .selected-emoji-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            min-height: 100px;
            padding: 5px;
            border: 1px dashed #38444d;
            border-radius: 6px;
            background-color: #1a1a1a;
        }
        .selected-emoji-preview img {
            width: 100px;
            height: 100px;
            vertical-align: middle;
            border-radius: 8px;
        }
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
            color: #8899a6;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.2s ease, transform 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .like-button.liked .fa-heart {
            color: #e0245e;
        }
        .like-button:hover .fa-heart {
            color: #e0245e;
            transform: scale(1.1);
        }
        .likes-count {
            color: #8899a6;
            font-size: 16px;
        }
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
            color: #1da1f2;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
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
        .modal-content h3 { color: #e0e0e0; margin-bottom: 20px; text-align: center; }
        .modal-content form { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #2f3336; }
        .modal-content form:last-of-type { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .modal-content label { display: block; margin-bottom: 8px; color: #bbbbbb; font-size: 15px; }
        .modal-content input[type="text"] { max-width: 100%; margin-bottom: 15px; }
        .modal-content button { width: 100%; margin-top: 10px; }
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
            <div id="error-container">
                <?php if (isset($error)): ?>
                    <p class="error"><?php echo $error; ?></p>
                <?php endif; ?>
            </div>
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

            <div class="tag-list" id="tag-list-container">
                <ul class="tag-list" id="tag-list">
                    <?php if (!empty($image['tags'])): ?>
                        <?php
                        $displayTags = explode(', ', $image['tags']);
                        foreach ($displayTags as $tag):
                        ?>
                            <li><a href="dashboard.php?search=<?php echo urlencode($tag); ?>"><?php echo htmlspecialchars($tag); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li>タグがありません</li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="comment-section">
                <h3>コメント</h3>
                <form class="comment-form" method="POST" id="comment-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="image_id" value="<?php echo $imageId; ?>">
                    <input type="hidden" name="image_owner_id" value="<?php echo $image['user_id']; ?>">
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
                <div class="comment-list" id="comment-list">
                     <p id="no-comment-message" style="display: <?php echo empty($comments) ? 'block' : 'none'; ?>;">コメントがありません</p>
                    <?php foreach ($comments as $comment): ?>
                        <div class="comment-item" id="comment-<?php echo $comment['id']; ?>">
                            <p><strong><?php echo htmlspecialchars($comment['commenter']); ?>:</strong> <?php echo renderComment($comment['comment'], $all_emojis); ?></p>
                            <?php if ($comment['user_id'] === $userId || $userId === $image['user_id']): ?>
                                <form method="POST">
                                    <input type="hidden" name="delete_comment" value="<?php echo $comment['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <button type="submit" class="comment-delete-btn">削除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="dashboard.php" class="back-btn">ダッシュボードに戻る</a>
        </div>
    </div>

    <div id="image-manage-modal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h3>画像管理</h3>
            <div id="modal-error-container"></div>

            <form method="POST" id="tag-update-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="image_id" value="<?php echo $imageId; ?>">
                <input type="hidden" name="image_owner_id" value="<?php echo $image['user_id']; ?>">
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
            const currentUserId = '<?php echo $userId; ?>';
            const errorContainer = document.getElementById('error-container');
            
            // --- 補助関数: エラーメッセージを表示 ---
            const showError = (message, container = errorContainer) => {
                container.innerHTML = `<p class="error">${message}</p>`;
            };
            const clearError = (container = errorContainer) => {
                container.innerHTML = '';
            };


            // --- 絵文字選択のプレビュー機能 (変更なし) ---
            const emojiSelector = document.getElementById('emoji-selector');
            const commentTextarea = document.getElementById('comment-textarea');
            const selectedEmojiPreview = document.getElementById('selected-emoji-preview');
            emojiSelector.addEventListener('change', (e) => {
                const selectedOption = e.target.options[e.target.selectedIndex];
                const emojiName = selectedOption.value;
                const emojiFilename = selectedOption.dataset.filename;
                if (emojiName) {
                    commentTextarea.value += `:${emojiName}:`;
                }
                selectedEmojiPreview.innerHTML = '';
                if (emojiFilename) {
                    const img = document.createElement('img');
                    img.src = `Emojis/${emojiFilename}`;
                    img.alt = emojiName;
                    img.className = 'emoji';
                    selectedEmojiPreview.appendChild(img);
                    const span = document.createElement('span');
                    span.textContent = `:${emojiName}:`;
                    selectedEmojiPreview.appendChild(span);
                }
            });


            // --- いいねボタンの非同期処理 (変更なし) ---
            const likeButton = document.getElementById('like-button');
            const likesCountSpan = document.getElementById('likes-count');
            if (likeButton) {
                likeButton.addEventListener('click', async () => {
                    const imageId = likeButton.dataset.imageId;
                    const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
                    try {
                        const response = await fetch('like.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `image_id=${imageId}&csrf_token=${csrfToken}`
                        });
                        const data = await response.json();
                        if (data.success) {
                            likesCountSpan.textContent = data.likes;
                            likeButton.classList.toggle('liked', data.hasLiked);
                        } else {
                            showError(data.message || 'いいね処理に失敗しました。');
                        }
                    } catch (error) {
                        showError('通信エラーが発生しました。');
                    }
                });
            }


            // --- ★ 新規: コメント投稿の非同期処理 ---
            const commentForm = document.getElementById('comment-form');
            const commentList = document.getElementById('comment-list');
            const noCommentMessage = document.getElementById('no-comment-message');

            commentForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                clearError();
                const formData = new FormData(commentForm);

                try {
                    const response = await fetch('add_comment.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();

                    if (data.success) {
                        // 新しいコメント要素を作成
                        const newComment = data.comment;
                        const commentDiv = document.createElement('div');
                        commentDiv.className = 'comment-item';
                        commentDiv.id = `comment-${newComment.id}`;
                        
                        let deleteFormHtml = '';
                        // 投稿者自身、または画像の所有者であれば削除ボタンを表示
                        if (currentUserId === newComment.commenter || currentUserId === newComment.image_owner_id) {
                            deleteFormHtml = `
                                <form method="POST">
                                    <input type="hidden" name="delete_comment" value="${newComment.id}">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <button type="submit" class="comment-delete-btn">削除</button>
                                </form>
                            `;
                        }

                        commentDiv.innerHTML = `
                            <p><strong>${newComment.commenter}:</strong> ${newComment.html}</p>
                            ${deleteFormHtml}
                        `;

                        // リストの先頭に追加
                        commentList.prepend(commentDiv);
                        
                        // 「コメントがありません」メッセージを非表示に
                        if (noCommentMessage) {
                            noCommentMessage.style.display = 'none';
                        }
                        
                        // フォームをリセット
                        commentForm.reset();
                        selectedEmojiPreview.innerHTML = '';

                    } else {
                        showError(data.message || 'コメント投稿に失敗しました。');
                    }
                } catch (error) {
                    showError('通信エラーが発生しました。');
                }
            });

            // --- ★ 新規: コメント削除の非同期処理 ---
            commentList.addEventListener('submit', async (e) => {
                // submitイベントが delete-comment-form クラスを持つフォームで発生した場合のみ処理
                if (e.target.classList.contains('delete-comment-form')) {
                    e.preventDefault();
                    clearError();
                    
                    if (!confirm('本当にこのコメントを削除しますか？')) {
                        return;
                    }

                    const deleteForm = e.target;
                    const formData = new FormData(deleteForm);

                    try {
                        const response = await fetch('delete_comment.php', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await response.json();

                        if (data.success) {
                            // 対応するコメント要素をDOMから削除
                            const commentToRemove = document.getElementById(`comment-${data.comment_id}`);
                            if (commentToRemove) {
                                commentToRemove.remove();
                            }
                            // コメントがなくなったらメッセージを表示
                            if (commentList.querySelectorAll('.comment-item').length === 0) {
                                if (noCommentMessage) {
                                    noCommentMessage.style.display = 'block';
                                }
                            }
                        } else {
                            showError(data.message || 'コメントの削除に失敗しました。');
                        }
                    } catch (error) {
                        showError('通信エラーが発生しました。');
                    }
                }
            });

            // --- ★ 新規: タグ更新の非同期処理 ---
            const tagUpdateForm = document.getElementById('tag-update-form');
            const tagList = document.getElementById('tag-list');
            const modalErrorContainer = document.getElementById('modal-error-container');

            tagUpdateForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                clearError(modalErrorContainer);
                const formData = new FormData(tagUpdateForm);
                
                try {
                    const response = await fetch('update_tags.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();

                    if (data.success) {
                        // タグリストを更新
                        tagList.innerHTML = data.tagsHtml;
                        // モーダルを閉じる
                        imageManageModal.style.display = 'none';
                    } else {
                        showError(data.message || 'タグの更新に失敗しました。', modalErrorContainer);
                    }
                } catch (error) {
                    showError('通信エラーが発生しました。', modalErrorContainer);
                }
            });


            // --- モーダル表示ロジック (変更なし) ---
            const manageImageBtn = document.getElementById('manage-image-btn');
            const imageManageModal = document.getElementById('image-manage-modal');
            const closeModalButton = document.querySelector('.modal .close-button');
            if (manageImageBtn) {
                manageImageBtn.addEventListener('click', () => {
                    imageManageModal.style.display = 'flex';
                    clearError(modalErrorContainer); // モーダルを開くときにエラーをクリア
                });
            }
            if (closeModalButton) {
                closeModalButton.addEventListener('click', () => {
                    imageManageModal.style.display = 'none';
                });
            }
            window.addEventListener('click', (event) => {
                if (event.target === imageManageModal) {
                    imageManageModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>

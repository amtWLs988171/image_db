<?php
require_once 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_POST['user_id'];
    $password = $_POST['password'];

    // IDが既に存在するかチェック
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        $error = "This ID is already used";
    } else {
        // パスワードをハッシュ化
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        // データベースに登録
        $stmt = $pdo->prepare("INSERT INTO users (user_id, password) VALUES (?, ?)");
        if ($stmt->execute([$userId, $hashedPassword])) {
            $success = "Success!";
        } else {
            $error = "Failed";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>REGISTER</title>
    <link rel="stylesheet" href="Rx93style.css">
</head>
<body>
    <div class="login-container">
        <h2>REGISTER</h2>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo $error; ?></p>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <p class="success"><?php echo $success; ?></p>
            <p><a href="login.php">Login</a></p>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>ID : </label>
                    <input type="text" name="user_id" required>
                </div>
                <div class="form-group">
                    <label>password : </label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">Register</button>
            </form>
            <p>You already have ID? <a href="login.php">Login Now!</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
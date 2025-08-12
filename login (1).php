<?php
session_start();

// إذا كان الأدمن مسجلاً بالفعل، التحويل مباشرة
if (!empty($_SESSION['admin_id']) && ($_SESSION['user_role'] ?? null) === 'admin') {
    header('Location: admin.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['admin_email'] ?? '');
    $password = $_POST['admin_password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'يرجى إدخال بريد إلكتروني صالح';
    } else {
        try {
            $dbCandidates = [
                __DIR__ . '/../../../db_config/db.php',
                dirname(__DIR__, 2) . '/db_config/db.php',
                dirname(__DIR__, 1) . '/db_config/db.php',
                (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') : '') . '/db_config/db.php'
            ];
            $dbPath = null;
            foreach ($dbCandidates as $candidate) {
                if ($candidate && is_file($candidate)) { $dbPath = $candidate; break; }
            }
            if (!$dbPath) {
                throw new Exception('ملف إعدادات قاعدة البيانات غير موجود: /db_config/db.php');
            }
            require_once $dbPath; // لا ننشئ الملف، فقط نستخدم المسار
            if (!isset($conn) || !$conn) {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $conn = $pdo;
                } elseif (isset($db) && $db instanceof PDO) {
                    $conn = $db;
                } elseif (isset($dbh) && $dbh instanceof PDO) {
                    $conn = $dbh;
                } elseif (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
                    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
                    $conn = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                } else {
                    throw new Exception('تعذر تهيئة الاتصال بقاعدة البيانات ($conn)');
                }
            }

            try {
                $stmt = $conn->prepare('SELECT id, username, email, password FROM admins WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $qe) {
                // Fallback: جدول باسم مختلف
                if ((int)($qe->errorInfo[1] ?? 0) === 1146 || ($qe->getCode() === '42S02')) {
                    $stmt = $conn->prepare('SELECT id, username, email, password FROM admin WHERE email = ? LIMIT 1');
                    $stmt->execute([$email]);
                    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    throw $qe;
                }
            }

            $isValid = false;
            if ($admin) {
                $storedPassword = (string)($admin['password'] ?? '');
                if ($storedPassword !== '' && function_exists('password_verify')) {
                    $isValid = @password_verify($password, $storedPassword);
                }
                // دعم قديم في حال كانت الكلمات مخزنة نصياً (غير مستحسن)
                if (!$isValid && $storedPassword !== '') {
                    // إذا كانت القيمة ليست hash bcrypt، جرّب التطابق الحرفي
                    $looksHashed = (substr($storedPassword, 0, 4) === '$2y$') || strlen($storedPassword) >= 50;
                    if (!$looksHashed) {
                        $isValid = hash_equals($storedPassword, $password);
                    }
                }
            }

            if ($isValid) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['user_role'] = 'admin';
                header('Location: admin.php');
                exit;
            } else {
                $error = 'بيانات تسجيل الدخول غير صحيحة';
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $error = 'حدث خطأ غير متوقع، حاول لاحقاً';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول الأدمن - babaalgeria</title>
    <meta name="description" content="تسجيل دخول الإدارة في موقع babaalgeria للتجارة الإلكترونية">
    <meta name="keywords" content="تسجيل دخول, أدمن, babaalgeria, إدارة, تجارة إلكترونية">
    
    <!-- خط عربي جميل -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #e74c3c;
            --primary-dark: #c0392b;
            --accent: #f39c12;
            --card-bg: rgba(255,255,255,0.95);
            --glass: rgba(255,255,255,0.08);
            --text-dark: #2c3e50;
            --max-width: 500px;
            --error-color: #e74c3c;
            --success-color: #27ae60;
            --admin-purple: #8e44ad;
            --admin-purple-dark: #732d91;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Cairo', Tahoma, Arial, sans-serif;
            background: linear-gradient(120deg, #2c1810 0%, #4a1c2e 40%, #3d1a78 60%, #1a1a2e 100%);
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 1rem;
            padding-top: 2rem;
        }

        /* Enhanced animated background with particles */
        .bg-anim {
            position: fixed;
            inset: 0;
            z-index: -2;
            pointer-events: none;
            overflow: hidden;
            background: 
                radial-gradient(circle at 20% 30%, rgba(231,76,60,0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(142,68,173,0.06) 0%, transparent 50%),
                linear-gradient(45deg, rgba(231,76,60,0.06), rgba(142,68,173,0.03));
            animation: bgShift 20s linear infinite;
        }

        @keyframes bgShift {
            0% { background-position: 0% 50%, 100% 50%, 0% 50%; }
            25% { background-position: 25% 25%, 75% 75%, 25% 25%; }
            50% { background-position: 50% 0%, 50% 100%, 50% 0%; }
            75% { background-position: 75% 75%, 25% 25%, 75% 75%; }
            100% { background-position: 0% 50%, 100% 50%, 0% 50%; }
        }

        /* Floating particles */
        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(231,76,60,0.4);
            pointer-events: none;
            animation: particleFloat 15s linear infinite;
        }

        .particle:nth-child(2n) {
            background: rgba(142,68,173,0.3);
            animation-duration: 18s;
        }

        .particle:nth-child(3n) {
            background: rgba(243,156,18,0.3);
            animation-duration: 12s;
        }

        @keyframes particleFloat {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% {
                transform: translateY(-10vh) rotate(360deg);
                opacity: 0;
            }
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(40px);
            opacity: 0.7;
            mix-blend-mode: screen;
        }

        .blob.b1 {
            width: 280px;
            height: 280px;
            right: -60px;
            top: -80px;
            background: linear-gradient(45deg, #e74c3c, #c0392b);
            animation: float1 8s ease-in-out infinite;
        }

        .blob.b2 {
            width: 200px;
            height: 200px;
            left: -40px;
            bottom: -60px;
            background: linear-gradient(45deg, #8e44ad, #732d91);
            animation: float2 10s ease-in-out infinite;
        }

        .blob.b3 {
            width: 150px;
            height: 150px;
            right: 30%;
            top: 20%;
            background: linear-gradient(45deg, #f39c12, #e67e22);
            animation: float3 12s ease-in-out infinite;
        }

        @keyframes float1 {
            0% { transform: translateY(0) rotate(0deg) scale(1); }
            33% { transform: translateY(20px) rotate(120deg) scale(1.1); }
            66% { transform: translateY(-15px) rotate(240deg) scale(0.9); }
            100% { transform: translateY(0) rotate(360deg) scale(1); }
        }

        @keyframes float2 {
            0% { transform: translateY(0) rotate(0deg) scale(1); }
            50% { transform: translateY(-18px) rotate(-180deg) scale(1.2); }
            100% { transform: translateY(0) rotate(-360deg) scale(1); }
        }

        @keyframes float3 {
            0% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(28px) rotate(90deg); }
            50% { transform: translateY(-10px) rotate(180deg); }
            75% { transform: translateY(15px) rotate(270deg); }
            100% { transform: translateY(0) rotate(360deg); }
        }

        /* Login Container */
        .login-container {
            max-width: var(--max-width);
            width: 100%;
            background: transparent;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(2,6,23,0.25);
            padding: 2rem;
            position: relative;
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
            margin: 1rem 0;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, var(--primary), var(--admin-purple), var(--primary-dark), var(--admin-purple), var(--primary));
            background-size: 400% 400%;
            border-radius: 26px;
            z-index: -1;
            animation: borderRotate 3s linear infinite;
        }

        .login-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.95), rgba(255,255,255,0.9));
            border-radius: 24px;
            z-index: -1;
        }

        @keyframes borderRotate {
            0% { background-position: 0% 50%; }
            25% { background-position: 100% 50%; }
            50% { background-position: 100% 100%; }
            75% { background-position: 0% 100%; }
            100% { background-position: 0% 50%; }
        }

        /* Header */
        .login-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            font-weight: 700;
            font-size: 1.4rem;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        .logo .icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.5rem;
            box-shadow: 0 8px 20px rgba(231,76,60,0.25);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .login-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.4rem;
            position: relative;
        }

        .login-subtitle {
            color: #6b7280;
            font-size: 0.9rem;
        }

        /* Admin Badge */
        .admin-badge {
            background: linear-gradient(135deg, var(--primary), var(--admin-purple));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 1rem;
            box-shadow: 0 4px 15px rgba(231,76,60,0.3);
            animation: glow 2s ease-in-out infinite alternate;
        }

        @keyframes glow {
            from { box-shadow: 0 4px 15px rgba(231,76,60,0.3); }
            to { box-shadow: 0 4px 25px rgba(231,76,60,0.5), 0 0 30px rgba(142,68,173,0.3); }
        }

        /* Welcome Message */
        .welcome-message {
            background: linear-gradient(135deg, rgba(231,76,60,0.1), rgba(142,68,173,0.05));
            border: 2px solid rgba(231,76,60,0.2);
            border-radius: 16px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .welcome-message::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: welcomeShimmer 3s infinite 1s;
        }

        @keyframes welcomeShimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        .welcome-emoji {
            font-size: 2.5rem;
            margin-bottom: 0.8rem;
            display: block;
            animation: bounce 2s infinite;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        @keyframes bounce {
            0%, 20%, 53%, 80%, 100% { transform: translateY(0); }
            40%, 43% { transform: translateY(-15px); }
        }

        .welcome-text {
            color: var(--text-dark);
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .welcome-subtext {
            color: #6b7280;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.2rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.4rem;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid rgba(44,62,80,0.1);
            border-radius: 10px;
            font-size: 0.95rem;
            font-family: inherit;
            background: rgba(255,255,255,0.8);
            transition: all 0.3s ease;
            color: var(--text-dark);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255,255,255,0.95);
            box-shadow: 0 0 0 3px rgba(231,76,60,0.1);
            transform: translateY(-1px);
        }

        .form-input::placeholder {
            color: #9ca3af;
        }

        /* Input Icons */
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary);
            font-size: 1rem;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .form-input.with-icon {
            padding-left: 40px;
        }

        #passwordToggle {
            cursor: pointer !important;
            pointer-events: all !important;
        }

        #passwordToggle:hover {
            color: var(--primary-dark);
            transform: translateY(-50%) scale(1.1);
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--primary), var(--admin-purple));
            color: white;
            padding: 12px 18px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-bottom: 0.8rem;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(231,76,60,0.25);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* Secondary Button */
        .btn-secondary {
            width: 100%;
            background: transparent;
            color: var(--text-dark);
            padding: 10px 18px;
            border: 2px solid rgba(44,62,80,0.1);
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .btn-secondary:hover {
            border-color: var(--primary);
            background: rgba(231,76,60,0.05);
            transform: translateY(-1px);
        }

        /* Remember me checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }

        .custom-checkbox {
            position: relative;
            display: inline-block;
            width: 20px;
            height: 20px;
        }

        .custom-checkbox input {
            opacity: 0;
            position: absolute;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background: rgba(255,255,255,0.8);
            border: 2px solid rgba(44,62,80,0.2);
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .custom-checkbox input:checked ~ .checkmark {
            background: var(--primary);
            border-color: var(--primary);
        }

        .checkmark::after {
            content: '';
            position: absolute;
            display: none;
            left: 6px;
            top: 3px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .custom-checkbox input:checked ~ .checkmark::after {
            display: block;
        }

        .checkbox-label {
            color: var(--text-dark);
            font-size: 0.9rem;
            cursor: pointer;
            user-select: none;
        }

        /* Security Features */
        .security-features {
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(231,76,60,0.05);
            border: 1px solid rgba(231,76,60,0.2);
            border-radius: 10px;
            font-size: 0.85rem;
        }

        .security-features h4 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .security-features ul {
            list-style: none;
            padding: 0;
        }

        .security-features li {
            color: var(--text-dark);
            margin-bottom: 0.3rem;
            position: relative;
            padding-left: 1.2rem;
        }

        .security-features li::before {
            content: '🛡️';
            position: absolute;
            left: 0;
            font-size: 0.8rem;
        }

        /* Admin Warning Box */
        .admin-warning {
            background: linear-gradient(135deg, rgba(142,68,173,0.1), rgba(243,156,18,0.05));
            border: 2px solid rgba(142,68,173,0.3);
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .admin-warning h4 {
            color: var(--admin-purple);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .admin-warning p {
            color: var(--text-dark);
            margin-bottom: 0.3rem;
        }

        .admin-warning .warning-icon {
            animation: warningPulse 2s infinite;
        }

        @keyframes warningPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* Error/Success Messages */
        .message {
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 0.8rem;
            font-weight: 600;
            text-align: center;
            animation: slideIn 0.3s ease;
            font-size: 0.9rem;
        }

        .message.error {
            background: rgba(231,76,60,0.1);
            color: var(--error-color);
            border: 1px solid rgba(231,76,60,0.2);
        }

        .message.success {
            background: rgba(39,174,96,0.1);
            color: var(--success-color);
            border: 1px solid rgba(39,174,96,0.2);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Loading State */
        .btn-submit.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-submit.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: translate(-50%, -50%) rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 600px) {
            body {
                padding: 0.5rem;
                padding-top: 1rem;
                align-items: flex-start;
            }

            .login-container {
                padding: 1.5rem 1.2rem;
                margin: 0.5rem 0;
                border-radius: 20px;
            }

            .login-header {
                margin-bottom: 1.2rem;
            }

            .login-title {
                font-size: 1.4rem;
            }

            .login-subtitle {
                font-size: 0.85rem;
            }

            .welcome-message {
                padding: 1rem;
                margin-bottom: 1.2rem;
            }

            .welcome-emoji {
                font-size: 2rem;
                margin-bottom: 0.6rem;
            }

            .welcome-text {
                font-size: 1rem;
            }

            .form-group {
                margin-bottom: 1rem;
            }

            .form-input {
                padding: 12px 14px;
                font-size: 0.9rem;
            }

            .form-input.with-icon {
                padding-left: 42px;
            }

            .input-icon {
                left: 14px;
            }

            .btn-submit {
                padding: 14px 18px;
                font-size: 0.95rem;
            }

            .btn-secondary {
                padding: 12px 18px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 400px) {
            body {
                padding: 0.3rem;
                padding-top: 0.5rem;
            }

            .login-container {
                padding: 1.2rem 1rem;
                margin: 0.2rem 0;
                border-radius: 16px;
            }

            .login-title {
                font-size: 1.3rem;
            }

            .welcome-emoji {
                font-size: 1.8rem;
            }

            .form-input {
                padding: 10px 12px;
                font-size: 0.9rem;
            }

            .form-input.with-icon {
                padding-left: 38px;
            }

            .input-icon {
                left: 12px;
                font-size: 0.9rem;
            }
        }

        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            .blob, .particle {
                animation: none;
            }

            .login-container {
                animation: none;
            }

            .welcome-emoji {
                animation: none;
            }

            .pulse {
                animation: none;
            }
        }

        /* Additional hover effects */
        .form-group {
            transition: transform 0.3s ease;
        }

        .form-group:focus-within {
            transform: scale(1.02);
        }

        /* 2FA Code Input (for future use) */
        .two-fa-container {
            display: none;
            margin-top: 1rem;
        }

        .two-fa-input {
            text-align: center;
            font-size: 1.2rem;
            letter-spacing: 0.5rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="bg-anim">
        <div class="blob b1"></div>
        <div class="blob b2"></div>
        <div class="blob b3"></div>
    </div>

    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <div class="icon">⚡</div>
                babaalgeria
            </div>
            <div class="admin-badge">🔒 لوحة تحكم الإدارة</div>
            <h1 class="login-title">تسجيل دخول الأدمن</h1>
            <p class="login-subtitle">الوصول إلى لوحة التحكم الرئيسية</p>
        </div>

        <!-- Admin Warning -->
        <div class="admin-warning">
            <h4><span class="warning-icon">⚠️</span> تنبيه هام </h4>
            <p>هذه المنطقة مخصصة لمديري النظام فقط. يتم تسجيل جميع محاولات تسجيل الدخول ومراقبتها.</p>
            <p>في حالة وجود أي محاول اختراق ، سوف يتم إتخاذ معاك اجراءات.</p>
        </div>

        <!-- Welcome Message -->
        <div class="welcome-message">
            <span class="welcome-emoji">👑</span>
            <div class="welcome-text">مرحباً بك أيها المدير</div>
            <div class="welcome-subtext">نحن في خدمتك لإدارة المنصة بكل سهولة وأمان</div>
        </div>

        <form id="adminLoginForm" method="POST" action="login.php" novalidate>
            <div id="messageContainer">
                <?php if (!empty($error)): ?>
                    <div class="message error">❌ <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="admin_email" class="form-label">البريد الإلكتروني الإداري</label>
                <div style="position:relative">
                    <input 
                        type="email" 
                        id="admin_email" 
                        name="admin_email" 
                        class="form-input with-icon" 
                        placeholder="admin@babaalgeria.com"
                        required
                        autocomplete="email"
                    >
                    <span class="input-icon">👤</span>
                </div>
            </div>

            <div class="form-group">
                <label for="admin_password" class="form-label">كلمة المرور الإدارية</label>
                <div style="position:relative">
                    <input 
                        type="password" 
                        id="admin_password" 
                        name="admin_password" 
                        class="form-input with-icon" 
                        placeholder="أدخل كلمة المرور الآمنة"
                        required
                        autocomplete="current-password"
                    >
                    <span class="input-icon" id="passwordToggle" title="إظهار/إخفاء كلمة المرور">🔐</span>
                </div>
            </div>



            <div class="checkbox-group">
                <label class="custom-checkbox">
                    <input type="checkbox" id="rememberAdmin" name="rememberAdmin">
                    <span class="checkmark"></span>
                </label>
                <label for="rememberAdmin" class="checkbox-label">تذكرني لمدة 7 أيام</label>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                دخول لوحة التحكم
            </button>

            <a href="#" class="btn-secondary" onclick="return false;">
                استرداد كلمة المرور
            </a>
        </form>

        

    </div>

    <script>
        // Create floating particles
        function createParticles() {
            const bgAnim = document.querySelector('.bg-anim');
            for (let i = 0; i < 10; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.width = Math.random() * 6 + 2 + 'px';
                particle.style.height = particle.style.width;
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 15 + 's';
                particle.style.animationDuration = (Math.random() * 8 + 12) + 's';
                bgAnim.appendChild(particle);
            }
        }
        createParticles();

        // تعطيل وظائف التجريب والـ 2FA وتحويل التحكم إلى المعالجة الخادمية فقط
        const form = document.getElementById('adminLoginForm');
        const submitBtn = document.getElementById('submitBtn');
        const messageContainer = document.getElementById('messageContainer');
        const twoFaContainer = null;

        // Enhanced validation patterns for admin
        const adminValidationRules = {
            admin_email: {
                pattern: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
                message: 'يرجى إدخال بريد إلكتروني صحيح للإدارة',
                adminCheck: true
            },
            admin_password: {
                pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/,
                message: 'كلمة المرور يجب أن تحتوي على: 8 أحرف، حرف كبير، حرف صغير، رقم، ورمز خاص',
                strict: true
            }
        };

        // تم تعطيل بيانات التجريب

        // Real-time validation for admin fields
        Object.keys(adminValidationRules).forEach(fieldName => {
            const field = document.getElementById(fieldName);
            if (field) {
                field.addEventListener('input', () => validateAdminField(field));
                field.addEventListener('blur', () => validateAdminField(field));
            }
        });

        function validateAdminField(field) {
            const rule = adminValidationRules[field.name];
            let isValid = true;
            
            if (field.value.trim() === '') {
                field.style.borderColor = 'rgba(44,62,80,0.1)';
                return false;
            }
            
            if (rule && rule.pattern) {
                isValid = rule.pattern.test(field.value.trim());
            }
            
            // تم تعطيل التحقق الإضافي لنطاق البريد حتى لا يرفض بريد الإدمن الصحيح
            if (false) {}
            
            if (isValid) {
                field.style.borderColor = 'var(--success-color)';
                return true;
            } else {
                field.style.borderColor = 'var(--error-color)';
                return false;
            }
        }

        function showMessage(text, type) {
            messageContainer.innerHTML = `<div class="message ${type}">${text}</div>`;
            setTimeout(() => {
                if (type !== 'error') {
                    messageContainer.innerHTML = '';
                }
            }, 5000);
        }

        // Enhanced security logging
        function logSecurityEvent(event, details) {
            const timestamp = new Date().toISOString();
            const logData = {
                timestamp,
                event,
                details,
                ip: 'تم إخفاؤه لأغراض الخصوصية',
                userAgent: navigator.userAgent.substring(0, 100)
            };
            
            console.log('🔒 Security Event:', logData);
            // في التطبيق الحقيقي، سيتم إرسال هذا إلى الخادم
        }

        // تم تعطيل 2FA

        // Admin login form submission
        form.addEventListener('submit', async (e) => {
            // اترك الإرسال يذهب إلى الخادم للتحقق الحقيقي
            submitBtn.classList.add('loading');
            submitBtn.textContent = '';
            
            const formData = new FormData(form);
            const email = formData.get('admin_email');
            const password = formData.get('admin_password');
            
            // Log login attempt
            logSecurityEvent('LOGIN_ATTEMPT', {
                email: email ? email.substring(0, 3) + '***' : 'empty',
                timestamp: new Date().toISOString()
            });
            
            let isValid = true;
            const errors = [];

            // Validate admin fields
            Object.keys(adminValidationRules).forEach(key => {
                const field = document.getElementById(key);
                if (field && field.offsetParent !== null) { // Check if field is visible
                    if (!validateAdminField(field)) {
                        isValid = false;
                        const rule = adminValidationRules[key];
                        const fieldValue = formData.get(key);
                        if (rule && fieldValue && fieldValue.trim() !== '') {
                            errors.push(rule.message);
                        }
                    }
                }
            });

            // Check required fields
            if (!email || !email.trim() || !password || !password.trim()) {
                isValid = false;
                errors.push('يرجى ملء جميع الحقول المطلوبة');
            }

            // تم تعطيل شرط 2FA

            if (!isValid) {
                submitBtn.classList.remove('loading');
                submitBtn.textContent = 'دخول لوحة التحكم';
                const errorMessage = errors.length > 0 ? errors[0] : 'يرجى التأكد من صحة جميع البيانات';
                showMessage('❌ ' + errorMessage, 'error');
                logSecurityEvent('LOGIN_FAILED', { reason: 'validation_error' });
                return;
            }

            // Simulate admin authentication
            setTimeout(() => {
                // Demo authentication logic
                if (false) { // تم تعطيل التجريبي
                    // Check 2FA if required
                                // تم تعطيل 2FA نهائياً على الواجهة الأمامية
                    
                    // Successful login
                    logSecurityEvent('LOGIN_SUCCESS', { 
                        email, 
                        twoFA: 'disabled' 
                    });
                    
                                         showMessage('✅ تم تسجيل الدخول بنجاح! سيتم الآن إرسال الطلب إلى الخادم...', 'success');
                    
                } else {
                    // Failed login
                    submitBtn.classList.remove('loading');
                    submitBtn.textContent = 'دخول لوحة التحكم';
                    showMessage('❌ بيانات تسجيل الدخول غير صحيحة', 'error');
                    logSecurityEvent('LOGIN_FAILED', { 
                        email: email ? email.substring(0, 3) + '***' : 'empty',
                        reason: 'invalid_credentials' 
                    });
                    
                    // Add progressive delay for failed attempts (security feature)
                    const failedAttempts = parseInt(localStorage.getItem('adminFailedAttempts') || '0') + 1;
                    localStorage.setItem('adminFailedAttempts', failedAttempts.toString());
                    
                    if (failedAttempts >= 3) {
                        const lockoutTime = 5 * 60 * 1000; // 5 minutes
                        localStorage.setItem('adminLockoutUntil', (Date.now() + lockoutTime).toString());
                        showMessage('🔒 تم قفل الحساب مؤقتاً لمدة 5 دقائق لأسباب أمنية', 'error');
                        logSecurityEvent('ACCOUNT_LOCKED', { email, attempts: failedAttempts });
                    }
                }
            }, 2000); // Simulate network delay
        });

        // Check for account lockout
        function checkAccountLockout() {
            const lockoutUntil = parseInt(localStorage.getItem('adminLockoutUntil') || '0');
            if (lockoutUntil > Date.now()) {
                const remainingTime = Math.ceil((lockoutUntil - Date.now()) / 1000 / 60);
                showMessage(`🔒 الحساب مقفل مؤقتاً. المتبقي: ${remainingTime} دقيقة`, 'error');
                submitBtn.disabled = true;
                return true;
            } else {
                localStorage.removeItem('adminFailedAttempts');
                localStorage.removeItem('adminLockoutUntil');
                return false;
            }
        }

        // Show/hide password functionality
        let passwordVisible = false;
        const passwordInput = document.getElementById('admin_password');
        const passwordToggle = document.getElementById('passwordToggle');

        if (passwordToggle && passwordInput) {
            passwordToggle.addEventListener('click', () => {
                passwordVisible = !passwordVisible;
                passwordInput.type = passwordVisible ? 'text' : 'password';
                passwordToggle.textContent = passwordVisible ? '👁️' : '🔐';
            });
        }

        // Remember me functionality with enhanced security
        const rememberAdminCheckbox = document.getElementById('rememberAdmin');
        if (rememberAdminCheckbox) {
            rememberAdminCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    showMessage('⚠️ سيتم تذكر تسجيل دخولك لمدة 7 أيام. تأكد من أنك تستخدم جهاز آمن', 'success');
                    logSecurityEvent('REMEMBER_ME_ENABLED', {});
                }
            });
        }

        // Enhanced welcome message animation
        const welcomeMessage = document.querySelector('.welcome-message');
        const welcomeEmoji = document.querySelector('.welcome-emoji');
        
        if (welcomeEmoji) {
            welcomeEmoji.addEventListener('click', () => {
                welcomeEmoji.style.transform = 'scale(1.3) rotate(20deg)';
                setTimeout(() => {
                    welcomeEmoji.style.transform = '';
                }, 300);
                showMessage('👑 شكراً لك على إدارة المنصة بكل اهتمام ومسؤولية!', 'success');
            });
        }

        // Add form field focus effects
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', function() {
                const parent = this.closest('.form-group');
                if (parent) {
                    parent.style.transform = 'scale(1.02)';
                }
            });
            
            input.addEventListener('blur', function() {
                const parent = this.closest('.form-group');
                if (parent) {
                    parent.style.transform = '';
                }
            });
        });

        // Keyboard navigation enhancement
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                const activeElement = document.activeElement;
                if (activeElement && activeElement.tagName === 'INPUT') {
                    e.preventDefault();
                    form.requestSubmit();
                }
            }
        });

        // Auto-focus first empty field
        window.addEventListener('load', function() {
            checkAccountLockout();
            
            const firstInput = document.querySelector('.form-input');
            if (firstInput && !firstInput.value) {
                setTimeout(() => firstInput.focus(), 100);
            }
        });

        // Handle network status for admin panel
        function checkNetworkStatus() {
            if (!navigator.onLine) {
                showMessage('🌐 لا يوجد اتصال بالإنترنت. لوحة التحكم تتطلب اتصال مستقر', 'error');
                return false;
            }
            return true;
        }

        form.addEventListener('submit', function(e) {
            if (!checkNetworkStatus() || checkAccountLockout()) {
                e.preventDefault();
                return false;
            }
            // لا نمنع الإرسال، نتركه للخادم
        });

        // Handle offline/online events
        window.addEventListener('offline', function() {
            showMessage('📡 انقطع الاتصال! لوحة التحكم قد لا تعمل بشكل صحيح', 'error');
            logSecurityEvent('CONNECTION_LOST', {});
        });

        window.addEventListener('online', function() {
            showMessage('✅ تم استعادة الاتصال', 'success');
            logSecurityEvent('CONNECTION_RESTORED', {});
        });

        // Security monitoring
        let suspiciousActivity = 0;
        
        // Monitor rapid input changes (potential bot activity)
        document.querySelectorAll('.form-input').forEach(input => {
            let lastInputTime = 0;
            input.addEventListener('input', function() {
                const now = Date.now();
                if (now - lastInputTime < 50) { // Less than 50ms between inputs
                    suspiciousActivity++;
                    if (suspiciousActivity > 10) {
                        logSecurityEvent('SUSPICIOUS_ACTIVITY', { 
                            type: 'rapid_input', 
                            count: suspiciousActivity 
                        });
                        showMessage('⚠️ تم اكتشاف نشاط مشبوه. يرجى التحقق من هويتك', 'error');
                    }
                }
                lastInputTime = now;
            });
        });

        // Monitor page visibility (tab switching during login)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden && form.querySelector('.form-input:focus')) {
                logSecurityEvent('PAGE_HIDDEN_DURING_LOGIN', {});
            }
        });

        // Developer console welcome message
        console.log('⚡ babaalgeria - لوحة تحكم الإدارة');

        // Add demo credentials helper
        let clickCount = 0;
        document.querySelector('.logo').addEventListener('click', function() {
            clickCount++;
            if (clickCount === 1) {
                showMessage('👑 مرحباً بك في لوحة تحكم الإدارة!', 'success');
            } else if (clickCount === 2) {
                showMessage('🚀 بالتوفيق في عملك الإداري', 'success');
            }
        });

        // Add security tips
        const securityTips = [
            'استخدم كلمة مرور قوية تحتوي على أحرف وأرقام ورموز',
            'لا تشارك بيانات تسجيل الدخول مع أي شخص',
            'تأكد من تسجيل الخروج بعد انتهاء العمل',
            'راقب تقارير النشاط بانتظام',
            'قم بتحديث كلمة المرور دورياً'
        ];

        setInterval(() => {
            if (Math.random() < 0.1) { // 10% chance every interval
                const tip = securityTips[Math.floor(Math.random() * securityTips.length)];
                console.log(`💡 نصيحة أمنية: ${tip}`);
            }
        }, 30000); // Every 30 seconds
    </script>
</body>
</html>
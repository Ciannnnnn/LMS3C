<?php
session_start();
require_once __DIR__ . '/security.php';

if (isset($_SESSION['userId'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($_SESSION['role'] === 'professor') {
        header('Location: professor_dashboard.php');
    } else {
        header('Location: user_dashboard.php');
    }
    exit;
}

$csrfToken = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LMS Infotech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        @keyframes gradientShift {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }

        body::before {
            content: '';
            position: fixed;
            width: 400px;
            height: 400px;
            border-radius: 40% 60% 70% 30% / 40% 50% 60% 50%;
            background: rgba(255, 255, 255, 0.1);
            top: -100px;
            left: -100px;
            animation: float 20s infinite ease-in-out;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            width: 300px;
            height: 300px;
            border-radius: 60% 40% 30% 70% / 60% 30% 70% 40%;
            background: rgba(255, 255, 255, 0.08);
            bottom: -150px;
            right: -150px;
            animation: float 25s infinite ease-in-out reverse;
            z-index: 0;
        }

        @keyframes float {
            0%, 100% {
                transform: translate(0, 0) rotate(0deg);
            }
            33% {
                transform: translate(30px, -50px) rotate(120deg);
            }
            66% {
                transform: translate(-20px, 20px) rotate(240deg);
            }
        }

        .login-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 50%, #f0f4ff 100%);
            backdrop-filter: blur(20px);
            border-radius: 25px;
            border: 2px solid transparent;
            background-clip: padding-box;
            position: relative;
            width: 100%;
            max-width: 420px;
            padding: 50px 40px;
            animation: slideUp 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 10;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 25px;
            padding: 2px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.5), rgba(118, 75, 162, 0.3), rgba(35, 165, 213, 0.2));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .login-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            box-shadow: 
                0 30px 60px rgba(102, 126, 234, 0.2),
                0 15px 40px rgba(118, 75, 162, 0.15),
                0 60px 120px rgba(0, 0, 0, 0.15),
                inset 0 1px 3px rgba(255, 255, 255, 0.9),
                inset 0 -2px 8px rgba(102, 126, 234, 0.08);
            border-radius: 25px;
            pointer-events: none;
            z-index: 1;
        }

        .login-container > * {
            position: relative;
            z-index: 2;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 20px;
            opacity: 0.1;
            z-index: -1;
        }

        .login-header h1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: -0.5px;
        }

        .login-header p {
            color: #999;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .form-group {
            margin-bottom: 25px;
            animation: fadeIn 0.6s ease-out forwards;
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .form-group:nth-child(3) { animation-delay: 0.3s; }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 13px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #555;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f5f5 0%, #fafafa 100%);
            color: #333;
        }

        .form-group select:hover,
        .form-group input:hover {
            border-color: #ddd;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }

        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667eea' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        .password-group {
            position: relative;
        }

        .password-group input {
            padding-right: 48px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #999;
            font-size: 18px;
            transition: all 0.3s;
            padding: 8px;
            margin-top: 2px;
        }

        .toggle-password:hover {
            color: #667eea;
            transform: translateY(-50%) scale(1.2);
        }

        .toggle-password:active {
            transform: translateY(-50%) scale(0.95);
        }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            margin-top: 15px;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }

        .login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:active {
            transform: translateY(-1px);
        }

        .form-footer {
            text-align: center;
            margin-top: 25px;
            font-size: 13px;
            color: #666;
        }

        .form-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            position: relative;
        }

        .form-footer a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s;
        }

        .form-footer a:hover::after {
            width: 100%;
        }

        .success-message {
            display: none;
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(23, 162, 184, 0.1));
            color: #155724;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            text-align: center;
            font-weight: 500;
            animation: slideDown 0.4s ease-out;
        }

        .error-message {
            display: none;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(255, 193, 7, 0.1));
            color: #721c24;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            text-align: center;
            font-weight: 500;
            animation: slideDown 0.4s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 12px;
                align-items: flex-start;
                padding-top: 30px;
            }

            .login-container {
                max-width: 100%;
                padding: 42px 30px;
                border-radius: 20px;
            }

            .login-header h1 {
                font-size: 28px;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .form-group select,
            .form-group input {
                padding: 12px 14px;
            }
        }

        @media (max-width: 360px) {
            .login-header p {
                font-size: 11px;
            }

            .login-btn {
                font-size: 14px;
                padding: 12px;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Login</h1>
            <p>LMS Infotech 3C</p>
        </div>

        <div class="success-message" id="successMessage"></div>
        <div class="error-message" id="errorMessage"></div>

        <form id="loginForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <div class="form-group">
                <label for="role">Select Role</label>
                <select id="role" name="role" required>
                    <option value=""> Choose Role </option>
                    <option value="user">Student</option>
                    <option value="professor">Professor</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="form-group">
                <label for="email">Email or Username</label>
                <input 
                    type="text" 
                    id="email" 
                    name="email" 
                    placeholder="Enter your email or username" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-group">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Enter your password" 
                        required
                    >
                    <button 
                        type="button" 
                        class="toggle-password" 
                        id="togglePassword"
                        aria-label="Toggle password visibility"
                    >
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="login-btn">Login</button>
        </form>

    </div>

    <script>
        const togglePasswordBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = togglePasswordBtn.querySelector('i');

        togglePasswordBtn.addEventListener('click', function() {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        });

        const loginForm = document.getElementById('loginForm');
        const successMessage = document.getElementById('successMessage');
        const errorMessage = document.getElementById('errorMessage');

        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const role = document.getElementById('role').value;
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            successMessage.style.display = 'none';
            errorMessage.style.display = 'none';

            if (!role) {
                errorMessage.textContent = 'Please select a role';
                errorMessage.style.display = 'block';
                return;
            }

            if (!email) {
                errorMessage.textContent = 'Please enter your email or username';
                errorMessage.style.display = 'block';
                return;
            }

            if (!password) {
                errorMessage.textContent = 'Please enter your password';
                errorMessage.style.display = 'block';
                return;
            }

            if (password.length < 6) {
                errorMessage.textContent = 'Password must be at least 6 characters';
                errorMessage.style.display = 'block';
                return;
            }

            successMessage.textContent = 'Logging in...';
            successMessage.style.display = 'block';

            const formData = new FormData(loginForm);
            formData.set('email', email);
            formData.set('password', password);
            formData.set('role', role);

            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    successMessage.textContent = 'Login successful! Redirecting...';
                    successMessage.style.display = 'block';
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                } else {
                    successMessage.style.display = 'none';
                    errorMessage.textContent = data.message || 'Login failed. Please try again.';
                    errorMessage.style.display = 'block';
                }
            })
            .catch(error => {
                successMessage.style.display = 'none';
                errorMessage.textContent = 'An error occurred. Please try again.';
                errorMessage.style.display = 'block';
                console.error('Error:', error);
            });
        });
    </script>
</body>
</html>

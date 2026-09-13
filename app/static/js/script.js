// تابع ورود — با پشتیبانی UX infrastructure + CAPTCHA
function login() {
    var username = document.getElementById('username').value;
    var password = document.getElementById('password').value;
    var captcha = document.getElementById('captcha').value;
    var loginBtn = document.getElementById('loginBtn');

    if (!username || !password) {
        showSystemError('لطفاً نام کاربری و رمز عبور را وارد کنید.');
        return;
    }

    if (!captcha) {
        showCaptchaError('لطفاً کد امنیتی را وارد کنید.');
        document.getElementById('captcha').focus();
        return;
    }

    // Double-submit protection
    if (window.HastamaUX && window.HastamaUX.submitLock) {
        if (!window.HastamaUX.submitLock('login')) return;
    }

    // Button loading state
    if (window.HastamaUX && loginBtn) {
        window.HastamaUX.btnLoad(loginBtn, 'در حال ورود...');
    }

    // ارسال درخواست به سرور برای اعتبارسنجی
    fetch('/login_user', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            username: username,
            password: password,
            captcha: captcha
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (window.HastamaUX && loginBtn) {
                window.HastamaUX.btnDone(loginBtn, '✓ ورود موفق');
            }
            // Brief delay to show success state, then redirect
            setTimeout(function () {
                window.location.href = data.redirect;
            }, 400);
        } else {
            if (window.HastamaUX && loginBtn) {
                window.HastamaUX.btnReset(loginBtn);
            }
            if (window.HastamaUX && window.HastamaUX.submitUnlock) {
                window.HastamaUX.submitUnlock('login');
            }
            // CAPTCHA error — refresh CAPTCHA and clear input
            if (data.captcha_error) {
                showCaptchaError(data.message);
                refreshCaptcha();
                document.getElementById('captcha').value = '';
                document.getElementById('captcha').focus();
            } else {
                showSystemError(data.message || 'نام کاربری یا رمز عبور اشتباه است');
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (window.HastamaUX && loginBtn) {
            window.HastamaUX.btnReset(loginBtn);
        }
        if (window.HastamaUX && window.HastamaUX.submitUnlock) {
            window.HastamaUX.submitUnlock('login');
        }
        showSystemError('خطا در برقراری ارتباط با سامانه.');
    });
}

// نمایش خطا در بخش CAPTCHA
function showCaptchaError(msg) {
    var el = document.getElementById('captchaError');
    if (el) {
        el.textContent = msg;
        el.style.display = 'block';
        // Shake animation
        var wrapper = document.querySelector('.captcha-group');
        if (wrapper) {
            wrapper.style.animation = 'none';
            wrapper.offsetHeight; // trigger reflow
            wrapper.style.animation = 'shake 0.4s ease';
        }
    }
}

// مخفی کردن خطای CAPTCHA
function hideCaptchaError() {
    var el = document.getElementById('captchaError');
    if (el) {
        el.textContent = '';
        el.style.display = 'none';
    }
}

// دریافت کد CAPTCHA جدید
function refreshCaptcha() {
    var img = document.getElementById('captchaImage');
    var btn = document.getElementById('captchaRefresh');
    if (!img) return;

    // Spin animation on button
    if (btn) btn.classList.add('spinning');

    fetch('/captcha/refresh', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.image) {
                img.src = data.image;
            } else {
                // Fallback: reload image with cache-bust
                img.src = '/captcha?t=' + Date.now();
            }
        })
        .catch(() => {
            img.src = '/captcha?t=' + Date.now();
        })
        .finally(() => {
            if (btn) {
                setTimeout(() => btn.classList.remove('spinning'), 500);
            }
        });

    hideCaptchaError();
}

// اضافه کردن گوش‌دهنده برای کلید Enter
document.getElementById('password').addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        login();
    }
});

// Enter key on CAPTCHA input
document.getElementById('captcha').addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        login();
    }
});

// CAPTCHA refresh button
document.getElementById('captchaRefresh').addEventListener('click', function() {
    refreshCaptcha();
});

// Clear CAPTCHA error when user types
document.getElementById('captcha').addEventListener('input', function() {
    hideCaptchaError();
});

// Load initial CAPTCHA on page load
document.addEventListener('DOMContentLoaded', function() {
    var img = document.getElementById('captchaImage');
    if (img && !img.src.includes('data:')) {
        img.src = '/captcha?t=' + Date.now();
    }
});

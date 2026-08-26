// تابع ورود
function login() {
    var username = document.getElementById('username').value;
    var password = document.getElementById('password').value;

    // ارسال درخواست به سرور برای اعتبارسنجی
    fetch('/login_user', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            username: username,
            password: password
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
            window.location.href = data.redirect;
        } else {
            showSystemError(data.message || 'نام کاربری یا رمز عبور اشتباه است');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showSystemError('خطا در برقراری ارتباط با سامانه.');
    });
    
}

// اضافه کردن گوش‌دهنده برای کلید Enter
document.getElementById('password').addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        login();  // فراخوانی تابع login زمانی که Enter فشرده شود
    }
});

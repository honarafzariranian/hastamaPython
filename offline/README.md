# بسته نصب آفلاین

این پوشه یک wheelhouse برای **Windows x64 / CPython 3.11** دارد. فایل‌های داخل
`wheels/` از dependency graph قفل‌شده پروژه تهیه شده‌اند و نصب را بدون اتصال به
PyPI ممکن می‌کنند.

## نصب

از ریشه پروژه PowerShell را اجرا کنید:

```powershell
.\offline\install-offline.ps1 -Python .\.venv\Scripts\python.exe
```

اگر محیط مجازی هنوز ساخته نشده است:

```powershell
py -3.11 -m venv .venv
.\offline\install-offline.ps1 -Python .\.venv\Scripts\python.exe
```

اسکریپت از `pip --no-index --find-links` استفاده می‌کند و سپس خود پروژه را بدون
build isolation نصب می‌کند؛ بنابراین در زمان نصب به اینترنت یا package index
نیازی ندارد.

این wheelhouse وابسته به پلتفرم است. برای Linux، Python دیگری، یا معماری دیگر،
باید همین پوشه روی همان پلتفرم با `pip download` مجدداً تولید شود؛ استفاده از
wheelهای Windows روی آن محیط پشتیبانی نمی‌شود.

## اجرای برنامه بدون اینترنت عمومی

پس از نصب، برنامه از فایل‌های محلی `app/static/`، فونت‌ها، لوگوها و کتابخانه
`persian-date` استفاده می‌کند و به CDN، Google Fonts یا API عمومی متصل نمی‌شود.
ارتباط‌های زیر شبکه داخلی و اختیاریِ deployment هستند و برای قابلیت مربوطه باید
در دسترس باشند:

- SQL Server/ODBC برای داده‌های سامانه
- فایل محلی Access برای اطلاعات Araz
- دستگاه Araz در آدرس تنظیم‌شده
- `tools/bridge_agent.py` و آدرس bridge در `tools/bridge_config.json`

این موارد اینترنت عمومی نیستند و حذف آن‌ها باعث حذف قابلیت‌های دستگاه/همگام‌سازی
می‌شود. برای اجرای کاملاً مستقل، قابلیت‌های دستگاه و bridge را غیرفعال یا آدرس
آن‌ها را به سرویس‌های داخل همان LAN تنظیم کنید.

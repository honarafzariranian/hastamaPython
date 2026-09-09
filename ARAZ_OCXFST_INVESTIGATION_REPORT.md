# گزارش تحقیق: فایل گمشده ARAZ_OCXFST.OCX

## تاریخ: ۱۴۰۴/۰۶/۰۶

---

## ۱. آیا ARAZ_OCXFST.OCX پیدا شد؟

**خیر.** فایل `ARAZ_OCXFST.OCX` به صورت مستقل در هیچ مسیری روی این سیستم وجود ندارد.

**اما:** این فایل **داخل فایل نصب‌کننده `Setuparaz.exe` (40MB) جاسازی شده** و با جستجوی دودویی در بایت‌های نصب‌کننده، مسیر آن پیدا شد:

```
Setup\Files\Araz_Ocxfst.ocx
```

در offset `0x1bfed6c` از فایل `Setuparaz.exe`.

---

## ۲. مسیر دقیق فایل

| آیتم | مقدار |
|------|-------|
| فایل حاوی | `E:\Backup\36\arazin\Setuparaz.exe` (40,383,090 بایت) |
| مسیر داخل نصب‌کننده | `Setup\Files\Araz_Ocxfst.ocx` |
| وضعیت | **جاسازی شده در آرشیو فشرده** — نیاز به استخراج دارد |
| مسیر نصب ویندوزی اصلی | `C:\Program Files (x86)\Aras Electronics\Aras Personnel System\` (طبق Server.ini) |

**توضیح:** نصب‌کننده از فشرده‌سازی استفاده می‌کند و فایل‌های OCX را در بخش فشرده نگهداری می‌کند. برای استخراج نیاز به ابزار 7-Zip یا مشابه آن است.

---

## ۳. اطلاعات فایل

| ویژگی | مقدار |
|--------|-------|
| نام فایل اصلی | `Araz_Ocxfst.ocx` |
| نام ProgID | `Araz_Ocxfst.Araz` |
| نام کلاس در Araz.exe | `Araz1`، `ArazOcx1`، `Arazocx` |
| نوع فایل | VB6 ActiveX OCX Control |
| اندازه تخمینی | نامشخص (فشرده شده) |
| معماری | 32-bit (VB6) |

---

## ۴. اطلاعات Registry

**هیچ اطلاعاتی ثبت نشده.** دلیل: فایل OCX هرگز روی این سیستم نصب نشده است.

جستجوهای انجام شده:
- `HKCR\CLSID` با فیلتر "Araz" → **خالی**
- `HKLM\SOFTWARE` با فیلتر "Araz" → **خالی**
- `HKLM\SOFTWARE\WOW6432Node` با فیلتر "Araz" → **خالی**
- `HKCR\.ocx` → **خالی**
- Uninstall entries → **خالی**

**نتیجه:** این سیستم (Hastama Server) هرگز `Araz.exe` یا OCXهای مرتبط را نصب نکرده است.

---

## ۵. وابستگی‌های ARAZ_OCXFST.OCX (از تحلیل Araz.exe)

از تحلیل باینری `Araz.exe` مشخص شد این OCX:

**وابستگی‌های مستقیم:**
- `ARAZ_OCXFST.OCX` ← خودش (کنترل ارتباطی اصلی)
- `Araz_Lock_OCX_NEW.ocx` ← قفل/لایسنس (موجود در نصب‌کننده)
- `Tinyx86(ver5.6.8).ocx` ← کتابخانه ارتباطی پایه (موجود در arazin)
- `MSVBVM60.DLL` ← Runtime VB6 (ویندوز)

**کلاس‌های مرتبط در Araz.exe:**
```
cConnection          ← مدیریت اتصال
cDevice              ← مدیریت دستگاه
cPrsInOut            ← رکوردهای حضور و غیاب
cArazOcxFunctions    ← توابع OCX آراز
frmConnection        ← فرم اتصال
frmDevices           ← فرم دستگاه‌ها
SpecificDevice       ← دستگاه خاص
SpecificDevice_Launch ← راه‌اندازی دستگاه
getportnum           ← دریافت شماره پورت
```

---

## ۶. نقش Tinyx86.ocx

**Tinyx86.ocx = Tiny.ocx** — کتابخانه ارتباطی پایه

| ویژگی | مقدار |
|--------|-------|
| نام داخلی | `Tiny.ocx` / `TinyPlus` |
| اندازه | 1,339,904 بایت |
| نوع | C++ MFC/ATL ActiveX Control |
| وارد شده | `WS2_32.dll` (TCP/IP) + `HID.DLL` (USB HID) |
| رمزگذاری | `CEncryptNet` |
| اشتراک پورت | `CSharePort` |

**توابع صادر شده:**
```
Connect HID              ← اتصال USB
TinyDisconnect           ← قطع اتصال
TinyHIDDidconnect        ← قطع USB
GetSerialNumberHID       ← شماره سریال USB
DisconnectFromTinyHID    ← قطع USB
SetTimerHID / GetTimerHID ← تایمر
CheckPortSharing         ← بررسی اشتراک پورت
CreateTinySocket         ← ایجاد سوکت TCP
```

**ویژگی‌ها:**
```
MServerIP                ← آدرس IP دستگاه
ServerIP                 ← آدرس IP سرور
UserPassWord             ← رمز عبور
SerialNumber             ← شماره سریال
strUserKeyWW             ← کلید کاربر
strSafeKey1W             ← کلید امنیتی ۱
strSafeKey2W             ← کلید امنیتی ۲
```

**نتیجه:** Tinyx86.ocx لایه پایه ارتباطی است. ARAZ_OCXFST.OCX روی آن سوار شده و پروتکل اختصاصی آراز را پیاده‌سازی می‌کند.

---

## ۷. نقش ArazLCS.dll

**ArazLCS.dll = فقط DRM/لایسنس‌گذاری**

| ویژگی | مقدار |
|--------|-------|
| اندازه | 32,768 بایت |
| نوع | VB6 COM DLL |
| صادرات | `DllCanUnloadNow`, `DllGetClassObject`, `DllRegisterServer`, `DllUnregisterServer` |
| توابع کلیدی | `GetProductKey`, `CheckLicense`, `LicenseStart`, `LicenseEnd` |
| رمزگذاری | `CryptAcquireContextA`, `CryptCreateHash`, `CryptEncrypt`, `CryptDecrypt` |
| متغیرها | `lock_serial`, `license_key`, `lock_data` |

**نتیجه:** این فایل **هیچ ارتباطی با دستگاه حضور و غیاب ندارد**. فقط لایسنس نرم‌افزار را بررسی می‌کند.

---

## ۸. روش احتمالی ارتباط با Araz T7

```
Araz.exe (VB6)
  └── ARAZ_OCXFST.OCX ← پروتکل اختصاصی آراز (فاقد فایل)
        └── Tinyx86.ocx (TinyPlus) ← TCP/IP + USB HID + رمزگذاری
              └── سوکت TCP → دستگاه Araz T7
```

**مراحل احتمالی ارتباط:**
1. Araz.exe تنظیمات دستگاه (IP، پورت) را از `Server.ini` می‌خواند
2. `ARAZ_OCXFST.OCX` با استفاده از `Tinyx86.ocx` اتصال TCP برقرار می‌کند
3. احراز هویت اولیه با استفاده از `strUserKeyWW`، `strSafeKey1W`، `strSafeKey2W` انجام می‌شود
4. دستورات به صورت بسته‌های رمزگذاری شده ارسال می‌شوند
5. پاسخ‌ها رمزگشایی و پردازش می‌شوند

---

## ۹. اطلاعاتی که هنوز نامشخص است

| # | سؤال | اهمیت |
|---|------|-------|
| 1 | **فریمینگ واقعی بسته‌های پروتکل چیست؟** | بحرانی |
| 2 | **آیا handshaking/احراز هویت اولیه لازم است؟** | بحرانی |
| 3 | **آیا داده رمزگذاری می‌شود (CEncryptNet)؟** | بحرانی |
| 4 | `header_filler` در هدر پروتکل چه مقداری است؟ | مهم |
| 5 | آیا `ARAZREQPROTO0002` در پروتکل واقعی استفاده می‌شود؟ | مهم |
| 6 | دستگاه واقعی روی چه پورتی گوش می‌دهد؟ | مهم |

---

## ۱۰. بهترین قدم بعدی

### قدم ۱ (فوری): استخراج ARAZ_OCXFST.OCX از نصب‌کننده

فایل `Setuparaz.exe` در مسیر زیر موجود است:
```
E:\Backup\36\arazin\Setuparaz.exe
```

برای استخراج:
```bash
# با 7-Zip:
7z x "E:\Backup\36\arazin\Setuparaz.exe" -o"E:\ArazExtracted"

# یا اگر 7-Zip نصب باشد:
# راست کلیک → 7-Zip → Extract Here
```

پس از استخراج، فایل `Araz_Ocxfst.ocx` قابل تحلیل خواهد بود.

### قدم ۲: تحلیل OCX استخراج شده
- بررسی export functions
- بررسی رشته‌های مرتبط با پروتکل
- بررسی وابستگی‌ها

### قدم ۳: ضبط ترافیک
- اگر OCX به تنهایی کافی نباشد، نیاز به Wireshark است

---

## خلاصه

| وضعیت | توضیح |
|--------|-------|
| `ARAZ_OCXFST.OCX` | ❌ به صورت مستقل وجود ندارد |
| مکان یابی | ✅ داخل `Setuparaz.exe` پیدا شد |
| استخراج | ⏳ نیاز به 7-Zip دارد |
| `Tinyx86.ocx` | ✅ موجود — TCP + USB HID |
| `ArazLCS.dll` | ✅ موجود — فقط DRM |
| پروتکل | ⏳ نامشخص — منتظر استخراج OCX |

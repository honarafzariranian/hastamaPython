# گزارش فنی بررسی پروتکل ارتباطی دستگاه حضور و غیاب آراز T7

## تاریخ: ۱۴۰۴/۰۶/۰۶

---

## ۱. خلاصه اجرایی

دستگاه حضور و غیاب آراز T7 از یک **پروتکل اختصاصی چند لایه** استفاده می‌کند که در یک فایل OCX به نام `ARAZ_OCXFST.OCX` پیاده‌سازی شده است. این فایل **در پوشه `arazin` موجود نیست** و بدون آن، امکان بازیابی کامل پروتکل وجود ندارد. ابزار کمکی `T7Broker.exe` که به صورت .NET نوشته شده، بخشی از پروتکل را آشکار می‌کند اما تمام جزئیات بسته‌بندی (framing) و احراز هویت در `ARAZ_OCXFST.OCX` پنهان است.

---

## ۲. یافته‌های تأیید شده (Confirmed Facts)

### ۲.۱. معماری نرم‌افزاری

```
آراز.exe (VB6, 5MB)
  ├── ARAZ_OCXFST.OCX ← ❌ موجود نیست - کنترل ارتباطی اصلی
  ├── Araz_Lock_OCX_NEW.ocx ← قفل/لایسنس
  ├── Tinyx86(ver5.6.8).ocx ← کتابخانه ارتباطی پایه (TinyPlus)
  └── ArazLCS.dll ← لایسنس‌گذاری (فقط DRM)

T7Broker.exe (.NET 2.0, 53KB)
  └── ارتباط مستقیم با دستگاه از طریق TcpClient
```

### ۲.۲. نقش هر فایل

| فایل | اندازه | نوع | نقش |
|------|--------|------|------|
| `Araz.exe` | 5.2MB | VB6 | نرم‌افزار اصلی مدیریت دستگاه |
| `ARAZ_OCXFST.OCX` | ❌ موجود نیست | VB6 OCX | **پیاده‌سازی پروتکل ارتباطی** |
| `Tinyx86(ver5.6.8).ocx` | 1.3MB | C++ MFC/ATL | کتابخانه ارتباطی پایه (TCP + USB HID) |
| `ArazLCS.dll` | 32KB | VB6 COM | لایسنس‌گذاری/DRM (ارتباط ندارد) |
| `sx32w.dll` | 225KB | C | Sentinel HASP SRM (قفل سخت‌افزاری) |
| `T7Broker.exe` | 53KB | .NET 2.0 | ابزار خط فرمان برای ارتباط با دستگاه |

### ۲.۳. Tinyx86.ocx — کتابخانه ارتباطی پایه

این فایل **کتابخانه اصلی ارتباط** است و دو روش ارتباطی را پشتیبانی می‌کند:

**ارتباط شبکه (TCP/IP):**
- وارد شده: `WS2_32.dll` (Windows Sockets 2)
- کلاس‌ها: `CTinySocket`، `CAsyncSocket`، `CSocket`، `CSocketFile`
- رمزگذاری شبکه: `CEncryptNet`
- اشتراک پورت: `CSharePort`

**ارتباط USB HID:**
- وارد شده: `HID.DLL`
- توابع: `Connect HID`، `GetSerialNumberHID`، `DisconnectFromTinyHID`، `SetTimerHID`، `GetTimerHID`

**توابع صادر شده:**
```
Connect HID
TinyDisconnect
TinyHIDDidconnect
GetSerialNumberHID
DisconnectFromTinyHID
SetTimerHID / GetTimerHID
CheckPortSharing
CreateTinySocket
```

**ویژگی‌ها/متغیرها:**
```
MServerIP          ← آدرس IP دستگاه
ServerIP           ← آدرس IP سرور
UserPassWord       ← رمز عبور کاربر
SerialNumber       ← شماره سریال
strUserKeyWW       ← کلید کاربر
strSafeKey1W       ← کلید امنیتی ۱
strSafeKey2W       ← کلید امنیتی ۲
TPLUS_SERIALNUMBERWWX  ← شماره سریال TinyPlus
TPLUS_TIMERWX           ← تایمر TinyPlus
```

**پیام‌های خطا:**
```
Server Socket failed to send: %d
DisConnect TPlus Net Error: %d
ONTimer Net Error: %d
```

### ۲.۴. ArazLCS.dll — فقط لایسنس‌گذاری

این فایل **هیچ ارتباطی با دستگاه ندارد**. فقط یک ماژول DRM است:
- توابع: `GetProductKey`، `CheckLicense`، `LicenseStart`، `LicenseEnd`
- متغیرها: `lock_serial`، `license_key`، `lock_data`
- رمزگذاری: `CryptAcquireContextA`، `CryptCreateHash`، `CryptHashData`، `CryptEncrypt`، `CryptDecrypt`

### ۲.۵. T7Broker.exe — پروتکل شناسایی شده

این فایل یک ابزار .NET 2.0 است که **مستقیماً** با دستگاه ارتباط برقرار می‌کند:

**ثابت‌های پروتکل:**
```
هدر درخواست:  ARAZREQPROTO0002  (16 بایت ASCII)
هدر پاسخ:     ARAZRESPROTO0002  (16 بایت ASCII)
```

**جداکننده‌ها (ASCII control characters):**
```
File Separator (FS):    0x1C (28)
Group Separator (GS):   0x1D (29)
Record Separator (RS):  0x1E (30)
Unit Separator (US):    0x1F (31)
```

**دستورات (Verbs):**
```
get_current_time        ← دریافت زمان دستگاه
set_current_time        ← تنظیم زمان دستگاه
get_records             ← دریافت رکوردهای حضور و غیاب
get_images              ← دریافت تصاویر
test_connection         ← تست اتصال
end_of_data             ← پایان داده‌ها
finger_template_operation ← عملیات اثرانگشت
sending_employee_info   ← ارسال اطلاعات کارمند
sending_employee_reports ← ارسال گزارش‌ها
sending_employee_messages ← ارسال پیام‌ها
sending_relay_schedule  ← ارسال برنامه رله
sending_prayer_times    ← ارسال اوقات شرعی
```

**فرمت رکورد:**
```
{0:D2}{1:D2}{2:D2}\t{3:D8}\t{4:D2}{5:D2}\t{6:D2}\t{7:D2}
= YYMMDD<tab>CardNo<tab>HHMM<tab>InOutType<tab>Flag
مثال: 040526\t00096142\t0822\t00\t01
```

**انواع رکورد:**
```
00 = enter_exit (ورود/خروج عادی)
02 = hourly_leave (مرخصی ساعتی)
03 = hourly_mission (ماموریت ساعتی)
04 = service_delay (تاخیر خدماتی)
05 = type2_leave (مرخصی نوع ۲)
06 = type2_mission (ماموریت نوع ۲)
07 = time_change (تغییر زمان)
```

**تنظیمات پیش‌فرض:**
```
IP پیش‌فرض:     192.168.1.237
پورت پیش‌فرض:   4370 (همانند ZKTeco)
شماره دستگاه:   01
توکن احراز هویت: 48F196489DF148B9B0FE18C9E506046D
```

**رابط خط فرمان:**
```
T7Broker.exe -command t7getenterexit -ip <IP> -port <PORT> -devno <DEVNO> -start <START> -end <END>
```

### ۲.۶. فرمت فایل متنی T7PrsInOutLast.txt

```
YYMMDD\tCardNo\tHHMM\t00\t01
040526\t00096142\t0822\t00\t01
```

این فرمت **دقیقاً** با فرمت رکورد T7Broker.exe مطابقت دارد (US[083]):
```
{0:D2}{1:D2}{2:D2}\t{3:D8}\t{4:D2}{5:D2}\t{6:D2}\t{7:D2}
```

---

## ۳. شواهد قوی (Strong Evidence)

### ۳.۱. ARAZ_OCXFST.OCX فایل کلیدی گمشده است

**شواهد:**
- `Araz.exe` به `ARAZ_OCXFST.OCX` با نام کلاس `Araz_Ocxfst.Araz` ارجاع می‌دهد
- ۴ بار در `Araz.exe` به این OCX ارجاع داده شده
- نام‌های متغیر: `Araz1`، `ArazOcx1`، `Arazocx`
- کلاس‌های مرتبط: `cConnection`، `cDevice`، `cPrsInOut`، `cArazOcxFunctions`

**نتیجه:** پیاده‌سازی واقعی پروتکل ارتباطی در این فایل OCX است که در پوشه `arazin` موجود نیست.

### ۳.۲. TinyPlus پروتکل اختصاصی خود را دارد

**شواهد:**
- Tinyx86.ocx از `CTinySocket` و `CEncryptNet` استفاده می‌کند
- رمزگذاری شبکه وجود دارد (`strUserKeyWW`، `strSafeKey1W`، `strSafeKey2W`)
- احراز هویت وجود دارد (`UserPassWord`، `SerialNumber`)
- خطاها به "TPlus Net Error" اشاره دارند

**نتیجه:** ارتباط با دستگاه از طریق پروتکل TinyPlus انجام می‌شود که رمزگذاری و احراز هویت دارد.

### ۳.۳. دستگاه فقط پورت 1001 را باز نگه می‌دارد

**شواهد از تست‌های شبکه:**
- پورت 1001: اتصال TCP برقرار می‌شود ✅
- ارسال هر داده‌ای: اتصال بسته می‌شود ❌
- ارسال هیچ داده‌ای (فقط اتصال): اتصال باقی می‌ماند ✅
- همه پورت‌های دیگر (80, 443, 5005, 4370): بسته هستند ❌
- اسکن کامل پورت: فقط 1001 باز است

**نتیجه:** دستگاه فقط یک پورت باز دارد (1001) و هر داده‌ای که ارسال شود باعث بسته شدن اتصال می‌شود. این **رفتار پروتکل** است، نه فیلتر IP.

### ۳.۴. پروتکل T7Broker ممکن است صحیح باشد اما framing اشتباه است

**شواهد:**
- دستگاه TCP را قبول می‌کند (پورت 1001 باز است)
- اما داده را رد می‌کند (اتصال بسته می‌شود)
- `ARAZREQPROTO0002` در T7Broker یک ثابت است اما ممکن است در پروتکل واقعی به صورت دیگری ارسال شود

**نتیجه:** احتمالاً فریمینگ بسته (packet framing) اشتباه است، نه خود پروتکل.

---

## ۴. فرضیات (Assumptions)

### ۴.۱. فرضیه اول: پروتکل TinyPlus است
- **فرض:** دستگاه به جای `ARAZREQPROTO0002`، پروتکل TinyPlus را صحبت می‌کند
- **دلیل:** Tinyx86.ocx کتابخانه ارتباطی پایه است
- **weakness:** T7Broker.exe مستقیماً از `TcpClient` استفاده می‌کند، نه TinyPlus
- **وضعیت:** فرضیه ضعیف

### ۴.۲. فرضیه دوم: فریمینگ اشتباه است
- **فرض:** پروتکل `ARAZREQPROTO0002` صحیح است اما بسته‌بندی (framing) متفاوت است
- **دلیل:** `header_filler` و `request_id` ممکن است مقادیر خاصی داشته باشند
- **weakness:** بدون `ARAZ_OCXFST.OCX` نمی‌توان فریمینگ واقعی را فهمید
- **وضعیت:** فرضیه قوی‌تر

### ۴.۳. فرضیه سوم: احراز هویت اولیه لازم است
- **فرض:** قبل از ارسال دستورات، باید یک handshaking/Authentication اولیه انجام شود
- **دلیل:** TinyPlus دارای `strUserKeyWW`، `strSafeKey1W`، `strSafeKey2W` است
- **weakness:** T7Broker.exe هیچ handshakingی نشان نمی‌دهد
- **وضعیت:** فرضیه متوسط

---

## ۵. اطلاعات نامشخص (Unknown Information)

| # | سؤال | اهمیت |
|---|------|-------|
| 1 | فریمینگ واقعی بسته‌های پروتکل چیست؟ | بحرانی |
| 2 | آیا handshaking/احراز هویت اولیه وجود دارد؟ | بحرانی |
| 3 | `header_filler` در هدر پروتکل چه مقداری است؟ | مهم |
| 4 | آیا `ARAZ_OCXFST.OCX` از TinyPlus استفاده می‌کند یا پروتکل مستقلی دارد؟ | مهم |
| 5 | آیا رمزگذاری شبکه فعال است؟ | مهم |
| 6 | دستگاه واقعی روی چه پورتی گوش می‌دهد؟ | مهم |
| 7 | آیا دستگاه واقعی `ARAZREQPROTO0002` را می‌فهمد یا فقط TinyPlus؟ | مهم |

---

## ۶. چرا کانکتور فعلی Hastama رد می‌شود

**دلیل:** کانکتور فعلی بسته‌ای با فرمت زیر ارسال می‌کند:

```
ARAZREQPROTO0002 (16 بایت)
+ request_id (2 بایت LE)
+ device_number (2 بایت LE)
+ verb + GS + RS
```

اما دستگاه این بسته را **قادر به پردازش نیست** و اتصال را می‌بندد.

**دلایل احتمالی:**
1. **فریمینگ اشتباه:** شاید باید یک هدر TCP اضافه شود (مثل TinyPlus)
2. **رمزگذاری:** شاید باید داده رمزگذاری شود (CEncryptNet)
3. **احراز هویت اولیه:** شاید باید ابتدا handshaking انجام شود
4. **پورت اشتباه:** شاید پورت واقعی دستگاه 1001 نیست

---

## ۷. آیا ارتباط مستقیم از Hastama فنی ممکن است؟

**پاسخ: بله، اما با شرایطی.**

**شرایط لازم:**
1. فایل `ARAZ_OCXFST.OCX` باید پیدا شود (از سیستمی که Araz.exe روی آن نصب است)
2. یا با استفاده از Wireshark، ترافیک واقعی بین Araz.exe و دستگاه ضبط شود
3. یا `T7Broker.exe` روی سیستمی که به دستگاه دسترسی دارد اجرا شود تا پاسخ دستگاه مشاهده شود

**рынک عملی:**
- اگر `ARAZ_OCXFST.OCX` پیدا شود → می‌توان پروتکل را کامل بازیابی کرد
- اگر ترافیک ضبط شود → می‌توان پروتکل را از روی بسته‌ها فهمید
- اگر `T7Broker.exe` اجرا شود → می‌توان پاسخ دستگاه را دید و فریمینگ را فهمید

---

## ۸. حداقل پیاده‌سازی برای حذف کامل Araz.exe

### مرحله ۱: بازیابی پروتکل (الزامی)
- [ ] پیدا کردن `ARAZ_OCXFST.OCX` از سیستم نصب شده
- [ ] یا ضبط ترافیک با Wireshark بین Araz.exe و دستگاه
- [ ] یا اجرای `T7Broker.exe` و مشاهده پاسخ دستگاه

### مرحله ۲: پیاده‌سازی کانکتور (پس از بازیابی پروتکل)
- [ ] پیاده‌سازی فریمینگ صحیح بسته
- [ ] پیاده‌سازی handshaking/احراز هویت
- [ ] پیاده‌سازی رمزگذاری (اگر لازم باشد)
- [ ] تست با دستگاه واقعی

### مرحله ۳: یکپارچه‌سازی با Hastama
- [ ] اضافه کردن endpoint دریافت رکورد
- [ ] هماهنگ‌سازی با جدول hozoor
- [ ] تست نهایی

---

## ۹. توصیه‌های فوری

### کار فوری ۱: پیدا کردن `ARAZ_OCXFST.OCX`
از سیستمی که `Araz.exe` روی آن نصب و در حال اجرا است:
```
# در Windows Explorer:
dir /s /b C:\*.ocx | findstr ARAZ
# یا
reg query HKCR\CLSID /s /f Araz_Ocxfst
```

### کار فوری ۲: ضبط ترافیک
با Wireshark روی سیستمی که به دستگاه متصل است:
```
# فیلتر Wireshark:
host 192.168.3.200 and port 1001
# سپس Araz.exe را اجرا کنید و عملیات دریافت رکورد را انجام دهید
```

### کار فوری ۳: اجرای T7Broker.exe
```
T7Broker.exe -command t7testconnection -ip 192.168.3.200 -port 1001 -devno 01
```
اگر این دستور روی سیستم متصل به دستگاه اجرا شود و پاسخ دهد، فریمینگ واقعی مشخص می‌شود.

---

## ۱۰. نتیجه‌گیری

پروتکل ارتباطی آراز T7 **اختصاصی و چند لایه** است:

1. **لایه پایه:** TinyPlus (Tinyx86.ocx) — TCP/IP + USB HID + رمزگذاری
2. **لایه میانی:** ARAZ_OCXFST.OCX — پروتکل اختصاصی آراز (گمشده)
3. **لایه بالا:** Araz.exe / T7Broker.exe — رابط کاربری

**بدون فایل `ARAZ_OCXFST.OCX` یا ضبط ترافیک، امکان بازیابی کامل پروتکل وجود ندارد.** ثابت‌های پروتکل (`ARAZREQPROTO0002`) شناسایی شده‌اند اما فریمینگ و احراز هویت نامشخص است.

**اقدام بعدی:** پیدا کردن `ARAZ_OCXFST.OCX` یا ضبط ترافیک شبکه.

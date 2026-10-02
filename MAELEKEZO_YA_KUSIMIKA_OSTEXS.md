# MWONGOZO WA KUSIMIKA MFUMO WA MATOKEO (OSTEXS.COM)
### PHP / Laravel 9 / MySQL / Blade Result Management System

Mfumo huu umehama kikamilifu kutoka Python/Flask/SQLite kwenda **PHP/Laravel/MySQL/Blade** bila kubadilisha muonekano (UI), HTML/CSS/JS, hesabu za madaraja, wastani, mikondo ya madarasa (streams), au sheria za kiusalama.

---

## 1. Muundo wa Mfumo (Project Structure)

| Saraka / Faili | Maelezo |
|---|---|
| `app/Http/Controllers/` | Controllers 16 zinazosimamia michakato yote (Wanafunzi, Madarasa, Masomo, Mitihani, Alama, Matokeo, Takwimu, Ripoti, Watumiaji, Mipangilio, n.k.) |
| `app/Models/` | Eloquent Models 9 (`User`, `SchoolClass`, `Subject`, `Student`, `Examination`, `Mark`, `ExamClassStatus`, `Setting`, `AuditLog`) |
| `app/Services/SchoolService.php` | Hesabu zote za madaraja (A: 81-100, B: 61-80.9, C: 41-60.9, D: 21-40.9, E: 0-20.9), maneno ya pongezi (`remark_for`), mgawanyo wa masomo (Std 1-3 vs Std 4-7), utambuzi wa jinsia, na logi za ukaguzi |
| `app/Services/PdfReportService.php` | Huduma ya kutengeneza ripoti za PDF (Ripoti ya mwanafunzi na Class Result Sheet) |
| `app/Services/BackupService.php` | Mfumo wa kutengeneza na kurejesha backup za database kiotomatiki na mikononi (MySQL/SQLite) |
| `app/Http/Middleware/` | Ulinzi wa usalama: `EnsureAuthenticated`, `EnsureHeadmaster`, `EnforcePasswordChange`, `CheckIdleTimeout` (dakika 10 za kutotumika), na `NoCacheHeaders` |
| `database/migrations/` | Migrations 10 zinazounda majedwali yote rasmi ya mfumo |
| `database/seeders/DatabaseSeeder.php` | Seeder inayoingiza madarasa 17 (mikondo ya A, B, C), masomo 9 rasmi, mitihani 4, na akaunti chaguomsingi ya Mkuu wa Shule |
| `database/ostexs_school_system_mysql.sql` | Faili la SQL lililokamilika tayari kuingizwa (import) moja kwa moja kwenye phpMyAdmin ya cPanel |
| `resources/views/` | Violezo vyote vya Blade (templates 28 + components) vinavyolingana 100% na Jinja2 ya awali |
| `public/` | Faili za static (`css/style.css`, `js/script.js`, nembo `images/logo.png`, `index.php`, `.htaccess`) |
| `routes/web.php` | Njia zote 56 za URL zilizopangwa kwa usahihi kulingana na mfumo wa awali |

---

## 2. Hatua za Kusimika Kwenye cPanel (OSTEXS.COM)

### Hatua ya 1: Unda Database ya MySQL Kwenye cPanel
1. Ingia kwenye cPanel ya **OSTEXS.COM**.
2. Nenda kwenye **MySQL Databases**.
3. Unda database mpya (mfano: `ostexs_results`).
4. Unda mtumiaji mpya wa database (mfano: `ostexs_user`) na weka nenosiri imara.
5. Unganisha mtumiaji huyo na database kwa kumpa ruhusa zote (**ALL PRIVILEGES**).

### Hatua ya 2: Ingiza Schema ya Database
1. Kwenye cPanel, fungua **phpMyAdmin**.
2. Chagua database uliyounda (`ostexs_results`).
3. Bonyeza kichupo cha **Import**.
4. Chagua faili la `database/ostexs_school_system_mysql.sql` lililopo kwenye kifurushi hiki na ubofye **Go / Import**.
*(Au unaweza kutumia amri ya SSH: `php artisan migrate --seed`)*.

### Hatua ya 3: Pakia Faili za Mfumo (Upload Files)
1. Kwenye cPanel, fungua **File Manager**.
2. Unaweza kupakia faili la zip `ostexs_school_system_laravel.zip` moja kwa moja.
3. Kwenye root ya akaunti yako ya cPanel (mfano nje ya `public_html`, au ndani ya folda la sub-domain au domain husika):
   - Weka faili zote za mfumo.
   - Hakikisha faili za `public/` zinaelekezwa kama `DocumentRoot` au unaelekeza `public_html` kusoma `public/index.php`.
   - Kama unaweka ndani ya folda moja kwa moja, faili la `.htaccess` lililopo kwenye root na `public/.htaccess` limeandaliwa kuongoza trafiki bila hitilafu.

### Hatua ya 4: Weka Faili la Mipangilio (`.env`)
1. Nakili `.env.ostexs.example` na ulipe jina la `.env`.
2. Weka taarifa za database uliyounda katika Hatua ya 1:
   ```env
   APP_NAME="Result Management System"
   APP_ENV=production
   APP_KEY=
   APP_DEBUG=false
   APP_URL=https://ostexs.com

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=jina_la_database_yako
   DB_USERNAME=jina_la_mtumiaji_wako
   DB_PASSWORD=nenosiri_lako_la_database
   ```

### Hatua ya 5: Ruhusa za Mafolda (Folder Permissions)
Hakikisha mafolda yafuatayo yana ruhusa ya kuandika (`chmod 775` au `chmod 755`):
- `storage/` (na mafolda yote ya ndani `storage/framework/`, `storage/logs/`, `storage/app/`)
- `bootstrap/cache/`

---

## 3. Taarifa za Kuingia Kwenye Mfumo Mara ya Kwanza

- **URL ya Kuingia:** `https://ostexs.com/login`
- **Username:** `headmaster`
- **Password:** `admin123`

*Kumbuka: Kama ilivyokuwa kwenye mfumo wa awali, mfumo utakutaka ubadilishe nenosiri na kuchagua jina jipya la mtumiaji mara tu unapoingia kwa mara ya kwanza kwa usalama wa shule.*

---

## 4. Vipengele Muhimu Vilivyothibitishwa (Verified Features)

1. **Madaraja na Wastani (Exact Decimal Boundaries):**
   - 0 – 20.9 = **E**
   - 21 – 40.9 = **D**
   - 41 – 60.9 = **C**
   - 61 – 80.9 = **B**
   - 81 – 100 = **A**
2. **Kupandisha Madarasa (Promote Students):**
   - Mwalimu mkuu akibadilisha mwaka wa masomo, walimu wa madarasa wanafunguliwa kupandisha wanafunzi wao.
   - Darasa la 7 (Standard 7) linahitimu (Graduate).
3. **Usimamizi wa Mikondo (Dynamic Streams):**
   - Inawezekana kuongeza mkondo wowote mpya (A, B, C, D, n.k.) na masomo yanapangwa kiotomatiki kulingana na darasa la chini (1-3) au la juu (4-7).
4. **Mchakato wa Kuidhinisha Matokeo (Approval Workflow):**
   - Mwalimu wa darasa anajaza na kutuma matokeo (`Submit`).
   - Mkuu wa Shule anakagua, anaidhinisha (`Approve`) au anarudisha kwa marekebisho (`Return for correction`) pamoja na maoni.
5. **Ripoti za PDF:**
   - Ripoti ya kila mwanafunzi (`Student Report Card`) yenye nembo, sahihi, na muhuri rasmi.
   - Orodha ya matokeo ya darasa zima (`Class Result Sheet`).
6. **Usalama:**
   - Kufunga akaunti baada ya majaribio 5 yasiyo sahihi kwa dakika 5 (`Account lockout`).
   - Kutoka kwenye mfumo kiotomatiki baada ya dakika 10 za kutotumika (`Idle session timeout`).
   - Backup ya database inaweza kupakuliwa na kurejeshwa wakati wowote kutoka kwenye Mipangilio.

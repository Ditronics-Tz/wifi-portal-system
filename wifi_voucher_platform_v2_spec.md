# WiFi Voucher Platform v2 — Full System Specification

**Kwa:** Engineer atakayejenga mfumo mpya kamili
**Msingi:** Mfumo wa v1 (voucher portal + RADIUS integration) tayari umejengwa, umepitisha demo, na unafanya kazi live. Angalia `WiFi_Voucher_Portal_Deployment_Report.md` kwa infrastructure iliyopo — **HAIBADILIKI**. Hii spec ni kwa ajili ya kujenga **v2**: layer mpya ya roles (Buyer/Seller/Admin), sales tracking, na reporting juu ya msingi uliopo.

---

## ⚠️ MASUALA YA KUFUATILIA KABLA YA KUANZA (kutoka Deployment Report)

Kabla ya kuanza ujenzi wa v2, engineer LAZIMA athibitishe/arekebishe haya:

1. **AP IP mismatch:** `clients.conf` ina `192.168.100.115` kwa AP, lakini architecture docs za awali zilisema AP ni `192.168.100.133`. **Thibitisha IP halisi ya AP kabla ya kuendelea** — hii inaweza kuwa AP tofauti au typo iliyofanyika wakati wa deployment.
2. **Secrets dhaifu za "testing":** RADIUS shared secret (`testing123`), DB password (`123456789`), admin password (`admin123`) — hizi ni za majaribio TU. **Lazima zibadilishwe kwa production-grade secrets** kabla mfumo haujaanza kupokea malipo halisi ya wateja.
3. Hizi ni security debt kutoka v1 — jumuisha "rotate all secrets" kama task ya kwanza ya v2 checklist, si baadaye.

---

## 1. Muhtasari wa Malengo

Jenga mfumo wenye **roles tatu**:

| Role | Anafanya nini |
|---|---|
| **Buyer** (mteja) | Ananunua/anatumia voucher. Account si lazima (walk-in inafanya kazi kama v1), lakini **inaruhusiwa** — buyer akitaka, anaweza kujisajili ili aone historia ya manunuzi yake |
| **Seller** (mfanyakazi) | Anagenerate voucher codes, **anarekodi malipo ya cash** aliyopokea kutoka kwa buyer, anaona mauzo yake mwenyewe |
| **Admin** | Anaona ripoti ya mauzo yote (ya sellers wote), idadi ya wateja/waliojiunga, anasimamia sellers (kuongeza/kuzima account) |

Malipo ni **cash/manual pekee kwa sasa** — hakuna M-Pesa/Tigo Pesa integration kwa v2 hii, lakini schema iachwe wazi kuongeza hilo baadaye bila kubadilisha structure kubwa (angalia sehemu 8).

---

## 2. Nini Kinabaki Kile Kile (v1 — usiguse)

- Customer-facing voucher entry flow (`public/index.php`) — two-step, redirect params kutoka AP, auto-submit kwenda `/portal/auth`
- `voucher_service.php` — `prepareVoucherForAuth()` timer logic
- FreeRADIUS + PostgreSQL + `radcheck`/`radreply`/`radacct` tables
- AP configuration (External RADIUS Server, PAP, standalone External Web Portal)
- Nginx + PHP-FPM setup, port 8090

**v2 inaongeza tu:** roles/auth layer mpya, jedwali za mauzo/malipo, na dashboards za seller/admin. Voucher generation logic ya zamani (`admin/generate.php`) inahamishwa/inarekebishwa kuwa sehemu ya **Seller** role, si Admin-only kama ilivyokuwa v1.

---

## 3. Roles & Permissions Matrix

| Uwezo | Buyer (bila account) | Buyer (na account) | Seller | Admin |
|---|---|---|---|---|
| Tumia voucher kwenye captive portal | ✅ | ✅ | – | – |
| Jisajili (signup) | – | ✅ | – | – |
| Ona historia yake ya manunuzi | – | ✅ | – | – |
| Generate voucher codes | – | – | ✅ | ✅ |
| Rekodi malipo ya cash | – | – | ✅ | ✅ |
| Ona mauzo yake mwenyewe | – | – | ✅ | ✅ (yote) |
| Ona mauzo ya sellers wote | – | – | – | ✅ |
| Ona idadi ya buyers waliojiunga | – | – | – | ✅ |
| Ongeza/zima seller accounts | – | – | – | ✅ |
| Manual expire/revoke voucher | – | – | ✅ (zake tu) | ✅ (zote) |

---

## 4. Data Model — Jedwali Mpya (juu ya `radius` DB iliyopo)

### `users` (badala ya `admins` table ya v1 — MIGRATE data)
```sql
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    role VARCHAR(16) NOT NULL CHECK (role IN ('admin', 'seller', 'buyer')),
    username VARCHAR(64) UNIQUE,          -- required for admin/seller, optional for buyer
    phone VARCHAR(20) UNIQUE,             -- required for buyer accounts, optional otherwise
    full_name VARCHAR(128),
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    created_by INT REFERENCES users(id)   -- admin who created this seller account
);
```

### `sales` (rekodi ya kila voucher iliyouzwa — kiungo kati ya seller, buyer (optional), na voucher)
```sql
CREATE TABLE sales (
    id SERIAL PRIMARY KEY,
    voucher_code VARCHAR(32) NOT NULL REFERENCES vouchers(code),
    seller_id INT NOT NULL REFERENCES users(id),
    buyer_id INT NULL REFERENCES users(id),        -- NULL kama buyer hana account
    buyer_phone VARCHAR(20) NULL,                   -- rekodi ya haraka hata bila account (kwa reporting)
    plan_name VARCHAR(64) NOT NULL,
    price NUMERIC(10,2) NOT NULL,
    payment_method VARCHAR(16) DEFAULT 'cash',       -- 'cash' kwa sasa; 'mpesa'/'tigopesa' baadaye
    payment_reference VARCHAR(64) NULL,              -- huru kwa sasa, itatumika kwa M-Pesa transaction ID baadaye
    sold_at TIMESTAMP DEFAULT NOW()
);
```

### `vouchers` (iliyopo — ONGEZA column moja)
```sql
ALTER TABLE vouchers ADD COLUMN sale_id INT REFERENCES sales(id);
-- (au tumia sales.voucher_code kama foreign key kinyume chake — chagua muelekeo mmoja tu, usiwe na circular ambiguity)
```

**Muhimu:** Voucher inaweza kuzalishwa (`generate`) bila kuuzwa bado (stock ya vouchers zisizouzwa). `sales` row inaundwa TU wakati seller anarekodi malipo halisi — hii inatenganisha "voucher imezalishwa" na "voucher imeuzwa", ambayo ni tofauti muhimu ya kibiashara.

---

## 5. Auth System

- **Admin na Seller:** lazima wawe na account (username + password), zinaundwa na Admin pekee (hakuna self-signup kwa hizi role mbili)
- **Buyer:** signup ni **optional** — form fupi (jina, namba ya simu, password) inayoweza kufikiwa kutoka customer portal (`public/index.php` iwe na link "Jisajili" isiyo ya lazima)
- Session-based auth (kama v1), password hashing na `PASSWORD_ARGON2ID`
- Role-based access control (RBAC) middleware: kila route inaangalia role kabla ya kuruhusu ufikiaji (mfano seller haruhusiwi kuona `/admin/sellers-management.php`)
- Rate-limiting kwenye login endpoints zote (admin, seller, buyer signup/login)

---

## 6. Feature Specs kwa Role

### 6.1 SELLER Dashboard
- **Generate Vouchers** — chagua plan (Siku 1/Wiki 1/Mwezi 1), quantity, generate codes (kazi hii inahamishwa kutoka Admin-only v1 kwenda Seller, Admin bado anaweza pia)
- **Rekodi Mauzo** — baada ya kuuza voucher ana kwa ana:
  - Chagua/ingiza voucher code iliyozalishwa
  - Weka namba ya simu ya buyer (optional)
  - Confirm bei (auto-fill kutoka plan, editable kama kuna discount)
  - Bonyeza "Rekodi Malipo" → inaunda `sales` row, inaweka `payment_method = 'cash'`
- **Mauzo Yangu** — jedwali la mauzo yake mwenyewe (leo/wiki/mwezi), jumla ya mapato
- **Voucher Stock** — angalia ni vouchers ngapi amezalisha ambazo bado hazijauzwa (status `unused` na hazina `sale_id`)

### 6.2 ADMIN Dashboard
- **Mauzo Yote** — jedwali/chart ya mauzo kutoka sellers wote, iliyoweza ku-filter kwa: tarehe, seller, plan
- **Ripoti ya Jumla** — jumla ya mapato (leo/wiki/mwezi/custom range), idadi ya vouchers zilizouzwa kwa plan
- **Wateja/Waliojiunga** — idadi ya buyers wenye account (signups), idadi ya unique buyer_phone kwenye `sales` (hata wasio na account) — hii ndiyo "wangapi wamejiunga/wamenunua"
- **Seller Management** — ongeza seller mpya (weka username+password), zima/washa account ya seller (`is_active`)
- **Voucher Oversight** — angalia vouchers zote (active/expired/unused), uwezo wa manual-expire voucher yoyote

### 6.3 BUYER (optional account)
- Signup fupi: jina, namba ya simu, password
- Baada ya login: angalia historia ya vouchers alizowahi kutumia (kupitia `sales.buyer_id`), muda uliobaki wa voucher inayotumika sasa (link kwenda `status.php`)
- Buyer asiye na account: bado anatumia mfumo kama v1 kabisa (hakuna kizuizi)

---

## 7. Reporting Queries (mifano kwa Admin dashboard)

```sql
-- Jumla ya mapato kwa siku
SELECT DATE(sold_at), SUM(price) FROM sales
WHERE sold_at >= NOW() - INTERVAL '30 days'
GROUP BY DATE(sold_at) ORDER BY 1;

-- Mauzo kwa kila seller
SELECT u.username, COUNT(*), SUM(s.price)
FROM sales s JOIN users u ON s.seller_id = u.id
GROUP BY u.username;

-- Idadi ya wateja wa kipekee (registered + walk-in kwa namba ya simu)
SELECT COUNT(DISTINCT COALESCE(buyer_phone, 'buyer-' || buyer_id::text)) FROM sales;
```

---

## 8. Structure ya Baadaye kwa Malipo ya Kielektroniki (usifanye sasa, lakini usifunge njia)

- `sales.payment_method` tayari ina uwezo wa kupokea `'mpesa'`/`'tigopesa'` bila kubadilisha schema
- `payment_reference` column tayari ipo kwa ajili ya transaction ID ya baadaye
- Wakati M-Pesa itaongezwa: seller/buyer hataandika malipo manually tena kwa hiyo method — endpoint mpya ya webhook itaunda `sales` row moja kwa moja baada ya malipo kuthibitika. Hakuna kazi ya ziada inayohitajika sasa isipokuwa kutokuwa na "hard assumption" kwamba `payment_method` ni `'cash'` popote kwenye code (fanya iwe configurable variable, si hardcoded string kila mahali)

---

## 9. Vitu vya Usalama vya Ziada kwa v2 (zaidi ya vile vya v1)

- Seller/Admin passwords: bcrypt/argon2, kamwe si plaintext kwenye logs
- Audit trail: kila `sales` row inarekodi `seller_id` na `sold_at` — hii yenyewe ni audit log ya msingi, lakini fikiria kuongeza jedwali la `audit_log` kwa vitendo nyeti (mfano seller ku-deactivate, voucher manual-expire) — nani alifanya, lini
- Buyer signup: validate namba ya simu format (Tanzania: `+255` au `0` prefix, digits 9 baada ya hapo)
- CSRF tokens kwenye forms zote za role zote tatu
- Rotate zile secrets za "testing" kutoka Deployment Report (sehemu ya ⚠️ hapo juu) kabla ya go-live ya v2

---

## 10. Muundo wa Application (juu ya structure ya v1 iliyopo)

```
/var/www/voucher-portal/
├── public/
│   ├── index.php              (v1 — customer voucher flow, HAIBADILIKI)
│   ├── status.php             (v1 — HAIBADILIKI)
│   ├── signup.php             (MPYA — buyer optional signup)
│   ├── login.php              (MPYA — universal login, inaelekeza role sahihi)
│   ├── seller/
│   │   ├── dashboard.php      (mauzo yangu, stock ya vouchers)
│   │   ├── generate.php       (voucher generation, moved kutoka admin-only)
│   │   └── record-sale.php    (rekodi malipo)
│   ├── admin/
│   │   ├── dashboard.php      (mauzo yote, ripoti)
│   │   ├── sellers.php        (seller management)
│   │   └── vouchers.php       (voucher oversight)
│   └── buyer/
│       └── history.php        (buyer aliye na account anaona historia)
├── src/
│   ├── db.php
│   ├── auth.php               (rekebisha kwa RBAC — role check middleware)
│   ├── voucher_service.php    (v1 — HAIBADILIKI)
│   ├── radius_client.php      (v1 — HAIBADILIKI)
│   ├── sales_service.php      (MPYA — record sale, reporting queries)
│   └── user_service.php       (MPYA — signup, seller creation, RBAC helpers)
├── migrations/
│   ├── 001_add_vouchers_table.sql      (v1, tayari imefanyika)
│   └── 002_users_sales_v2.sql          (MPYA — users, sales tables, migrate admins→users)
└── config.php
```

---

## 11. Migration Plan kutoka v1 kwenda v2

1. Rudia zote "MASUALA YA KUFUATILIA" (sehemu ya juu) kwanza — thibitisha AP IP, badilisha secrets
2. Unda `002_users_sales_v2.sql`: unda `users`+`sales` tables, **migrate** existing `admins` table data kwenda `users` (role='admin')
3. Unda seller account(s) za kwanza (Admin anaunda kupitia dashboard mpya, au seed script kwa ajili ya go-live)
4. Hamisha kazi ya "generate voucher" kutoka `admin/generate.php` (v1) kwenda `seller/generate.php` (v2) — Admin bado anaweza kufikia pia (RBAC inaruhusu Admin kufanya kila kitu Seller anachoweza)
5. Ongeza RBAC middleware kwenye `auth.php`, itumike kwenye routes zote mpya
6. **USIGUSE** `public/index.php`, `status.php`, `voucher_service.php`, `radius_client.php` — hizi zinaendelea kufanya kazi kama zilivyo

---

## 12. Deliverables Zinazotarajiwa

1. `002_users_sales_v2.sql` migration (ikiwa na data migration kutoka `admins`)
2. Universal `login.php` inayoelekeza kwa dashboard sahihi kulingana na role
3. Seller dashboard kamili (generate, record-sale, mauzo yangu)
4. Admin dashboard kamili (mauzo yote, ripoti, seller management, voucher oversight)
5. Buyer optional signup + history page
6. `sales_service.php` na `user_service.php`
7. RBAC middleware iliyowekwa kwenye `auth.php`
8. Ripoti fupi ya secrets zipi zimebadilishwa (kwa ajili ya production hardening checklist)
9. Smoke tests mpya kwa kila role (kwa muundo kama ule wa v1 Deployment Report — jedwali la PASS/FAIL)

---

## 13. Nje ya Scope kwa v2 Hii

- M-Pesa/Tigo Pesa integration halisi (schema tu, si implementation)
- SMS notifications kwa buyer
- Multi-location/multi-AP support (bado single AP kwa sasa)
- Seller commission calculation (kama itahitajika baadaye, `sales` table tayari ina data ya msingi kuiongeza)

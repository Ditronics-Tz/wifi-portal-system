# WiFi Voucher System — Documentation ya Mwisho

**Status:** Demo imepita ✅ — mfumo unafanya kazi kama ilivyopangwa

---

## 1. Muhtasari wa Mfumo

Mfumo wa kuuza WiFi access kwa vocha (Siku/Wiki/Mwezi), zenye disconnect ya kiotomatiki muda ukiisha. Voucher duration inaanza kuhesabiwa **tangu mtumiaji atumie voucher mara ya kwanza**, si tangu iuzwe.

**Vipengele vikuu:**
- Admin panel (PHP) — kuzalisha voucher codes
- Customer portal (PHP) — mteja anaweka code, anaunganishwa
- FreeRADIUS + PostgreSQL — authentication backend
- EAP650 (standalone mode) — Access Point, inayoongea moja kwa moja na FreeRADIUS

---

## 2. Topology ya Mwisho

```
[Simu ya Mteja]
      |  connect open SSID "Test-1"
      v
[EAP650 - STANDALONE MODE] (192.168.100.133)
      |  Redirect → External Web Portal (PHP yangu)
      v
[PHP Portal] --(inasoma voucher, inaupdate DB)--> [PostgreSQL: vouchers, radcheck, radreply]
      |
      |  Auto-submit form
      v
[AP inatuma Access-Request (PAP) moja kwa moja] --UDP 1812--> [FreeRADIUS]
      |                                                          (192.168.100.100)
      |<--Access-Accept + Session-Timeout------------------------|
      v
[AP inaruhusu client, inaonyesha Success Page]
      |
      v
Muda ukiisha (Session-Timeout) -> AP inam-disconnect kiotomatiki
Accounting records -> radacct (kupitia port 1813)
```

**Uamuzi muhimu wa mwisho:** Kutokana na kikwazo cha firmware ya EAP650 kwenye standalone mode (External Web Portal inafanya kazi TU pamoja na "External RADIUS Server" auth type), **AP yenyewe** ndiyo inayotuma Access-Request kwenda FreeRADIUS — si PHP moja kwa moja kama ilivyopangwa awali kwenye architecture ya kwanza. PHP inabaki "ubongo" wa mfumo (voucher logic, timer, admin) lakini haishughulikii live RADIUS packet transmission tena.

---

## 3. Infrastructure — Component Table

| Kifaa/Huduma | IP / Port | Kazi |
|---|---|---|
| TTCL Router | 192.168.100.1 | Gateway/Internet |
| Ubuntu Server | 192.168.100.100 | FreeRADIUS + PostgreSQL + PHP Portal + isc-dhcp-server + Mail server |
| EAP650 (standalone) | 192.168.100.133 | AP + RADIUS NAS client + Portal redirect source |
| PHP Voucher Portal | `192.168.100.100:8090` | Voucher entry + Admin panel |
| FreeRADIUS Auth | `192.168.100.100:1812` | Access-Request/Accept (PAP) |
| FreeRADIUS Accounting | `192.168.100.100:1813` | Session accounting (radacct) |
| Voucher clients (DHCP) | 192.168.100.150–220 | Dynamic pool |

---

## 4. AP Configuration (Iliyowekwa na Imepitishwa)

| Setting | Thamani |
|---|---|
| SSID | Test-1 |
| Authentication Type | External RADIUS Server |
| RADIUS Server IP / Port | 192.168.100.100 / 1812 |
| Authentication Mode | PAP |
| RADIUS Accounting | Enabled (1813), Interim Update disabled |
| NAS ID | EAP650-Portal-01 |
| Authentication Timeout | 30 D (inazidi voucher ndefu zaidi ili Session-Timeout ndio idhibiti disconnect halisi) |
| Portal Logout | Enabled (`portal.tplink.net/portal/logout`) |
| Landing Page | The Success Page |
| Portal Customization | External Web Portal → `http://192.168.100.100:8090/` |
| Free Authentication Policy | `192.168.100.100/32`, port `8090` pekee, wazi kwa unauthenticated traffic |

---

## 5. Database Schema (PostgreSQL — DB `radius`)

### Jedwali jipya: `vouchers`
```
id, code (unique), plan_name, duration_seconds, price,
status ('unused' | 'active' | 'expired'),
created_at, first_used_at, expires_at, created_by
```

### Jedwali za FreeRADIUS zilizotumika (hazikubadilishwa structurally)
- `radcheck` — `username = code`, `Cleartext-Password = code`
- `radreply` — `username = code`, `Session-Timeout` (value inabadilika **dynamically** kila voucher inapotumika)
- `radacct` — accounting records kutoka AP (session start/stop, muda halisi)

---

## 6. Voucher Lifecycle Logic (Core Business Rule)

Muda unaanza kuhesabiwa **tangu matumizi ya kwanza**, si tangu kuuzwa:

1. **Admin anagenerate voucher** → `vouchers.status = 'unused'`, `radcheck`+`radreply` zinaundwa (Session-Timeout placeholder = full duration)
2. **Mteja anatumia mara ya kwanza** → PHP inaweka `first_used_at = NOW()`, `expires_at = NOW() + duration`, status → `active`, na inaupdate `radreply.Session-Timeout` = full duration kabla ya kumpeleka AP
3. **Mteja anaungana tena kabla muda haujaisha (reconnect)** → PHP inahesabu muda uliobaki (`expires_at - NOW()`), inaupdate `radreply.Session-Timeout` kwa hiyo thamani ndogo zaidi — hii inazuia mteja "kupata muda mpya" kwa kudisconnect na kureconnect
4. **Muda ukiisha** → status → `expired`, request zinazofuata zinakataliwa na PHP kabla hata AP haijafikiwa

---

## 7. PHP Application — Muundo wa Mwisho

```
/var/www/voucher-portal/
├── public/
│   ├── index.php        -- Two-step: (1) voucher form, (2) auto-submit form → AP /portal/auth
│   └── status.php        -- Look-up page: code → muda uliobaki (haiwezi kuwa auto-redirect
│                              baada ya login kwa sababu AP ndiyo inaonyesha Success Page)
├── admin/
│   ├── login.php, dashboard.php, generate.php, logout.php
│   └── "Test Voucher" button kwenye dashboard — inatumia radius_client.php kwa manual radtest
├── src/
│   ├── db.php
│   ├── radius_client.php  -- SASA ni admin/debug tool TU (manual radtest), si live customer flow
│   ├── voucher_service.php -- prepareVoucherForAuth(): DB read/update logic (HATUA 6 hapo juu)
│   └── auth.php
└── config.php
```

### Mtiririko wa `index.php` (muhimu zaidi kuelewa)
- **Step A:** Inasoma `?target=<ap_ip>&clientMac=<mac>` kutoka AP redirect, inaonyesha form ya voucher code
- **Step B:** Baada ya code kuwekwa, `prepareVoucherForAuth()` inafanya DB check + timer update (transaction-safe), kisha inaonyesha form ya pili inayo-**auto-submit** kwenda `http://<target>/portal/auth` na fields `username`/`password` (voucher code) + `clientMac`

---

## 8. Usalama Uliozingatiwa

- Voucher code input: whitelist regex kabla ya kufika DB
- PDO prepared statements kila mahali
- Admin session-based auth, password ya bcrypt/argon2
- `config.php`, `src/`, `admin/` hazipatikani moja kwa moja bila ku-pitia PHP routing (muhimu zaidi baada ya Free Auth Policy kufungua unauthenticated access kwenye port 8090)
- DB transactions kuzuia race condition endapo voucher inatumika mara mbili kwa wakati mmoja

---

## 9. Testing Iliyofanyika (Demo — PASSED)

- ✅ Portal inaonekana (`curl`/browser) kwenye `192.168.100.100:8090`
- ✅ Admin login + voucher generation (bulk) → DB rows sahihi (`vouchers`+`radcheck`+`radreply`)
- ✅ Manual `radclient` test (kabla ya PHP flow) — Access-Accept + Session-Timeout sahihi
- ✅ Full flow: redirect params → voucher form → auto-submit → AP → RADIUS → Access-Accept
- ✅ First-use timer: `first_used_at`/`expires_at` zinajazwa sahihi wakati wa matumizi ya kwanza
- ✅ Reconnect mid-voucher: `Session-Timeout` inarudishwa kama muda uliobaki, si full duration mpya
- ✅ Voucher expired: inakataliwa kabla ya kufika AP
- ✅ Invalid code: error inayofaa, hakuna crash

---

## 10. Vitu Vilivyobaki / Kufuatilia (Known Follow-ups)

- [ ] **`status.php` UX** — kwa sasa ni look-up page (code → muda uliobaki), si auto-redirect baada ya login moja kwa moja, kwa sababu AP's "Success Page" ndiyo inayoonekana kwanza. Fikiria kuongeza link kwenye Success Page (kama AP inaruhusu customization) ikimwelekeza mteja kurudi `status.php?code=XXX`
- [ ] **CoA/live-disconnect** kwa manual voucher revoke — bado ni stretch goal, haijafanywa
- [ ] **Payment/M-Pesa integration** — bado nje ya scope, vouchers zinauzwa offline kwa sasa
- [ ] **Multi-admin roles** — bado admin mmoja tu
- [ ] **HTTPS** kwa portal — kwa sasa HTTP tu ndani ya LAN, fikiria self-signed cert baadaye
- [ ] **Interim Update** (RADIUS accounting) — imezimwa kwa sasa, inaweza kuwashwa baadaye kama utahitaji real-time "who's online" dashboard

---

## 11. Historia ya Maamuzi Muhimu (kwa rekodi)

1. Awali ilipangwa PHP kuongea moja kwa moja na FreeRADIUS (bila Omada) — hii ilithibitika haiwezekani kiufundi kwenye EAP650 standalone mode
2. Ilithibitishwa (kupitia TP-Link official docs) kwamba External Web Portal + External RADIUS Server = AP yenyewe inatuma Access-Request, si external portal server, kwenye standalone mode
3. Uamuzi wa mwisho: Option A ilichaguliwa — AP-initiated RADIUS, PHP inabaki na udhibiti wa voucher business logic (generation, timer, admin) bila kubadilisha muundo wa admin panel
4. Authentication Mode ilibadilishwa kutoka CHAP (default) kwenda PAP, kulingana na jinsi voucher passwords zinavyohifadhiwa (Cleartext-Password)

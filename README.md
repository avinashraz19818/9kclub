# Dhani Win V34

Ye build supplied PHP/MySQL project ka cleaned and upgraded version hai. Isme user-side gift history timestamp, detailed balance ledger, deposit turnover lock, safe withdrawal reservation/refund flow, missing local images, complete K3/5D/TRX compatibility aur completely rebuilt advanced admin panel included hai.

## Server requirements

- PHP 8.1+ with `mysqli`, `json`, `openssl`, `fileinfo` and sessions
- MySQL 8+ or a modern MariaDB release
- Apache with `mod_rewrite` and `.htaccess` enabled
- All wallet/finance tables must use InnoDB

## Install

1. Full database backup lene ke baad separately supplied `DhaniWin-V34-Database.sql` ko phpMyAdmin me import karein. Script fresh install aur existing normalized install dono ke liye non-destructive `CREATE IF NOT EXISTS`/upsert flow use karti hai; users, wallet history ya finance records drop nahi karti.
2. Existing installation par SQL import ke baad code upload/open karein, jisse runtime compatibility checks remaining legacy columns/indexes repair kar saken.
3. `api/_core/config.php` me database name/user/password aur ek long random `APP_SECRET` set karein.
   Production reverse proxy/CDN use ho to trusted public base URL `DW_PUBLIC_ORIGIN` environment variable me set karein.
4. Website kholne par safe auto-installer remaining compatible tables/columns verify karega.
5. Admin URL: `/admin/`
6. Fresh seed login: username `admin`, password `admin123`. First login par password change compulsory hai.
7. Site Settings me deposit turnover multiplier, withdrawal minimum/daily limits, support links aur feature switches set karein.
8. Payment Methods me apne verified manual payment details configure karein. Supplied demo UPI values ko production me use na karein.

## Finance flow

1. User deposit order banata hai aur UTR/proof submit karta hai.
2. Admin Deposits queue se order approve karta hai.
3. Principal balance me credit hota hai aur default `1×` turnover requirement create hoti hai.
4. User ke valid game bets oldest active requirement ko complete karte hain.
5. Requirement complete hone tak locked principal `withdrawableBalance` me nahi dikhta.
6. Withdrawal submit hote hi amount reserve/deduct hota hai, isliye double-withdraw possible nahi hai.
7. Admin approval par dobara deduction nahi hoti; rejection par automatic `WithdrawReject` refund ledger entry banti hai.

## Fixed user records

- Gift claim response/history me `createTime`, `receiveTime`, `claimTime`, claim number and balance before/after.
- Balance Record me recognised transaction type, order/record number, status, amount, balance before, balance after, description, remark and exact timestamp.
- Game bet uses `GameBet`; result settlement uses `GameEnd`.

## V34 visual, game and support fixes

- Home category ke Lottery, Crash, Slots, Sports, Fish, Casino aur Poker icons ab bundled local SVG files use karte hain.
- Active compiled client ke 720 local image/animation references package me resolve hote hain. Bottom navigation, Earn/Promo icons, invitation-wheel artwork aur legacy hashed filenames ke deterministic local aliases included hain.
- Daily Check-in banner aur claimed-state image local package me included hain; reward claim per-user/per-day database me save hota hai.
- K3 result API exactly three compact dice values bhejta hai aur six local dice faces included hain.
- 5D result API five digits, sum, big/small aur odd/even compatibility fields deta hai.
- TRX history me result ke saath block number, block time aur deterministic hash fields milte hain.
- K3, 5D aur TRX ke 1, 3, 5 aur 10 minute variants seed kiye gaye hain.
- Purana `domain is unAvailable` popup remove hai aur service worker `/admin/` aur `/admin1/` requests ko intercept nahi karta.
- 5D ke legacy aur current dono skins me period/countdown block visible hai; `/webapi/kv/issue/*` aur `/webapi/v/issue/*` requests local lottery issue API par normalize hoti hain.
- Live Support ke purane dead `wallet/feedback` links Self Service Center `#/workOrder` par redirect hote hain; local support icons, ticket form, uploads, comments aur Progress Query included hain.
- Earn page direct invite aur recursive team totals, deposit/bet data, three-level commission entries aur auditable commission receive flow use karta hai.
- Invitation Wheel exactly eight local prize values, transactional spin counter, record avatar, turnover check aur wallet cash-out ledger use karta hai.
- JILI/JDB/Aviator jaise third-party games ke liye official-provider adapter, short-lived launch session, HTTPS host allowlist, signed/idempotent wallet callback aur safe configuration-status page included hai.

## Admin modules

Purple responsive dashboard, users, demo/banned users, deposits, withdrawals, detailed ledger, turnover requirements/events, WinGo/K3/5D/TRX live round controls, gift codes/claims, UPI/USDT methods and image uploads, banners, notifications, VIP, bonus activities, daily agent salary, invite/recharge wheels, support tickets, provider adapters, same-IP risk scan, maintenance, role permissions, login security, audit exports and system health are included. Finance actions are transactional and audit-logged.

`ADMIN_FEATURES.md` me 160 available admin/operations controls ka module-wise map diya gaya hai.

## Security

The supplied reference admin was not merged. Its unauthenticated file manager, command execution, remote updater, Telegram tracking and destructive domain logic were excluded. This build also removes the old owner-license client, debug/download scripts and public installer diagnostics. Admin actions use CSRF protection, permission checks, prepared statements, login rate limiting and secure session cookies.

## Verification

Run:

```bash
node tests/static_checks.js
node tests/frontend_graph_check.js
```

Second check active 705-module frontend graph ke imports/preloads ke saath all 720 local runtime asset references bhi verify karta hai. `tools/repair_runtime_assets.js` aliases ko repeatably regenerate kar sakta hai; is process me koi remote file download nahi hota.

The package is configured as `DEMO_MODE` with a virtual wallet and manual UPI/USDT approval workflow. Local WinGo/K3/5D/TRX can run against this wallet. External games are intentionally disabled until you configure official merchant credentials; the package does not proxy another site's private APIs or credentials. A live gateway/provider should only be enabled with verified access, signed callbacks and legal/compliance review.

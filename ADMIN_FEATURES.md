# Dhani Win Admin — 140 Working Controls

Admin URL: `/admin/`

All state-changing controls use login authentication, CSRF validation, module permissions and an audit record. Finance controls use the shared transactional wallet service.

## Access and security

1. Password-hash based admin login
2. Login attempt rate limiting
3. Secure, HTTP-only and SameSite session cookie
4. Mandatory first-login password change
5. CSRF protection on every POST action
6. Module-level role permissions
7. Super-admin access model
8. Create additional admin accounts
9. Enable or disable permission sets
10. Reset another admin's password
11. Revoke sessions after a password reset
12. Audit login, logout and all admin mutations

## Dashboard and reporting

13. Total-user KPI
14. New-users-today KPI
15. Wallet-liability KPI
16. Approved-deposit KPI
17. Approved-withdrawal KPI
18. Active-turnover-lock KPI
19. Pending-lottery-bet KPI
20. Open-risk-flag KPI
21. Latest deposit queue
22. Latest withdrawal queue
23. User CSV export
24. Deposit CSV export
25. Withdrawal CSV export
26. Ledger CSV export
27. Audit CSV export

## Users and wallet

28. Search by user ID, username, mobile or nickname
29. Filter active and suspended users
30. Paginated user directory
31. Display balance, locked balance and withdrawable balance
32. Suspend or activate a user
33. Revoke all user sessions
34. Credit wallet with a detailed ledger entry
35. Debit wallet with insufficient-balance protection
36. Require a reason for manual balance changes
37. Add configurable turnover to an admin credit
38. Clear a withdrawal PIN
39. Reset a user password
40. Revoke sessions after user-password reset
41. Change a user's VIP level
42. Enable or disable agent status

## Deposits, withdrawals and ledger

43. Search deposits by order, UTR or user
44. Filter deposits by payment state
45. Review submitted UTR and payment proof
46. Approve deposits transactionally
47. Reject pending deposits
48. Credit deposit principal exactly once
49. Credit deposit gift exactly once
50. Create configurable principal turnover on approval
51. Create configurable gift turnover on approval
52. Search and filter withdrawals
53. Display saved withdrawal-account snapshot
54. Reserve withdrawal balance at submission
55. Approve without double deduction
56. Reject with an automatic exact refund
57. Enforce minimum withdrawal
58. Enforce daily withdrawal count
59. Enforce daily withdrawal amount
60. Detailed ledger with order and record numbers
61. Ledger balance-before and balance-after values
62. Ledger descriptions, remarks and exact timestamps
63. Filter ledger by user, type and direction
64. Filter ledger by start and end date

## Turnover control

65. View every active and completed requirement
66. View source deposit/order and locked amount
67. View required, completed and remaining turnover
68. View wager-application events
69. Manually complete a requirement with reason
70. Cancel a requirement with reason
71. Audit every turnover override

## Games and WinGo control

72. Enable or disable games
73. Put individual games into maintenance
74. Configure displayed RTP
75. Configure game sorting
76. Configure lottery auto/win/lose force mode
77. Configure forced result value
78. Configure target win rate
79. Configure game fee percentage
80. Configure number payout
81. Configure colour payout
82. Configure big/small payout
83. Configure K3 payout
84. Configure 5D payout
85. Configure Moto Racing payout
86. Save a manual result for a specific issue
87. Settle eligible pending bets after a result
88. Run pending-bet settlement on demand

## Promotions and content

89. Create and edit gift codes
90. Configure gift amount, claim limit and expiry
91. Configure minimum recharge for gift claims
92. Enable or disable gift codes
93. View gift claim user, amount and claim time
94. Create and edit payment methods
95. Configure payment type, UPI, QR, limits and notes
96. Enable or disable payment methods
97. Create and edit banners
98. Create and edit notifications
99. Enable or disable banners and notifications
100. Configure VIP deposit, bet and reward levels
101. Enable or disable VIP levels
102. Configure activity tasks, targets and rewards
103. Enable or disable activity tasks
104. Configure invite-wheel limits and rewards
105. Configure recharge-wheel unlock rules

## Operations and system

106. Filter and review support tickets
107. Reply to tickets and update their status
108. Configure agent hierarchy and salary
109. View team count and earned commission
110. Create risk flags with severity and reason
111. Move risk flags through review/resolved/dismissed states
112. Toggle home, popup, maintenance and finance features
113. Toggle WinGo, K3, 5D, Moto and TRX games
114. Toggle VIP, agent, invite, support and turnover features
115. Configure deposit and gift turnover multipliers
116. Configure maintenance text and support URLs
117. Inspect critical-table availability and row counts
118. Inspect HTTPS, charset, installer and queue health
119. Detect leftover debug files
120. Review structured audit JSON with actor, IP and target

## V33 game, payment and operator controls

121. Live countdown for the selected WinGo, K3, 5D or TRX round
122. Separate 30-second, 1-minute, 3-minute, 5-minute and available 10-minute game selectors
123. Save or safely unset an unsettled manual issue result
124. Display issue-wise bet users, stake and estimated payout totals
125. Compact three-dice K3 public result compatibility
126. Five-digit 5D result and sum compatibility
127. TRX block number, block time and deterministic result hash fields
128. Separate deposit and withdrawal enable switches per payment method
129. Configure USDT rate, address and TRC20/ERC20/BEP20 network
130. Upload and validate local UPI or USDT payment images
131. Create separately flagged demo users with an audited opening balance
132. Scan duplicate user IPs and refresh risk flags
133. Filter and review active, banned and demo-user groups
134. Run idempotent daily agent salary for a selected date
135. Credit daily salary through the detailed wallet ledger
136. Save dated team-deposit and team-bet salary snapshots
137. Dynamic dashboard report date and live-status indicator
138. Live user, wallet, recharge, withdrawal, pending and profit KPI cards
139. Quick actions for maintenance, gift code, same-IP scan and salary
140. Responsive purple Teacher_lara-inspired admin navigation and dashboard

## V34 support, team and provider reliability

141. Repair the installation administrator by username instead of assuming database ID 1
142. Clear only the repaired administrator's failed-login lockout rows during manual import
143. Force an immediate password change after the temporary installation login
144. Route legacy and current 5D issue requests through one local countdown API
145. Keep both current and legacy 5D timer layouts visible on mobile
146. Keep game-duration selectors above the result drum on narrow screens
147. Redirect every legacy Live Support entry point to Self Service Center
148. Serve bundled support icons with no missing remote artwork dependency
149. Return support form fields using aliases required by multiple frontend builds
150. Create authenticated support tickets and expose them in Progress Query
151. Upload locally validated support images, PDFs and short videos
152. Append user comments while keeping each ticket scoped to its owner
153. Calculate direct invite and recursive team metrics independently
154. Persist three-level commission entries when valid lottery bets are accepted
155. Claim pending commission transactionally through the wallet ledger
156. Configure official third-party providers without storing raw secrets in SQL
157. Validate launch URLs against HTTPS and an explicit host allowlist
158. Create short-lived hashed provider launch sessions
159. Verify signed callbacks and reject duplicate external wallet transaction IDs
160. Display a safe provider-status page until official merchant access is configured

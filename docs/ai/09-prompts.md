# Prompts to paste into Claude Code

Paste one prompt per step, on its branch. Start a new Claude Code session (`/clear`) for each step so old context does not mix in.

---

## Step 0: Setup
```
Read CLAUDE.md, docs/ai/00-build-plan.md and docs/ai/01-database-and-rules.md, and docs/database/jeanrose_pos_schema.sql.
Do Step 0 ("Step 0 tasks" in 01). Show me your plan first and wait for my OK.
Do not create migrations and do not run migrate. Ask me before installing Bootstrap (Vite or CDN).
When done, run php artisan test and give me the manual checklist and a 5 line summary.
```

## Step 1: Login, menu, 403, session expired
```
Read CLAUDE.md and docs/ai/02-login-menu-errors.md. Look at screens 1, 5 and 14 in docs/wireframes/Jean_Rose_POS_Wireframes_v4.pdf.
Build Step 1 only. Show me your plan first and wait for my OK.
The test accounts "owner" and "cashier" already exist in user_account. Do not create or change them.
Run php artisan test when done, then stop.
```

## Step 2: POS screen
```
Read CLAUDE.md, docs/ai/01-database-and-rules.md (Money, Near-expiry, Stock status) and docs/ai/04-pos-payment-receipt.md. Look at screen 3 in the wireframes.
Build Step 2 only (POS screen and cart). Do not build Pay Now yet. Plan first, then wait for my OK.
Use the open decisions D3, D4, D6 and D7 in docs/ai/00-build-plan.md.
Run php artisan test when done, then stop.
```

## Step 3: Payment and receipt
```
Read CLAUDE.md and docs/ai/04-pos-payment-receipt.md (Step 3). Look at screen 4 in the wireframes.
Build Step 3 only: the payment box, POST /pos/pay in one DB transaction, and the receipt page with print.
Before coding, list every table write in order and wait for my OK.
Include the tests listed in the brief, including the rollback test. Run php artisan test, then stop.
```

## Step 4: Inventory list
```
Read CLAUDE.md and docs/ai/07-inventory-products.md (Step 4). Look at screen 9.
Build Step 4 only (owner-only inventory list with search and status). Buttons for later steps stay disabled.
Plan first, wait for my OK. Run php artisan test, then stop.
```

## Step 5: Add or Edit Product
```
Read CLAUDE.md and docs/ai/07-inventory-products.md (Step 5). Look at screen 12.
Build Step 5 only: add product with first batch, edit product, add batch, delete row (rule D12).
Plan first, wait for my OK. Run php artisan test, then stop.
```

## Step 6: Update Stock and Price
```
Read CLAUDE.md and docs/ai/07-inventory-products.md (Step 6). Look at screen 10.
Build Step 6 only. Every change must write inventory_history (for stock) and audit_log with old and new values.
Plan first, wait for my OK. Run php artisan test, then stop.
```

## Step 7: Sales Records
```
Read CLAUDE.md and docs/ai/06-sales-records-void.md (Step 7). Look at screen 7.
Build Step 7 only. Export and Void buttons stay disabled for now.
Plan first, wait for my OK. Run php artisan test, then stop.
```

## Step 8: Void a Sale
```
Read CLAUDE.md and docs/ai/06-sales-records-void.md (Step 8). Look at screen 8.
Build Step 8 only, in one DB transaction. Before coding, list every table write in order and wait for my OK.
Run php artisan test, then stop.
```

## Step 9: Audit Log
```
Read CLAUDE.md and docs/ai/08-excel-audit-log.md (Step 9). Look at screen 13.
Build Step 9 only. The log is read-only. Plan first, wait for my OK. Run php artisan test, then stop.
```

## Step 10: Alerts and daily email
```
Read CLAUDE.md and docs/ai/05-alerts.md. Look at screen 6.
Build Step 10 only: AlertService::sync, the /alerts page, the Alerts button count, and the daily email command with the scheduler.
Plan first, wait for my OK. Run php artisan test, then tell me how to test the email with Mailpit, then stop.
```

## Step 11: Password recovery
```
Read CLAUDE.md and docs/ai/03-password-recovery.md. Look at screen 2.
Build the Gmail link part first and stop so I can test it with Mailpit. After my OK, build the SMS code part with the log driver.
Never print or log tokens or codes outside local dev. Run php artisan test after each part.
```

## Step 12: Excel export
```
Read CLAUDE.md and docs/ai/08-excel-audit-log.md (Step 12). Look at screen 11 (export side).
Tell me before you run composer require maatwebsite/excel. Build Step 12 only and wire up the Export buttons.
Run php artisan test, then stop.
```

## Step 13: Excel import
```
Read CLAUDE.md and docs/ai/08-excel-audit-log.md (Step 13). Look at screen 11 (import side).
Build Step 13 only: template download, check file, import valid rows in one transaction, one audit row.
Plan first, wait for my OK. Run php artisan test, then stop.
```

## Step 14: CI
```
Read CLAUDE.md and docs/ai/08-excel-audit-log.md (Step 14).
Create .github/workflows/tests.yml as described. Do not add deployment. Show me the file before committing.
```

---

## Useful follow-up prompts
- Bug: `On /pos, when I <action>, I expected <result> but I see <result>. Here is the error: <paste>. Find the cause, explain it in 3 lines, then fix it and add a test.`
- Explain: `Explain how <file or feature> works, step by step, in simple words, so I can present it to my professor.`
- Review: `Review the changes on this branch against CLAUDE.md and the brief for this step. List anything that breaks a rule. Do not change code yet.`
- Commit: `Commit the changes with a short message in the form "feat: <screen>". Do not push.`

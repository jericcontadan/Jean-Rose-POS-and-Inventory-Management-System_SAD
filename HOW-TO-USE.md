# How to use these files (for the team, not for Claude Code)

## 1. Copy the files into the project
Unzip, then copy into `C:\laragon\www\jeanrose-pos` so it looks like this:

```
jeanrose-pos/
  CLAUDE.md                         <- replace the one from the Laravel starter
  docs/
    ai/
      00-build-plan.md
      01-database-and-rules.md
      02-login-menu-errors.md
      03-password-recovery.md
      04-pos-payment-receipt.md
      05-alerts.md
      06-sales-records-void.md
      07-inventory-products.md
      08-excel-audit-log.md
      09-prompts.md
    database/
      jeanrose_pos_schema.sql
      jeanrose_pos_upgrade.sql
    wireframes/
      Jean_Rose_POS_Wireframes_v4.pdf
    design/                         <- your team's mockup, already filled in
      design-tokens.md              <- colors, fonts, sizes
      01-login.png ... 10-access-denied-session-expired.png
      screens/01-login.html ...     <- static HTML copies with exact styles
      assets/logo.png
      Jean-Rose-POS-Mockup.html     <- your original mockup file
    architecture/                   <- already in your repo
```

### About the design files
Your mockup is already split into one PNG and one HTML file per screen, with the real colors and fonts in `design-tokens.md`. Claude Code copies the look from these and takes the rules from `docs/ai/`. If you change the design later, replace the matching PNG and HTML and update `design-tokens.md`.

## 2. Commit the brief
```
git checkout main
git pull
git checkout -b docs/claude-brief
git add CLAUDE.md docs
git commit -m "docs: add Claude Code build brief"
git push -u origin docs/claude-brief
```
Open a pull request and merge it, so every teammate has the same rules.

## 3. Build step by step
1. Open the project in VS Code and start Claude Code in the terminal.
2. Open `docs/ai/09-prompts.md`, make the branch for the step, and paste that step's prompt.
3. Read the plan it shows. Say OK or correct it.
4. Test in the browser with its checklist. Then commit, push, pull request, review, merge.
5. Use `/clear` before the next step.

## 4. Before you start
- Run `jeanrose_pos_upgrade.sql` on your database if you have not yet, so it has all 12 tables.
- Check the open decisions table in `docs/ai/00-build-plan.md` with the team. Change any default you disagree with **before** building that screen.
- Keep `.env` out of GitHub.

## 5. Things you must check yourself
- Pay Now and Void: stock goes down or back up exactly once, and the audit log has one row.
- A cashier gets "Access denied" when typing `/inventory`, `/sales` or `/audit-log` in the address bar.
- Totals match the wireframe example: 570.00, −12.00, 558.00, cash 600.00, change 42.00.
- You can explain every file you submit.

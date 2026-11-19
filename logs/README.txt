ReeferON DF — application logs (server-side, not public)

Files (daily rotation):
  app-YYYY-MM-DD.log     — all events (info, audit, warning, error, failed)
  errors-YYYY-MM-DD.log  — warnings / errors / failed only

Each line is JSON: timestamp, level, event, message, user, IP, URI, context.

Do not expose this folder on the web. .htaccess / web.config deny access.

Improvement backlog (open-by-open)
Status: [DONE] [NEXT] [TODO] [SKIP]

Feature improvements
[DONE] Technician Home shows only their AMC due / today’s work
[DONE] Photo compress before upload (faster mobile)
[DONE] AMC success screen with store, freezer ID, date & time
[TODO] WhatsApp / SMS when AMC due or FAIL
[DONE] Map view of nearby AMC due freezers — removed from technician UI (Home / nav / profile)
[SKIP] Offline queue — save AMC when network is weak, sync later
[TODO] Bulk “due this week” export by zone/city
[TODO] Stronger audit log — who moved store / FAIL / photo change
[TODO] Streaming Excel export (safer for large data)
[TODO] Push reminder for pending AMC

UI / UX improvements
[DONE] Assets tabs: All · OK · Pending · Issue
[DONE] Cleaner mobile cards (status pill + city + next action)
[DONE] Sticky Submit on all long forms (not only AMC) — AMC + Edit profile
[DONE] Skeleton loaders instead of blank wait — Assets tab loading
[DONE] Empty states with one clear CTA (Scan / Add) — Assets mobile
[DONE] Larger tap targets + clearer error text on mobile — cards/actions
[DONE] Consistent blue primary buttons on field screens
[DONE] Unified app shell — desktop Dashboard width (1280) + mobile field blue UI
[DONE] Profile view: big photo gallery + AMC timeline (technician focus)
[DONE] Dashboard: click KPI → filtered list
[TODO] Success toast with Undo everywhere deletes happen
[DONE] Manage Users: assigned Zone + City; field users only see/fill that area
[DONE] Technician Home = data-entry: my installs + area AMC pending + Scan to fill
[FOCUS] Technician workflow — next: WhatsApp/SMS or bulk due export

# Member panel and external server
- [x] Preserve the desktop member-panel layout with proportional screen fitting; all 11 pages passed at 320/393/768/1024/1280px without page overflow, with one-row navigation and mobile profile navigation/input verified in demo mode. Live-account checks remain blocked by the external PHP host.
- [x] Finish mobile dashboard full-width layout and profile label/colon alignment; all 11 member pages verified in demo mode at 320/390/768px, desktop columns preserved. Live-account checks still require the external PHP host.
- [ ] Resolve conflicting rank thresholds (1,000-matchings rank name) and generation-1 recipient; confirm external PHP/MySQL hosting URL for live checks.
- [ ] Build the member panel to match the supplied dashboard kit (kit received through the new share link). [done: dashboard, tree, sponsors, statistics, activation, withdrawals, withdrawals-report, commission, profile, change-password, notice; pending decision: Transaction / Mobile Recharge / Stockiest kit menus]
- [x] Verify statistics/sponsors/activation pages against the kit screenshots in the live preview (demo mode).
- [ ] Connect member login, profile, wallets, reports and trees to the PHP API; verify signed-out access boundaries and authenticated flows.
- [ ] Review old project mechanisms (blocked until an accessible Google Drive connection is available).
- [x] Prepare backendserver PHP REST API and migration-compatible MySQL schema.
- [x] Add audited, restricted deployment and database management with signed updates and rollback.
- [x] Test financial rules, login, access boundaries and server deployment (50/50 checks pass; live-server checks still need host access).

# Clone website
- [x] Copy original pages, styling and photos.
- [x] Add transparent Life OS logo at the top-right of news/event photos and their image viewer.
- [x] Verify pages, image loading, navigation and narrow-screen layout.
- [ ] Add a brand-matched background to Benefits of Life OS.
- [ ] Restore homepage news photos and a visible View more link; verify photo viewing and navigation.

# About and business presentation
- [ ] Add two illustrative senior-management profiles and matching portraits.
- [ ] Replace three expert images and two business-collaboration images.
- [x] Repair corrupted business-opportunity text; keep website content in English.
- [ ] Improve all About subsection layouts across device sizes without changing the theme.
- [ ] Verify updated pages and existing outstanding photo/background tasks.

# Services, galleries and support
- [ ] Refine all service submenu layouts for phones, tablets and desktops.
- [ ] Improve photo/video galleries and add an audio gallery with generated Life OS BD narration.
- [ ] Refine FAQ, Contact Us and Join Us presentation and English content.
- [ ] Generate suitable branded Contact Us and Join Us photographs.
- [ ] Verify requested pages, media controls and responsive layouts.

# Whole-site quality check
- [ ] Apply reference-style global mobile gutters, header/login/search and footer; contain and test every sidebar submenu.
- [ ] Verify GitHub includes all required files and real media while excluding private/generated files.
- [ ] Match supplied mobile reference positioning with a compact header, usable side menu and single-column gallery.
- [ ] Polish both Contact submenu pages (Contact Us and Business Center) within the existing theme.
- [ ] Test every public page across mobile, tablet and desktop widths; repair observed layout and interaction bugs.
- [ ] Verify image viewers, audio playback, video controls, menus, FAQs and enquiry forms.
- [ ] Run regression tests and record remaining external limitations.
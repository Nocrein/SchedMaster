# SchedMaster

Class-schedule builder: enter patterns, rooms, subjects, instructors and
sections, then press **Auto-Generate**. Students and teachers view the result
through public links (no login needed).

PHP + MySQL + a plain HTML/Alpine front end.

## Run it on your computer (XAMPP)

1. Start **Apache** and **MySQL** in XAMPP.
2. Copy this folder to `C:\xampp\htdocs\sm-capstone`.
3. Open `http://localhost/sm-capstone/` — the first visit creates the
   database tables automatically (database name defaults to `sm_capstone`;
   create an empty one in phpMyAdmin first if it doesn't exist).
4. Register an admin account and follow the **Getting Started** checklist.

## Put it online (temporary demo)

> **GitHub Pages cannot run this app** — it only serves static files, and
> SchedMaster needs PHP and MySQL. Use GitHub to *store* the code and a
> PHP host to *run* it. The steps below use Railway (free trial), but any
> Docker host works.

1. **Push to GitHub**
   ```
   cd sm-capstone
   git init
   git add .
   git commit -m "SchedMaster"
   git branch -M main
   git remote add origin https://github.com/<you>/sm-capstone.git
   git push -u origin main
   ```
2. **Railway**: New Project → *Deploy from GitHub repo* → pick the repo.
   It builds the included `Dockerfile` automatically.
3. In the same project press **+ New → Database → MySQL**.
4. Open the web service → **Variables** and add references to the MySQL
   service's values:
   `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`
   (Railway's "Add Reference" lists them).
5. Web service → **Settings → Networking → Generate Domain**. Open the
   link: the tables are created on first visit.
6. Register an admin account.

When you're done, delete the Railway project so the site (and anyone who
registered on it) is gone. Registration is open to anyone with the link, so
don't enter real student or staff data on a temporary demo.

## Free hosting without Docker (InfinityFree, Byet.host, any cPanel host)

These shared hosts run PHP + MySQL but can't run the Dockerfile.

1. Create a free account, then a hosting account, and in its control panel
   create a **MySQL database** (note the host name, database name, user).
2. Copy `config/db_config.sample.php` to `config/db_config.php` and fill in
   those details.
3. Upload the **contents** of this folder (not the folder itself) into the
   site's `htdocs` folder via the online file manager or FTP.
4. Open your site address. The tables are created on the first visit.

## Try it with sample data

1. Create a throwaway schedule in the app.
2. Open the app's database (phpMyAdmin locally; on Railway, connect with
   MySQL Workbench or DBeaver using the details on the MySQL service's
   **Connect** tab) and run `config/seed_sample_data.sql` **once**. It fills the newest schedule
   with ~35 instructors, 44 subjects, 15 rooms and 21 day/night sections.
3. In the app: **Assignments → Run Auto-Generate**.

## Day vs. night sections

On the **Sections** tab each section has *Schedule Rules*: which day-patterns
it may use, the time window its classes must fall in, and a mandatory
**lunch break**. The **Day preset** (7:30 AM–5:00 PM, lunch 12:00–1:00 PM,
patterns of up to 4 days) and **Night preset** (5:30–9:00 PM, no break,
patterns of 5+ days) fill these in; everything can be edited.
Auto-Generate spreads each section's classes across its allowed patterns.

### Lunch break

- Pick a quick option (11:30–12:30, 12:00–1:00, 12:30–1:30, 12:00–12:30), type
  a custom time, or choose **No break** — in the Add/Edit section form.
- **Sections tab → 🍴 Set lunch for many** applies one break to all sections,
  day sections only, or night sections only (or removes it) in one click.
- Auto-Generate never places a class inside a section's break, and the manual
  add/edit class form rejects times that overlap it.
- The student view shows the LUNCH BREAK row at that section's real break time.
- Existing databases upgrade automatically on first load. To give old day
  sections the new 7:30 start and a 12–1 lunch, run
  `config/migration_section_break.sql` (the last two statements are optional).

## One-sitting subjects (3-hour subjects in one block)

By default a subject's units are split across a day-pattern (a 3-unit subject becomes three 1-hour classes on Mon/Wed/Fri). With the **one-sitting rule**, a 3-hour subject is instead placed as **one continuous 3-hour block on a single day**, so the teacher never has to move between rooms.

- **Schedule tab → "Keep [3]-hour subjects in one sitting":** the on/off switch and the hour length (default 3). Applies to subjects of exactly that many hours.
- **Per subject (Subjects tab, Add/Edit):** *Meeting: automatic* follows the rule, *Always one sitting* forces a single block for any subject, *Allow splitting across days* opts that subject out. Subjects on the rule show a **◼ One sitting** tag.
- **How it places them:** the least-loaded day for the section first (so subjects spread across the week), the earliest free time inside the section's window, never across its lunch break, with the usual teacher / room / section conflict checks and teacher hour limits.
- **It's a preference:** if a block can't fit, the subject falls back to the normal split and the build results say so ("No room for one 3-hour sitting, so it was split across days").
- **Single-day patterns** (Mon, Tue, ... ) are created automatically when needed and only used by one-sitting subjects; your own patterns are untouched. Days come from the section's allowed patterns, so if a section's patterns include Saturday, Saturday can be used. Restrict that on the Sections tab.
- **Existing schedules:** the rule only affects classes built from now on. To redo existing ones, **Clear all classes**, then **Build schedule**.
- Existing databases upgrade automatically (or run `config/migration_one_sitting.sql`).

## Mobile support

Every page works on phones (tested at 360, 390, 768 and 1280 px wide, no sideways scrolling).

- **Students and teachers:** the schedule opens as a **By day** list (one card per day, today highlighted). Tap **Weekly grid** for the full table, which scrolls sideways with the time column pinned. Print/PDF always uses the full grid.
- **Admin editor:** the step menu becomes a swipeable tab strip that stays at the top while you scroll and slides to the current step. A **Share** menu in the top bar holds the Student view, Teacher view and Accounts links (the sidebar is hidden on phones). Tables turn into labelled cards, dialogs fit the screen, and inputs are 16px so iPhones don't zoom in when you tap a field.
- **Login, dashboard, accounts:** one shared look (`views/theme.css`): forest-green buttons, readable link rows with a Copy button, and full-width buttons on small screens.

## Accounts: Admin, Teacher, Student

| Role | Can do | Sees |
|---|---|---|
| **Admin** | Build and manage schedules (everything above) | Their own schedules only |
| **Teacher** | Log in and view their own timetable | Only their own classes |
| **Student** | Log in and view their section's timetable | Only their own section |

**How teachers and students join**
1. Admin opens a schedule → **Accounts & access** (sidebar, or the dashboard card) and shares the **join code** or **invite link**.
2. The person goes to the login page → **Sign up** → picks *Teacher* or *Student*, enters the code, and picks their name/section from the list.
3. The request shows under **Waiting for approval**. The admin clicks **Approve** (or Reject).
4. After approval they log in and land directly on their own page.

**Lots of students? Skip the approvals.** On the Accounts page, switch on **"Let students join without my approval"**: a student who enters the join code and picks their section gets in immediately. Teachers always still need approval. If you keep approvals on, **Approve all N students** clears the queue in one click, and the lists have search and a Students/Teachers filter. Only share the code with your students, and use **New code** if it leaks.

**Admin controls on that page:** copy/regenerate the join code, reset a forgotten password, remove an account, and a switch for **"Anyone with the link can view (no login)"**.
- *On* (default, same as before): the student/teacher links work for anyone; accounts just give a personal landing page.
- *Off*: students see only their own section and teachers only their own timetable, after logging in. Everyone else is turned away.

Security notes: teacher/student accounts get a 403 on every admin API; each instructor can be claimed by only one account; the instructor/section link is checked in the database on every page view (so removing an account locks it out immediately). Existing databases upgrade automatically (or run `config/migration_roles.sql`, then `config/migration_student_auto.sql`); existing users stay admins.

## Teacher view

Every schedule also has a public teacher page (no login): `public/teacher.php?s=<slug>`
(the schedule's link with `teacher.php` instead of `view.php`; the dashboard and
the schedule page's sidebar both show it).

- Search for your name, then see your weekly timetable (Mon–Sat), class list,
  rooms, earliest/latest class, and weekly teaching load versus the instructor's
  maximum hours.
- Free gaps show as VACANT and the school's lunch hour as LUNCH BREAK.
- **Print / Save as PDF** for one teacher, or **Print all teachers** for one
  page per instructor.

## Folders

- `views/` — pages (`login`, `dashboard`, `schedule`)
- `api/` — JSON endpoints
- `public/view.php` — student-facing schedule
- `public/teacher.php` — teacher-facing schedule
- `views/theme.css` — shared colours/buttons and phone rules for login, dashboard, accounts
- `views/accounts.html` — admin: join code, approvals, accounts, public-link switch
- `includes/viewer_access.php` — who may see which student/teacher page
- `config/` — `schema.sql`, optional migrations, `seed_sample_data.sql`
- `includes/` — shared PHP (auth, conflict checking)

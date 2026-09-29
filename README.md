# Smart ICT Helpdesk System

A comprehensive web-based ICT support ticket management system for Mutare City Council ICT Department.

## Features

### Core Functionality
- **User Management**: Role-based access control (Admin, Technician, User)
- **Ticket Management**: Create, assign, track, and resolve support tickets
- **Automatic Technician Assignment**: Intelligent assignment based on specialization and workload
- **Technician Daily Attendance**: Clock in/out to mark availability; only on-duty technicians receive assignments
- **Knowledge Base**: Self-service solutions and documentation
- **Real-time Dashboard**: Live statistics and performance metrics
- **Reporting System**: Comprehensive reports and analytics

### User Roles
- **Admin**: Full system control, user management, reports
- **Technician**: Manage assigned tickets, update status, add solutions
- **User**: Submit tickets, track requests, access knowledge base

## Technical Stack

- **Frontend**: HTML5, CSS3 (Tailwind CSS compiled locally + custom dark theme), JavaScript
- **Backend**: PHP 7.4+ (procedural style)
- **Database**: MySQL 5.7+
- **Environment**: XAMPP (Apache + MySQL + PHP)

### Runs fully offline

The application makes **no outbound network requests**. Tailwind CSS, the Lucide
icon set and both webfonts are vendored into `assets/`, so the UI renders and
behaves identically with no internet connection.

| Asset | Source | Vendored to |
| --- | --- | --- |
| Tailwind CSS | `tailwindcss` (dev dependency) | `assets/css/tailwind.css` |
| Lucide icons | `lucide` (dev dependency) | `assets/js/lucide.js` |
| Space Grotesk | Google Fonts | `assets/fonts/` |
| Plus Jakarta Sans | Google Fonts | `assets/fonts/` |

The generated files are committed, so **Node.js is only needed if you want to
rebuild them** — for example after adding new pages or new `data-lucide` icons.

```bash
npm install
npm run build        # css + icons + fonts
npm run watch:css    # rebuild Tailwind while editing
```

`npm run build:fonts` is the only step that touches the internet. After adding a
new icon to a page, run `npm run build:icons` so it is included in the bundle.

## Project Structure

```
/mcc-ict-helpdesk/
├── config/
│   ├── database.php          # Database configuration
│   └── auth_helper.php       # Authentication helper functions
├── auth/
│   ├── login.php             # User login
│   ├── logout.php            # User logout
│   └── register.php          # User registration
├── admin/
│   ├── dashboard.php         # Admin dashboard
│   ├── manage_users.php      # User management
│   ├── manage_technicians.php # Technician management
│   ├── all_tickets.php       # View all tickets
│   └── reports.php           # Reports and analytics
├── technician/
│   ├── dashboard.php         # Technician dashboard
│   ├── my_tickets.php        # Assigned tickets
│   └── update_ticket.php     # Update ticket status
├── user/
│   ├── submit_ticket.php     # Submit new ticket
│   └── my_requests.php       # View user's tickets
├── system/
│   ├── auto_assign.php       # Automatic assignment logic
│   └── knowledge_base.php    # Knowledge base system
├── assets/
│   ├── css/
│   │   ├── style.css         # Legacy stylesheet (3 pages)
│   │   └── tailwind.css      # Compiled Tailwind (generated)
│   ├── js/
│   │   ├── script.js         # JavaScript functions
│   │   └── lucide.js         # Vendored icon subset (generated)
│   ├── fonts/                # Self-hosted webfonts (generated)
│   └── images/
│       └── mutarelogo.png    # MCC Logo
├── scripts/                  # Asset build scripts (Node)
├── src/                      # Tailwind source stylesheet
├── tailwind.config.js        # Tailwind content globs
├── package.json              # Build scripts
├── index.php                 # Entry point
├── database.sql              # Database schema and sample data
└── README.md                 # This file
```

## Installation Instructions

### Prerequisites
- XAMPP (or equivalent LAMP/WAMP stack)
- MySQL 5.7+
- PHP 7.4+
- Modern web browser

### Step 1: Setup XAMPP
1. Download and install XAMPP from https://www.apachefriends.org/
2. Start Apache and MySQL services from XAMPP Control Panel

### Step 2: Create Database
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Create a new database named `mcc_helpdesk`
3. Import the `database.sql` file:
   - Click on `mcc_helpdesk` database
   - Click "Import" tab
   - Choose `database.sql` file
   - Click "Go"

   > Select the database in phpMyAdmin before importing. `database.sql` does not
   > create or switch databases itself, so it can never write to the wrong one.
4. Import `config/migrations/baseline_data.sql` the same way. This adds the
   staff accounts, the auto-assignment rules, and the knowledge base articles
   the AI assistant relies on. It is safe to re-run and will not duplicate rows.

> **The accounts these files create have no usable password.** They are
> inserted with the sentinel value `!locked`, which is not a valid bcrypt
> hash, so no login can match it. This is deliberate: these SQL files are in a
> public repository, so an earlier version that seeded a default password made
> the admin and all four technicians reachable by anyone who had read the
> source. Set a real password from the command line before signing in:

> ```bash
> # Create (or reset) the admin, printing a generated password once
> php tools/create_admin.php
>
> # Set or reset any account, in any role table
> php tools/set_password.php JoseLambo@mcc.co.zw
> ```

> Trying to log in before doing this returns an explicit message telling you
> which command to run, rather than a generic failure.

> **Never open `seed.php` in a browser.** It drops the core tables and
> recreates them with demo data, destroying all tickets and accounts. It is now
> restricted to the command line and requires an explicit opt-in:
> `set SEED_ALLOW=1 && php seed.php`
> It is a *development* tool and its demo accounts use trivial passwords
> (`admin123`, `tech123`, `user123`). Never use its output for anything real.

### Step 3: Deploy Application
1. Copy the entire project folder to `C:\xampp\htdocs\mcc-ict-helpdesk\`
2. Ensure the folder structure is maintained

### Step 4: Configure Database
1. Open `config/database.php`
2. Verify database connection settings:
   ```php
   private $host = "localhost";
   private $username = "root";
   private $password = "";
   private $database = "mcc_helpdesk";
   ```

### Step 5: Access the System
1. Open your web browser
2. Navigate to: http://localhost/mcc-ict-helpdesk/
3. You will be redirected to the login page

## Accounts Created by the Installer

These accounts are created by `config/migrations/baseline_data.sql` and by the
sample data in `database.sql`. **None of them has a working password.** Each is
inserted with the sentinel `!locked`, which no input can match, so there is no
default credential sitting in a public repository waiting to be used.

Set a password for any of them from the command line:

```bash
php tools/create_admin.php                      # admin@mcc.co.zw, generated password
php tools/set_password.php admin@mcc.co.zw      # or reset a specific account
php tools/set_password.php JoseLambo@mcc.co.zw
```

The password is printed once and stored only as a bcrypt hash. If you would
rather not keep the seeded accounts at all, delete the rows and create exactly
the staff you need with `tools/create_admin.php`.

### Technician Accounts
| Specialization | Email |
| --- | --- |
| Network | JoseLambo@mcc.co.zw |
| Network | TanakaK@mcc.co.zw |
| Hardware | AbbyC@mcc.co.zw |
| Software | TanyaS@mcc.co.zw |
| General | SeanM@mcc.co.zw |

`General` is the catch-all specialization: it matches any ticket category. Keep at
least one general technician, otherwise hardware and software tickets have
nowhere to route.

Specialization is set by hand in the admin panel — the system does not infer
skill from ticket history. The resolution rate shown in Reports is display-only
and does not affect routing.

### User Accounts
| Email | Department |
| --- | --- |
| IanSmith@mcc.co.zw | ICT |
| RDzanza@mcc.co.zw | ICT |

## System Features in Detail

### 1. Automatic Technician Assignment
- Assigns tickets based on technician specialization
- Considers current workload for balanced distribution
- Prioritizes exact category matches over general technicians
- Updates workload automatically
- **Only technicians who are clocked in / on duty for the day are eligible for assignment**

### 1b. Technician Daily Attendance
- Technicians clock in when their shift starts and clock out when they leave
- While clocked in they are "On Duty" and receive auto-assigned tickets
- Clocking out keeps their current tickets but stops new auto-assignment until they clock in again
- Admin can review daily attendance (who clocked in/out, hours worked) via **Attendance** in the admin panel
- Technicians can view their own attendance history via **Attendance** in their panel
- The attendance table (`technician_attendance`) is created automatically on first use, so no manual migration is needed for existing installations

### 2. Priority System
- **High**: Server/network outages (2-hour response)
- **Medium**: Account/login issues (8-hour response)
- **Low**: Printer/minor issues (24-hour response)

### 3. Knowledge Base
- Searchable solution database
- Categorized by issue type
- Usage tracking for popular solutions
- Admin/technician content management

### 4. Reporting Dashboard
- Ticket statistics and trends
- Technician performance metrics
- Department-wise analytics
- Export functionality (CSV)

### 5. Responsive Design
- Mobile-friendly interface
- Dark theme for reduced eye strain
- Progressive enhancement
- Cross-browser compatibility

## Security Features

- Password hashing (bcrypt via `password_hash`/`password_verify`), with
  `password_needs_rehash` upgrading stored hashes on successful login
- No default password: installer-created accounts use an unmatchable sentinel
  and are unlocked with `tools/create_admin.php` / `tools/set_password.php`
- Session-based authentication, with the session id rotated on login
  (fixation) and `HttpOnly`/`SameSite`/`use_strict_mode` on the session cookie
- Login brute-force throttling: 5 failures per IP per 15 minutes
- CSRF tokens on every state-changing form - including login, registration and
  password-reset request - validated with `hash_equals`
- Password-reset and remember-me tokens stored only as SHA-256 digests, so a
  database read cannot be replayed to take over an account
- Remember-me tokens rotated on use and burned by logout or a password change
- Reset links delivered by email over a configured base URL, never rendered
  into the page, and never built from the client-supplied `Host` header
- SQL injection prevention (prepared statements on the authentication paths,
  including the login audit log, which previously interpolated `User-Agent`)
- XSS protection (`htmlspecialchars` on output)
- Role-based access control
- `X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy` on every
  response

### Environment variables

| Variable | Purpose | Default |
| --- | --- | --- |
| `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Database connection | XAMPP defaults (`localhost`, `root`, empty, `mcc_helpdesk`) |
| `MCC_BASE_URL` | Absolute base URL used in emailed links | Derived from `Host`, validated |
| `MCC_COOKIE_SECURE` | Set to `1` to force `Secure` on cookies | Off (plain HTTP on XAMPP) |
| `AI_API_KEY` | Optional, for the AI assistant | Empty, assistant degrades gracefully |

Set `MCC_BASE_URL` and `MCC_COOKIE_SECURE=1` before serving this over the
public internet, and give MySQL a real password.

## Maintenance

### Database Backup
Regularly backup the database using:
```sql
mysqldump -u root -p mcc_helpdesk > backup.sql
```

This is the only way to recover tickets and accounts. Binary logging is enabled
in `mysql/bin/my.ini`, so MariaDB can also replay changes to a point in time:
```sql
SHOW BINARY LOGS;
mysqlbinlog --start-datetime="2026-09-27 14:00:00" mysql-bin.000004 \
  | mysql -u root -p mcc_helpdesk
```
Binlogs are kept for 7 days. Delete the files in `mysql/data/` to reclaim space.

### Log Monitoring
Check system logs in the `system_logs` table for:
- Login attempts
- Failed operations
- Security events

### Performance Optimization
- Regular database maintenance
- Log cleanup (older than 6 months)
- Index optimization

## Troubleshooting

### Common Issues

#### 1. Database Connection Error
- Verify MySQL service is running
- Check database credentials in `config/database.php`
- Ensure database exists and is accessible

#### 2. Login Issues
- Verify user exists in database
- Check password hashing
- Clear browser cookies/sessions

#### 3. Page Not Found (404)
- Verify .htaccess configuration
- Check file permissions
- Ensure Apache mod_rewrite is enabled

#### 4. Styling Issues
- Clear browser cache
- Check CSS file paths
- Verify asset folder permissions

### Error Logging
PHP errors are logged to:
- XAMPP: `C:\xampp\apache\logs\error.log`
- Application: Custom error logging in database

## Customization

### Adding New Fields
1. Modify database schema
2. Update PHP forms and queries
3. Adjust CSS styling as needed

### Theme Customization
- Edit `assets/css/style.css`
- Modify color variables
- Update logo in `assets/images/`
- Colours/classes from the Tailwind utilities used in the pages are built into
  `assets/css/tailwind.css` — edit `tailwind.config.js` (or add to the page
  markup) and run `npm run build:css` to regenerate

### Email Notifications
To add email notifications:
1. Configure PHP mail settings
2. Create email templates
3. Integrate with PHPMailer or similar

## API Integration

The system can be extended with:
- REST API endpoints
- Mobile app integration
- Third-party service integration
- Webhook support

## Support

For technical support:
1. Check this documentation
2. Review error logs
3. Test with demo accounts
4. Verify database integrity

## License

This project is developed for Mutare City Council ICT Department.
© 2024 Mutare City Council. All rights reserved.

## Version History

### v1.0.0 (Current)
- Initial release
- Core ticket management system
- User authentication and roles
- Automatic technician assignment
- Knowledge base
- Reporting dashboard
- Responsive dark theme

---

**Note**: This system is designed to work in a XAMPP environment. For production deployment, additional security hardening and server configuration are recommended.

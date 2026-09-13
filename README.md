# Smart ICT Helpdesk System

A comprehensive web-based ICT support ticket management system for Mutare City Council ICT Department.

## Features

### Core Functionality
- **User Management**: Role-based access control (Admin, Technician, User)
- **Ticket Management**: Create, assign, track, and resolve support tickets
- **Automatic Technician Assignment**: Intelligent assignment based on specialization and workload
- **Knowledge Base**: Self-service solutions and documentation
- **Real-time Dashboard**: Live statistics and performance metrics
- **Reporting System**: Comprehensive reports and analytics

### User Roles
- **Admin**: Full system control, user management, reports
- **Technician**: Manage assigned tickets, update status, add solutions
- **User**: Submit tickets, track requests, access knowledge base

## Technical Stack

- **Frontend**: HTML5, CSS3 (with custom dark theme), JavaScript
- **Backend**: PHP 7.4+ (procedural style)
- **Database**: MySQL 5.7+
- **Environment**: XAMPP (Apache + MySQL + PHP)

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
│   │   └── style.css         # Main stylesheet
│   ├── js/
│   │   └── script.js         # JavaScript functions
│   └── images/
│       └── mutarelogo.png    # MCC Logo
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

## Default Login Credentials

The system comes with pre-configured demo accounts:

### Admin Account
- **Email**: admin@mcc.co.zw
- **Password**: password

### Technician Accounts
- **Email**: john.tech@mcc.co.zw
- **Password**: password

- **Email**: mary.hardware@mcc.co.zw
- **Password**: password

- **Email**: peter.network@mcc.co.zw
- **Password**: password

- **Email**: sarah.software@mcc.co.zw
- **Password**: password

### User Account
- **Email**: user@mcc.co.zw
- **Password**: password

## System Features in Detail

### 1. Automatic Technician Assignment
- Assigns tickets based on technician specialization
- Considers current workload for balanced distribution
- Prioritizes exact category matches over general technicians
- Updates workload automatically

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

- Password hashing (bcrypt)
- Session-based authentication
- SQL injection prevention
- XSS protection
- CSRF protection
- Role-based access control

## Maintenance

### Database Backup
Regularly backup the database using:
```sql
mysqldump -u root -p mcc_helpdesk > backup.sql
```

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

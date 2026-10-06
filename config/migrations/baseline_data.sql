-- Optional baseline data for a fresh MCC ICT Helpdesk install.
-- Run this ONCE, after importing database.sql, to create a working starting
-- point. It is NOT the same as seed.php: nothing is dropped, and every insert
-- is guarded so re-running it will not duplicate rows.
--
--   mysql -u root mcc_helpdesk < config/migrations/baseline_data.sql
--
-- ACCOUNTS ARE CREATED LOCKED, NOT WITH A DEFAULT PASSWORD.
--
-- The only account inserted here is the admin below, carrying the sentinel
-- password '!locked', which cannot be matched by any input: it is not a valid
-- bcrypt hash, so password_verify() rejects every guess against it. Nobody can
-- sign in until a real password is set.
--
-- This is deliberate. This row used to carry the bcrypt hash of the literal
-- string "password", and the repository is public, so the admin was reachable
-- by anyone who had read the file. There is no value in a default password
-- that is also public.
--
-- ROSTER: one admin. No sample users or technicians are seeded - a fresh
-- install starts empty apart from this admin, and staff are created from the
-- admin panel. Specialization is a MANUAL admin-assigned field (enum:
-- network/hardware/software/general). Assignment is driven by it, plus
-- current_workload. 'general' is the catch-all that matches any category, so
-- at least one general technician should exist once you add staff. Nothing
-- here infers skill from ticket history - reports.php shows a resolution rate,
-- but that number is display-only and never feeds back into assignment.
--
-- After importing, set a real password from the command line:
--
--   php tools/create_admin.php                 (new admin, password generated)
--   php tools/set_password.php admin@mcc.co.zw (set/reset the admin password)
--
-- ---------------------------------------------------------------- admins
INSERT INTO admins (name, email, password, department)
SELECT 'System Administrator', 'admin@mcc.co.zw', '!locked', 'ICT'
WHERE NOT EXISTS (SELECT 1 FROM admins WHERE email = 'admin@mcc.co.zw');

-- ---------------------------------------------------------------- auto-assign rules
-- Required for automatic technician assignment; without these, tickets are
-- created with no technician assigned.
INSERT INTO assignment_rules (category, specialization, priority, auto_assign, priority_order, is_active)
SELECT r.category, r.specialization, r.priority, r.auto_assign, r.priority_order, r.is_active
FROM (
    SELECT 'network'  AS category, 'network' AS specialization, 'any' AS priority, 1 AS auto_assign, 1  AS priority_order, 1 AS is_active
    UNION ALL SELECT 'hardware', 'hardware', 'any', 1, 2,  1
    UNION ALL SELECT 'software', 'software', 'any', 1, 3,  1
    UNION ALL SELECT 'login',    'general',  'any', 1, 4,  1
    UNION ALL SELECT 'all',      'general',  'any', 1, 10, 1
    UNION ALL SELECT 'general',  'general',  'any', 1, 20, 1
) r
WHERE NOT EXISTS (SELECT 1 FROM assignment_rules a WHERE a.category = r.category);

-- ---------------------------------------------------------------- knowledge base
-- Seeds AIAssistantService::loadCorpus(). Without these the AI assistant has
-- nothing to retrieve and falls back to generic per-category checklists.
-- Written as plain prose steps so asBullets() renders each one cleanly.
-- Loaded into a temp table first so re-running this file is a no-op for
-- articles that already exist, rather than creating duplicates.
DROP TEMPORARY TABLE IF EXISTS baseline_kb;
CREATE TEMPORARY TABLE baseline_kb (
    issue_keyword        VARCHAR(200) NOT NULL,
    category             VARCHAR(20)  NOT NULL,
    recommended_solution TEXT         NOT NULL
);

INSERT INTO baseline_kb (issue_keyword, category, recommended_solution) VALUES
('wifi keeps disconnecting weak signal', 'network',
'Confirm the SSID and exact WPA2 passphrase being entered. Compare signal strength at the user desk against a known-good location. Confirm the access point is powered and in service. Check for a rogue or competing AP on the same channel. Check channel saturation and consider moving the AP to a cleaner channel. Verify the number of clients on the AP is within its licence limit.'),

('cannot connect to wifi network', 'network',
'Confirm the SSID and exact WPA2 passphrase being entered. Confirm the user is not on a guest network that isolates clients. Restart the wireless connection and forget then rejoin the network. Confirm the access point is powered, in service and broadcasting. Check whether other users on the same AP are also affected. Verify DHCP scope has addresses available for the wireless clients.'),

('wired network no link lights', 'network',
'Inspect both cable terminations for bent pins, damage or dirt. Swap to a known-good patch lead and retest link. Confirm the switch port is enabled and not in an error or disabled state. Test the same port with a known-good device. Check the run length against the cable category limit for the distance involved.'),

('network port not working in office', 'network',
'Confirm link and activity LEDs on the NIC and the switch port. Test the same port with a known-good cable and a known-good device. Verify the port is enabled and not disabled at the switch. Check the patch panel and any wall outlets between the desk and the switch. Confirm the correct VLAN is assigned to the port.'),

('no internet but network connected', 'network',
'Confirm the default gateway responds to a ping from the client. Verify DNS resolution on the gateway before blaming the client. Test with a second device on the same segment. Check the router or firewall WAN link is up. Check for high CPU or a saturated WAN link on the router.'),

('website cannot be reached name not resolving', 'network',
'Resolve the failing hostname directly to confirm the fault is DNS. Test resolution from a second device on the same network. Check the internal DNS service is running and reachable. Review record expiry and zone configuration for the affected name. Check for recent zone transfer or record changes.'),

('computer not getting ip address', 'network',
'Confirm the client received a valid address in the expected subnet. Renew the DHCP lease on the client. Check the DHCP scope for exhaustion and lease conflicts. Verify the gateway and DNS values handed out by DHCP. Confirm the client is authorised in the correct scope or has a static reservation.'),

('slow internet connection', 'network',
'Measure whether the slowdown is bandwidth, latency or a saturated host. Check throughput on the switch port and on the WAN link. Correlate the slow period with backups, patching or scheduled jobs. Check for a single user or a whole subnet to scope the problem. Look for a misbehaving device saturating the link with large transfers.'),

('vpn will not connect remote access', 'network',
'Confirm where exactly the tunnel stalls during the connection attempt. Verify the credentials are still valid and the account is not locked. Check the client clock is correct, as this breaks certificate validation. Check the gateway and firewall rules allowing the VPN port. Confirm the remote access server is running and reachable from outside.'),

('printer not printing queue stuck', 'hardware',
'Confirm the printer has power, is online and has paper and toner. Clear the queued jobs on the device and on the workstation. Restart the print spooler on the workstation. Check the driver and queue on the print server if networked. Confirm the correct default printer is selected on the workstation.'),

('printer paper jam', 'hardware',
'Clear the jam per the device access path and check for torn paper. Inspect the feed rollers and the paper tray for obstruction. Run the device head-clean cycle if the feed keeps failing. Confirm the paper is the correct type and weight for the tray. Check the fuser and duplexer paths for obstructions.'),

('printer shows offline on network', 'hardware',
'Check the printer has its own IP address and can be pinged from the workstation. Confirm the printer is not in sleep or error state. Restart the printer and the print server. Check the print server can reach the printer port. Reinstall the network printer on the workstation if the address changed.'),

('monitor blank no display', 'hardware',
'Test the same PC on a second display to isolate monitor from PC. Check the cable, the input source and the on-screen display menu. Reseat the graphics output and confirm the display is detected in the BIOS. Listen for the PC POST beep and check whether the fans are running. Try a known-good cable and a different input port on the display.'),

('monitor flickering', 'hardware',
'Test the same PC on a second display to isolate the monitor. Check the cable seating at both ends and try a known-good cable. Confirm the refresh rate is set to a supported value in the display settings. Reseat the graphics card and check for overheating. Test on a different port and with a different graphics output.'),

('keyboard not working some keys', 'hardware',
'Test the keyboard on another machine and that machine on this port. Clean the key contacts and check for stuck or liquid-damaged keys. Confirm the port is delivering data at the USB or PS/2 layer. Try the keyboard on a different port. If liquid damage is suspected, do not power on and arrange replacement.'),

('mouse not detected wireless', 'hardware',
'Confirm the receiver is plugged into a working USB port. Replace or recharge the batteries. Clean the optical sensor and check the surface. Test the mouse on another machine. Re-pair the device if it is Bluetooth. Check for USB port power management disabling the port.'),

('laptop will not power on', 'hardware',
'Confirm the charger is connected at both ends and the LED indicates power is being delivered. Remove peripherals and any attached USB devices. Hold the power button for 30 seconds to drain residual charge, then retry. Remove and reinsert the battery if removable. Check for a swollen battery. Test with a known-good charger.'),

('computer overheating shutting down', 'hardware',
'Check air vents and fans for dust blockage. Confirm the machine is on a hard surface and not blocking ventilation. Monitor temperatures under load to confirm the shutdown trigger. Check that CPU fan and case fans are spinning. Replace thermal paste if the machine is more than three years old. Verify the fan is connected to the correct header on the motherboard.'),

('hard drive failing bad sectors', 'hardware',
'Read the SMART and disk health data and check for reallocated sectors. Back up the user data before attempting any repair. Run a filesystem check. Check free space on the volume. Plan a disk replacement if reallocated or pending sector counts are climbing. Confirm the backup completed and is readable before wiping anything.'),

('battery not charging laptop', 'hardware',
'Confirm the charger, its LED and the voltage being delivered. Try a different known-good charger and socket. Check the battery charge percentage and whether it drops under load. Verify the battery is recognised in the firmware or BIOS. Clean the charging port and check the DC jack for bent pins.'),

('computer extremely slow startup', 'hardware',
'Measure startup time and check which processes dominate CPU and memory use. Check for autorunning third-party software and pending updates. Confirm disk health and free space on the system drive. Check for disk-hibernation problems in the drive settings. Uninstall or disable software that is no longer needed.'),

('computer randomly restarts', 'hardware',
'Read the Windows Event Viewer for the faulting module and the stop code. Check the CPU and case temperatures for thermal shutdown. Check the power supply under load, as a failing PSU causes random restarts. Run a memory diagnostic. Check for a failing disk and update the storage drivers.'),

('usb device not recognised', 'hardware',
'Try the device on a different USB port, preferring a rear port on the desktop. Try a different device in the same port to see if the port is faulty. Check Device Manager for an unknown device or a driver error. Reinstall the device driver. Disable USB selective suspend and try again.'),

('software installation fails error', 'software',
'Capture the exact installer error and where it stops. Confirm the account has rights to install software. Verify there is sufficient free space on the target drive. Check the installer source integrity by re-downloading or re-copying the media. Check whether an antivirus or policy is blocking the installer. Run the installer as administrator.'),

('application crashes on startup', 'software',
'Note the exact error text and timing and which application is affected. Check Windows Event Viewer for the faulting module. Test the application on a second user profile to rule out profile corruption. Repair the installation or reinstall it. Check whether a recent update or add-in caused the crash and roll it back if so.'),

('application very slow to open', 'software',
'Check how much free space is left on the system drive. Test the application on a second profile and a second machine. Disable recent add-ins one at a time to isolate a conflict. Check antivirus scanning load on the application folder. Verify the application has been updated to a current version.'),

('word or excel will not open file', 'software',
'Try opening the file on a second machine to rule out a local problem. Open it in WordPad or another editor to see whether the file itself is corrupt. Check the file is not marked read-only or locked by another open instance. Recover the previous version of the file. Ask the user to re-save a copy under a new name.'),

('cannot install windows update', 'software',
'Check whether a pending update is blocking or has partially applied. Confirm the machine can reach the update source and the time service. Run the update troubleshooter. Check free disk space on the system drive. Confirm the machine has rebooted since the last update attempt. Check for a conflicting third-party update or antivirus agent.'),

('virus or malware suspected', 'software',
'Run a full anti-malware scan and quarantine anything detected. Check for autoruns, unknown startup items and scheduled tasks. Review recent downloads and email attachments with the user. Confirm the operating system is fully patched. Back up important data to clean media before remediation. Change passwords used on the affected machine.'),

('email not sending from outlook', 'software',
'Confirm the mailbox is not full and that the account is not blocked or disabled. Check Outlook is not stuck in offline or disconnected mode. Verify the account settings and that the correct send server is configured. Try sending from webmail to separate the client from the account. Check for a stuck sending queue and clear it.'),

('slow browsing browser', 'software',
'Clear the browser cache and temporary files. Check for a large number of installed extensions and disable them one at a time. Update the browser to the current version. Confirm the machine is not overloaded by other processes. Check for a proxy or security agent inspecting traffic. Try a clean profile to rule out profile corruption.'),

('account locked out too many attempts', 'login',
'Confirm the exact error message and whether the lockout is at the directory level rather than the workstation. Check for repeated failed attempts and reset the account. Ask the user to wait out any lockout window and change the password. Confirm the user is attempting the correct domain and username format. Check the client clock is synchronised, as this breaks domain authentication.'),

('password forgotten or reset needed', 'login',
'Confirm the exact error message shown at sign-in. Use the self-service password reset if the account is eligible. Test the same credentials on a second machine to separate an account problem from a profile problem. Check the password has not expired and the account is enabled. Reset the password through the standard account recovery path and confirm the user can sign in.'),

('cannot sign in after password change', 'login',
'Confirm the user is entering the new password and not the cached old one. Check the account is enabled and not locked. Verify the account has not been suspended. Check the client clock is synchronised with the time service. Clear any cached credentials in Credential Manager and try again.'),

('permission denied accessing shared folder', 'login',
'Confirm whether the denial is on a file share, a folder or an application. Compare the effective permissions against the parent folder. Check group membership and whether the change to rights has replicated. Confirm the client is authenticated to the correct domain. Test with a different user account to see if the issue is user-specific.'),

('domain joined computer not signing in', 'login',
'Confirm the exact error at sign-in and whether it affects this machine only. Test with a local administrator account to separate the domain from the machine. Check the client clock is synchronised with the time service. Check the network can reach a domain controller. Confirm the computer account object is not disabled or orphaned in Active Directory.');

-- Copy across only the articles that are not already present, so this file can
-- be re-run safely. Articles an admin has since edited or replaced are kept.
INSERT INTO knowledge_base (issue_keyword, category, recommended_solution, usage_count)
SELECT b.issue_keyword, b.category, b.recommended_solution, 0
FROM baseline_kb b
WHERE NOT EXISTS (
    SELECT 1 FROM knowledge_base k WHERE k.issue_keyword = b.issue_keyword
);

DROP TEMPORARY TABLE IF EXISTS baseline_kb;

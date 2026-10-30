# ReeferON SIM - Freezer & Store Profile Management System

An enterprise-grade, mobile-responsive Cold-Chain Asset Management System built with **PHP 8.3** and **MySQL 8.4**. Designed for **ReeferON RaaS (Refrigeration-as-a-Service)** to manage retail stores, verify asset installations with GPS geolocation, upload multi-angle freezer and IoT photos, generate standardized unique IDs with QR tags, and track live temperature compliance.

---

## Key Features

### 1. Store Profile & Mobile Device GPS Location
- **GPS Capture:** Field technicians can click **"Capture GPS Location"** to automatically acquire device GPS coordinates (`navigator.geolocation`) with high accuracy (± meters).
- **Interactive Map:** Integrated with OpenStreetMap & Leaflet.js to pinpoint the exact storefront or entrance, adjust marker position, and open in Google Maps navigation.
- **Store Metadata:** Captures Store Name, Outlet ID, Customer Account (e.g. *Baskin Robbins*, *TVF*), Distributor, Contact Person, Phone, Email, City, State, Zone, and 6-digit PIN code.

### 2. Standardized Unique Freezer ID Generation
Automatically generates, validates, and prevents duplicate IDs adhering to the exact ReeferON specification:
```
{TYPE}-{COMPANY}-{MODEL}-{LOCATION}-{MMYY}-{PIN6}-{UNITSEQ}
```
**Example:** `DF-RW-SFR550DDU-AMD1-0324-380058-AAA004`

| Component | Example | Description |
|---|---|---|
| **Freezer Type** | `DF` | Master code: `DF` (Deep Freezer), `DCF` (Deep Chest Freezer), `UDF` (Upright Deep Freezer), `FRZ` (General Freezer) |
| **Company / Series Code** | `RW` | Brand/OEM: `RW` (Rockwell), `VL` (Voltas), `BS` (Blue Star), `HV` (Havells), `LG`, etc. |
| **Model Number** | `SFR550DDU` | Sanitized alphanumeric model number |
| **Location Code** | `AMD1` | Standard location code (e.g. `AMD1`, `MUM1`, `DEL1`) |
| **Install MMYY** | `0324` | Installation month/year first (`0324` for March 2024) |
| **PIN Code (6)** | `380058` | Full 6-digit PIN after MMYY |
| **Unique Unit Sequence** | `A7K2M9` | Random 6-char letter+digit mix; re-rolled if already used in existing data |

- **Real-Time Dynamic Generator:** The Add Profile wizard displays a live ID preview and color-coded validity badge as the technician fills the form.
- **Duplicate Prevention:** Database unique constraint on `unique_freezer_id` combined with server-side pre-flight duplicate checking and sequential auto-advancing.

### 3. Photographic Verification (3 Required Photos)
- **Freezer Left Side Image** (`image_left_side`)
- **Freezer Right Side Image** (`image_right_side`)
- **IoT Device Attachment Image** (`image_iot_device`)
- **Security Validation:** Server-side MIME check (`image/jpeg`, `image/png`, `image/webp`), `getimagesize()` binary verification, 10MB file limit, random hex filenames, and path traversal prevention.
- **Interactive Lightbox:** Click any image in profile view for high-res zoom.

### 4. Vector QR Code Generation & Scanning
- Generated via `chillerlan/php-qrcode` based on the complete unique Freezer ID.
- **Mobile Camera QR Scanner:** Built-in web scanner (`qr_scanner.php`) utilizing camera viewfinder and audio beep to immediately look up and open corresponding freezer profiles.
- **Printable Industrial Asset Tag:** (`qr_print.php`) Thermal sticker/label ready for printing and pasting onto freezer chassis.

### 5. Access Control
- **Super Admin only (`super_admin`):** Full access — profiles, import/export, users, QR, KYC, transfer.
- **Security Practices:** Password hashing with `password_hash()` (bcrypt), prepared statements (PDO), and CSRF tokens.

### 6. IoT Telemetry Ready
- Pre-configured `iot_devices` and `iot_telemetry` database tables.
- Live temperature telemetry card with target range compliance (-24°C to -18°C), compressor status, door alerts, and battery indicator.
- REST API endpoint (`api/iot_telemetry.php`) ready for receiving incoming sensor pings and querying historical logs.

---

## Directory Structure

```
SIM App/
├── app/
│   ├── Config/
│   │   ├── Database.php          # PDO singleton connection
│   │   └── AppConfig.php         # App constants, upload limits, roles
│   ├── Database/
│   │   └── schema.sql            # Complete database schema
│   ├── Models/
│   │   ├── Store.php             # Store CRUD & GPS coordinates
│   │   ├── Freezer.php           # Freezer CRUD & filter engine
│   │   ├── IoTDevice.php         # IoT master & telemetry logger
│   │   └── MasterData.php        # Masters: Types, Brands, Locations
│   └── Services/
│       ├── FreezerIdGenerator.php# Standardized ID generator & validator
│       ├── QRCodeService.php     # Chillerlan QR Code renderer
│       ├── FileUploadService.php # Secure image upload & MIME validator
│       └── AuthService.php       # RBAC, sessions & CSRF tokens
├── api/
│   ├── generate_id.php           # AJAX endpoint for live ID preview
│   └── iot_telemetry.php         # REST endpoint for sensor readings
├── assets/
│   ├── css/app.css               # Modern mobile-first CSS design
│   └── js/app.js                 # Dynamic ID generator & GPS widget
├── includes/
│   ├── header.php                # Responsive navbar & sidebar
│   └── footer.php                # Layout footer & script imports
├── uploads/
│   ├── freezers/                 # Both freezer sides photos
│   ├── iot/                      # IoT device mounting photos
│   └── qrcodes/                  # Generated SVG asset tags
├── vendor/
│   ├── autoload.php              # PSR-4 Autoloader
│   └── chillerlan/               # php-qrcode library
├── index.php                     # Operations Dashboard
├── login.php                     # Authentication & demo quick-fill
├── logout.php                    # Session termination
├── profiles.php                  # Profiles Directory, Search & Export
├── profile_add.php               # Onboarding Wizard (GPS + Photos + ID)
├── profile_view.php              # Full profile view + QR + IoT telemetry
├── profile_edit.php              # Edit details & swap photos
├── profile_delete.php            # Super admin deletion
├── qr_scanner.php                # Live camera QR scanner
├── qr_print.php                  # Printable Industrial Asset Tag Label
├── database_setup.php            # Automated database installer
└── seed_demo_data.php            # Demo data & sample photos seeder
```

---

## Default Demo Credentials

All seeded test accounts use password: **`Admin@123`**

| Role | Email | Password |
|---|---|---|
| **Super Admin** | `admin@reeferon.com` | `Admin@123` |

---

## Running the Application

1. **Ensure MySQL is running:**
   ```bash
   # WAMP / Standalone MySQL on 127.0.0.1:3306
   ```

2. **Initialize Database (if not already run):**
   ```bash
   php database_setup.php
   php seed_demo_data.php
   ```

3. **Start the Web Server:**
   ```bash
   php -S 0.0.0.0:8000
   ```

4. **Access in Web Browser / Mobile Device:**
   - URL: `http://localhost:8000`
   - Mobile Phone (local network): `http://<YOUR_PC_IP>:8000`

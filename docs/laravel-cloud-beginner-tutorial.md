# The Ultimate Beginner's Guide to Deploying on Laravel Cloud

Welcome! If you are new to Laravel Cloud and want to deploy the Receiving Operations project, you are in the exact right place. We will walk through every single step in detail, assuming you have never deployed an app like this before.

Laravel Cloud is a powerful platform that manages your servers, databases, and deployments automatically. However, because this specific application handles sensitive files, uses Cloudmersive antivirus scanning, and processes complex background jobs (queues), we have to configure a few things manually.

We are going to set this up directly as your **Production** environment.

---

## Phase 1: Local Preparation (Before you open the browser)

Before we start clicking buttons in Laravel Cloud, we need to gather some "secrets" and ensure our code is ready.

1. **Push your code to GitHub:**
   Ensure all your latest code changes are committed and pushed to the `master` branch of your GitHub repository. Laravel Cloud will pull your code directly from GitHub.

2. **Generate a Secret App Key:**
   Laravel uses a unique "App Key" to encrypt passwords and sessions. Open your terminal in the project folder and run:
   ```bash
   php artisan key:generate --show
   ```
   You will see a string that starts with `base64:`. Copy this entire string and save it in a notepad. We will need it later.

3. **Gather Your Third-Party Secrets:**
   This app talks to external services. You need to have these ready:
   - **Google Gemini API Key:** For the AI data extraction.
   - **SMTP Credentials:** Your email server details (Host, Port, Username, Password) so the app can send One-Time Passwords (OTPs).
   - **Cloudmersive API Key:** Store it in your password manager and confirm the monthly call and file-size allowances shown in the Cloudmersive portal.
   - **An Admin Password:** Invent a strong password (at least 20 characters long) for your first admin account.

---

## Phase 2: Create the App in Laravel Cloud

Now we are ready to connect to the cloud.

1. Go to [cloud.laravel.com](https://cloud.laravel.com) and log in.
2. Click the **+ New application** button.
3. Choose **Continue with GitHub**. You will be asked to authorize Laravel Cloud to read your repositories. Select your `receiving` repository.
4. Name the application **Receiving**.
5. Choose a region. Pick the one closest to where you and your users live (for example, `Asia Pacific (Singapore)`).
6. Click **Create**.

Laravel Cloud will create a default environment for you (usually named **production**). Let's make sure it's configured right:
1. Make sure the branch it deploys from is set to `master`.
3. Go to the **General Settings** for this environment and ensure:
   - **PHP version:** `8.4`
   - **Node version:** `22`
   - **Octane:** OFF (we don't use it)
   - **Inertia SSR:** OFF (we use standard client-side rendering)
   - **App replicas:** `1` (one server is enough for now)

---

## Phase 3: Create the Database & Storage

Websites need a place to store data (text) and storage (files). Laravel Cloud makes this easy.

### 1. Set up the Database
- On your production dashboard, click **Add database**.
- Select **Laravel Serverless Postgres**. Postgres is the type of database this app is built for.
- Name it `receiving-production-postgres` (keep it in the same region you chose earlier).
- Allocate at least **5 GB** of storage.
- Create a logical database inside it named `receiving_production`.
- **CRITICAL STEP:** Make sure you attach this database to your `production` environment. Once attached, Laravel Cloud automatically tells your app how to connect to it (it injects the `DB_` environment variables).

### 2. Set up File Storage (Object Storage)
- Click **Add bucket**.
- Select **Laravel Object Storage** (this uses Cloudflare R2 behind the scenes).
- Name it `receiving-production-documents`.
- **CRITICAL STEP:** Set the Disk Name exactly to `r2`. (The code looks specifically for a disk named 'r2').
- Set the visibility to **Private** (we don't want anyone downloading sensitive invoices!).
- **Attach it** to your production environment.

---

## Phase 4: Configure Environment Variables

Environment variables are like a secret settings file for your app. Go to **Settings > Environment variables** in Laravel Cloud. 

Cloud has automatically added `AWS_` variables for your bucket and `DB_` variables for your database. Keep those! But we need to add a few more.

### 1. Map the Bucket Variables
Our app looks for variables starting with `R2_`, but Laravel Cloud provides them starting with `AWS_`. We need to link them up manually. Add these new variables, copying the values from the existing `AWS_` variables:

- `R2_ACCESS_KEY_ID` ➔ (Paste the value from AWS_ACCESS_KEY_ID)
- `R2_SECRET_ACCESS_KEY` ➔ (Paste the value from AWS_SECRET_ACCESS_KEY)
- `R2_BUCKET_NAME` ➔ (Paste the value from AWS_BUCKET)
- `R2_ENDPOINT` ➔ (Paste the value from AWS_ENDPOINT)

Now, add these static bucket settings:
- `R2_REGION=auto`
- `R2_USE_PATH_STYLE_ENDPOINT=true`
- `RECEIVING_DISK=r2`
- `FILESYSTEM_DISK="r2"`
- `RECEIVING_PROXY_UPLOADS=true`

### 2. Add Core App Settings
Copy and paste these in, making sure to replace the dummy values with your real ones:
```env
APP_NAME="Receiving Operations"
APP_ENV=production
APP_KEY=base64:PASTE_YOUR_GENERATED_KEY_HERE
APP_DEBUG=false
APP_URL=https://your-production-domain.laravel.cloud
APP_TIMEZONE=Asia/Singapore
SESSION_DRIVER="database"
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=360
QUEUE_FAILED_DRIVER=database-uuids
```
*(Note: You can find your `APP_URL` at the top of your Cloud dashboard. It usually ends in `.laravel.cloud` until you add a custom domain. We set `SESSION_DRIVER="database"` to override Laravel Cloud's default "cookie" sessions, which is required for the production check to pass).*

### 3. Add Temporary Admin Variables
For the very first time we deploy, the app needs to create an admin account for you. Add these (we will delete them later):
```env
INITIAL_ADMIN_NAME="Your Name"
INITIAL_ADMIN_EMAIL="admin@yourdomain.com"
INITIAL_ADMIN_PASSWORD="YourVeryStrongPassword123!"
```

### 4. Add External Services
Finally, add your email, AI, and antivirus settings:
```env
# Email Settings (for sending login OTPs)
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD="your-smtp-pass-here"   # Wrap in double quotes if there are spaces!
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"

# Gemini AI Settings (for scanning documents)
GEMINI_API_KEY=your-gemini-key
GEMINI_MODEL=gemini-2.5-flash
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
RECEIVING_AI_BATCH_SIZE=1

# Cloudmersive Antivirus Settings
RECEIVING_SCANNER_DRIVER=cloudmersive
CLOUDMERSIVE_API_KEY=your-cloudmersive-api-key
CLOUDMERSIVE_MONTHLY_CALL_LIMIT=800
CLOUDMERSIVE_MINIMUM_INTERVAL_MILLISECONDS=1100
CLOUDMERSIVE_MAX_FILE_KILOBYTES=3584
```

> **💡 TIP ON QUOTES:** In Laravel Cloud, if any environment variable value contains spaces (like SMTP passwords with spaces), you **must** wrap that value in double quotes `""`. Otherwise, the deployment parser will fail with a syntax error.

---

## Phase 5: Setup Build, Deploy, and Workers

Laravel Cloud needs to know how to install your app's dependencies and how to run background tasks.

### 1. Build & Deploy Commands
Go to **Settings > Deployments**.

Under **Build command**, enter exactly this (copy it as a single line):
```bash
composer install --no-dev --optimize-autoloader --no-interaction && npm ci && npm run build && php artisan route:cache && php artisan view:cache
```

Under **Deploy command**, enter exactly this (copy it as a single line):
```bash
php artisan receiving:check-production && php artisan migrate --seed --force
```

### 2. Add Background Workers
This app does heavy lifting (scanning files, talking to AI) in the background so the user doesn't have to wait. We need "Workers" to handle this.

- Go to your **App compute cluster > Background processes**.
- Add two custom processes (1 instance each):
  - **Process 1 (OTP Worker):** `php artisan queue:work --queue=otp --tries=3 --timeout=60 --sleep=1`
  - **Process 2 (Receiving Worker):** `php artisan queue:work --queue=receiving,ai,default --tries=3 --timeout=300 --sleep=3`

### 3. Enable the Scheduler
The app automatically cleans up old temporary files every hour. 
- In the same App compute cluster settings, find the **Scheduler** toggle and turn it **ON**.

---

## Phase 6: The First Deployment & Cleanup

1. Click the big **Deploy** button. 
2. Watch the logs. You want to see the migrations run successfully and the environment turn green (Active).
3. **CRITICAL SECURITY STEP:** Once it is successful, we must clean up!
   - Go back to your **Environment Variables** and **DELETE** `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, and `INITIAL_ADMIN_PASSWORD`. You don't want these sitting around.
   - Go back to **Settings > Deployments** and remove `--seed` from the Deploy command. It should now just be:
     ```bash
     php artisan receiving:check-production && php artisan migrate --force
     ```
   - Click **Save & Deploy** one more time to lock in these secure settings.

---

## Phase 7: Test Your Deployment (Verification)

Congratulations! It's deployed. Let's make sure it works.

1. **Check the System:** Go to `https://your-production-domain.laravel.cloud/up`. If you see a blank page or "ok", the server is running!
2. **Log In:** Go to `https://your-production-domain.laravel.cloud/login`. Enter the email and password you used for your Admin account.
3. **Test Email:** The system will try to send you a One-Time Password (OTP). If you receive the email, your SMTP settings and OTP worker are perfect!
4. **Test File Upload:** Try uploading a harmless PDF. 
   - If it gets stuck on "Pending/Processing", check that your Background Workers are running.
   - If it successfully moves to "Complete", that means Cloudmersive, Object Storage, and Gemini AI are all configured correctly.

Once you have verified all of this, your Production environment is ready! You can now attach your real custom domain in the Laravel Cloud dashboard under the **Network** tab.

---

## Appendix A: Legacy emergency rollback with ClamAV on a Standard VPS

Skip this appendix for the normal Cloudmersive deployment. It is retained only as an emergency rollback path while `RECEIVING_SCANNER_DRIVER=clamav` remains supported. A raw public ClamAV port is not an acceptable production design; use a protected private network and rerun all scanner probes if rollback is necessary.

Here is the absolute easiest way for a beginner to do this using a cheap Virtual Private Server (VPS) and Docker.

### Step 1: Rent a cheap VPS
1. Go to a provider like **DigitalOcean**, **Hetzner**, or **Linode**.
2. Create a new basic server (called a "Droplet" in DigitalOcean). 
3. Choose **Ubuntu** as the Operating System.
4. Pick the cheapest plan with at least **2GB of RAM** (ClamAV requires a bit of memory to load virus definitions). This usually costs about $5 to $10 a month.
5. Create the server and copy its **Public IP Address**.

### Step 2: Log into the server
Open your terminal (Command Prompt or Terminal on Mac) and SSH into your new server:
```bash
ssh root@YOUR_SERVER_IP
```
*(It will ask for the password you created, or use your SSH key).*

### Step 3: Install Docker & Run ClamAV
Once you are logged into your server's terminal, run these two commands exactly as written:

**1. Install Docker:**
```bash
sudo apt update && sudo apt install docker.io -y
```

**2. Start the ClamAV Scanner:**
```bash
sudo docker run -d -p YOUR_PRIVATE_INTERFACE_IP:3310:3310 --name clamav --restart always clamav/clamav
```

### Step 4: Add it to Laravel Cloud
The rollback scanner now listens only on the protected interface. Confirm Laravel Cloud can reach it through your VPN/private network before continuing.

Go back to your Laravel Cloud **Environment Variables** and enter:
```env
RECEIVING_SCANNER_DRIVER=clamav
CLAMAV_HOST=YOUR_PRIVATE_INTERFACE_IP
CLAMAV_PORT=3310
```

> [!WARNING]
> **Security Note:** Never bind ClamAV to a public interface. Allow TCP 3310 only from the application through a private network or VPN.

---

## Appendix B: Archived ClamAV rollback example for Oracle Cloud

This archived example is not part of the Cloudmersive deployment. Do not expose TCP 3310 to `0.0.0.0/0`; a rollback scanner must be reachable only through a protected network. Oracle plan availability can change, so verify it independently before relying on this option.

### Step 1: Create an Oracle Cloud Account
1. Go to [Oracle Cloud Free Tier](https://www.oracle.com/cloud/free/) and click **Start for free**.
2. Complete the registration process (you will need a credit card for verification, but you won't be charged for "Always Free" resources).
3. Once logged into the Oracle Cloud Console, select your region.

### Step 2: Create a Compute Instance
1. In the Oracle Cloud Console, click the hamburger menu (top left) > **Compute** > **Instances**.
2. Click **Create Instance**.
3. **Name your instance:** `clamav-server`.
4. **Image and Shape:** 
   - Click **Edit** on Image and shape. 
   - Change the image to **Ubuntu**.
   - Click **Change Shape**, select **Virtual Machine** > **Ampere** > **VM.Standard.A1.Flex** (This is the Always Free ARM shape).
   - Set it to **1 OCPU** and **6 GB RAM** (which is plenty for ClamAV and stays well within the free limits).
5. **Networking:** Leave the default Virtual Cloud Network (VCN) settings. Ensure "Assign a public IPv4 address" is selected.
6. **Add SSH keys:** 
   - Select **Generate a key pair for me**.
   - Click **Save private key** (Keep this `.key` file safe, you will need it to log in).
7. Click **Create** at the bottom. Wait a few minutes for the instance state to become **Running**.
8. Copy the **Public IP Address** shown on the instance details page.

### Step 3: Open the Port in Oracle's Firewall (VCN)
Oracle Cloud blocks all ports by default. We must open port 3310 for ClamAV.
1. On your Instance details page, click on the **Subnet** link (under Primary VNIC).
2. Click on the **Default Security List**.
3. Click **Add Ingress Rules**.
4. Fill in the rule:
   - **Source CIDR:** your protected VPN/private-network CIDR. Never use `0.0.0.0/0`.
   - **IP Protocol:** `TCP`.
   - **Destination Port Range:** `3310`.
5. Click **Add Ingress Rules**.

### Step 4: Log into the Server
Open your terminal (Command Prompt, PowerShell, or Terminal) and use the private key you downloaded to SSH into the server:
```bash
# If on Mac/Linux, fix the permissions on your key file first:
chmod 400 path/to/your-private-key.key

# Connect to the server (username is 'ubuntu' for Ubuntu image)
ssh -i path/to/your-private-key.key ubuntu@YOUR_PUBLIC_IP
```

### Step 5: Install Docker and Run ClamAV
Once inside the server, run these commands to install Docker and start ClamAV:

```bash
# Update packages
sudo apt update && sudo apt upgrade -y

# Install Docker
sudo apt install docker.io -y

# Start the ClamAV container
sudo docker run -d -p YOUR_PRIVATE_INTERFACE_IP:3310:3310 --name clamav --restart always clamav/clamav
```
*(Docker will automatically manage the OS-level firewall rules on Ubuntu).*

### Step 6: Add it to Laravel Cloud
Your free ClamAV server is now running! 

Go back to your Laravel Cloud **Environment Variables** and update them:
```env
RECEIVING_SCANNER_DRIVER=clamav
CLAMAV_HOST=YOUR_PRIVATE_INTERFACE_IP
CLAMAV_PORT=3310
```

> [!WARNING]
> **Security Note:** This rollback path is permitted only through a protected private network or VPN. If that is unavailable, keep Cloudmersive and do not expose ClamAV.

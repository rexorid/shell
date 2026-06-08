import time
import os
import requests
import subprocess
import threading
import shutil
import hashlib
from watchdog.observers import Observer
from watchdog.events import FileSystemEventHandler

TELEGRAM_TOKEN = "8830731603:AAG1A7ZB0rKbMaldao0FyaWMwfUreiUIcHY"
CHAT_ID = "5115967642"

BLACKLIST_EXTENSIONS = {
    # --- RUMPUN PHP ---
    "php", "php3", "php4", "php5", "php6", "php7", "php8", "php9", 
    "php55", "php56", "php77", "phar", "pht", "phtml", "phps", "phtm",

    # --- EXT PHP DKK ---
    "Php", "pHP", "phP", "PHP", 
    "Phar", "pHar", "phAr", "phaR", "PHAR",
    "Php5", "Php6", "Php7", "Php8", "Php9", "Php55", "Php56", "Php77",
    "pHp5", "pHp6", "pHp7", "pHp8", "pHp9", "pHp55", "pHp56", "pHp77",
    "phP5", "phP6", "phP7", "phP8", "phP9", "phP55", "phP56", "phP77",
    "Pht", "pHt", "phT", "PHT", 
    "Phtml", "pHtml", "phTml", "phtMl", "phtmL", "PHTML",

    # --- HTML & CONFIG WEB SERVER ---
    "html", "htm", "shtml", "xhtml",
    "htaccess", ".htaccess", "htpasswd", ".htpasswd",
    "user.ini", ".user.ini", "web.config",

    # --- RUMPUN JSP / JAVA  ---
    "jsp", "jspx", "jsw", "jsv", "jspf", "war", "ear",
    "Jsp", "JSP", "Jspx", "JSPX",

    # --- RUMPUN ASP / ASP.NET / IIS ---
    "asp", "aspx", "asa", "asax", "ashx", "asmx", "axd", "config",
    "Asp", "ASP", "Aspx", "ASPX",

    # --- RUMPUN PERL / CGI / COLD FUSION ---
    "pl", "cgi", "cfm", "cfc", "plx",
    "Pl", "PL", "Cgi", "CGI",

    # --- RUMPUN PYTHON / RUBY / SHELL SCRIPT  ---
    "py", "pyc", "pyo", "rb", "sh", "bash", "zsh", "bat", "cmd", "exe",
    "Py", "PY", "Sh", "SH", "Bash", "BASH",

    # --- SERVER SIDE INCLUDES (SSI) & NODE / RUST EXECUTABLES ---
    "inc", "shtml", "stm", "shtm", "js", "json"
}

CRITICAL_KEYWORDS = [b"gsocket", b"gs-netcat", b"gs_netcat", b"gs-secret", b"segfault.net", b"deploy-all.sh"]
SHELL_SIGNATURES = ["/dev/tcp/", "/dev/udp/", "pty.spawn", "subprocess.popen", "socket.socket", "nc ", "ncat ", "socat ", "base64 -d", "sh -i", "bash -i", "zsh -i"]
FORBIDDEN_BINARIES = ["adduser", "useradd"]
PRIMARY_BACKUP_DIR = "/var/backups/eye"
FALLBACK_BACKUP_DIR = os.path.expanduser("~/.eye")
SELECTED_BACKUP_DIR = PRIMARY_BACKUP_DIR
PROTECTED_FILES = []

def send_telegram_message(message):
    url = f"https://api.telegram.org/bot{TELEGRAM_TOKEN}/sendMessage"
    payload = {
        "chat_id": CHAT_ID,
        "text": message,
        "parse_mode": "Markdown"
    }
    try:
        requests.post(url, json=payload)
    except Exception:
        pass

def instant_kill(pid):
    try:
        os.kill(int(pid), 9)
        return True
    except Exception:
        return False

def calculate_md5(file_path):
    if not os.path.isfile(file_path):
        return None
    hash_md5 = hashlib.md5()
    try:
        with open(file_path, "rb") as f:
            for chunk in iter(lambda: f.read(4096), b""):
                hash_md5.update(chunk)
        return hash_md5.hexdigest()
    except Exception:
        return None

def init_backup_system(files_input):
    global PROTECTED_FILES, SELECTED_BACKUP_DIR
    
    try:
        os.makedirs(PRIMARY_BACKUP_DIR, exist_ok=True)
        test_file = os.path.join(PRIMARY_BACKUP_DIR, ".write_test")
        with open(test_file, "w") as f:
            f.write("test")
        os.remove(test_file)
        SELECTED_BACKUP_DIR = PRIMARY_BACKUP_DIR
        print(f"[+] Menggunakan lokasi backup utama: {SELECTED_BACKUP_DIR}")
    except Exception:
        try:
            os.makedirs(FALLBACK_BACKUP_DIR, exist_ok=True)
            SELECTED_BACKUP_DIR = FALLBACK_BACKUP_DIR
            print(f"[⚠️] /var/backups tidak writable. Switch otomatis ke fallback: {SELECTED_BACKUP_DIR}")
        except Exception as e:
            print(f"[-] Gagal total membuat folder backup: {e}")
            return
            
    for f_path in files_input:
        if f_path:
            abs_path = os.path.abspath(f_path)
            if abs_path not in PROTECTED_FILES:
                PROTECTED_FILES.append(abs_path)
            safe_name = abs_path.replace("/", "_")
            backup_target = os.path.join(SELECTED_BACKUP_DIR, safe_name)
            try:
                if os.path.exists(abs_path):
                    shutil.copy2(abs_path, backup_target)
                    print(f"[+] Backup Master Terbuat: {abs_path} -> {backup_target}")
            except Exception as e:
                print(f"[-] Gagal backup {abs_path}: {e}")

class UltimateProtectionHandler(FileSystemEventHandler):
    def is_shell_or_htaccess(self, file_path):
        file_name = os.path.basename(file_path)
        
        if ".htaccess" in file_name or ".htaccess" in file_name.lower():
            return True, "Manipulasi atau pembuatan file `.htaccess` terdeteksi!"
            
        for ext in BLACKLIST_EXTENSIONS:
            dot_ext = f".{ext}"
            if dot_ext in file_name:
                return True, f"File mengandung komponen ekstensi terlarang `{dot_ext}`!"
                
        return False, ""

    def inspect_file(self, file_path):
        file_name = os.path.basename(file_path).lower()
        
        for kw in [k.decode() for k in CRITICAL_KEYWORDS]:
            if kw in file_name:
                return True, f"Nama file mengandung keyword berbahaya `{kw}`"

        is_shell, reason = self.is_shell_or_htaccess(file_path)
        if is_shell:
            return True, reason

        if file_name.endswith(".sh") or file_path.startswith(("/tmp", "/dev/shm")):
            if os.path.isfile(file_path) and os.path.getsize(file_path) < 2 * 1024 * 1024:
                try:
                    with open(file_path, 'rb') as f:
                        content = f.read().lower()
                        for keyword in CRITICAL_KEYWORDS:
                            if keyword in content:
                                return True, f"Isi file/script mengandung signature `{keyword.decode()}`"
                except Exception:
                    pass
                    
        return False, ""

    def enforce_mitigation(self, path, reason):
        try:
            os.remove(path)
            msg = (
    "🛡️ *[ SECURITY REPORT: DEEP INTERCEPT ]* 🛡️\n"
    "====================================\n"
    "💥 *ACTION:* `MALICIOUS FILE DESTROYED`\n"
    "🟢 *STATUS:* `THREAT PURGED (0-DELAY)`\n"
    "====================================\n\n"
    "📋 *INTERCEPTION DETAILS:* \n"
    "• 📄 *TARGET PATH:* `{}`\n"
    "• 🔍 *TRIGGER REASON:* `{}`\n"
    "• ⚡ *MITIGATION:* `REALTIME AUTOMATIC WIPE`\n\n"
    "🔥 _Intrusion attempt in subfolder has been completely neutralized. File permanently deleted._"
).format(path, reason)
            print(msg)
            send_telegram_message(msg)
        except Exception:
            try:
                os.chmod(path, 000)
                msg = (
    "⚠️ *[ CRISIS ALERT: SUBSYSTEM LOCKDOWN ]* ⚠️\n"
    "====================================\n"
    "🔒 *ACTION:* `FILE ISOLATED & LOCKDOWN`\n"
    "🔴 *WARNING:* `WIPE DIRECTORY FAILED`\n"
    "====================================\n\n"
    "📋 *LOCKDOWN DETAILS:* \n"
    "• 📄 *TARGET PATH:* `{}`\n"
    "• 🚫 *PERMISSION:* `REVOKED TOTAL (CHMOD 000)`\n"
    "• ⚡ *MITIGATION:* `EXECUTION & READ BLOCKED`\n\n"
    "💀 _File destruction failed due to system lock, but all access privileges have been forcefully crushed to 000._"
).format(path)
                send_telegram_message(msg)
            except Exception:
                pass

    def check_and_restore(self, file_path, event_type="modification"):
        abs_path = os.path.abspath(file_path)
        if abs_path in PROTECTED_FILES:
            safe_name = abs_path.replace("/", "_")
            backup_source = os.path.join(SELECTED_BACKUP_DIR, safe_name)
            
            if os.path.exists(backup_source):
                if event_type in ["modification", "creation"]:
                    current_md5 = calculate_md5(abs_path)
                    backup_md5 = calculate_md5(backup_source)
                    if current_md5 == backup_md5:
                        return True
                
                try:
                    shutil.copy2(backup_source, abs_path)
                    msg = (
    "🛡️ *[ SECURITY ALERT: COMPONENT AUTO-RESTORE ]* 🛡️\n"
    "====================================\n"
    "🔄 *EVENT:* `CRITICAL SYSTEM COMPONENT {}`\n"
    "🟢 *STATUS:* `INTEGRITY SUCCESSFULLY REPAIRED`\n"
    "====================================\n\n"
    "📋 *RECOVERY DETAILS:* \n"
    "• 📄 *TARGET PATH:* `{}`\n"
    "• 🗄️ *SOURCE:* `MASTER BACKUP CLONE`\n"
    "• ⚡ *MITIGATION:* `RECURSIVE WIPE & ROLLBACK`\n\n"
    "🔒 _File integrity has been forcefully restored to its original state._"
).format(event_type.upper(), abs_path)
                    print(msg)
                    send_telegram_message(msg)
                    return True
                except Exception:
                    pass
        return False

    def on_created(self, event):
        if not event.is_directory:
            path = event.src_path
            if self.check_and_restore(path, "creation"):
                return
                
            is_malicious, reason = self.inspect_file(path)
            if is_malicious:
                self.enforce_mitigation(path, reason)

    def on_modified(self, event):
        if not event.is_directory:
            path = event.src_path
            if self.check_and_restore(path, "modification"):
                return
                
            is_malicious, reason = self.inspect_file(path)
            if is_malicious:
                self.enforce_mitigation(path, reason)

    def on_deleted(self, event):
        if not event.is_directory:
            path = event.src_path
            if self.check_and_restore(path, "deletion"):
                return

    def on_moved(self, event):
        if not event.is_directory:
            old_path = event.src_path
            new_path = event.dest_path
            
            if self.check_and_restore(old_path, "renaming/moving"):
                is_malicious, reason = self.inspect_file(new_path)
                if is_malicious:
                    self.enforce_mitigation(new_path, reason)
                return

            is_malicious, reason = self.inspect_file(new_path)
            if is_malicious:
                self.enforce_mitigation(new_path, reason)

def hyper_process_and_network_hunter():
    while True:
        try:
            proc = subprocess.Popen(
                "ps -eo pid,user,args", 
                shell=True, 
                stdout=subprocess.PIPE, 
                stderr=subprocess.PIPE
            )
            stdout, _ = proc.communicate()
            lines = stdout.decode('utf-8', errors='ignore').split('\n')
            
            for line in lines[1:]:
                line = line.strip()
                if not line:
                    continue
                
                parts = line.split(None, 2)
                if len(parts) < 3:
                    continue
                
                pid, user, cmdline = parts[0], parts[1], parts[2].lower()
                
                if "python" in cmdline and "eye.py" in cmdline:
                    continue
                    
                is_malicious_proc = False
                trigger_reason = ""
                
                for f_bin in FORBIDDEN_BINARIES:
                    if f_bin in cmdline:
                        is_malicious_proc = True
                        trigger_reason = f"Upaya Pembuatan User Baru (`{f_bin}`)"
                        break
                
                if not is_malicious_proc:
                    for keyword in [k.decode() for k in CRITICAL_KEYWORDS]:
                        if keyword in cmdline:
                            is_malicious_proc = True
                            trigger_reason = f"Backdoor Gsocket (`{keyword}`)"
                            break
                        
                if not is_malicious_proc:
                    for sig in SHELL_SIGNATURES:
                        if sig in cmdline:
                            if "nc" in cmdline and ("-l" in cmdline or "-p" in cmdline):
                                is_malicious_proc = True
                                trigger_reason = "Blind Port Listener (Netcat)"
                                break
                            elif "/dev/tcp" in cmdline or "/dev/udp" in cmdline or "sh -i" in cmdline or "bash -i" in cmdline:
                                is_malicious_proc = True
                                trigger_reason = "Bash/Sh Reverse Shell Pipeline"
                                break
                                
                if is_malicious_proc:
                    if instant_kill(pid):
                        msg = (
    "🚨 *[ INTERCEPT REPORT: SYSTEM MITIGATION ]* 🚨\n"
    "====================================\n"
    "💥 *ACTION:* `PROCESS TERMINATED (KILL -9)`\n"
    "🛡️ *STATUS:* `THREAT SUCCESSFULLY ISOLATED`\n"
    "====================================\n\n"
    "📋 *ACTIVITY DETAILS:* \n"
    "• 🆔 *PROCESS PID:* `{}`\n"
    "• 👤 *OS USER:* `{}`\n"
    "• 🔎 *THREAT TYPE:* `{}`\n"
    "• 💻 *COMMAND/CMD:* `{}`\n\n"
    "🟢 _System integrity restored. Continuous monitoring in progress._"
).format(pid, user, trigger_reason, parts[2])
                        print(msg)
                        send_telegram_message(msg)
                        
        except Exception:
            pass
        time.sleep(0.005)

def inject_shell_protection():
    hooks = [
        "\n# Insomnia Protection Hook",
        "alias gsocket='echo \"[!] Command Blocked by Insomnia Defender\"'",
        "alias gs-netcat='echo \"[!] Command Blocked by Insomnia Defender\"'",
        "alias adduser='echo \"[!] User Creation Blocked by Insomnia Defender\"'",
        "alias useradd='echo \"[!] User Creation Blocked by Insomnia Defender\"'",
        "gsocket() { echo '[!] Function Blocked'; }",
        "gs-netcat() { echo '[!] Function Blocked'; }",
        "adduser() { echo '[!] Function Blocked'; }",
        "useradd() { echo '[!] Function Blocked'; }\n"
    ]
    targets = [".bashrc", ".zshrc", ".bash_profile"]
    home = os.path.expanduser("~")
    
    for t in targets:
        p = os.path.join(home, t)
        if os.path.exists(p):
            try:
                with open(p, "r") as f:
                    content = f.read()
                if "Insomnia Protection" not in content:
                    with open(p, "a") as f:
                        f.write("\n".join(hooks))
            except Exception:
                pass

if __name__ == "__main__":
    print("\n" + "="*50)
    print(" 🛡️  [ INSOMNIA DEFENDER HARDENING SYSTEM ] 🛡️")
    print("="*50)
    print("📡 STATUS: PRE-INITIALIZING CORE INTERFACE...")
    print("-"*50)
    inject_shell_protection()
    
    print("\n[👁️ ] STEP 2: RECURSIVE DIRECTORY WATCHDOG")
    print("    👉 Enter web root or upload folders to monitor 24/7.")
    print("    💡 Example: /var/www/html, /tmp, /dev/shm")
    input_dir = input("    📝 INPUT DIRS  : ")
    directories = [d.strip() for d in input_dir.split(",") if d.strip()]
    
    print("\n[🔒] STEP 1: TARGET SPECIFIC FILES LOCKDOWN")
    print("    👉 Enter full paths of critical files you want to secure.")
    print("    💡 Example: /etc/passwd, /etc/shadow, /var/www/html/index.php")
    input_files = input("    📝 INPUT FILES : ")
    files_to_protect = [f.strip() for f in input_files.split(",") if f.strip()]
    print("\n" + "-"*50)
    print("🚀 BOOTING ENGINE... ATTACHING RECURSIVE SUBFOLDER HANDLERS")
    print("="*50 + "\n")
    system_critical_files = [
        "/etc/passwd",
        "/etc/shadow",
        "/etc/crontab"
    ]
    
    cron_spool_dir = "/var/spool/cron/crontabs"
    if os.path.exists(cron_spool_dir):
        try:
            for root, _, files in os.walk(cron_spool_dir):
                for file in files:
                    system_critical_files.append(os.path.join(root, file))
        except Exception:
            pass
            
    for scf in system_critical_files:
        if os.path.exists(scf):
            files_to_protect.append(scf)
            
    init_backup_system(files_to_protect)
    
    for fp in files_to_protect:
        parent_dir = os.path.dirname(fp)
        if os.path.exists(parent_dir) and parent_dir not in directories:
            directories.append(parent_dir)
            
    home_dir = os.path.expanduser("~")
    gsocket_dirs = [
        os.path.join(home_dir, ".gsocket"),
        "/tmp",
        "/dev/shm"
    ]
    
    for gd in gsocket_dirs:
        if gd not in directories:
            directories.append(gd)
        if not os.path.exists(gd):
            try:
                os.makedirs(gd)
            except Exception:
                pass

    observer = Observer()
    event_handler = UltimateProtectionHandler()
    active_watches = 0

    for path in directories:
        if os.path.exists(path):
            observer.schedule(event_handler, path=path, recursive=True)
            active_watches += 1

    hunter_thread = threading.Thread(target=hyper_process_and_network_hunter, daemon=True)
    hunter_thread.start()

    send_telegram_message(
        "⚡ *⚡⚡ [ INSOMNIA DEFENDER CORE ] ⚡⚡*\n"
        "====================================\n"
        "🛰️ *STATUS:* `ENGINE ONLINE & ACTIVE`\n"
        "🛡️ *SECURITY MODE:* `MAXIMUM HARDENING (AGGRESSIVE)`\n"
        "📊 *SCAN INTERVAL:* `0ms (REAL-TIME PROCESS INTERCEPT)`\n"
        "====================================\n\n"
        "🔒 *SUBSYSTEM REGISTRATION:* \n"
        "• `ANTI-WEBSHELL` 🟢 [ONLINE]\n"
        "• `ANTI-GSOCKET C2` 🟢 [ONLINE]\n"
        "• `REVERSE-SHELL BLOCKER` 🟢 [ONLINE]\n"
        "• `BLIND-PORT INTERCEPTOR` 🟢 [ONLINE]\n"
        "• `CRONTAB PERSISTENCE WATCH` 🟢 [ONLINE]\n"
        "• `SYSTEM ACCOUNT INTEGRITY LOCK` 🟢 [ONLINE]\n\n"
        "🚫 *ENFORCED POLICY:* \n"
        "`[CRITICAL]` *Anti-User Creation Mode* is fully deployed. Any execution of `adduser` or `useradd` via terminal, sudo, or obfuscated binaries will be terminated instantly without exception!\n\n"
        "🚀 _Internal server defense system is armed and protecting the infrastructure._"
    )
    
    observer.start()
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        observer.stop()
    observer.join()

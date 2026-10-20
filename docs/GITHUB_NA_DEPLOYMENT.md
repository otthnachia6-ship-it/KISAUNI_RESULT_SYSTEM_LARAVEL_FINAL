# GITHUB NA DEPLOYMENT — kisaunips.ostexs.com

## A. KWENYE KOMPYUTA YAKO (Windows, Git Bash au PowerShell)
```
cd "C:\njia\ya\KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL"
git --version                      # ikikosekana: sakinisha Git for Windows
git status                         # kama "not a git repository" -> endelea na git init
git init -b main
git check-ignore -v .env vendor    # LAZIMA zote mbili zionyeshe mstari wa .gitignore
git add .
git status                         # kagua: .env, vendor/, *.sqlite, storage/backups HAVIONEKANI
git config user.name  "Jina Lako"
git config user.email "email@yako.com"
git commit -m "Kisauni Result System - Laravel final (user CRUD fix, circular grades, tests)"
```
Kwenye github.com: **New repository** -> jina `kisauni-result-system` -> chagua **Private** -> usiweke README/.gitignore -> Create.
```
git remote add origin https://github.com/JINA_LAKO/kisauni-result-system.git
git remote -v
git push -u origin main            # itaomba login ya GitHub (browser) au Personal Access Token
```
Baada ya hapo, kila mabadiliko: `git add .` -> `git commit -m "maelezo"` -> `git push`.
USITUMIE: `git push --force`, `git reset --hard`, `git clean -fd` (zinaweza kufuta kazi).

## B. KWA NINI `git push` PEKEE HAI-UPDATE production
GitHub ni hifadhi tu. Server ya Ostex haijui kuwa umepush. Lazima server iamriwe kuvuta (pull).

## C. MUUNDO ULIOPENDEKEZWA (salama kwa Ostex/cPanel)
LOCAL --push--> GITHUB (private) --pull--> OSTEXS (script `deploy.sh`) --> kisaunips.ostexs.com
* Njia 1 (inapendekezwa): Ostex panel -> Advanced Features -> Git -> Pull (unayoijua), kisha Terminal: `bash ~/deploy.sh`
* Njia 2 (otomatiki kamili): kama panel ina "Git Version Control" yenye "Deploy HEAD Commit", weka `.cpanel.yml` inayoita deploy.sh. HAIJATHIBITISHWA kama panel ya Ostex (Evo) inaunga mkono - kagua kwenye panel.
* Webhook: HAIPENDEKEZWI (inahitaji endpoint ya wazi kwenye site).
Database HAIGUSWI na git. Migrations zinaendeshwa tu kwa `--force` baada ya backup, na hazitumii kamwe `migrate:fresh`.

## D. KUUNGANISHA FOLDA YA PRODUCTION NA GITHUB (mara moja tu)
Folda `~/KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL` sasa si git repo. Hatua zisizo hatari:
```
cd ~/KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL
cp .env ~/env.backup.txt                       # nakala ya siri
cp -r ~/KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL ~/kisauni_backup_kabla_ya_git   # nakala kamili
git init -b main
git remote add origin https://github.com/JINA_LAKO/kisauni-result-system.git
git fetch origin
git reset --mixed origin/main                  # haigusi faili; inalinganisha rekodi ya git tu
git status                                     # tazama faili zitakazobadilika
git checkout -- .                              # husasisha FAILI ZA CODE TU kutoka GitHub
```
`.env`, `vendor/`, `storage/` (uploads, backups, logs) na database havibadilishwi kwa sababu haviko kwenye git.
Repo ya private inahitaji server iwe na ufikiaji: tumia **deploy key** (SSH) au Personal Access Token kwenye URL ya remote.

## E. deploy.sh (weka ~/deploy.sh kwenye server)
```
#!/bin/bash
set -e
cd ~/KISAUNI_RESULT_SYSTEM_LARAVEL_FINAL
php artisan down || true
git pull --ff-only origin main
php ~/composer.phar install --no-dev --optimize-autoloader
php artisan migrate --force        # migrations MPYA tu; HAINA migrate:fresh
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```
Kabla ya `migrate` kwenye release yenye migration mpya: bonyeza Settings -> Create Backup.
Release hii HAINA migration mpya.

## F. ULINZI WA SIRI NA DATA
* `.env`, `APP_KEY`, password ya DB: kwenye server tu, `.gitignore` inazuia. Kama zilishawahi kupushwa, zibadilishe.
* Data za wanafunzi/alama/audit log ziko MySQL tu; backups (`storage/backups`) na uploads vimeigwa kwenye .gitignore.
* Repo iwe **Private**. Production: `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`.

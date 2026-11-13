# SSGVote

A PHP and MySQL student-voting app with administrator and student accounts, election setup, one ballot per student, automatic tallying, turnout metrics, and controlled result publishing.

## Run locally with XAMPP

1. Start MySQL in the XAMPP Control Panel.
2. Create a MySQL database named `ssg_voting` in phpMyAdmin.
3. From this folder, run:

   ```powershell
   & 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t .
   ```

4. Open `http://127.0.0.1:8000/`. On first visit, create the administrator account.
5. Add positions, at least two candidates for every position, and student accounts. Open voting when ready; close it and publish results after the election.

The local database defaults are host `127.0.0.1`, port `3306`, database `ssg_voting`, user `root`, and a blank password. The database must exist before starting the app, and the MySQL user must have permission to create tables in it. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` to use different credentials.

## Deploy on Vercel

Vercel's static hosting does not execute `.php` files, which is why a plain-PHP deployment may download `index.php` instead of displaying the site. The root `Dockerfile` runs Apache with PHP and the MySQLi extension using Vercel's [Container Runtime](https://vercel.com/docs/functions/runtimes/container).

1. Push the `Dockerfile` and application files to the Git branch connected to your Vercel project.
2. In Vercel project settings, set the Framework Preset to **Container** if it is not detected automatically, then redeploy.
3. Create a MySQL database with a provider reachable from Vercel. Do not use `127.0.0.1` for the database host; that would point to the app container, not your computer or a hosted database.
4. Add these environment variables to the Vercel project for Production (and Preview if needed): `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. `DB_NAME` must already exist, and its user must be allowed to create tables.
5. Redeploy after setting the variables. Open the domain and create the administrator account.

Vercel containers do not include your XAMPP MySQL server. The database must be hosted separately, and the configured credentials must allow connections from Vercel. The app stores election data in MySQL; do not rely on the container filesystem for persistent data.

## Notes

- Student accounts are created by the administrator; there are no shared default credentials.
- Voter participation is stored separately from anonymous ballot choices; candidate totals do not expose which student selected whom.
- This version manages one election per database. Submitted ballots and published results remain stored in MySQL.
- This prototype does not connect to a school's existing identity provider. Use HTTPS and a properly secured MySQL account before deploying beyond local testing.
# SSGVote

A standalone PHP and MySQL student-voting prototype. It supports administrator and student accounts, candidate/position setup, one ballot per student, automatic tallying, and administrator-controlled result publishing.

## Run locally with XAMPP

1. Start MySQL in the XAMPP control panel.
2. From this folder, run:

   ```powershell
   & 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t .
   ```
   

3. Open `http://127.0.0.1:8000/`. On first visit, create the administrator account.
4. Sign in as the administrator. Add positions, at least two candidates for every position, and student accounts.
5. Open voting. Each student signs in and selects one candidate per position. Close voting and publish results when the election ends.

The default local database connection is `127.0.0.1:3306`, database `ssg_voting`, user `root`, and a blank password (the usual local XAMPP defaults). The app creates the database and tables automatically when needed. For a different MySQL setup, provide `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` as PHP environment variables before starting the server. If the database already exists, the configured MySQL account only needs access to it; creating a new database requires the corresponding MySQL privilege.

## Notes

- Student accounts are created by the administrator; there are no shared default credentials.
- Voter participation is stored separately from anonymous ballot choices; candidate totals do not expose which student selected whom.
- This version manages one election per database. Submitted ballots and published results remain stored in MySQL.
- This prototype does not connect to a school's existing identity provider. Use HTTPS and a properly secured MySQL account before deploying beyond local testing.



Set-Location 'C:\SSG Voting System'
& 'C:\xampp\php\php.exe' -S 127.0.0.1:8000 -t .
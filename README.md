# SSGVote

A PHP and PostgreSQL student-voting app with administrator and student accounts, election setup, one ballot per student, automatic tallying, turnout metrics, and controlled result publishing.

## Run locally with Docker

1. Make sure Docker Desktop is running.
2. Create a network and persistent PostgreSQL volume:

   ```powershell
   docker network create ssg-voting-net
   docker volume create ssg-voting-data
   ```

3. Start PostgreSQL with a local-only password:

   ```powershell
   docker run -d --name ssg-postgres --network ssg-voting-net `
     -e POSTGRES_DB=ssg_voting `
     -e POSTGRES_USER=ssg_voting `
     -e POSTGRES_PASSWORD=local-only-change-me `
     -v ssg-voting-data:/var/lib/postgresql/data postgres:17
   ```

4. Build and run the web app:

   ```powershell
   docker build -t ssg-voting .
   docker run --rm --name ssg-voting-web --network ssg-voting-net -p 8080:80 `
     -e PGHOST=ssg-postgres `
     -e PGPORT=5432 `
     -e PGDATABASE=ssg_voting `
     -e PGUSER=ssg_voting `
     -e PGPASSWORD=local-only-change-me `
     -e PGSSLMODE=disable ssg-voting
   ```

5. Open `http://127.0.0.1:8080/`. On first visit, create the administrator account. Add positions, candidates, and student accounts, then open voting when ready.

The app creates its PostgreSQL tables automatically. The example password is for local development only; change it if you reuse the commands.

## Deploy on Vercel

Vercel's static hosting does not execute `.php` files. The root `Dockerfile` runs Apache with PHP and PDO PostgreSQL using Vercel's [Container Runtime](https://vercel.com/docs/functions/runtimes/container).

1. Push the `Dockerfile` and application files to the Git branch connected to your Vercel project.
2. Set the project's Framework Preset to **Container**. The project needs a PostgreSQL database; the Neon integration can create one in Vercel Storage and connect it to the project.
3. Use the PostgreSQL connection URL supplied by the provider. The app prefers `POSTGRES_URL_NON_POOLING` for reliable session locking, then accepts `POSTGRES_URL`, `DATABASE_URL`, or `POSTGRES_PRISMA_URL`. Alternatively, configure `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, and `PGPASSWORD`.
4. Redeploy after connecting the database. Tables are created on the first successful request; then create the administrator account.

Use a database region close to your voters. Local XAMPP/MySQL is not reachable from Vercel. Existing data in a MySQL database is not imported automatically; export and migrate it separately before switching production data. Login sessions are stored in PostgreSQL so they survive container restarts and multiple instances.

## Notes

- Student accounts are created by the administrator; there are no shared default credentials.
- Voter participation is stored separately from anonymous ballot choices; candidate totals do not expose which student selected whom.
- This version manages one election per database. Submitted ballots and published results remain stored in PostgreSQL.
- This prototype does not connect to a school's existing identity provider. Use HTTPS and a properly secured PostgreSQL account before deploying beyond local testing.

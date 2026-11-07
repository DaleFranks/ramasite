# Backup

The Backup screen downloads everything that makes the site its own as one zip file. Download one before big changes and upgrades, and from time to time, and keep it somewhere safe.

![The Backup screen, with what a backup holds and the Download backup button](https://raw.githubusercontent.com/DaleFranks/ramasite/main/docs/screenshots/backup.png)

## What's in a backup

| In the zip | What it is |
|---|---|
| `database/database.sqlite` | Everything entered in the admin: pages, posts, cards, modules, sliders, forms and their submissions, redirects, Theme and Email settings, and the admin accounts |
| `public/uploads/` | The image and file libraries, logo and browser tab icon |
| `.env` | The site's settings file. Its key unlocks the saved SMTP password and everyone's two-step login. |
| `storage/app/database-version` | Which database updates the copy has |
| `RESTORING.txt` | Which version of RamaSite made the backup, and how to restore it |

Keep backups private: they contain the admin accounts and the key to the saved passwords.

On a site with many images the zip can take a little while to make; the download starts when it's ready.

The Backup button needs PHP's `zip` extension. If the server doesn't have it, the button is greyed out; ask your host to turn it on.

## Restoring a backup

1. Put a copy of RamaSite on the server: the version the backup was made with, or a newer one.
2. Upload the files in the backup into the site's folder, replacing the ones already there.
3. If the site's address has changed, open `.env` and change `APP_URL` to the new address.
4. Open the site. If the CMS is newer than the backup, it updates the database on this first visit.

## Copies made before updates

When a new version of RamaSite updates the database (on the first visit after an upgrade; see [Upgrading](upgrading.md)), it first saves a copy of the database in `storage/app/backups/`, named `before-update-` with the date and time. The newest three are kept. To go back to one, put it in place of `database/database.sqlite`.

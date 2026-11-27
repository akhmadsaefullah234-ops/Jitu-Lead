# Profil saya and Profil agensi

## Profil saya (`App\Filament\Pages\Auth\MyProfile`, every role)

This page is Filament's profile page (`->profile(MyProfile::class, isSimple: false)`), under the user menu. It holds:

- **Photo** (`users.avatar_path`): stored on the public disk at `avatars/{user id}/`, JPG, PNG or WebP up to 2 MB, and
  resized. `User::getFilamentAvatarUrl()` only accepts the user's own folder.
- **Name.**
- **Email**: with `->emailChangeVerification()`, a new address is used only after the link sent to it is opened.
- **WhatsApp**: an Indonesian mobile number, stored as `62…`. A changed number has to be verified again for the daily
  report.
- **Password**: the old password is required to change the password or the email.

`handleRecordUpdate` writes only the name, email, password, WhatsApp and photo. A role, `is_super_admin` or any
other field is never written from this page.

**Keluar dari perangkat lain** checks the password, then calls `Auth::logoutOtherDevices()` (`AuthenticateSession`
is in the panel middleware). With the database session driver, it also deletes the other sessions.

## Profil agensi (`App\Filament\Pages\AgencyProfilePage`, admins only)

The page is the first tab of Profil agensi (account menu, top right), slug `profil-agensi`. The name and time zone (WIB, WITA, WIT) go in their own
`tenants` columns. Everything else goes in `tenants.profile`, a JSON column, through `App\Agency\AgencyProfile::clean`:

- logo (`landing/{tenant id}/…` on the public disk, JPG, PNG or WebP up to 2 MB);
- colour (`#rrggbb`);
- business WhatsApp;
- email;
- address;
- `about` (500 characters);
- website (`http(s)://`).

The slug is shown read-only and never saved.

`App\Agency\AgencyProfile` is the one source the rest of the app reads:

| Where | What |
| --- | --- |
| `LandingPages\PageData` | WhatsApp button and links: the page's number, else the business WhatsApp, else the first WhatsApp channel. Logo: the page's logo, else the agency logo. The footer shows "tentang kami", address, email and website when they are filled. |
| `CreateLandingPage` | A new page starts with the agency colour, else the template colour. |
| `WhatsApp\TemplateRenderer` and `Welcome\WelcomeText` | `{agensi}` is the agency name. `{wa_agensi}` is the business WhatsApp as `08…`, else the first channel's number. |

An agency that leaves the profile empty sees exactly what it saw before.

## Migration

`2026_12_09_000100_add_profiles` adds `users.avatar_path` and `tenants.profile`. Both are nullable, so the migration
only adds columns.
